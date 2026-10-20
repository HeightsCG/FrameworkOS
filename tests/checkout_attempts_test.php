<?php
/**
 * Checkout attempts (2026-10-20): every status path of a plan / add-on checkout, the hour timeout, the admin reads,
 * and real Stripe TEST-mode payments through BillingService (a good card, a card declined in the card form, and a
 * card that saves but is declined on the charge). Throwaway accounts with no email; everything is removed at the end.
 *   APPLICATION_ENV=development php tests/checkout_attempts_test.php
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
$m     = new CheckoutAttemptsModel();
$made  = array();
$mk = function ($demo = 1) use ($users, $db, &$made) {
    $uid = (int) $users->create_user('cot' . mt_rand(100000, 999999), '!no-sign-in', 'Checkout', 'Test', '', 0, 0);
    $made[] = $uid;
    $db->x("UPDATE user_accounts SET is_demo = :d WHERE user_id = :u", array(':d' => (int) $demo, ':u' => $uid));
    BillingService::account($uid);
    return $uid;
};
$latest = function ($uid) use ($db) { $r = $db->q("SELECT * FROM checkout_attempts WHERE user_id = :u ORDER BY id DESC LIMIT 1", array(':u' => $uid)); return $r ? $r[0] : null; };
$count  = function ($uid) use ($db) { return (int) $db->q("SELECT COUNT(*) n FROM checkout_attempts WHERE user_id = :u", array(':u' => $uid))[0]['n']; };
$back   = function ($id, $col, $secs) use ($db) { $db->x("UPDATE checkout_attempts SET $col = :t WHERE id = :id", array(':t' => gmdate('Y-m-d H:i:s', time() - $secs), ':id' => $id)); };
$fake_charge = function ($uid, $kind) use ($db) {   // a billing_charges row without Stripe, for the settle paths
    $db->x("INSERT INTO billing_charges (user_id, kind, amount_cents, line_items, effects, idempotency_key, status, created_at, updated_at)
            VALUES (:u, :k, 4900, '[]', '{}', :i, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())", array(':u' => $uid, ':k' => $kind, ':i' => 'cot-' . bin2hex(random_bytes(6))));
    return (int) $db->q("SELECT MAX(id) id FROM billing_charges WHERE user_id = :u", array(':u' => $uid))[0]['id'];
};

try {
    echo "== reasons\n";
    check('insufficient funds in plain language', CheckoutAttempts::reason('insufficient_funds', 'Your card has insufficient funds.') === 'Card declined: insufficient funds');
    check('generic decline', CheckoutAttempts::reason('generic_decline') === 'Card declined');
    check('an unknown code falls back to Stripe\'s message', CheckoutAttempts::reason('something_new', 'Stripe says no.') === 'Stripe says no.');
    check('an unknown code with no message names the code', CheckoutAttempts::reason('something_new') === 'Card declined (something new)');

    echo "== opened -> card_entered -> succeeded\n";
    $a = $mk();
    $id = CheckoutAttempts::opened($a, 'plan', 'creator', 'subscribe', 4900, 4900);
    $r = $m->get($id);
    check('opening checkout creates an attempt', $id > 0 && $r && $r['status'] === 'opened' && (int) $r['amount_cents'] === 4900 && $r['item'] === 'creator');
    $id2 = CheckoutAttempts::opened($a, 'plan', 'creator', 'subscribe', 3900, 4900);   // promo applied: re-quoted
    check('a re-quote of the same checkout updates it, no second row', $id2 === $id && $count($a) === 1 && (int) $m->get($id)['amount_cents'] === 3900);
    CheckoutAttempts::card_entered($a, 'seti_test_a');
    check('a saved card moves it to card_entered', $m->get($id)['status'] === 'card_entered' && $m->get($id)['stripe_setup_intent_id'] === 'seti_test_a' && $m->get($id)['card_entered_at'] !== null);
    $c = $fake_charge($a, 'subscribe');
    CheckoutAttempts::charging($a, 'subscribe', $c, 3900, 'creator');
    check('the charge is linked to the checkout', (int) $m->get($id)['charge_id'] === $c && $count($a) === 1);
    CheckoutAttempts::settled($c, 'succeeded', '', '', 'pi_test_a');
    check('a successful payment shows succeeded', $m->get($id)['status'] === 'succeeded' && $m->get($id)['stripe_payment_intent_id'] === 'pi_test_a' && $m->get($id)['finished_at'] !== null);
    CheckoutAttempts::settled($c, 'failed', 'late webhook', 'generic_decline');
    check('a late failure never overwrites a success', $m->get($id)['status'] === 'succeeded');

    echo "== card declined in the form, retried, then paid\n";
    $b = $mk();
    $id = CheckoutAttempts::opened($b, 'plan', 'studio', 'subscribe', 19900, 19900);
    CheckoutAttempts::card_failed($b, 'insufficient_funds', 'Your card has insufficient funds.', 'seti_test_b');
    $r = $m->get($id);
    check('a declined card shows failed with the reason', $r['status'] === 'failed' && $r['failure_reason'] === 'Card declined: insufficient funds' && $r['failure_code'] === 'insufficient_funds' && (int) $r['declines'] === 1);
    CheckoutAttempts::card_failed($b, 'insufficient_funds', '', 'seti_test_b');   // the webhook reports the same decline
    check('the page and the webhook reporting one decline count it once', (int) $m->get($id)['declines'] === 1);
    CheckoutAttempts::card_entered($b, 'seti_test_b2');
    check('another card in the same checkout moves it on', $m->get($id)['status'] === 'card_entered' && $count($b) === 1);
    $c = $fake_charge($b, 'subscribe');
    CheckoutAttempts::charging($b, 'subscribe', $c, 19900, 'studio');
    CheckoutAttempts::settled($c, 'succeeded');
    $r = $m->get($id);
    check('it ends succeeded and keeps the decline', $r['status'] === 'succeeded' && (int) $r['declines'] === 1 && $r['failure_reason'] === 'Card declined: insufficient funds');

    echo "== charge declined, bank authentication\n";
    $d = $mk();
    $id = CheckoutAttempts::opened($d, 'plan', 'creator', 'subscribe', 4900, 4900);
    $c = $fake_charge($d, 'subscribe');
    CheckoutAttempts::charging($d, 'subscribe', $c, 4900, 'creator');
    check('a charge on the card on file is card_entered', $m->get($id)['status'] === 'card_entered' && $m->get($id)['card_entered_at'] !== null);
    CheckoutAttempts::settled($c, 'requires_action');
    check('waiting for the bank stays card_entered with the reason', $m->get($id)['status'] === 'card_entered' && $m->get($id)['failure_reason'] === 'Waiting for bank authentication');
    CheckoutAttempts::settled($c, 'failed', 'Your card was declined.', 'do_not_honor', 'pi_test_d');
    $r = $m->get($id);
    check('a declined charge shows failed with the decline code', $r['status'] === 'failed' && $r['failure_code'] === 'do_not_honor' && $r['failure_reason'] === 'Card declined by the bank' && (int) $r['declines'] === 1);
    CheckoutAttempts::settled($c, 'failed', 'Your card was declined.', 'do_not_honor');
    check('the same failure reported twice counts once', (int) $m->get($id)['declines'] === 1);

    echo "== abandoned after an hour\n";
    $e = $mk();
    $id = CheckoutAttempts::opened($e, 'plan', 'creator', 'subscribe', 4900, 4900);
    CheckoutAttempts::expire();
    check('under an hour it stays opened', $m->get($id)['status'] === 'opened');
    $back($id, 'opened_at', 3700);
    CheckoutAttempts::expire();
    check('no card within an hour shows abandoned', $m->get($id)['status'] === 'abandoned' && $m->get($id)['failure_reason'] === 'Closed checkout without entering a card');
    $c = $fake_charge($e, 'subscribe');
    CheckoutAttempts::charging($e, 'subscribe', $c, 4900, 'creator');
    check('coming back and paying within 6 hours revives the same checkout', $m->get($id)['status'] === 'card_entered' && $m->get($id)['failure_reason'] === null && $count($e) === 1);
    $f = $mk();
    $id = CheckoutAttempts::opened($f, 'pack', '20', 'pack', 2000, 2000);
    CheckoutAttempts::card_entered($f);
    $back($id, 'card_entered_at', 3700); $back($id, 'opened_at', 3800);
    CheckoutAttempts::expire();
    check('a card but no payment within an hour shows abandoned', $m->get($id)['status'] === 'abandoned' && $m->get($id)['failure_reason'] === 'Entered a card but never paid');

    echo "== charges with no checkout open\n";
    $g = $mk();
    $c = $fake_charge($g, 'slots');
    CheckoutAttempts::charging($g, 'slots', $c, 1500, '2');
    $r = $latest($g);
    check('a charge with no checkout open gets its own attempt', $r && $r['kind'] === 'slots' && $r['item'] === '2' && $r['status'] === 'card_entered' && (int) $r['charge_id'] === $c);
    $c = $fake_charge($g, 'renewal');
    CheckoutAttempts::charging($g, 'renewal', $c, 4900);
    check('renewals are not checkouts', $count($g) === 1);

    echo "== admin reads\n";
    $list = $m->admin_list(array('status' => 'failed'), 1000);
    check('Billing > Checkout attempts: the Failed filter', in_array($d, array_map('intval', array_column($list, 'user_id')), true) && !in_array($a, array_map('intval', array_column($list, 'user_id')), true));
    $today = $m->admin_list(array('problems_today' => 1), 1000);
    $tids = array_map('intval', array_column($today, 'user_id'));
    check('Today: failed and abandoned checkouts from today', in_array($d, $tids, true) && in_array($f, $tids, true) && !in_array($a, $tids, true));
    $day = $m->admin_list(array('signup_day' => gmdate('Y-m-d')), 1000);
    check('Growth > Funnel day link: attempts by that day\'s signups', in_array($b, array_map('intval', array_column($day, 'user_id')), true));
    $none = $m->admin_list(array('signup_day' => '2001-01-01'), 10);
    check('another signup day shows none of them', count($none) === 0);
    $um = (new AdminModel())->users('cot', '', 200);
    $row = function ($uid) use ($um) { foreach ($um as $u) { if ((int) $u['user_id'] === $uid) { return $u; } } return null; };
    check('Users: Checkout column shows the latest attempt', $row($d) && $row($d)['checkout_status'] === 'failed' && (string) $row($d)['checkout_at'] !== '');
    check('Users: "Opened checkout, not paid" includes a declined account', !empty($row($d)['checkout_unpaid']));
    check('Users: ...and leaves out one that paid', empty($row($a)['checkout_unpaid']));
    $none_u = $row($mk());
    check('Users: no checkout = no status', $none_u === null || (string) ($none_u['checkout_status'] ?? '') === '');

    echo "== backfill from the opened-checkout events\n";
    $h = $mk(0);
    $ts = gmdate('Y-m-d H:i:s', time() - 7200);
    $db->x("INSERT INTO analytics_events (ts, kind, name, user_id, creator_id, value_cents, meta) VALUES (:t, 'event', 'checkout_started', :u, :u2, 4900, 'subscribe creator founding')", array(':t' => $ts, ':u' => $h, ':u2' => $h));
    $sql = (string) file_get_contents($root . '/sql/2026-10-20_checkout_attempts.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) { if (stripos($stmt, 'CREATE TABLE') === false) { $db->x($stmt); } }
    $r = $latest($h);
    check('an old opened-checkout event is linked to its user', $r && (int) $r['backfilled'] === 1 && $r['item'] === 'creator' && (int) $r['founding'] === 1 && $r['opened_at'] === $ts);
    check('...and shows abandoned when nothing was paid', $r && $r['status'] === 'abandoned');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) { if (stripos($stmt, 'CREATE TABLE') === false) { $db->x($stmt); } }
    check('running the backfill twice adds nothing', $count($h) === 1);
    $fr = (new AdminModel())->funnel(1);
    $todayrow = null; foreach ($fr as $x) { if ($x['day'] === gmdate('Y-m-d')) { $todayrow = $x; } }
    check('Growth > Funnel counts the account under Opened Checkout', $todayrow && (int) $todayrow['checkout'] >= 1);

    echo "== Stripe test mode, through BillingService\n";
    $stripe = StripeService::client();
    $card = function ($uid, $pm) use ($users, $stripe) {   // what the card form does: confirm a SetupIntent with a test card
        $u = $users->get_user_by_id($uid)[0];
        $cus = StripeService::ensure_customer($u);
        $si = StripeService::create_setup_intent($cus, array('type' => 'platform_card', 'user_id' => (string) $uid));
        try { $stripe->setupIntents->confirm($si['id'], array('payment_method' => $pm)); } catch (\Throwable $ex) { /* a decline throws; the SetupIntent keeps the error */ }
        return array($cus, $si['id']);
    };
    // 1. a good card: the plan starts, as today
    $p = $mk();
    CheckoutAttempts::opened($p, 'plan', 'creator', 'subscribe', (int) BillingService::quote_plan($p, 'creator')['today'], 4900);
    list($cus, $si) = $card($p, 'pm_card_visa');
    $sv = BillingService::save_card($p, $si); CheckoutAttempts::card_entered($p, $si);
    $res = BillingService::change_plan($p, 'creator');
    $r = $latest($p);
    check('test card 4242: the payment succeeds', ($res['status'] ?? '') === 'succeeded', json_encode($res));
    check('...the attempt shows succeeded with the PaymentIntent', $r && $r['status'] === 'succeeded' && strpos((string) $r['stripe_payment_intent_id'], 'pi_') === 0 && $count($p) === 1);
    check('...and the plan updates as today', BillingService::account($p)['plan_key'] === 'creator' && BillingService::account($p)['status'] === 'active');
    // 2. 4000 0000 0000 0002: declined in the card form
    $q = $mk();
    CheckoutAttempts::opened($q, 'plan', 'creator', 'subscribe', 4900, 4900);
    list($cus, $si) = $card($q, 'pm_card_chargeDeclined');
    $err = StripeService::setup_intent_error($cus, $si);
    if ($err) { CheckoutAttempts::card_failed($q, $err['code'], $err['message'], $si); }   // billing_card_failed
    $r = $latest($q);
    check('test card 0002: Stripe reports the decline', !empty($err) && $err['code'] === 'generic_decline', json_encode($err));
    check('...the attempt shows failed with the decline reason', $r && $r['status'] === 'failed' && $r['failure_reason'] === 'Card declined' && $r['failure_code'] === 'generic_decline');
    check('...and the plan does not change', BillingService::account($q)['plan_key'] === 'free');
    // 3. 4000 0000 0000 9995 in the card form: insufficient funds
    $q2 = $mk();
    CheckoutAttempts::opened($q2, 'plan', 'creator', 'subscribe', 4900, 4900);
    list($cus, $si) = $card($q2, 'pm_card_visa_chargeDeclinedInsufficientFunds');
    $err = StripeService::setup_intent_error($cus, $si);
    if ($err) { CheckoutAttempts::card_failed($q2, $err['code'], $err['message'], $si); }
    $r = $latest($q2);
    check('test card 9995: failed, "Card declined: insufficient funds"', $r && $r['status'] === 'failed' && $r['failure_reason'] === 'Card declined: insufficient funds', json_encode($err));
    // 4. 4000 0000 0000 0341: the card saves, the charge is declined
    $s = $mk();
    CheckoutAttempts::opened($s, 'plan', 'creator', 'subscribe', 4900, 4900);
    list($cus, $si) = $card($s, 'pm_card_chargeCustomerFail');
    BillingService::save_card($s, $si); CheckoutAttempts::card_entered($s, $si);
    check('test card 0341: the card saves (card_entered)', $latest($s)['status'] === 'card_entered');
    $res = BillingService::change_plan($s, 'creator');
    $r = $latest($s);
    check('...the charge is declined', ($res['status'] ?? '') === 'failed', json_encode($res));
    check('...the attempt shows failed with Stripe\'s decline code', $r && $r['status'] === 'failed' && (string) $r['failure_code'] !== '' && (string) $r['failure_reason'] !== '' && (int) $r['charge_id'] > 0, json_encode($r));
    check('...and the plan does not change', BillingService::account($s)['plan_key'] === 'free');
} finally {
    foreach ($made as $uid) {
        $db->x("DELETE FROM checkout_attempts WHERE user_id = :u", array(':u' => $uid));
        $db->x("DELETE FROM billing_charges WHERE user_id = :u", array(':u' => $uid));
        $db->x("DELETE FROM billing_accounts WHERE user_id = :u", array(':u' => $uid));
        $db->x("DELETE FROM ai_credit_transactions WHERE user_id = :u", array(':u' => $uid));
        $db->x("DELETE FROM analytics_events WHERE user_id = :u", array(':u' => $uid));
        $db->x("DELETE FROM user_accounts WHERE user_id = :u AND user_email = ''", array(':u' => $uid));
    }
}
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
