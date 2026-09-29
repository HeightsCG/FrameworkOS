<?php
/**
 * One-off: list every creator already on a paid plan in the Creator Directory, the same as upgrading from Free does
 * now (DirectoryService::list_on_upgrade). Creators who upgraded before 2026-09-29 were never listed. Each one gets
 * the photo check queued (the queue worker runs it); they show once it passes and they have a photo and a
 * safe-for-work post. Only touches creators who aren't listed, so it's safe to run again.
 *
 *   APPLICATION_ENV=production php cron/directory_backfill.php
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
$ids = (new CreatorProfileModel())->paid_unlisted_creators();
foreach ($ids as $uid) {
    DirectoryService::list_on_upgrade($uid);
    fwrite(STDOUT, 'listed creator ' . $uid . "\n");
}
fwrite(STDOUT, count($ids) . " creator(s) listed; photo checks queued.\n");
