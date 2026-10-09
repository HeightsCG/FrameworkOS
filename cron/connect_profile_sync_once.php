<?php
// One-off (2026-10-08): push every creator's page name and URL to their connected Stripe account, so Checkout and
// card statements show the creator, not the person Stripe verified. Safe to re-run.
// Prod: APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/connect_profile_sync_once.php
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
foreach ((new UsersModel())->with_connect_accounts() as $u) {
    $p = StripeService::connect_profile_params((int) $u['user_id']);
    $ok = StripeService::sync_connect_profile((string) $u['stripe_connect_account_id'], (int) $u['user_id']);
    echo date('c'), ' ', $u['u_name'], ' ', $u['stripe_connect_account_id'], ': ', $ok ? 'set "' . ($p['business_profile']['name'] ?? '') . '"' . (isset($p['settings']) ? ' descriptor ' . $p['settings']['payments']['statement_descriptor'] : '') : 'skipped (no name or Stripe error)', "\n";
}
