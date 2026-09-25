<?php
/**
 * Generate the marketing-page illustrations (see libs/Classes/SiteImages.php).
 *   php cron/site_images.php              → generate every image that does not exist yet
 *   php cron/site_images.php --regen=KEY  → re-roll one image
 *   php cron/site_images.php --all        → re-roll all of them
 *   php cron/site_images.php --bg         → (re)build the small 640px WebP hero backgrounds (SiteImages::bg)
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$opts = getopt('', array('regen::', 'all', 'bg'));
$keys = array_keys(SiteImages::SUBJECTS);
if (isset($opts['bg'])) {
    if (empty(SiteImages::all())) {
        fwrite(STDERR, "No site images on this server (" . Main::app_path() . "/app/config/site_images.json is missing or empty).\n"
            . "Copy that file from an environment that has it, or run this script without --bg to generate the photos first.\n");
        exit(1);
    }
    foreach ($keys as $k) {
        $t = microtime(true);
        if (SiteImages::url($k) === '') { printf("%s.bg skipped: no original image for %s\n", $k, $k); continue; }
        $u = SiteImages::make_bg($k);
        printf("%s.bg %s %.1fs\n", $k, $u !== '' ? $u : 'FAILED (see the PHP error log: download, GD or S3 upload)', microtime(true) - $t);
    }
    exit(0);
}
if (!empty($opts['regen'])) { $keys = array((string) $opts['regen']); }
foreach ($keys as $k) {
    if (empty($opts['regen']) && !isset($opts['all']) && SiteImages::url($k) !== '') { echo "skip $k (exists)\n"; continue; }
    $t = microtime(true); $u = SiteImages::generate($k);
    printf("%s %s %.1fs\n", $k, $u !== '' ? $u : 'FAILED', microtime(true) - $t);
}
