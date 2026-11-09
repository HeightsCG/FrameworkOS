<?php
/**
 * Admin financials: plan payments + AI credit packs are platform revenue, never negative, and MRR counts what a renewal charges.
 * Run: APPLICATION_ENV=development php tests/admin_financials_test.php
 */
putenv('APPLICATION_ENV=development');
$path = dirname(__DIR__);
require_once $path . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($path) {
    foreach (["/app/controllers/$class.php", "/app/controllers/api/$class.php", "/app/models/$class.php", "/app/$class.php", "/libs/Classes/$class.php"] as $s) {
        if (file_exists($path . $s)) { require_once $path . $s; }
    }
});
$fails = 0;
function check($name, $ok, $note = '') { global $fails; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $note === '' ? '' : '  -> ' . $note) . "\n"; if (!$ok) { $fails++; } }

// dev data: nothing on the financial pages is negative, and revenue = plan payments + AI credit packs
$m = new AdminModel();
$neg = array(); $sum_ok = true;
foreach ($m->money_series() as $mo) {
    if ($mo['revenue'] < 0 || $mo['ai'] < 0 || $mo['plans'] < 0) { $neg[] = $mo['k']; }
    if ($mo['revenue'] !== $mo['plans'] + $mo['ai']) { $sum_ok = false; }
}
check('money_series has no negative amounts', $neg === array(), implode(', ', $neg));
check('money_series revenue = plans + AI credits', $sum_ok);
check('money_series has 24 months', count($m->money_series()) === 24);
$fin = $m->financials(array());
check('financials() has no fan-sale keys', !isset($fin['sales']) && !isset($fin['owed_creators']) && isset($fin['ai_sold']) && isset($fin['plan_mrr']));
$neg = array();
foreach ($m->daily_series(30) as $d) { if ($d['revenue'] < 0 || $d['revenue'] !== $d['plans'] + $d['ai']) { $neg[] = $d['d']; } }
check('daily_series revenue = plans + AI credits, never negative', $neg === array(), implode(', ', $neg));
check('daily_series returns 30 days, oldest first', count($m->daily_series(30)) === 30 && $m->daily_series(30)[29]['d'] === gmdate('Y-m-d'));

// MRR counts what an account's renewal actually charges: a 100% free-forever code is $0, a canceling plan is $0
$base = array('status' => 'active', 'plan_key' => 'studio', 'next_charge_at' => '2026-11-01 00:00:00', 'cancel_at_period_end' => 0, 'pending_plan_key' => '',
              'influencer_slots' => 0, 'influencer_slots_next' => null, 'pack_dollars' => 0, 'pack_dollars_next' => null, 'pack_price_cents' => null,
              'promo_code' => null, 'promo_percent' => null, 'promo_amount_cents' => null, 'promo_periods_left' => null);
$studio = BillingService::plan_cents('studio');
check('full-price Studio counts its plan price', AdminController::account_mrr($base) === $studio, (string) AdminController::account_mrr($base));
check('100% free-forever code counts $0', AdminController::account_mrr(array_merge($base, array('promo_code' => 'FREESTUDIO2026', 'promo_percent' => '100.00'))) === 0);
check('50% code counts half', AdminController::account_mrr(array_merge($base, array('promo_code' => 'HALF', 'promo_percent' => '50.00'))) === (int) round($studio / 2));
check('fixed-amount code larger than the plan never goes negative', AdminController::account_mrr(array_merge($base, array('promo_code' => 'BIG', 'promo_amount_cents' => $studio + 5000))) === 0);
check('free code on Creator still counts a paid add-on slot', AdminController::account_mrr(array_merge($base, array('plan_key' => 'creator', 'promo_code' => 'FREECREATOR', 'promo_percent' => '100.00', 'influencer_slots' => 1))) === BillingService::slot_cents());
check('canceling at period end counts $0', AdminController::account_mrr(array_merge($base, array('cancel_at_period_end' => 1))) === 0);
check('nothing scheduled counts $0', AdminController::account_mrr(array_merge($base, array('next_charge_at' => null))) === 0);

echo $fails ? "\n$fails FAILED\n" : "\nALL OK\n";
exit($fails ? 1 : 0);
