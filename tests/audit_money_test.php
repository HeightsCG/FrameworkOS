<?php
/*
 * 2026-10-18 audit, money area: reactivation restarts the billing period (no catch-up), trial-safe prorating, the
 * billing anchor day, partial affiliate reversals. Throwaway accounts with no email address and a fake saved card (only $0 charges are made, so nothing
 * reaches the payment processor); everything it made is deleted at the end.  php tests/audit_money_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
$db = new class extends Model {
    public function run($sql, array $p = array()) { return parent::sql($sql, $p); }
    public function rows($sql, array $p = array()) { return (array) parent::select($sql, $p); }
    public function add($table, array $d) { return (int) parent::insert($table, $d); }
};
$t0 = gmdate('Y-m-d H:i:s', time() - 2);
$users = array();
$mk = function ($tag) use ($db, &$users) {
    $uid = $db->add('user_accounts', array('u_name' => 'zzaudm' . $tag . time(), 'user_email' => '', 'first_name' => 'Audit', 'last_name' => 'Money ' . strtoupper($tag),
        'p_word' => 'x', 'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 1, 'created_at' => gmdate('Y-m-d H:i:s')));
    $users[] = $uid;
    return $uid;
};
$ts = function ($s) { return strtotime((string) $s . ' UTC'); };
$near = function ($a, $b, $secs = 120) use ($ts) { return abs($ts($a) - $ts($b)) <= $secs; };
$now = function () { return gmdate('Y-m-d H:i:s'); };
$assets = array(); $charges = array();

try {
    $accts = new BillingAccountsModel();

    // ---- 1. reactivation restarts the period from now; renew never catches up missed months ----
    $c1 = $mk('re');
    $old = gmdate('Y-m-d H:i:s', time() - 95 * 86400);
    $accts->save($c1, array('plan_key' => 'creator', 'status' => 'active', 'current_period_start' => gmdate('Y-m-d H:i:s', time() - 125 * 86400),
        'current_period_end' => $old, 'next_charge_at' => $old, 'billing_anchor_day' => (int) gmdate('j', $ts($old)), 'stripe_payment_method_id' => 'pm_test_audit_fake',
        'promo_code' => 'ZZAUDIT', 'promo_percent' => 100, 'promo_periods_left' => null));   // forever 100% off: every charge is $0
    AccountBilling::on_reactivate($c1);
    $a = BillingService::account($c1);
    check('reactivate: period end and next charge moved to now', $near($a['current_period_end'], $now()) && $near($a['next_charge_at'], $now()));
    check('reactivate: billing day becomes today', (int) $a['billing_anchor_day'] === (int) gmdate('j'));
    $r = BillingService::renew($c1);
    $a = BillingService::account($c1);
    $n = count($db->rows("SELECT id FROM billing_charges WHERE user_id = :u AND kind = 'renewal'", array('u' => $c1)));
    check('renew after reactivate: one charge, succeeded', ($r['status'] ?? '') === 'succeeded' && $n === 1);
    check('renew: the new period starts now and runs one month', $near($a['current_period_start'], $now()) && $a['current_period_end'] === BillingService::add_period($a['current_period_start'], 1, (int) gmdate('j')));
    // defensive: a period end long past without a reactivation (renew restarts it, stored before charging)
    sleep(1);   // a new start second, so this renewal is a new charge
    $old2 = gmdate('Y-m-d H:i:s', time() - 60 * 86400);
    $accts->save($c1, array('current_period_end' => $old2, 'next_charge_at' => $old2));
    BillingService::renew($c1);
    $a = BillingService::account($c1);
    check('renew from a 60-day-old period end: one new period from now, not two catch-up months', $near($a['current_period_start'], $now()) && $ts($a['current_period_end']) > time() + 27 * 86400
        && count($db->rows("SELECT id FROM billing_charges WHERE user_id = :u AND kind = 'renewal'", array('u' => $c1))) === 2);
    // a recent period end (inside the grace) still renews from the period end
    $recent = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
    $accts->save($c1, array('current_period_end' => $recent, 'next_charge_at' => $recent));
    BillingService::renew($c1);
    $a = BillingService::account($c1);
    check('renew 3 days late keeps the billing date (no restart)', $a['current_period_start'] === $recent);
    // reactivating inside a paid period changes nothing
    $before = BillingService::account($c1);
    AccountBilling::on_reactivate($c1);
    check('reactivate inside a paid period: dates unchanged', BillingService::account($c1)['current_period_end'] === $before['current_period_end']);

    // ---- 15. anchor day: no drift after month end ----
    check('Jan 31 -> Feb 28', BillingService::add_period('2027-01-31 10:00:00', 1, 31) === '2027-02-28 10:00:00');
    check('Feb 28 with anchor 31 -> Mar 31 (not Mar 28)', BillingService::add_period('2027-02-28 10:00:00', 1, 31) === '2027-03-31 10:00:00');
    check('Mar 31 -> Apr 30 -> May 31', BillingService::add_period('2027-03-31 10:00:00', 1, 31) === '2027-04-30 10:00:00' && BillingService::add_period('2027-04-30 10:00:00', 1, 31) === '2027-05-31 10:00:00');
    check('no anchor: the old day-of-month rule', BillingService::add_period('2027-02-28 10:00:00') === '2027-03-28 10:00:00');
    $accts->save($c1, array('current_period_end' => gmdate('Y-m-d H:i:s', time() - 3600), 'next_charge_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'billing_anchor_day' => 31));
    BillingService::renew($c1);
    $a = BillingService::account($c1);
    $next_month = new DateTimeImmutable('first day of next month', new DateTimeZone('UTC'));
    check('renew uses the stored anchor (day ' . (int) gmdate('j', $ts($a['current_period_end'])) . ')', (int) gmdate('j', $ts($a['current_period_end'])) === min(31, (int) $next_month->format('t')));

    // ---- 2. prorating against one billing month, capped at 1 ----
    $c2 = $mk('tr');
    $accts->save($c2, array('plan_key' => 'creator', 'status' => 'active', 'current_period_start' => gmdate('Y-m-d H:i:s', time() - 86400),
        'current_period_end' => gmdate('Y-m-d H:i:s', time() + 13 * 86400), 'next_charge_at' => gmdate('Y-m-d H:i:s', time() + 13 * 86400), 'stripe_payment_method_id' => 'pm_test_audit_fake'));
    $f = BillingService::remaining_fraction(BillingService::account($c2));
    check('founding trial day 1: 13 days of a month left (' . round($f, 3) . '), not 13/14', $f > 0.40 && $f < 0.46);
    $q = BillingService::quote_plan($c2, 'studio');
    $studio13 = (int) round(BillingService::plan_cents('studio') * $f);
    check('upgrade quote on the trial charges about 13/30 of Studio (' . BillingService::money($q['lines'][0][1]) . ')', !empty($q['ok']) && (int) $q['lines'][0][1] === $studio13 && $q['lines'][0][1] < BillingService::plan_cents('studio') / 2);
    $accts->save($c2, array('current_period_start' => gmdate('Y-m-d H:i:s', time() - 10 * 86400), 'current_period_end' => BillingService::add_period(gmdate('Y-m-d H:i:s', time() - 10 * 86400))));
    $f2 = BillingService::remaining_fraction(BillingService::account($c2));
    $acct2 = BillingService::account($c2);
    $exp = ($ts($acct2['current_period_end']) - time()) / ($ts($acct2['current_period_end']) - $ts($acct2['current_period_start']));
    check('a normal month: same share as before', abs($f2 - $exp) < 0.001);
    $accts->save($c2, array('current_period_start' => gmdate('Y-m-d H:i:s', time() - 86400), 'current_period_end' => gmdate('Y-m-d H:i:s', time() + 60 * 86400)));
    check('a period longer than a month is capped at 1', BillingService::remaining_fraction(BillingService::account($c2)) === 1.0);


    // ---- 13. partial refunds reverse pro rata; a won dispute restores ----
    $ac = new AffiliateCommissionsModel();
    $chid = $db->add('billing_charges', array('user_id' => $c1, 'kind' => 'renewal', 'amount_cents' => 4900, 'line_items' => '[]', 'effects' => '{}',
        'idempotency_key' => 'zzaudit-aff-' . time(), 'status' => 'succeeded', 'applied' => 1));
    $charges[] = $chid;
    $affid = 900000000 + $c1;
    $ac->add_earned($affid, $c1, $chid, 4900, 1225);
    check('25% refund reverses 25% (306)', $ac->reverse_charge($chid, 2500, 0.25) === 1 && $ac->available_cents($affid) === 1225 - 306);
    check('the same refund again adds nothing', $ac->reverse_charge($chid, 2500, 0.25) === 0 && $ac->available_cents($affid) === 1225 - 306);
    check('refunded up to 50%: another 307', $ac->reverse_charge($chid, 2500, 0.5) === 1 && $ac->available_cents($affid) === 1225 - 613);
    check('then in full: nets to zero, row stays earned', $ac->reverse_charge($chid, 2500, 1.0) === 1 && $ac->available_cents($affid) === 0 && (string) $ac->for_charge($chid)['status'] === 'earned');
    check('won dispute restores the unpaid adjustments', $ac->restore_charge($chid) === 1 && $ac->available_cents($affid) === 1225);
    check('full dispute with nothing before: the row is reversed', $ac->reverse_charge($chid, 2500, 1.0) === 1 && (string) $ac->for_charge($chid)['status'] === 'reversed' && $ac->available_cents($affid) === 0);
    check('won: earned again', $ac->restore_charge($chid) === 1 && (string) $ac->for_charge($chid)['status'] === 'earned' && $ac->available_cents($affid) === 1225);
    $db->run("DELETE FROM affiliate_commissions WHERE affiliate_id = :a", array(':a' => $affid));

} finally {
    foreach ($assets as $x) { $db->run("DELETE FROM media_assets WHERE id = :x", array(':x' => $x)); }
    foreach ($charges as $c) { $db->run("DELETE FROM billing_charges WHERE id = :c", array(':c' => $c)); }
    foreach ($users as $u) {
        foreach (array("DELETE FROM billing_charges WHERE user_id = :u", "DELETE FROM billing_accounts WHERE user_id = :u", "DELETE FROM ai_credit_transactions WHERE user_id = :u",
                       "DELETE FROM user_notifications WHERE user_id = :u", "DELETE FROM analytics_events WHERE user_id = :u", "DELETE FROM creator_profiles WHERE user_id = :u",
                       "DELETE FROM user_accounts WHERE user_id = :u") as $q) {
            try { $db->run($q, array(':u' => $u)); } catch (\Throwable $e) { /* table not present */ }
        }
    }
    try { $db->run("DELETE FROM jobs WHERE type = 'public_thumb' AND created_at >= :t AND state = 'queued'", array(':t' => $t0)); } catch (\Throwable $e) {}
    $left = $db->rows("SELECT COUNT(*) AS n FROM user_accounts WHERE u_name LIKE 'zzaudm%'");
    check('throwaway accounts removed', (int) $left[0]['n'] === 0);
}
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
