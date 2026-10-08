<?php
/**
 * Public renditions catch-up (PublicThumbService): WebP copies for media in free published posts that lack one
 * (the publish job misses media still pending moderation, and posts switched to free later), WebP blog covers,
 * WebP profile photos and covers, and removal of public copies that no longer qualify (deleted, re-moderated,
 * post gated or unpublished, creator suspended, demo or without a selling plan). Only touches rows that need it, so
 * every run after the first is cheap. Most purges already happen as things change (PublicThumbService::queue_purge).
 * Cron (prod), daily:
 *
 *   40 3 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/public_thumbs_backfill.php >> /tmp/cls-public-thumbs.log 2>&1
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');   // same as Bootstrap: every stored timestamp is UTC, whatever the server's clock is set to
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
CronRuns::start('public_thumbs');
// Media: walk the missing rows by id. A run builds at most MAX_PER_RUN; the cursor file lets the next run carry on
// from there, so a long backlog (or rows that keep failing) never starves the ones after it. Permanent failures are
// recorded in media_assets.public_error by PublicThumbService::make() and skipped from then on.
const MAX_PER_RUN = 2000;
$cursor_file = sys_get_temp_dir() . '/cls-public-thumbs.cursor';
$cursor = is_file($cursor_file) ? (int) @file_get_contents($cursor_file) : 0;
$model = new MediaAssetsModel();
$made = 0; $failed = 0; $seen = 0;
while ($seen < MAX_PER_RUN) {
    $rows = $model->public_missing(0, 100, $cursor);
    if (empty($rows)) { $cursor = 0; break; }   // reached the end: the next run starts from the top
    foreach ($rows as $a) {
        $cursor = (int) $a['id']; $seen++;
        if (PublicThumbService::make($a)) { $made++; fwrite(STDOUT, 'asset ' . (int) $a['id'] . " public\n"); }
        else { $failed++; fwrite(STDOUT, 'asset ' . (int) $a['id'] . " FAILED\n"); }
    }
}
@file_put_contents($cursor_file, (string) $cursor);
$purged = PublicThumbService::purge_stale();
$covers = 0;
foreach ((new SeoArticlesModel())->missing_cover_webp(500) as $art) {
    if (PublicThumbService::blog_cover((int) $art['id'], (string) $art['cover_image_url']) !== '') { $covers++; fwrite(STDOUT, 'article ' . (int) $art['id'] . " cover webp\n"); }
    else { $failed++; fwrite(STDOUT, 'article ' . (int) $art['id'] . " cover FAILED\n"); }
}
$profiles = 0;
foreach ((new CreatorProfileModel())->missing_image_webp(substr(S3Service::public_url('x'), 0, -1), 500) as $pr) {
    foreach (array('avatar', 'cover') as $kind) {
        $src = (string) ($pr[$kind . '_url'] ?? '');
        if ($src === '' || !empty($pr[$kind . '_webp_url']) || preg_match('/\.gif$/i', $src)) { continue; }
        if (PublicThumbService::profile_image((int) $pr['user_id'], $kind, $src) !== '') { $profiles++; fwrite(STDOUT, 'creator ' . (int) $pr['user_id'] . ' ' . $kind . " webp\n"); }
        else { $failed++; fwrite(STDOUT, 'creator ' . (int) $pr['user_id'] . ' ' . $kind . " FAILED\n"); }
    }
}
$note = "$made made, $purged purged, $covers blog covers, $profiles profile images, $failed failed, cursor $cursor";
fwrite(STDOUT, $note . "\n");
CronRuns::finish('public_thumbs', $failed === 0, $note);
