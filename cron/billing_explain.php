<?php
/**
 * Read-only: what billing holds for one account, to see why a charge came out as it did. Prints the plan and promo
 * fields, the founding claim, every plan charge with its lines and the promo it carried, and the Stripe terms of each
 * promo code involved. Changes nothing.
 *
 *   APPLICATION_ENV=production php cron/billing_explain.php --login=name@example.com     (email or username)
 *   APPLICATION_ENV=production php cron/billing_explain.php --user=123
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});

$uid = 0; $login = '';
foreach ($argv as $a) {
    if (strpos($a, '--user=') === 0) { $uid = (int) substr($a, 7); }
    if (strpos($a, '--login=') === 0) { $login = substr($a, 8); }
}
$users = new UsersModel();
if ($uid === 0 && $login !== '') { $r = $users->get_user_by_login($login); $uid = (int) (is_array($r) && isset($r[0]) ? $r[0]['user_id'] : ($r['user_id'] ?? 0)); }
if ($uid === 0) { fwrite(STDERR, "Account not found. Use --login=<email or username> or --user=<id>\n"); exit(1); }
$u = $users->get_user_by_id($uid)[0] ?? null;
if (!$u) { fwrite(STDERR, "No user $uid\n"); exit(1); }

$show = function ($label, $v) { echo str_pad($label, 26) . ($v === null ? 'NULL' : (is_bool($v) ? ($v ? 'yes' : 'no') : $v)) . "\n"; };
echo "== account\n";
$show('user', $uid . ' @' . $u['u_name'] . ' ' . $u['user_email']);
$show('stripe customer', $u['stripe_customer_id'] ?? null);
$acct = (new BillingAccountsModel())->get($uid);
if (!$acct) { echo "no billing_accounts row\n"; exit(0); }
foreach (array('plan_key', 'plan_price_cents', 'status', 'current_period_start', 'current_period_end', 'next_charge_at', 'billing_anchor_day', 'cancel_at_period_end', 'pending_plan_key',
               'promo_code', 'promo_percent', 'promo_amount_cents', 'promo_periods_left', 'trial_credits_held', 'fee_percent_override', 'migrated_subscription_id', 'card_brand', 'card_last4', 'last_charge_id', 'last_charge_status') as $k) {
    if (array_key_exists($k, $acct)) { $show($k, $acct[$k]); }
}
$fc = (new FoundingClaimsModel())->for_user($uid);
$show('founding claim', $fc ? $fc['status'] . ' (' . ($fc['created_at'] ?? '') . ')' : null);

echo "\n== plan charges, oldest first\n";
$codes = array();
foreach (array_reverse((new BillingChargesModel())->history($uid, 100)) as $ch) {
    $fx = (array) json_decode((string) $ch['effects'], true);
    echo "#{$ch['id']} {$ch['created_at']}  {$ch['kind']}  " . BillingService::money((int) $ch['amount_cents']) . "  {$ch['status']}" . ((int) $ch['applied'] ? ' applied' : '') . ($ch['failure_reason'] ? '  (' . $ch['failure_reason'] . ')' : '') . "\n";
    echo "    period " . ($ch['period_start'] ?: '-') . ' to ' . ($ch['period_end'] ?: '-') . "\n";
    foreach ((array) json_decode((string) $ch['line_items'], true) as $li) { echo '    line  ' . str_pad(BillingService::money((int) $li['amount_cents']), 10) . $li['label'] . "\n"; }
    echo '    effects plan=' . ($fx['plan'] ?? '-') . (isset($fx['promo']) ? ' promo=' . json_encode($fx['promo']) : ' promo=none') . (isset($fx['promo_code']) ? ' promo_code=' . $fx['promo_code'] : '') . "\n";
    if (!empty($fx['promo']['promo_code'])) { $codes[(string) $fx['promo']['promo_code']] = 1; }
}
if (!empty($acct['promo_code'])) { $codes[(string) $acct['promo_code']] = 1; }

echo "\n== promo codes in Stripe\n";
if (!$codes) { echo "none used on this account\n"; }
foreach (array_keys($codes) as $code) {
    if ($code === Founding::CODE) { echo "$code: the founding trial (internal, first " . Founding::TRIAL_DAYS . " days only)\n"; continue; }
    $r = StripeService::resolve_promo_code($code);
    if (!$r) { echo "$code: not found in Stripe as an active promotion code or coupon\n"; continue; }
    echo "$code: " . $r['label'] . '  percent_off=' . var_export($r['percent_off'], true) . ' amount_off=' . var_export($r['amount_off'], true)
        . '  charges covered=' . ($r['periods'] === null ? 'forever' : $r['periods']) . "\n";
}
