<?php
/**
 * Admin financials: our fee on a sale type is gross minus the creator's share minus refunds and never negative
 * (an event ticket refunded after the creator's share was released used to show a negative fee on /admin/financials).
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

// the formula
check('normal sale: 10% fee', AdminModel::platform_fee(1000, 900, 0) === 100);
check('full refund before release: fee is zero, not the whole gross', AdminModel::platform_fee(1000, 0, 1000) === 0);
check('refund after the creator share was released: never negative', AdminModel::platform_fee(3200, 3040, 3200) === 0);
check('partial refund reduces the fee', AdminModel::platform_fee(1000, 800, 100) === 100);
check('negative creator share (clawbacks exceed earnings) counts as zero', AdminModel::platform_fee(1000, -50, 0) === 1000);
check('returns an int', is_int(AdminModel::platform_fee('1000', '900', '0')));

// dev data: nothing on the financial pages is negative
$m = new AdminModel();
$neg = array();
foreach ($m->money_series() as $mo) {
    if ($mo['fee'] < 0) { $neg[] = $mo['k'] . ' fee'; }
    if ($mo['revenue'] < 0) { $neg[] = $mo['k'] . ' revenue'; }
    foreach ($mo['types'] as $tk => $t) { if ($t['platform'] < 0) { $neg[] = $mo['k'] . ' ' . $tk; } }
}
check('money_series has no negative fee or revenue', $neg === array(), implode(', ', $neg));
$fin = $m->financials(array());
$neg = array();
foreach ($fin['sales'] as $k => $row) { if ($row['platform'] < 0) { $neg[] = $k; } }
check('financials() sales by type has no negative fee', $neg === array() && $fin['sales_total']['platform'] >= 0, implode(', ', $neg));
$neg = array();
foreach ($m->daily_series(30) as $d) { if ($d['fee'] < 0 || $d['revenue'] < 0) { $neg[] = $d['d']; } }
check('daily_series has no negative fee or revenue', $neg === array(), implode(', ', $neg));
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
