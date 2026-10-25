<?php
/*
 * Referral reward (ReferralRewards, BillingService::apply hook, refund reversal). Makes throwaway accounts with
 * no email address (nothing is sent), writes billing charges straight to the table and applies them (no payment
 * processor call), and deletes everything it made at the end. php tests/referral_rewards_test.php
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
    return $db->add('user_accounts', array('u_name' => 'zzref' . $tag . time(), 'user_email' => '', 'first_name' => 'Affiliate', 'last_name' => 'Test ' . strtoupper($tag),
        'p_word' => 'x', 'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 1, 'created_at' => $now, 'referred_by_creator_id' => $ref));
};
$charge = function ($uid, $kind, array $lines, array $fx) {
    static $n = 0; $n++;
    $items = array_map(function ($l) { return array('label' => $l[0], 'amount_cents' => $l[1]); }, $lines);
    $now = gmdate('Y-m-d H:i:s');
    return (int) (new BillingChargesModel())->create(array('user_id' => $uid, 'kind' => $kind, 'amount_cents' => max(0, (int) array_sum(array_column($lines, 1))),
        'line_items' => json_encode($items), 'effects' => json_encode($fx), 'period_start' => $now, 'period_end' => BillingService::add_period($now),
        'idempotency_key' => 'reftest-' . $uid . '-' . $n . '-' . bin2hex(random_bytes(4)), 'status' => 'pending', 'attempt' => 1));
};
$period = array('period_start' => gmdate('Y-m-d H:i:s'), 'period_end' => BillingService::add_period(gmdate('Y-m-d H:i:s')));
$plan_cents = BillingService::plan_cents('creator');
$all = array();
$ai = new AiCreditsModel();
try {
    $referrer = $mk('r'); $all[] = $referrer;
    $buyer = $mk('b', $referrer); $all[] = $buyer;
    $plain = $mk('p'); $all[] = $plain;
    $start = (int) $ai->get_balance($referrer);

    // a $0 trial start earns nothing
    $c0 = $charge($buyer, 'subscribe', array(array('Creator plan', $plan_cents), array('Trial', -$plan_cents)), array('plan' => 'creator', 'no_card' => 1) + $period);
    BillingService::apply($c0);
    check('free trial start earns nothing', $ai->row_by_key(ReferralRewards::key($c0)) === null);

    // first paid plan charge: the referrer gets the reward, once
    $c1 = $charge($buyer, 'renewal', array(array('Creator plan', $plan_cents)), array('plan' => 'creator', 'slots' => 0, 'pack' => 0));
    BillingService::apply($c1);
    $row = $ai->row_by_key(ReferralRewards::key($c1));
    check('first paid charge rewards the referrer', $row && (int) $row['user_id'] === $referrer && (int) $row['credits'] === ReferralRewards::CREDITS && (string) $row['type'] === 'referral_reward');
    check('referrer balance up by the reward', (int) $ai->get_balance($referrer) === $start + ReferralRewards::CREDITS);
    ReferralRewards::on_charge((new BillingChargesModel())->get($c1));
    check('same charge again rewards nothing more', (int) $ai->get_balance($referrer) === $start + ReferralRewards::CREDITS);

    // later charges earn nothing
    $c2 = $charge($buyer, 'renewal', array(array('Creator plan', $plan_cents)), array('plan' => 'creator', 'slots' => 0, 'pack' => 0));
    BillingService::apply($c2);
    check('second paid charge earns nothing', $ai->row_by_key(ReferralRewards::key($c2)) === null);

    // an account nobody referred earns nobody anything
    $c3 = $charge($plain, 'subscribe', array(array('Creator plan', $plan_cents)), array('plan' => 'creator') + $period);
    BillingService::apply($c3);
    check('unreferred account earns nothing', $ai->row_by_key(ReferralRewards::key($c3)) === null);

    // a refund of the rewarded charge takes it back, once
    $pi = 'pi_reftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($c1, array('stripe_payment_intent_id' => $pi));
    ReferralRewards::on_reverse($pi);
    ReferralRewards::on_reverse($pi);
    $rev = $ai->row_by_key(ReferralRewards::key($c1) . ':rev');
    check('refund takes the reward back once', $rev && (int) $rev['credits'] === -ReferralRewards::CREDITS && (int) $ai->get_balance($referrer) === $start);

    // a refund on a charge with no reward does nothing
    $pi2 = 'pi_reftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($c2, array('stripe_payment_intent_id' => $pi2));
    ReferralRewards::on_reverse($pi2);
    check('refund of an unrewarded charge does nothing', $ai->row_by_key(ReferralRewards::key($c2) . ':rev') === null);

    // reward already spent: the take-back stops at zero and settles the key
    $b2 = $mk('c', $referrer); $all[] = $b2;
    $c4 = $charge($b2, 'subscribe', array(array('Creator plan', $plan_cents)), array('plan' => 'creator') + $period);
    BillingService::apply($c4);
    $bal = (int) $ai->get_balance($referrer);
    $ai->apply_delta($referrer, -$bal, 'spend', 'test spend');
    $pi4 = 'pi_reftest_' . bin2hex(random_bytes(6));
    (new BillingChargesModel())->set($c4, array('stripe_payment_intent_id' => $pi4));
    ReferralRewards::on_reverse($pi4);
    $rev4 = $ai->row_by_key(ReferralRewards::key($c4) . ':rev');
    check('spent reward: balance stays at zero, key settled', $rev4 && (int) $rev4['credits'] === 0 && (int) $ai->get_balance($referrer) === 0);
} finally {
    foreach ($all as $uid) {
        foreach (array('billing_charges', 'billing_accounts', 'ai_credit_transactions', 'notifications', 'user_notifications', 'creator_profiles', 'creator_setup', 'user_notification_prefs', 'analytics_events') as $t) {
            try { $db->run("DELETE FROM `$t` WHERE user_id = :u", array(':u' => $uid)); } catch (\Throwable $e) {}
        }
        $db->run("DELETE FROM jobs WHERE dedupe_key IN (:k1, :k2) OR payload_json->'$.purge.creator' = :u OR payload_json->'$.user_id' = :u2", array(':k1' => 'membership_fee:' . $uid, ':k2' => 'directory_recheck:' . $uid, ':u' => $uid, ':u2' => $uid));
        $db->run('DELETE FROM user_accounts WHERE user_id = :u', array(':u' => $uid));
    }
}
check('throwaway accounts removed', count($db->rows('SELECT user_id FROM user_accounts WHERE user_id IN (' . implode(',', array_map('intval', $all)) . ')')) === 0);
exit($fail ? 1 : 0);
