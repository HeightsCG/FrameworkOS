<?php
/**
 * One-time: give every published article's cover its 176 wide copy (PublicThumbService::THUMB), used by the "Keep reading"
 * thumbnails. Covers made before 2026-10-12 have only the 960 and 480 copies; this regenerates the set for those and removes
 * the old pair. Safe to run again: covers that already have the small copy are skipped. Does not change article dates.
 * Usage: APPLICATION_ENV=production php cron/cover_thumbs_once.php [--dry-run] [--limit=N]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'limit::'));
$dry = isset($opts['dry-run']); $limit = max(1, (int) ($opts['limit'] ?? 500));
$m = new SeoArticlesModel(); $done = 0; $skip = 0; $fail = 0;
foreach ($m->published_markdown() as $row) {
    $a = $m->get((int) $row['id']); if (!$a) { continue; }
    $cover = trim((string) ($a['cover_image_url'] ?? '')); $webp = trim((string) ($a['cover_webp_url'] ?? ''));
    if ($cover === '' || PublicThumbService::cover_thumb($webp) !== '') { $skip++; continue; }
    if ($done >= $limit) { break; }
    echo '/blog/' . $a['slug'] . ($dry ? "  would regenerate\n" : '');
    if ($dry) { $done++; continue; }
    $url = PublicThumbService::blog_cover((int) $a['id'], $cover, $webp);
    if ($url === '') { echo "  FAILED\n"; $fail++; if ($fail >= 2) { echo "stopping after two failures\n"; break; } continue; }
    echo "  -> " . basename($url) . "\n"; $done++;
}
echo ($dry ? 'would regenerate: ' : 'regenerated: ') . "$done, already done or no cover: $skip, failed: $fail\n";
exit($fail ? 1 : 0);
