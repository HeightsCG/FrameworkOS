<?php
/*
 * Affiliate program (Affiliates, BillingService::apply hook, dispute reversal, payouts). Makes throwaway accounts with
 * no email address (nothing is sent), writes billing charges straight to the table and applies them (no payment
 * processor call), and deletes everything it made at the end. php tests/affiliates_test.php
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
    public function rows($sql, array $p = array()) { return parent::select($sql, $p); }
    public function add($table, array $d) { return (int) parent::insert($table, $d); }
};
$mk = function ($tag, $ref = null) use ($db) {
    $now = gmdate('Y-m-d H:i:s');
    return $db->add('user_accounts', array('u_name' => 'zzaff' . $tag . time(), 'user_email' => '', 'first_name' => 'Affiliate', 'last_name' => 'Test ' . strtoupper($tag),
        'p_word' => 'x', 'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 1, 'created_at' => $now, 'referred_by_creator_id' => $ref));
};
$charge = function ($uid, $kind, array $lines, array $fx) {
    static $n = 0; $n++;
    $items = array_map(function ($l) { return array('label' => $l[0], 'amount_cents' => $l[1]); }, $lines);
    $now = gmdate('Y-m-d H:i:s');
    return (int) (new BillingChargesModel())->create(array('user_id' => $uid, 'kind' => $kind, 'amount_cents' => max(0, (int) array_sum(array_column($lines, 1))),
        'line_items' => json_encode($items), 'effects' => json_encode($fx), 'period_start' => $now, 'period_end' => BillingService::add_period($now),
        'idempotency_key' => 'afftest-' . $uid . '-' . $n . '-' . bin2hex(random_bytes(4)), 'status' => 'pending', 'attempt' => 1));
};
$period = array('period_start' => gmdate('Y-m-d H:i:s'), 'period_end' => BillingService::add_period(gmdate('Y-m-d H:i:s')));
$plan_cents = BillingService::plan_cents('creator');

$aff_user = $mk('a'); $ref_user = $mk('r'); $ref2_user = $mk('s', $aff_user); $other = $mk('o');
$all = array($aff_user, $ref_user, $ref2_user, $other); $last = array();
$am = new AffiliatesModel(); $cm = new AffiliateCommissionsModel(); $pm = new AffiliatePayoutsModel();
try {
    // application: pending, then approved by staff
    $code = Affiliates::new_code('zzaffa');
    $aid = $am->create($aff_user, $code, 'https://example.com', 'Newsletter and reviews');
    check('application pending', (string) $am->get($aid)['status'] === 'pending');
    check('pending affiliate earns nothing at signup', (function () use ($am, $ref2_user) { $_COOKIE[Affiliates::COOKIE] = ''; Affiliates::attribute_signup($ref2_user); return $am->affiliate_of_user($ref2_user) === 0; })());
    $am->set_status($aid, 'approved');
    check('approved, with a code', (string) $am->get($aid)['status'] === 'approved' && Affiliates::valid_code($am->get($aid)['code']));

    // click rows: one per viewer per hour
    $ck = new AffiliateClicksModel();
    check('first click recorded', $ck->record($aid, sha1('1.2.3.4|ua'), '/'));
    check('same viewer within the hour not recorded again', !$ck->record($aid, sha1('1.2.3.4|ua'), '/pricing'));
    check('another viewer recorded', $ck->record($aid, sha1('5.6.7.8|ua'), '/'));

    // signup attribution: the cookie, never the affiliate themself, and the approved-affiliate creator ref ruling
    $_COOKIE[Affiliates::COOKIE] = json_encode(array('code' => $code, 't' => time()));
    Affiliates::attribute_signup($ref_user);
    check('cookie signup stores affiliate_id', $am->affiliate_of_user($ref_user) === $aid);
    Affiliates::attribute_signup($aff_user);
    check('the affiliate is never their own referral', $am->affiliate_of_user($aff_user) === 0);
    $_COOKIE[Affiliates::COOKIE] = json_encode(array('code' => $code, 't' => time() - 31 * 86400));
    Affiliates::attribute_signup($other);
    check('a cookie older than 30 days is ignored', $am->affiliate_of_user($other) === 0);
    unset($_COOKIE[Affiliates::COOKIE]);
    Affiliates::attribute_signup($ref2_user);
    check('?ref= of an approved affiliate creator stores affiliate_id', $am->affiliate_of_user($ref2_user) === $aid);

    // a paid Creator subscription: 25% of the charge, once
    $c1 = $charge($ref_user, 'subscribe', array(array('Creator plan', $plan_cents)), array('plan' => 'creator') + $period);
    BillingService::apply($c1);
    $row = $cm->for_charge($c1);
    check('subscribe charge earns 25% (' . ($row ? $row['commission_cents'] : 'none') . ' cents)', $row && (int) $row['commission_cents'] === intdiv($plan_cents * Affiliates::RATE_PERCENT, 100) && (string) $row['status'] === 'earned');
    check('second apply of the same charge does nothing', BillingService::apply($c1) === false && count($db->rows('SELECT id FROM affiliate_commissions WHERE charge_id = :c', array(':c' => $c1))) === 1);
    Affiliates::on_charge((new BillingChargesModel())->get($c1));
    check('a repeat hook call never writes a second row', count($db->rows('SELECT id FROM affiliate_commissions WHERE charge_id = :c', array(':c' => $c1))) === 1);

    // a credit pack: nothing
    $c2 = $charge($ref_user, 'pack', array(array('250 AI credits (monthly)', 2500)), array('pack' => 25, 'pack_cents' => 2500));
    BillingService::apply($c2);
    check('credit pack charge earns nothing', $cm->for_charge($c2) === null);

    // a renewal with a pack: the plan part only
    $c3 = $charge($ref_user, 'renewal', array(array('Creator plan', $plan_cents), array('250 AI credits (monthly)', 2500)), array('plan' => 'creator', 'slots' => 0, 'pack' => 25));
    BillingService::apply($c3);
    $r3 = $cm->for_charge($c3);
    check('renewal earns on the plan line only', $r3 && (int) $r3['invoice_cents'] === $plan_cents && (int) $r3['commission_cents'] === intdiv($plan_cents * Affiliates::RATE_PERCENT, 100));

    // an add-on slot charge mid-period earns like any plan invoice line
    $cs = $charge($ref_user, 'slots', array(array('1 extra AI influencer', 1500)), array('slots' => 1));
    BillingService::apply($cs);
    $rs = $cm->for_charge($cs);
    check('add-on slots charge earns 25%', $rs && (int) $rs['commission_cents'] === intdiv(1500 * Affiliates::RATE_PERCENT, 100));
    $db->run('DELETE FROM affiliate_commissions WHERE charge_id = :c', array(':c' => $cs));   // keep the payout maths below on whole plan payments

    // a $0 (fully discounted) first month: nothing
    $c4 = $charge($ref2_user, 'subscribe', array(array('Creator plan', $plan_cents), array('Promo FOUNDING (first month free)', -$plan_cents)), array('plan' => 'creator') + $period);
    BillingService::apply($c4);
    check('$0 charge earns nothing', $cm->for_charge($c4) === null);

    // the affiliate's own plan never earns (even if affiliate_id were set on them)
    $db->run('UPDATE user_accounts SET affiliate_id = :a WHERE user_id = :u', array(':a' => $aid, ':u' => $aff_user));
    $c5 = $charge($aff_user, 'subscribe', array(array('Creator plan', $plan_cents)), array('plan' => 'creator') + $period);
    BillingService::apply($c5);
    check('own account earns nothing', $cm->for_charge($c5) === null);
    $db->run('UPDATE user_accounts SET affiliate_id = NULL WHERE user_id = :u', array(':u' => $aff_user));

    // a dispute on the first charge reverses it
    $pi = 'pi_afftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($c1, array('stripe_payment_intent_id' => $pi));
    Affiliates::on_reverse($pi);
    check('dispute reverses the commission', (string) $cm->for_charge($c1)['status'] === 'reversed');
    $one = intdiv($plan_cents * Affiliates::RATE_PERCENT, 100);
    check('balance now one commission', $cm->available_cents($aid) === $one);

    // payouts: under the minimum refused, at the minimum requested, paid, then a dispute after payout adjusts
    $a = $am->get($aid);
    $r = Affiliates::request_payout($a);
    check('payout under ' . Price::PAYOUT_MIN_LABEL . ' refused ("' . $r['message'] . '")', $r['ok'] === false && $r['message'] === 'Minimum payout is ' . Price::PAYOUT_MIN_LABEL . '.');
    $c6 = $charge($ref_user, 'renewal', array(array('Creator plan', $plan_cents)), array('plan' => 'creator', 'slots' => 0, 'pack' => 0));
    BillingService::apply($c6);
    $c7 = $charge($ref2_user, 'subscribe', array(array('Creator plan', $plan_cents)), array('plan' => 'creator') + $period);
    BillingService::apply($c7);
    $avail = $cm->available_cents($aid);
    check('three earned commissions reach the minimum (' . $avail . ')', $avail >= Price::PAYOUT_MIN_CENTS);
    $r = Affiliates::request_payout($a);
    check('payout requested', $r['ok'] === true && (int) $pm->get($r['id'])['amount_cents'] === $avail && $cm->available_cents($aid) === 0);
    check('a second request is refused while one waits', Affiliates::request_payout($a)['ok'] === false);
    $pm->settle($r['id'], 'paid'); $cm->mark_paid($r['id']);
    check('mark paid: payout and its commissions paid', (string) $pm->get($r['id'])['status'] === 'paid' && (string) $cm->for_charge($c3)['status'] === 'paid');
    $pi6 = 'pi_afftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($c6, array('stripe_payment_intent_id' => $pi6));
    Affiliates::on_reverse($pi6);
    check('dispute after payout: row stays paid, one negative adjustment', (string) $cm->for_charge($c6)['status'] === 'paid' && $cm->available_cents($aid) === -$one);
    Affiliates::on_reverse($pi6);
    check('a repeated dispute adds nothing', $cm->available_cents($aid) === -$one);
    $s = Affiliates::stats($a);
    check('stats: 2 clicks, 2 signups, paid ' . $s['paid'] . ', earned nets the reversed paid one (' . $s['earned'] . ')', $s['clicks'] === 2 && $s['signups'] === 2 && $s['paid'] === $avail && $s['pending'] === 0 && $s['earned'] === $avail - $one);
    $al = array_values(array_filter($am->admin_list(), function ($x) use ($aid) { return (int) $x['id'] === $aid; }));
    check('admin list earned agrees with the dashboard', $al && (int) $al[0]['earned_cents'] === $s['earned'] && (int) $al[0]['paid_cents'] === $s['paid']);

    // refund through the webhook handler (charge.refunded on the platform account) reverses an earned commission
    $c8 = $charge($ref_user, 'renewal', array(array('Creator plan', $plan_cents)), array('plan' => 'creator', 'slots' => 0, 'pack' => 0));
    BillingService::apply($c8);
    $pi8 = 'pi_afftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($c8, array('stripe_payment_intent_id' => $pi8));
    $wh = new WebhookController();
    $hm = new ReflectionMethod($wh, 'handle_account_event'); $hm->setAccessible(true);
    $ev = json_decode(json_encode(array('type' => 'charge.refunded', 'account' => '', 'data' => array('object' => array('payment_intent' => $pi8, 'amount_refunded' => $plan_cents, 'amount' => $plan_cents)))));
    $hm->invoke($wh, $ev, new CreatorSubscriptionsModel());
    check('refund webhook reverses the commission', (string) $cm->for_charge($c8)['status'] === 'reversed');

    // reversal while the payout is only requested: the row leaves the payout, which shrinks (or goes under the minimum)
    foreach (range(1, 5) as $i) { $x = $charge($ref_user, 'renewal', array(array('Creator plan', $plan_cents)), array('plan' => 'creator', 'slots' => 0, 'pack' => 0)); BillingService::apply($x); $last[] = $x; }
    $before = $cm->available_cents($aid);
    $r = Affiliates::request_payout($a);
    check('payout requested for ' . $before . ' (adjustment netted)', $r['ok'] === true && (int) $pm->get($r['id'])['amount_cents'] === $before);
    $x4 = $charge($ref_user, 'renewal', array(array('Creator plan', $plan_cents)), array('plan' => 'creator', 'slots' => 0, 'pack' => 0)); BillingService::apply($x4);
    // a fourth row earned after the request is not in it; requesting again is refused while one waits
    check('later commission stays out of the open payout', (int) $pm->get($r['id'])['amount_cents'] === $before && $cm->available_cents($aid) === $one);
    $pix = 'pi_afftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($last[0], array('stripe_payment_intent_id' => $pix));
    Affiliates::on_reverse($pix);
    $po_row = $pm->get($r['id']);
    $rv = $cm->for_charge($last[0]);
    $expect = $before - $one;
    check('requested reversal: row reversed, out of the payout, payout now ' . $expect, (string) $rv['status'] === 'reversed' && $rv['payout_id'] === null && $po_row && (int) $po_row['amount_cents'] === $expect && $cm->sum_for_payout($r['id']) === $expect);
    check('no negative adjustment for a requested reversal', count($db->rows('SELECT id FROM affiliate_commissions WHERE charge_id = :c AND invoice_cents < 0', array(':c' => $last[0]))) === 0);
    $piy = 'pi_afftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($last[1], array('stripe_payment_intent_id' => $piy));
    Affiliates::on_reverse($piy);   // down to $expect - one, under the minimum
    check('requested payout under the minimum after a reversal: deleted, its rows back in the balance', !$pm->get($r['id']) && $cm->available_cents($aid) === $expect - $one + $one && count($db->rows('SELECT id FROM affiliate_commissions WHERE payout_id = :p', array(':p' => $r['id']))) === 0);

    // seed one more so the balance clears the minimum again
    $x5 = $charge($ref_user, 'renewal', array(array('Creator plan', $plan_cents)), array('plan' => 'creator', 'slots' => 0, 'pack' => 0)); BillingService::apply($x5);
    // race: two requests at once (both wait on the lock this test holds, then run), only one lands
    $code_child = 'putenv("APPLICATION_ENV=development"); $p = ' . var_export($root, true) . '; require "$p/vendor/autoload.php"; spl_autoload_register(function ($c) use ($p) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php") as $s) { if (file_exists($p . $s)) { require_once $p . $s; return; } } }); $r = Affiliates::request_payout((new AffiliatesModel())->get(' . (int) $aid . ')); echo $r["ok"] ? "ok" : $r["message"];';
    $cm->lock($aid);
    $kids = array(); $pipes = array();
    foreach (array(0, 1) as $k) { $kids[$k] = proc_open(array(PHP_BINARY, '-r', $code_child), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$k]); }
    usleep(1500000);   // both children are now waiting on the lock
    $cm->unlock($aid);
    $outs = array(); foreach (array(0, 1) as $k) { $outs[] = trim(stream_get_contents($pipes[$k][1])); stream_get_contents($pipes[$k][2]); proc_close($kids[$k]); }
    sort($outs);
    $open = $db->rows("SELECT id, amount_cents FROM affiliate_payouts WHERE affiliate_id = :a AND status = 'requested'", array(':a' => $aid));
    check('race: one request lands, the other is refused (' . implode(' / ', $outs) . '), one open payout', $outs === array('You already have a payout request waiting.', 'ok') && count($open) === 1 && (int) $open[0]['amount_cents'] === $cm->sum_for_payout($open[0]['id']));
} finally {
    $aff_ids = array_column($db->rows('SELECT id FROM affiliates WHERE user_id IN (' . implode(',', array_map('intval', $all)) . ')'), 'id');
    foreach ($aff_ids as $x) {
        foreach (array('affiliate_clicks', 'affiliate_commissions', 'affiliate_payouts') as $t) { $db->run("DELETE FROM `$t` WHERE affiliate_id = :a", array(':a' => (int) $x)); }
        $db->run('DELETE FROM affiliates WHERE id = :a', array(':a' => (int) $x));
    }
    foreach ($all as $uid) {
        foreach (array('billing_charges', 'billing_accounts', 'ai_credit_transactions', 'notifications', 'creator_profiles', 'creator_setup', 'user_notification_prefs') as $t) {
            $db->run("DELETE FROM `$t` WHERE user_id = :u", array(':u' => $uid));
        }
        $db->run("DELETE FROM jobs WHERE dedupe_key IN (:k1, :k2) OR payload_json->'$.purge.creator' = :u OR payload_json->'$.user_id' = :u2", array(':k1' => 'membership_fee:' . $uid, ':k2' => 'directory_recheck:' . $uid, ':u' => $uid, ':u2' => $uid));
        $db->run('DELETE FROM user_accounts WHERE user_id = :u', array(':u' => $uid));
    }
}
check('throwaway accounts removed', count($db->rows('SELECT user_id FROM user_accounts WHERE user_id IN (' . implode(',', array_map('intval', $all)) . ')')) === 0);
exit($fail ? 1 : 0);
