<?php
/**
 * Custom domains: daily DNS re-check (PRD §39). Promotes domains whose records have appeared and takes a live
 * domain off only after its records have been missing on two runs in a row, so one DNS blip can't take a
 * creator's page down. Also clears spent session-handoff tokens.
 *
 *   17 4 * * *  APPLICATION_ENV=production php /path/to/framework/cron/domains.php >> /tmp/cls-domains.log 2>&1
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

$model = new CreatorDomainsModel();
foreach ($model->list_to_recheck() as $row) {
    $r    = CustomDomains::verify($row);
    $live = in_array($row['status'], array('verified', 'active'), true);
    $rank = array('failed' => 0, 'pending' => 0, 'verified' => 1, 'active' => 2);
    if ($live && $rank[$r['status']] < $rank[$row['status']] && (string) $row['last_error'] === '') {
        // First miss on a live domain: note it, keep serving.
        $model->set_status((int) $row['id'], $row['status'], $r['error']);
        echo gmdate('c'), ' ', $row['hostname'], ' miss (kept ', $row['status'], '): ', $r['error'], "\n";
        continue;
    }
    $model->set_status((int) $row['id'], $r['status'], $r['error']);
    if ($r['status'] !== $row['status']) { echo gmdate('c'), ' ', $row['hostname'], ' ', $row['status'], ' -> ', $r['status'], "\n"; }
}
$model->purge_handoffs();
