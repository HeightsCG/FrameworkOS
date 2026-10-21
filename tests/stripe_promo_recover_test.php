<?php
/**
 * Coupons lost in the move to app billing (2026-10-21): a coupon on the old Stripe plan subscription (or an older one on
 * the Stripe customer; the current API can't set those, so only the subscription is tested) is picked up before the renewal is charged (BillingService::recover_stripe_promo), so a free plan renews
 * at $0 with no receipt and a discounted one at the discounted price. Stripe TEST mode, throwaway accounts with no email.
 *   APPLICATION_ENV=development php tests/stripe_promo_recover_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php", "$root/app/controllers/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($extra !== '' && !$ok ? '  -> ' . $extra : '') . "\n"; if (!$ok) { $fail++; } }
if (strpos(StripeService::secret_key(), '_live_') !== false) { fwrite(STDERR, "live keys: refusing\n"); exit(1); }

$db    = new class extends Model { public function x($s, $p = array()) { return $this->sql($s, $p); } public function q($s, $p = array()) { return $this->select($s, $p); } };
$users = new UsersModel();
$c     = StripeService::client();
$made  = array(); $coupons = array(); $subs = array();
$studio = BillingService::plan_cents('studio');

// A Studio account billed by the app, with a card, whose period has just ended.
$mk = function () use ($users, $db, &$made) {
    $uid = (int) $users->create_user('spr' . mt_rand(100000, 999999), '!no-sign-in', 'Promo', 'Test', '', 0, 0);
    $made[] = $uid;
    $db->x("UPDATE user_accounts SET role_id = (SELECT id FROM user_roles WHERE role_name = 'Creator'), email_verified = 1, is_demo = 1 WHERE user_id = :u", array(':u' => $uid));
    BillingService::account($uid);
    $cus = StripeService::ensure_customer($users->get_user_by_id($uid)[0]);
    $si  = StripeService::create_setup_intent($cus, array('type' => 'platform_card', 'user_id' => (string) $uid));
    StripeService::client()->setupIntents->confirm($si['id'], array('payment_method' => 'pm_card_visa'));
    BillingService::save_card($uid, $si['id']);
    $db->x("UPDATE billing_accounts SET plan_key = 'studio', plan_price_cents = :p, status = 'active', current_period_start = UTC_TIMESTAMP() - INTERVAL 1 MONTH,
            current_period_end = UTC_TIMESTAMP() - INTERVAL 1 MINUTE, next_charge_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = :u", array(':p' => BillingService::plan_cents('studio'), ':u' => $uid));
    return array($uid, $cus);
};
$coupon = function (array $f) use ($c, &$coupons) { $k = $c->coupons->create($f + array('name' => 'Test ' . mt_rand(1000, 9999))); $coupons[] = $k->id; return $k; };
// The old plan subscription, with the coupon, as migrate_subscriptions.php left it (canceled in Stripe, id kept).
$old_sub = function ($cus, $coupon_id) use ($c, $db, &$subs) {
    $prod = $c->products->create(array('name' => 'Test Studio plan'));
    $s = $c->subscriptions->create(array('customer' => $cus, 'items' => array(array('price_data' => array('currency' => 'usd', 'product' => $prod->id, 'unit_amount' => 19900, 'recurring' => array('interval' => 'month')))),
        'discounts' => array(array('coupon' => $coupon_id)), 'payment_behavior' => 'allow_incomplete'));
    $subs[] = $s->id;
    $c->subscriptions->cancel($s->id);
    return $s->id;
};
$last = function ($uid) use ($db) { $r = $db->q("SELECT * FROM billing_charges WHERE user_id = :u AND kind = 'renewal' ORDER BY id DESC LIMIT 1", array(':u' => $uid)); return $r ? $r[0] : null; };
$receipts = function ($uid) use ($db) { return (int) $db->q("SELECT COUNT(*) n FROM notifications WHERE user_id = :u AND title LIKE 'Your receipt%'", array(':u' => $uid))[0]['n']; };

try {
    echo "== migrated account, 100% forever coupon on the old subscription\n";
    list($a, $cus) = $mk();
    $sid = $old_sub($cus, $coupon(array('percent_off' => 100, 'duration' => 'forever'))->id);
    $db->x("UPDATE billing_accounts SET migrated_subscription_id = :s WHERE user_id = :u", array(':s' => $sid, ':u' => $a));
    $r0 = $receipts($a);
    $r = BillingService::renew($a);
    $ch = $last($a); $acct = BillingService::account($a);
    check('the renewal succeeds', ($r['status'] ?? '') === 'succeeded', json_encode($r));
    check('it costs $0', $ch && (int) $ch['amount_cents'] === 0, $ch ? (string) $ch['amount_cents'] : 'no charge');
    check('no receipt', $receipts($a) === $r0);
    check('the coupon is kept on the account, forever', (float) $acct['promo_percent'] === 100.0 && $acct['promo_periods_left'] === null && (string) $acct['promo_code'] !== '');
    check('the period moved on', strtotime($acct['next_charge_at'] . ' UTC') > time() + 20 * 86400);

    echo "== 50% off for 3 months on the old subscription\n";
    list($b, $cus) = $mk();
    $sid = $old_sub($cus, $coupon(array('percent_off' => 50, 'duration' => 'repeating', 'duration_in_months' => 3))->id);
    $db->x("UPDATE billing_accounts SET migrated_subscription_id = :s WHERE user_id = :u", array(':s' => $sid, ':u' => $b));
    BillingService::renew($b);
    $ch = $last($b); $acct = BillingService::account($b);
    check('charged half', $ch && (int) $ch['amount_cents'] === (int) round($studio / 2) && $ch['status'] === 'succeeded', $ch ? $ch['amount_cents'] . ' ' . $ch['status'] : '');
    check('the remaining months are counted', (int) $acct['promo_periods_left'] >= 1 && (int) $acct['promo_periods_left'] <= 3, (string) $acct['promo_periods_left']);

    echo "== a one-time coupon was already spent\n";
    list($d, $cus) = $mk();
    $sid = $old_sub($cus, $coupon(array('percent_off' => 100, 'duration' => 'once'))->id);
    $db->x("UPDATE billing_accounts SET migrated_subscription_id = :s WHERE user_id = :u", array(':s' => $sid, ':u' => $d));
    BillingService::renew($d);
    $ch = $last($d);
    check('full price', $ch && (int) $ch['amount_cents'] === $studio && empty(BillingService::account($d)['promo_code']));

    echo "== no coupon anywhere\n";
    list($f) = $mk();
    BillingService::renew($f);
    $ch = $last($f);
    check('full price, as before', $ch && (int) $ch['amount_cents'] === $studio && $ch['status'] === 'succeeded');
} finally {
    foreach ($subs as $s) { try { $c->subscriptions->cancel($s); } catch (\Throwable $ex) {} }
    foreach ($coupons as $k) { try { $c->coupons->delete($k); } catch (\Throwable $ex) {} }
    foreach ($made as $uid) {
        foreach (array('checkout_attempts', 'billing_charges', 'billing_accounts', 'ai_credit_transactions', 'analytics_events', 'notifications') as $tb) { $db->x("DELETE FROM $tb WHERE user_id = :u", array(':u' => $uid)); }
        $db->x("DELETE FROM user_accounts WHERE user_id = :u AND user_email = ''", array(':u' => $uid));
    }
}
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
