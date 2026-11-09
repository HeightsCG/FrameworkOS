<?php
/**
 * ONE-OFF (2026-11-09): retire creator custom domains after the code that served them was deleted.
 * NOT for crontab. Dry run by default (prints what it would do); --apply acts. Safe to run twice:
 * only domain rows that are not removed yet are touched, so a second run finds nothing.
 *
 * Run on prod AFTER deploying the code that removes custom domains, and BEFORE
 * sql/2026-11-09_drop_custom_domains.sql:
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/retire_domains_once.php
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/retire_domains_once.php --apply
 *
 * What it does: lists every creator domain still on record (handle, domain, status, connected since),
 * and with --apply marks each one removed and sends each owner one in-platform notice (Notify 'system')
 * naming their main domain.
 *
 * Depends only on Main (config), Model/Database, RetireDomainsModel and Notify.
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

$apply = in_array('--apply', $argv, true);
$m     = new RetireDomainsModel();
$line  = function ($s = '') { fwrite(STDOUT, $s . "\n"); };

$line('Retire custom domains — ' . ($apply ? 'APPLY' : 'DRY RUN (add --apply to act)') . ' — ' . gmdate('Y-m-d H:i:s') . ' UTC, env ' . getenv('APPLICATION_ENV'));
$line();

try {
    $rows = $m->open_domains();
} catch (\Throwable $e) {
    $line('creator_domains n/a (' . $e->getMessage() . ')');
    exit(0);
}

$owners = array();   // user_id => ['handle' => , 'domain' => first (main) domain]
$n = 0;
foreach ($rows as $r) {
    $live  = in_array((string) $r['status'], array('active', 'verified'), true) && (string) ($r['verified_at'] ?? '') !== '';
    $since = $live ? 'connected since ' . $r['verified_at'] : 'added ' . $r['date_created'] . ', not connected';
    $line('  @' . (string) $r['u_name'] . ' (' . (int) $r['user_id'] . ')  ' . $r['hostname'] . '  ' . $r['status'] . (empty($r['is_primary']) ? '' : ' (main)') . '  ' . $since
        . '  -> ' . ($apply ? 'removed' : 'would remove'));
    if (!isset($owners[(int) $r['user_id']])) { $owners[(int) $r['user_id']] = array('handle' => (string) $r['u_name'], 'domain' => (string) $r['hostname']); }
    if ($apply && $m->remove_domain((int) $r['id'])) { $n++; }
}

$line();
$line(($apply ? "removed: $n of " : 'would remove: ') . count($rows) . ' domain row(s), ' . count($owners) . ' owner(s)');

foreach ($owners as $uid => $o) {
    $body = 'Custom domains are no longer available, so ' . $o['domain'] . ' no longer shows your page. Your page is still at '
        . Main::public_domain() . '/@' . $o['handle'] . '. You can remove the DNS record you added for it.';
    $line('  ' . ($apply ? 'notified' : 'would notify') . ' @' . $o['handle'] . ': ' . $body);
    if ($apply) { Notify::send($uid, 'system', 'Custom domains are no longer available', $body, '/@' . rawurlencode($o['handle'])); }
}

$line(); $line($apply ? 'Done.' : 'Dry run only. Re-run with --apply to act.');
