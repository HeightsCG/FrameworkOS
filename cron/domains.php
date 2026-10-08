<?php
/**
 * Custom domains: daily DNS re-check (PRD §39). Promotes domains whose records have appeared and takes a live
 * domain off as soon as its records are gone: a host that no longer resolves to the gateway must not stay a
 * sign-in target (it comes back on the next run, or at once with Verify in Settings). Also clears spent
 * session-handoff tokens.
 *
 *   17 4 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/domains.php >> /tmp/cls-domains.log 2>&1
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
date_default_timezone_set('UTC');
CronRuns::start('domains');

$model = new CreatorDomainsModel();
foreach ($model->list_to_recheck() as $row) {
    $r = CustomDomains::verify($row);
    $model->set_status((int) $row['id'], $r['status'], $r['error']);
    if ($r['status'] !== $row['status']) { echo gmdate('c'), ' ', $row['hostname'], ' ', $row['status'], ' -> ', $r['status'], "\n"; }
}
$model->purge_handoffs();
CronRuns::finish('domains', true);
