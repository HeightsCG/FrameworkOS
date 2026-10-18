<?php
/**
 * 2026-10-18 audit, High findings H1-H3, through the same entry points the app uses:
 *   H1 the Claude connector can't delete or hide a sold event; canceling through it refunds every ticket, and a
 *      canceled event never releases earnings.
 *   H2 a plan with members can't be deleted (web or connector), and a membership whose plan row is gone still lists.
 *   H3 "Stop Being a Creator" ends the fans' paid memberships to that creator.
 * Dev DB: creator #1, fans #26 and #27. Makes throwaway rows and removes them; balances end where they began.
 * The cancel step notifies fan #26 (test emails reach Daniel, as in events_management_test).
 *   APPLICATION_ENV=development php tests/audit_high_test.php
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
function throws($fn){ try { $fn(); return ''; } catch (\Throwable $e) { return $e->getMessage(); } }

$db = new class extends Model { public function x($s, $p = array()) { return $this->sql($s, $p); } public function rows($s, $p = array()) { return $this->select($s, $p); } };
$creator = 1; $fan = 26; $fan2 = 27;
$events = new EventsModel(); $credits = new CreditsModel();
$bal_fan0 = $credits->get_balance($fan); $bal_cr0 = $credits->get_balance($creator);

/* ---- H1: events through the connector ---- */
$eid = (int) $events->create($creator, array('title' => 'Audit Test Event', 'start_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'timezone' => 'UTC',
    'access_type' => 'paid', 'price_credits' => 20, 'capacity' => 5, 'location' => '1 Test St', 'format' => 'in_person', 'status' => 'published'));
$c = $events->claim_registration($eid, $fan);
$credits->apply_delta($fan, -20, 'event_ticket', 'test');   // a ticket: the fan pays now, the creator's 19 waits for the end
$events->set_paid($c['id'], 20, 19);

$e = throws(function () use ($creator, $eid) { McpTools::call('delete_event', $creator, array('id' => $eid)); });
check('connector refuses to delete a live sold event', $e !== '' && $events->get_one($creator, $eid) !== null, $e);
$e = throws(function () use ($creator, $eid) { McpTools::call('update_event', $creator, array('id' => $eid, 'status' => 'draft')); });
check('connector refuses to hide a sold event', $e !== '' && (string) $events->get_one($creator, $eid)['status'] === 'published', $e);
$r = McpTools::call('update_event', $creator, array('id' => $eid, 'title' => 'Audit Test Event 2'));
check('connector still edits other fields', !empty($r['ok']) && (string) $events->get_one($creator, $eid)['title'] === 'Audit Test Event 2');
$r = McpTools::call('update_event', $creator, array('id' => $eid, 'status' => 'canceled'));
check('connector cancel refunds the ticket', !empty($r['canceled']) && (int) $r['refunded'] === 1 && $credits->get_balance($fan) === $bal_fan0, json_encode($r));
check('event is canceled, no one left going', (string) $events->get_one($creator, $eid)['status'] === 'canceled' && !$events->has_paid_going($eid));
$e = throws(function () use ($creator, $eid) { McpTools::call('update_event', $creator, array('id' => $eid, 'title' => 'x')); });
check('a canceled event can no longer be edited', $e !== '', $e);

// a canceled event never pays out, even if a ticket row somehow stayed open
$db->x("UPDATE events SET start_at = :t WHERE id = :id", array(':t' => gmdate('Y-m-d H:i:s', time() - 7200), ':id' => $eid));
$db->x("UPDATE event_registrations SET status = 'registered' WHERE id = :id", array(':id' => $c['id']));
$due = array_column($events->earnings_due(5000), 'event_id');
check('earnings_due skips a canceled event', !in_array($eid, array_map('intval', $due), true));
$db->x("UPDATE event_registrations SET status = 'refunded' WHERE id = :id", array(':id' => $c['id']));

$r = McpTools::call('delete_event', $creator, array('id' => $eid));
check('connector deletes a canceled event', !empty($r['ok']) && $events->get_one($creator, $eid) === null);
$db->x("DELETE FROM event_registrations WHERE event_id = :e", array(':e' => $eid));

/* ---- H2: plans with members ---- */
$plans = new CreatorPlansModel(); $subs = new CreatorSubscriptionsModel();
$pid = (int) $plans->add($creator, array('name' => 'Audit Tier', 'price_cents' => 999, 'billing_interval' => 'month'));
$now = gmdate('Y-m-d H:i:s');
$db->x("INSERT INTO creator_subscriptions (subscriber_id, creator_id, plan_id, status, is_free, price_cents, billing_interval, stripe_subscription_id, created_at, updated_at)
        VALUES (:s, :c, :p, 'active', 0, 999, 'month', '', :n1, :n2)", array(':s' => $fan2, ':c' => $creator, ':p' => $pid, ':n1' => $now, ':n2' => $now));
check('the plan counts its member', $subs->count_open_for_plan($creator, $pid) === 1);
$e = throws(function () use ($creator, $pid) { McpTools::call('delete_plan', $creator, array('id' => $pid)); });
check('connector refuses to delete a plan with members', $e !== '' && $plans->get_one($creator, $pid) !== null, $e);
// a membership whose plan row is already gone (deleted before this fix) still shows, so the fan can cancel it
$db->x("DELETE FROM creator_plans WHERE id = :id", array(':id' => $pid));
$mine = array_values(array_filter((array) $subs->get_for_subscriber($fan2), function ($r) use ($pid) { return (int) $r['plan_id'] === $pid; }));
check('an orphaned membership still lists for the fan', count($mine) === 1 && $mine[0]['plan_name'] === 'Membership');
check('and still counts toward tier access', $subs->max_active_tier_price($fan2, $creator) >= 999);

/* ---- H3: leaving the creator role ends fans' memberships ---- */
// a throwaway creator (no email, no plan, no payout account) with fan #27 as a paying member
$tmp = (int) (new UsersModel())->create_user('audittmp' . mt_rand(10000, 99999), '!no-sign-in', 'Audit', 'Temp', '', 0, 0);
$tpid = (int) $plans->add($tmp, array('name' => 'Temp Tier', 'price_cents' => 500, 'billing_interval' => 'month'));
$db->x("INSERT INTO creator_subscriptions (subscriber_id, creator_id, plan_id, status, is_free, price_cents, billing_interval, stripe_subscription_id, created_at, updated_at)
        VALUES (:s, :c, :p, 'active', 0, 500, 'month', '', :n1, :n2)", array(':s' => $fan2, ':c' => $tmp, ':p' => $tpid, ':n1' => $now, ':n2' => $now));
check('the member is active before leaving', count($subs->active_paid_for_creator($tmp)) === 1);
AccountBilling::on_leave_creator($tmp);
check('leaving ends every paid membership to the creator', count($subs->active_paid_for_creator($tmp)) === 0 && $subs->count_open_for_plan($tmp, $tpid) === 0);
check('leave_creator calls it', strpos((string) file_get_contents($root . '/app/controllers/api/ApiProfileController.php'), 'AccountBilling::on_leave_creator($user_id)') !== false);
$db->x("DELETE FROM creator_subscriptions WHERE creator_id = :c", array(':c' => $tmp));
$db->x("DELETE FROM creator_plans WHERE user_id = :c", array(':c' => $tmp));
$db->x("DELETE FROM billing_accounts WHERE user_id = :c", array(':c' => $tmp));
$db->x("DELETE FROM user_accounts WHERE user_id = :c AND user_email = ''", array(':c' => $tmp));

$db->x("DELETE FROM creator_subscriptions WHERE plan_id = :p AND subscriber_id = :s", array(':p' => $pid, ':s' => $fan2));
check('balances back where they started', $credits->get_balance($fan) === $bal_fan0 && $credits->get_balance($creator) === $bal_cr0);
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
