<?php
/**
 * Plan price lock + the Founding Creator offer as one setting (2026-10-18). With the offer's end date in the past:
 * new signups pay the regular price, an account that started at $49 keeps $49 on every renewal, a plan change locks
 * the new plan's price, moving to Free clears the lock, and the offer flips back on when the date moves forward again
 * (extending the offer is one admin setting). Uses throwaway accounts with no email (nothing is sent, no card is
 * charged: only quotes and next-charge previews are read) and restores the admin setting it touches.
 *   APPLICATION_ENV=development php tests/plan_price_lock_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($extra !== '' && !$ok ? '  -> ' . $extra : '') . "\n"; if (!$ok) { $fail++; } }

$settings = new AdminSettingsModel();
$saved    = $settings->get(CreatorPromo::SETTING, '');
$users    = new UsersModel();
$accts    = new BillingAccountsModel();
$made     = array();
$mk = function ($plan, $lock) use ($users, $accts, &$made) {
    $uid = (int) $users->create_user('lockt' . mt_rand(100000, 999999), '!no-sign-in', 'Lock', 'Test', '', 0, 0);
    $made[] = $uid;
    BillingService::account($uid);
    $start = gmdate('Y-m-d H:i:s', time() - 20 * 86400); $end = gmdate('Y-m-d H:i:s', time() + 10 * 86400);
    $accts->save($uid, array('plan_key' => $plan, 'plan_price_cents' => $lock, 'status' => 'active', 'current_period_start' => $start,
        'current_period_end' => $end, 'next_charge_at' => $end, 'billing_anchor_day' => (int) gmdate('j', strtotime($end . ' UTC'))));
    return $uid;
};
$plan_line = function ($nc) { foreach ((array) ($nc['lines'] ?? array()) as $l) { if (preg_match('/ plan$/', (string) $l[0])) { return (int) $l[1]; } } return -1; };

try {
    // The offer ended yesterday (set BEFORE anything reads the tiers: PlanTiers caches them per process).
    $settings->set(CreatorPromo::SETTING, gmdate('Y-m-d', time() - 2 * 86400));
    CreatorPromo::reset();
    check('after the end date the offer is over', !CreatorPromo::running() && !CreatorPromo::active());
    check('new signups get the regular Creator price', PlanTiers::price('creator') === CreatorPromo::regular() && CreatorPromo::list_price() === CreatorPromo::regular());

    $locked = $mk('creator', 4900);
    $nc = BillingService::next_charge(BillingService::account($locked));
    check('an account that started at $49 renews at $49', $plan_line($nc) === 4900, json_encode($nc));
    check('its billing page shows $49 for its plan', BillingService::account_plan_cents(BillingService::account($locked), 'creator') === 4900);

    $unlocked = $mk('creator', null);
    check('an account with no lock pays the list price', $plan_line(BillingService::next_charge(BillingService::account($unlocked))) === CreatorPromo::regular() * 100);

    $free = (int) $users->create_user('lockf' . mt_rand(100000, 999999), '!no-sign-in', 'Lock', 'Free', '', 0, 0); $made[] = $free;
    BillingService::account($free);
    $q = BillingService::quote_plan($free, 'creator');
    check('a new Creator quote is the regular price', !empty($q['ok']) && (int) $q['recurring'] === CreatorPromo::regular() * 100 && (int) $q['today'] === CreatorPromo::regular() * 100, json_encode($q));

    // Upgrading from a $49 Creator credits the unused time at $49 (what they paid), not at the new list price.
    $q2 = BillingService::quote_plan($locked, 'studio');
    $credit = 0; foreach ((array) $q2['lines'] as $l) { if (strpos((string) $l[0], 'Unused') === 0) { $credit = -(int) $l[1]; } }
    $frac = BillingService::remaining_fraction(BillingService::account($locked));
    check('an upgrade credits unused time at the locked $49', $credit > 0 && abs($credit - (int) round(4900 * $frac * BillingService::paid_share(BillingService::account($locked)))) <= 1, json_encode($q2));

    // A scheduled downgrade locks the new plan's price as it starts.
    $studio = $mk('studio', 19900);
    $accts->save($studio, array('pending_plan_key' => 'creator'));
    $nc2 = BillingService::next_charge(BillingService::account($studio));
    check('a scheduled change to Creator previews the price at that moment', $plan_line($nc2) === CreatorPromo::regular() * 100, json_encode($nc2));

    // Moving to Free clears the lock: coming back later is a new start at the price of the day.
    BillingService::to_free($locked, true, 'test');
    check('moving to Free clears the locked price', BillingService::account($locked)['plan_price_cents'] === null);

    // Extending the offer is the one setting: a later date turns it back on (new process state: reset + fresh check).
    $settings->set(CreatorPromo::SETTING, gmdate('Y-m-d', time() + 30 * 86400));
    CreatorPromo::reset();
    check('a later end date reopens the offer', CreatorPromo::running() && CreatorPromo::list_price() === CreatorPromo::price());
    check('the label follows the saved date', CreatorPromo::ends_label() === (new DateTime(gmdate('Y-m-d', time() + 30 * 86400)))->format('F j, Y'));
} finally {
    if ($saved === '') {
        (new class extends Model { public function x($k) { return $this->sql("DELETE FROM app_settings WHERE k = :k", array(':k' => $k)); } })->x(CreatorPromo::SETTING);
    } else {
        $settings->set(CreatorPromo::SETTING, $saved);
    }
    $db = new class extends Model { public function x($s, $p) { return $this->sql($s, $p); } };
    foreach ($made as $uid) {
        $db->x("DELETE FROM billing_accounts WHERE user_id = :u", array(':u' => $uid));
        $db->x("DELETE FROM ai_credit_transactions WHERE user_id = :u", array(':u' => $uid));
        $db->x("DELETE FROM user_accounts WHERE user_id = :u AND user_email = ''", array(':u' => $uid));
    }
}
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
