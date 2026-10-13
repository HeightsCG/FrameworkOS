<?php
/**
 * One-time repair (2026-10-13): files that were deleted from a gallery or through the connector while a post still used them.
 * Those delete paths did not touch the post, so it stayed published with nothing to show. Each such file is brought back for
 * its posts (no longer deleted) and stays hidden from the library, which is what a removal does from now on
 * (MediaAssetsModel::remove_from_library). Files deleted from the Content Studio library were unlinked from their posts at the
 * time, so they are not in this list. Needs sql/2026-10-13_media_library_hidden.sql.
 * Usage: APPLICATION_ENV=production php cron/media_restore_post_files_once.php [--dry-run|--apply]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }
$m = new MediaAssetsModel(); $n = 0;
foreach ($m->deleted_but_on_posts() as $r) {
    echo 'file ' . (int) $r['id'] . ' (creator ' . (int) $r['creator_id'] . ', deleted ' . $r['deleted_at'] . '): on post(s) ' . $r['post_ids'] . ', ' . (int) $r['published'] . " published\n";
    if ($apply) { $m->restore_for_posts((int) $r['id']); }
    $n++;
}
echo "\nfiles " . ($apply ? 'restored' : 'to restore') . ": $n\n" . ($apply ? "applied\n" : "dry run only\n");
