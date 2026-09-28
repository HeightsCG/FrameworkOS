<?php
/**
 * One-off: put every creator's current platform fee on their fans' existing memberships. Before 2026-09-28 the fee
 * was fixed when a fan joined, so creators who changed plan since then still have the old fee on older memberships.
 * New plan changes are synced automatically (BillingService::mirror → MembershipFeeJob). Safe to run again.
 *
 *   APPLICATION_ENV=production php cron/sync_membership_fees.php
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
foreach ((new CreatorSubscriptionsModel())->creators_with_paid_members() as $uid) {
    fwrite(STDOUT, 'creator ' . $uid . ': ' . AccountBilling::sync_membership_fees($uid) . "\n");
}
