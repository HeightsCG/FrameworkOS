<?php
/**
 * No-card trial (2026-10-20): the founding trial starts with no card and Founding::NO_CARD_CREDITS AI credits; adding a
 * card unlocks the rest of the plan's credits; at the trial's end with no card the account moves to Free (no charge, no
 * retries); with a card it renews as before. Card adds go through Stripe TEST mode. Throwaway accounts with no email.
 *   APPLICATION_ENV=development php tests/no_card_trial_test.php
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

$db    = new class extends Model { public function x($s, $p = array()) { return $this->sql($s, $p); } public function q($s, $p = array()) { return $this->select($s, $p); } };
$users = new UsersModel();
$ai    = new AiCreditsModel();
$made  = array();
$mk = function () use ($users, $db, &$made) {
    $uid = (int) $users->create_user('nct' . mt_rand(100000, 999999), '!no-sign-in', 'Trial', 'Test', '', 0, 0);
    $made[] = $uid;
    $db->x("UPDATE user_accounts SET role_id = (SELECT id FROM user_roles WHERE role_name = 'Creator'), email_verified = 1, is_demo = 1 WHERE user_id = :u", array(':u' => $uid));
    BillingService::account($uid);
    return $uid;
};
$add_card = function ($uid, $pm = 'pm_card_visa') use ($users) {   // what the card form does
    $cus = StripeService::ensure_customer($users->get_user_by_id($uid)[0]);
    $si  = StripeService::create_setup_intent($cus, array('type' => 'platform_card', 'user_id' => (string) $uid));
    StripeService::client()->setupIntents->confirm($si['id'], array('payment_method' => $pm));
    return BillingService::save_card($uid, $si['id']);
};
$full = (int) PlanTiers::get(Founding::PLAN)['limits']['ai_credits'];
Founding::present(true);   // as the billing API does when founding=1 comes with the request

try {
    echo "== start with no card\n";
    $a = $mk();
    $q = BillingService::quote_plan($a, Founding::PLAN, Founding::CODE);
    check('the trial quote needs no card', !empty($q['ok']) && (int) $q['today'] === 0 && $q['card_needed'] === false && BillingService::no_card_trial_quote($q), json_encode($q));
    check('a regular (non-trial) quote still needs a card', BillingService::quote_plan($a, Founding::PLAN)['card_needed'] === true);
    $r = BillingService::change_plan($a, Founding::PLAN, Founding::CODE, array());
    $acct = BillingService::account($a);
    check('the trial starts with no card', ($r['status'] ?? '') === 'succeeded' && $acct['plan_key'] === Founding::PLAN && $acct['status'] === 'active', json_encode($r));
    check('it gets ' . Founding::NO_CARD_CREDITS . ' AI credits', $ai->get_balance($a) === Founding::NO_CARD_CREDITS, (string) $ai->get_balance($a));
    check('the rest are held for a card', (int) $acct['trial_credits_held'] === $full - Founding::NO_CARD_CREDITS);
    check('it is a no-card trial', BillingService::in_no_card_trial($acct));
    check('the founding spot is active', (string) ((new FoundingClaimsModel())->for_user($a)['status'] ?? '') === 'active');
    $ca = $db->q("SELECT status FROM checkout_attempts WHERE user_id = :u ORDER BY id DESC LIMIT 1", array(':u' => $a));
    check('the checkout attempt shows succeeded', $ca && $ca[0]['status'] === 'succeeded');

    echo "== trial-ending email\n";
    $u = $users->get_user_by_id($a)[0];
    $mail = LifecycleEmails::trial_ending_email($u, $acct, BillingService::next_charge($acct));
    check('no-card version asks for a card', ($mail['button'] ?? '') === 'Add Card' && strpos((string) $mail['link'], 'add_card=1') !== false && strpos($mail['paras'][1], 'moves to Free') !== false);
    check('it says no card is on file', in_array(array('Card', 'None yet'), $mail['facts'], true));

    echo "== adding a card during the trial\n";
    $s = $add_card($a);
    $acct = BillingService::account($a);
    check('the card saves', !empty($s['ok']) && (string) $acct['stripe_payment_method_id'] !== '', json_encode($s));
    check('the held AI credits unlock', $ai->get_balance($a) === $full && $acct['trial_credits_held'] === null, (string) $ai->get_balance($a));
    check('the message says so', strpos((string) $s['message'], number_format($full - Founding::NO_CARD_CREDITS) . ' more AI credits') !== false, (string) $s['message']);
    check('no longer a no-card trial', !BillingService::in_no_card_trial($acct));
    $s2 = $add_card($a);
    check('a second card adds no credits', $ai->get_balance($a) === $full && !empty($s2['ok']));
    $mail = LifecycleEmails::trial_ending_email($u, $acct, BillingService::next_charge($acct));
    check('the trial-ending email is the usual one once a card is on file', !isset($mail['button']) && strpos($mail['paras'][1], 'Nothing to do') !== false);

    echo "== trial ends with no card\n";
    $b = $mk();
    BillingService::change_plan($b, Founding::PLAN, Founding::CODE, array());
    $db->x("UPDATE billing_accounts SET next_charge_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE, current_period_end = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = :u", array(':u' => $b));
    $charges0 = (int) $db->q("SELECT COUNT(*) n FROM billing_charges WHERE user_id = :u", array(':u' => $b))[0]['n'];
    $r = BillingService::renew($b);
    $acct = BillingService::account($b);
    check('day 14 with no card: moved to Free', ($r['status'] ?? '') === 'free' && $acct['plan_key'] === 'free' && $acct['status'] === 'free', json_encode($r));
    check('...not past due, no retries', $acct['past_due_since'] === null && $acct['next_retry_at'] === null);
    check('...no charge attempted', (int) $db->q("SELECT COUNT(*) n FROM billing_charges WHERE user_id = :u", array(':u' => $b))[0]['n'] === $charges0);
    check('...trial AI credits gone, nothing held', $ai->get_balance($b) === 0 && $acct['trial_credits_held'] === null);
    check('...the founding claim lapsed', (string) ((new FoundingClaimsModel())->for_user($b)['status'] ?? '') === 'lapsed');

    echo "== trial with a card renews as before\n";
    $c = $mk();
    $add_card($c);
    BillingService::change_plan($c, Founding::PLAN, Founding::CODE, array());
    $acct = BillingService::account($c);
    check('a trial started with a card gets all ' . $full . ' AI credits', $ai->get_balance($c) === $full && $acct['trial_credits_held'] === null);
    $db->x("UPDATE billing_accounts SET next_charge_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE, current_period_end = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = :u", array(':u' => $c));
    $r = BillingService::renew($c);
    check('at the trial end the card is charged', ($r['status'] ?? '') === 'succeeded' && BillingService::account($c)['plan_key'] === Founding::PLAN, json_encode($r));
} finally {
    foreach ($made as $uid) {
        foreach (array('checkout_attempts', 'billing_charges', 'billing_accounts', 'ai_credit_transactions', 'analytics_events', 'founding_claims') as $tb) { $db->x("DELETE FROM $tb WHERE user_id = :u", array(':u' => $uid)); }
        $db->x("DELETE FROM user_accounts WHERE user_id = :u AND user_email = ''", array(':u' => $uid));
    }
    @unlink(sys_get_temp_dir() . '/cls_founding_taken.txt');
}
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
