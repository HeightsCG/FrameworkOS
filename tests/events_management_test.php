<?php
/**
 * Events management: EventsModel stats/attendees/transitions + EventRefunds, against the dev DB.
 * Creator #1, fans #26 and #27. Creates throwaway events and removes them; balances end where they began.
 *   APPLICATION_ENV=development php tests/events_management_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$creator = 1; $fan_paid = 26; $fan_free = 27;
$m = new EventsModel();
$credits = new CreditsModel();
$tomorrow = gmdate('Y-m-d H:i:s', time() + 86400);

function make_event($m, $creator, $when){
    return $m->create($creator, array('title' => 'Sunset Photo Walk', 'start_at' => $when, 'timezone' => 'America/New_York',
        'access_type' => 'paid', 'price_credits' => 20, 'capacity' => 5, 'location' => '100 Main St, Orlando', 'format' => 'in_person', 'status' => 'published'));
}
function register_paid($m, $credits, $eid, $fan, $creator){
    $c = $m->claim_registration($eid, $fan);
    $credits->apply_delta($fan, -20, 'event_ticket', 'test');
    $credits->apply_delta($creator, 19, 'event_earning', 'test');
    $m->set_paid($c['id'], 20, 19);
    return $c['id'];
}
function cleanup($m, $creator, $eid){
    $db = new class extends Model { public function x($s, $p) { return $this->sql($s, $p); } };
    $db->x("DELETE FROM event_registrations WHERE event_id = :e", array(':e' => $eid));
    $db->x("DELETE FROM event_messages WHERE event_id = :e", array(':e' => $eid));
    $m->delete_event($creator, $eid);
}

// ---- Task 1: model reads/writes ----
$bal_fan0 = $credits->get_balance($fan_paid); $bal_cr0 = $credits->get_balance($creator);
$eid = make_event($m, $creator, $tomorrow);
check('event created with format', ($m->get_one($creator, $eid)['format'] ?? '') === 'in_person');
$paid_reg = register_paid($m, $credits, $eid, $fan_paid, $creator);
$free = $m->claim_registration($eid, $fan_free); $free_reg = $free['id'];

$s = $m->stats($eid);
check('stats going=2 gross=20 net=19', $s['going'] === 2 && $s['gross'] === 20 && $s['net'] === 19);
$att = $m->attendees($eid);
check('attendees has 2 rows with handles', count($att) === 2 && $att[0]['handle'] !== '' && isset($att[0]['name']));
check('has_paid_going true', $m->has_paid_going($eid));
$ids = $m->going_user_ids($eid); sort($ids);
check('going_user_ids = [26, 27]', $ids === array(26, 27));
check('registration() finds the row', ($m->registration($eid, $paid_reg)['user_id'] ?? 0) == $fan_paid);
check('registration() scoped to event', $m->registration($eid + 999999, $paid_reg) === null);
$mid = $m->add_message($eid, $creator, 'Doors open at 6:30', 2);
check('add_message + messages()', $mid > 0 && count($m->messages($eid)) === 1 && (int) $m->messages($eid)[0]['recipients'] === 2);

// ---- Task 2: EventRefunds ----
$ev = $m->get_one($creator, $eid);
$r = EventRefunds::refund_one($ev, $paid_reg, 'creator');
check('refund_one ok, 20 back to fan', !empty($r['ok']) && $r['refunded'] === 20);
check('fan balance restored', $credits->get_balance($fan_paid) === $bal_fan0);
check('creator clawed back 19', $credits->get_balance($creator) === $bal_cr0);
check('status refunded, paid amount kept for history', ($m->registration($eid, $paid_reg)['status'] ?? '') === 'refunded' && (int) $m->registration($eid, $paid_reg)['price_credits'] === 20);
$r2 = EventRefunds::refund_one($ev, $paid_reg, 'creator');
check('second refund is a no-op', empty($r2['ok']) && $credits->get_balance($fan_paid) === $bal_fan0);
$rm = EventRefunds::remove_one($ev, $free_reg);
check('remove_one -> removed', !empty($rm['ok']) && $m->registration($eid, $free_reg)['status'] === 'removed');
check('stats after: going 0, refunded 1/$20', ($x = $m->stats($eid))['going'] === 0 && $x['refunded_n'] === 1 && $x['refunded_credits'] === 20);
$again = $m->claim_registration($eid, $fan_paid);
check('re-register after refund is charged fresh (prior_paid 0)', is_array($again) && $again['prior_paid'] === 0 && (int) $m->registration($eid, $paid_reg)['price_credits'] === 0);
$m->release_registration($again);
check('release puts it back to refunded', $m->registration($eid, $paid_reg)['status'] === 'refunded');
cleanup($m, $creator, $eid);

// cancel_all: one paid + one free
$eid = make_event($m, $creator, $tomorrow);
register_paid($m, $credits, $eid, $fan_paid, $creator);
$m->claim_registration($eid, $fan_free);
$ev = $m->get_one($creator, $eid);
$c = EventRefunds::cancel_all($ev);
check('cancel_all refunded 1 paid, notified 2', !empty($c['ok']) && $c['refunded_n'] === 1 && $c['notified'] === 2);
check('event is canceled', $m->get_one($creator, $eid)['status'] === 'canceled');
check('nobody left going', $m->stats($eid)['going'] === 0);
check('balances back where they started', $credits->get_balance($fan_paid) === $bal_fan0 && $credits->get_balance($creator) === $bal_cr0);
check('has_paid_going false after cancel', !$m->has_paid_going($eid));
cleanup($m, $creator, $eid);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
