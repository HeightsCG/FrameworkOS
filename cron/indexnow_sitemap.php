<?php
/**
 * IndexNow: announce every sitemap URL at once. Run after a deploy that changes product, compare or feature pages
 * (they have no publish event of their own), or any time the index should re-check the whole site.
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/indexnow_sitemap.php
 * Blog publishes, creator post publishes and profile saves ping on their own; this is the catch-all.
 * Prints the sitemap size and the IndexNow status (202 = accepted); the same line goes to the error log.
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
$urls = array();
foreach (SeoController::sitemap_entries() as $u) { $urls[] = (string) $u['loc']; }
echo date('c'), ' ', count($urls), " sitemap url(s)\n";
if (!IndexNow::enabled()) { echo "indexnow is off on this host (dev/local, or the key file is missing)\n"; exit(0); }
$code = IndexNow::ping($urls, true);
echo date('c'), ' indexnow answered ', $code, ($code === 200 || $code === 202 ? ' (accepted)' : ' (see error log)'), "\n";
exit(($code === 200 || $code === 202) ? 0 : 1);
