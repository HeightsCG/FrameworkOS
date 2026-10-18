<?php
/*
 * 2026-10-18 audit, money area: reactivation restarts the billing period (no catch-up), trial-safe prorating, the
 * billing anchor day, event date guards, atomic capacity seats (two real processes), repeat service bookings, bundle
 * items on delete, stuck recordings, event delete with recordings, partial affiliate reversals, the invoice webhook
 * cancel flag. Throwaway accounts with no email address and a fake saved card (only $0 charges are made, so nothing
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

// Child mode for the race checks: wait for the shared start time, then take one seat and print the result.
if (($argv[1] ?? '') === '--seat') {
    list(, , $kind, $id, $uid, $cap, $at) = $argv;
    while (microtime(true) < (float) $at) { usleep(500); }
    if ($kind === 'event') { $r = (new EventsModel())->claim_registration((int) $id, (int) $uid, (int) $cap); echo (is_array($r) && empty($r['full'])) ? 'got' : 'full'; }
    else { $r = (new ServicesModel())->record_purchase((int) $id, (int) $uid, 0, (int) $cap); echo $r > 0 ? 'got' : 'full'; }
    exit(0);
}

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
$events = array(); $services = array(); $assets = array(); $bundles = array(); $charges = array();

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

    // ---- 3/4. event dates ----
    $em = new EventsModel();
    $cr = $mk('cr'); $fa = $mk('fa'); $fb = $mk('fb');
    $tomorrow = gmdate('Y-m-d H:i:s', time() + 86400);
    $eid = $em->create($cr, array('title' => 'Audit Walk', 'start_at' => $tomorrow, 'end_at' => gmdate('Y-m-d H:i:s', time() + 90000), 'access_type' => 'paid', 'price_credits' => 20,
        'capacity' => 1, 'format' => 'in_person', 'status' => 'published'));
    $events[] = $eid;
    check('unparseable start refused', $em->check_times('invalid', '') !== '');
    check('end before start refused', $em->check_times($tomorrow, gmdate('Y-m-d H:i:s', time() + 3600)) === 'The end must be after the start.');
    check('a past date is fine with no paid tickets', $em->check_times(gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 3600), $em->get_one($cr, $eid)) === '');
    $claim = $em->claim_registration($eid, $fa, 1);
    $em->set_paid((int) $claim['id'], 20, 18);
    $cur = $em->get_one($cr, $eid);
    check('sold: moving the end into the past is refused', $em->check_times($cur['start_at'], gmdate('Y-m-d H:i:s', time() - 60), $cur) !== '');
    check('sold: moving the start into the past is refused', $em->check_times(gmdate('Y-m-d H:i:s', time() - 3600), $cur['end_at'], $cur) !== '');
    check('sold: clearing the end of a past-start event is refused', $em->check_times(gmdate('Y-m-d H:i:s', time() - 3600), '', $cur) !== '');
    check('sold: moving it later is fine', $em->check_times(gmdate('Y-m-d H:i:s', time() + 5 * 86400), gmdate('Y-m-d H:i:s', time() + 5 * 86400 + 3600), $cur) === '');
    check('sold: saving the same dates is fine', $em->check_times($cur['start_at'], $cur['end_at'], $cur) === '');

    // ---- 9. capacity: the seat check under a lock ----
    check('capacity 1: a second fan is refused', !empty($em->claim_registration($eid, $fb, 1)['full']));
    check('capacity 1: the registered fan is not refused again', $em->claim_registration($eid, $fa, 1) === null);
    $race = function ($kind, $id, array $uids, $cap) use ($root) {
        $at = microtime(true) + 1.0; $procs = array(); $out = array();
        foreach ($uids as $u) {
            $p = proc_open(array(PHP_BINARY, $root . '/tests/audit_money_test.php', '--seat', $kind, (string) $id, (string) $u, (string) $cap, (string) $at), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $procs[] = array($p, $pipes);
        }
        foreach ($procs as $pp) { $out[] = trim(stream_get_contents($pp[1][1])); fclose($pp[1][1]); fclose($pp[1][2]); proc_close($pp[0]); }
        sort($out);
        return $out;
    };
    $eid2 = $em->create($cr, array('title' => 'Audit Race', 'start_at' => $tomorrow, 'access_type' => 'free', 'capacity' => 1, 'format' => 'in_person', 'status' => 'published'));
    $events[] = $eid2;
    $res = $race('event', $eid2, array($fa, $fb, $c2), 1);
    check('event race, 3 fans for 1 seat: one gets it (' . implode(',', $res) . ')', $res === array('full', 'full', 'got') && $em->attendee_count($eid2) === 1);

    // ---- 8. repeat service bookings, one open at a time ----
    $sm = new ServicesModel();
    $sid = $sm->create($cr, array('name' => 'Audit Session', 'price_credits' => 0, 'delivery_method' => 'custom', 'status' => 'published'));
    $services[] = $sid;
    $p1 = $sm->record_purchase($sid, $fa);
    check('first booking', $p1 > 0 && $sm->has_purchased($sid, $fa));
    check('a second open booking is refused', $sm->record_purchase($sid, $fa) === 0);
    check('delivered', (new CreditsModel())->release_service_earning($p1, $cr) !== false);
    check('after delivery: no open booking', !$sm->has_purchased($sid, $fa));
    $p2 = $sm->record_purchase($sid, $fa);
    check('book again after delivery: a new row', $p2 > 0 && $p2 !== $p1 && $sm->paid_purchase_id($sid, $fa) === $p2);
    check('the delivered booking keeps its own row', !empty($sm->purchase($sid, $p1)['delivered_at']) && (string) $sm->purchase($sid, $p1)['status'] === 'paid');
    $sm->set_purchase_status($p2, 'paid', 'refunded');
    $p3 = $sm->record_purchase($sid, $fa);
    check('book again after a refund: a new row; the refunded one stays refunded', $p3 > 0 && (string) $sm->purchase($sid, $p2)['status'] === 'refunded');
    check('stats count each booking', $sm->stats($sid)['rows'] === 3 && $sm->stats($sid)['sold'] === 2 && $sm->stats($sid)['refunded_n'] === 1);
    $sid2 = $sm->create($cr, array('name' => 'Audit Group', 'price_credits' => 0, 'delivery_method' => 'custom', 'capacity' => 1, 'status' => 'published'));
    $services[] = $sid2;
    $res = $race('service', $sid2, array($fa, $fb, $c2), 1);
    check('service race, 3 fans for 1 seat: one gets it (' . implode(',', $res) . ')', $res === array('full', 'full', 'got') && $sm->purchase_count($sid2) === 1);
    check('full service: record_purchase says -1', $sm->record_purchase($sid2, $c1, 0, 1) === -1);

    // ---- 7. bundles: a deleted file leaves unsold bundles; sold bundles keep it; only live files count ----
    $mm = new MediaAssetsModel(); $cb = new ContentBundlesModel();
    $mkasset = function () use ($mm, $cr, $db, &$assets) {
        $id = (int) $mm->add($cr, 'image', 'audit.jpg', 'image/jpeg', 'ready');
        $db->run("UPDATE media_assets SET moderation_status = 'approved' WHERE id = :i", array(':i' => $id));
        $assets[] = $id; return $id;
    };
    $x1 = $mkasset(); $x2 = $mkasset(); $x3 = $mkasset();
    $b1 = (int) $cb->add($cr, 'Audit Unsold', '', 100); $bundles[] = $b1; $cb->set_items($b1, array($x1, $x2));
    $b2 = (int) $cb->add($cr, 'Audit Sold', '', 100);   $bundles[] = $b2; $cb->set_items($b2, array($x1, $x3));
    $cb->record_unlock($b2, $cr, $fb, 100);
    $mm->soft_delete($cr, $x1);
    check('unsold bundle loses the deleted file', $cb->get_item_asset_ids($b1) === array($x2) && $cb->live_item_count($b1) === 1);
    check('sold bundle keeps the item row, but it no longer counts', in_array($x1, $cb->get_item_asset_ids($b2), true) && $cb->live_item_count($b2) === 1);
    $db->run("UPDATE media_assets SET moderation_status = 'blocked' WHERE id = :i", array(':i' => $x3));
    check('a blocked file does not count: sold bundle at 0 live files', $cb->live_item_count($b2) === 0);
    $listed = array_column($cb->get_for_creator($cr), 'item_count', 'id');
    check('management list counts live files only', (int) $listed[$b1] === 1 && (int) $listed[$b2] === 0);

    // ---- 10/11. recordings ----
    $rm = new LiveRecordingsModel();
    $stuck = $rm->add(array('room' => 'ev-' . $eid2, 'event_id' => $eid2, 'creator_id' => $cr, 'started_by' => $cr, 'egress_id' => 'EG_audit', 's3_key' => '',
        'status' => 'processing', 'limit_minutes' => 120, 'started_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600), 'ended_at' => gmdate('Y-m-d H:i:s', time() - 2 * 3600)));
    $fresh = $rm->add(array('room' => 'ev-' . $eid2, 'event_id' => $eid2, 'creator_id' => $cr, 'started_by' => $cr, 'egress_id' => 'EG_audit2', 's3_key' => '',
        'status' => 'stopping', 'limit_minutes' => 120, 'started_at' => gmdate('Y-m-d H:i:s', time() - 900), 'ended_at' => gmdate('Y-m-d H:i:s', time() - 600)));
    check('stuck processing for 2 hours: deletable', LiveRecordingsModel::deletable($rm->get($stuck)));
    check('stopping 10 minutes ago: not yet', !LiveRecordingsModel::deletable($rm->get($fresh)));
    $va = $mkasset();
    $ready = $rm->add(array('room' => 'ev-' . $eid2, 'event_id' => $eid2, 'creator_id' => $cr, 'started_by' => $cr, 'egress_id' => 'EG_audit3', 's3_key' => '',
        'status' => 'ready', 'asset_id' => $va, 'limit_minutes' => 120));
    $em->set_status($cr, $eid2, 'canceled');
    $em->delete_event($cr, $eid2);
    $events = array_values(array_diff($events, array($eid2)));
    $gone = $db->rows("SELECT deleted_at FROM media_assets WHERE id = :i", array('i' => $va));
    check('deleting the event deletes its recording video', !empty($gone[0]['deleted_at']));
    check('and marks every recording deleted', (string) $rm->get($ready)['status'] === 'deleted' && (string) $rm->get($stuck)['status'] === 'deleted' && (string) $rm->get($fresh)['status'] === 'deleted');
    $db->run("DELETE FROM live_recordings WHERE id IN (:a, :b, :c)", array(':a' => $stuck, ':b' => $fresh, ':c' => $ready));

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

    // ---- 14. invoice events leave the fan's scheduled cancel alone ----
    $subs = new CreatorSubscriptionsModel();
    $ssid = 'sub_zzaudit_' . time();
    $db->add('creator_subscriptions', array('subscriber_id' => $fa, 'creator_id' => $cr, 'plan_id' => 0, 'status' => 'active', 'is_free' => 0, 'stripe_subscription_id' => $ssid,
        'cancel_at_period_end' => 1, 'created_at' => $now(), 'updated_at' => $now()));
    $subs->update_by_stripe_id($ssid, 'past_due', null, null);
    $subs->update_by_stripe_id($ssid, 'active', null, null);
    $row = $subs->get_by_stripe_id($ssid);
    check('past_due then active: cancel_at_period_end still 1', (int) $row['cancel_at_period_end'] === 1 && (string) $row['status'] === 'active');
    $subs->update_by_stripe_id($ssid, 'active', null, 0);
    check('an explicit 0 still clears it', (int) $subs->get_by_stripe_id($ssid)['cancel_at_period_end'] === 0);
    $db->run("DELETE FROM creator_subscriptions WHERE stripe_subscription_id = :s", array(':s' => $ssid));
} finally {
    foreach ($events as $e) { $db->run("DELETE FROM event_registrations WHERE event_id = :e", array(':e' => $e)); $db->run("DELETE FROM events WHERE id = :e", array(':e' => $e)); }
    foreach ($services as $s) { $db->run("DELETE FROM service_purchases WHERE service_id = :s", array(':s' => $s)); $db->run("DELETE FROM services WHERE id = :s", array(':s' => $s)); }
    foreach ($bundles as $b) { $db->run("DELETE FROM bundle_items WHERE bundle_id = :b", array(':b' => $b)); $db->run("DELETE FROM bundle_unlocks WHERE bundle_id = :b", array(':b' => $b)); $db->run("DELETE FROM content_bundles WHERE id = :b", array(':b' => $b)); }
    foreach ($assets as $x) { $db->run("DELETE FROM media_assets WHERE id = :x", array(':x' => $x)); }
    foreach ($charges as $c) { $db->run("DELETE FROM billing_charges WHERE id = :c", array(':c' => $c)); }
    foreach ($users as $u) {
        foreach (array("DELETE FROM billing_charges WHERE user_id = :u", "DELETE FROM billing_accounts WHERE user_id = :u", "DELETE FROM ai_credit_transactions WHERE user_id = :u", "DELETE FROM credit_transactions WHERE user_id = :u",
                       "DELETE FROM user_notifications WHERE user_id = :u", "DELETE FROM analytics_events WHERE user_id = :u", "DELETE FROM creator_profiles WHERE user_id = :u",
                       "DELETE FROM user_accounts WHERE user_id = :u") as $q) {
            try { $db->run($q, array(':u' => $u)); } catch (\Throwable $e) { /* table not present */ }
        }
        try { $db->run("DELETE FROM jobs WHERE dedupe_key = :k", array(':k' => 'membership_fee:' . $u)); } catch (\Throwable $e) {}
    }
    try { $db->run("DELETE FROM jobs WHERE type = 'public_thumb' AND created_at >= :t AND state = 'queued'", array(':t' => $t0)); } catch (\Throwable $e) {}
    $left = $db->rows("SELECT COUNT(*) AS n FROM user_accounts WHERE u_name LIKE 'zzaudm%'");
    check('throwaway accounts removed', (int) $left[0]['n'] === 0);
}
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
