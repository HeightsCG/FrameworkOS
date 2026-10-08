<?php
/**
 * Billing worker — charges every account whose next_charge_at has passed (plan, extra AI
 * influencers and the monthly credit pack in one PaymentIntent) and retries past-due accounts
 * on the schedule in PlanTiers::BILLING. A period is never charged twice: BillingChargesModel
 * checks for a paid renewal first and every attempt has its own idempotency key.
 * Cron (prod), every 15 minutes:
 *
 *   * /15 * * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/billing.php >> /var/www/creatorlinkstudio.com/www/cron/billing.log 2>&1
 *
 * (Remove the space in "* /15" — it's only there to keep this comment block valid PHP.)
 * Dev runs it from the com.creatorlinkstudio.billing launchd agent.
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

// One run at a time.
$lock = fopen(sys_get_temp_dir() . '/cls-billing.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
CronRuns::start('billing');

$now   = gmdate('Y-m-d H:i:s');
$accts = new BillingAccountsModel();
foreach (array_merge($accts->due($now, 200), $accts->retries_due($now, 200)) as $a) {
    try {
        $r = BillingService::renew((int) $a['user_id']);
        fwrite(STDOUT, date('c') . ' user ' . (int) $a['user_id'] . ': ' . ($r['status'] ?? '?') . ' ' . ($r['message'] ?? '') . "\n");
    } catch (\Throwable $e) {
        error_log('[billing] user ' . (int) $a['user_id'] . ': ' . $e->getMessage());
        fwrite(STDOUT, date('c') . ' user ' . (int) $a['user_id'] . ': error ' . $e->getMessage() . "\n");
    }
}
CronRuns::finish('billing', true);
exit(0);
