<?php
/*
 * TikTok Events API (TikTokEventJob): the stored ad click id fallback, StartTrial for a $0 plan start, the first paid
 * charge after a trial sent once from cron, InitiateCheckout once per account per day at the plan's price.
 * No network: the test seam captures queued events and fakes the transport. Throwaway accounts (no email address)
 * and charges are deleted at the end. php tests/tiktok_events_test.php
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
$tag = (string) time();
$mk = function ($t, array $extra = array()) use ($db, $tag) {
    return $db->add('user_accounts', array('u_name' => 'zztt' . $t . $tag, 'user_email' => 'tt' . $t . $tag . '@example.com', 'first_name' => 'TikTok', 'last_name' => 'Test',
        'p_word' => 'x', 'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 1, 'created_at' => gmdate('Y-m-d H:i:s')) + $extra);
};
$charge = function ($uid, $kind, $cents) use ($db, $tag) {
    static $n = 0; $n++;
    return $db->add('billing_charges', array('user_id' => $uid, 'kind' => $kind, 'amount_cents' => $cents, 'line_items' => '[]', 'effects' => '{"plan":"creator"}',
        'idempotency_key' => 'tttest-' . $tag . '-' . $n, 'status' => 'succeeded', 'applied' => 1, 'created_at' => gmdate('Y-m-d H:i:s')));
};

$sent = array();
$sink = function ($type, $payload, $dedupe) use (&$sent) { $sent[] = array('type' => $type, 'payload' => $payload, 'dedupe' => $dedupe); };
$bodies = array();
$transport = function ($body) use (&$bodies) { $bodies[] = $body; return array('{"code":0,"message":"OK"}', 200, ''); };
TikTokEventJob::test_seam(array('configured' => true, 'web' => true, 'sink' => $sink, 'transport' => $transport));
$last = function () use (&$sent) { return $sent ? $sent[count($sent) - 1] : null; };

$ad    = $mk('ad', array('ad_source' => 'tiktok', 'ad_campaign' => 'tt_test', 'ad_ttclid' => 'TTCLID_TEST_123'));
$plain = $mk('plain');
$made_charges = array();
try {
    // --- 1. handle(): the stored click id fills in when the request had none; a fresh cookie wins ---
    TikTokEventJob::handle(array('user_id' => $ad, 'data' => array('event' => 'AddPaymentInfo', 'event_time' => time(), 'event_id' => 'x1', 'user' => array('ip' => '8.8.8.8'))));
    $u = $bodies[0]['data'][0]['user'] ?? array();
    check('fallback: stored ad_ttclid added', ($u['ttclid'] ?? '') === 'TTCLID_TEST_123');
    check('fallback: hashed email + external_id kept', ($u['email'] ?? '') === hash('sha256', 'ttad' . $tag . '@example.com') && ($u['external_id'] ?? '') === hash('sha256', (string) $ad));
    TikTokEventJob::handle(array('user_id' => $ad, 'data' => array('event' => 'Purchase', 'event_time' => time(), 'event_id' => 'x2', 'user' => array('ttclid' => 'COOKIE_CLICK'))));
    check('fallback: the browser click id is not replaced', ($bodies[1]['data'][0]['user']['ttclid'] ?? '') === 'COOKIE_CLICK');
    TikTokEventJob::handle(array('user_id' => $plain, 'data' => array('event' => 'Purchase', 'event_time' => time(), 'event_id' => 'x3')));
    check('fallback: no click id for an account without one', !isset($bodies[2]['data'][0]['user']['ttclid']));

    // --- 2. a $0 plan start (founding trial) = StartTrial at the plan price ---
    $sent = array();
    $c0 = $charge($ad, 'subscribe', 0); $made_charges[] = $c0;
    TikTokEventJob::from_event('plan_paid', $ad, array('value_cents' => 0, 'meta' => 'subscribe creator', 'charge_id' => $c0));
    $e = $last();
    check('trial: StartTrial, not Purchase', $e && $e['payload']['data']['event'] === 'StartTrial');
    check('trial: value = Creator price from PlanTiers', $e && (float) $e['payload']['data']['properties']['value'] === (float) PlanTiers::price('creator') && PlanTiers::price('creator') > 0);
    check('trial: fixed event id + dedupe key', $e && $e['payload']['data']['event_id'] === 'cls_trial_' . $ad && $e['dedupe'] === 'tiktok_trial:' . $ad);

    // a demo (test) account never reaches TikTok
    $sent = array();
    TikTokEventJob::test_seam(array('configured' => true, 'web' => true, 'sink' => $sink, 'transport' => $transport, 'demo' => array($ad)));
    TikTokEventJob::from_event('plan_paid', $ad, array('value_cents' => 0, 'meta' => 'subscribe creator', 'charge_id' => $c0));
    check('demo account: no TikTok event', count($sent) === 0);
    TikTokEventJob::test_seam(array('configured' => true, 'web' => true, 'sink' => $sink, 'transport' => $transport));

    // a paid plan start keeps Purchase with its amount
    $sent = array();
    $cp = $charge($plain, 'subscribe', 4900); $made_charges[] = $cp;
    TikTokEventJob::from_event('plan_paid', $plain, array('value_cents' => 4900, 'meta' => 'subscribe creator', 'charge_id' => $cp));
    $e = $last();
    check('paid start: Purchase 49.00 with cls_plan_<charge>', $e && $e['payload']['data']['event'] === 'Purchase' && (float) $e['payload']['data']['properties']['value'] === 49.0 && $e['payload']['data']['event_id'] === 'cls_plan_' . $cp);

    // --- 3. first paid renewal after the trial: sent from cron (CLI) once, ad-attributed accounts only ---
    TikTokEventJob::test_seam(array('configured' => true, 'web' => false, 'sink' => $sink, 'transport' => $transport));
    $sent = array();
    TikTokEventJob::from_event('plan_paid', $plain, array('value_cents' => 4900, 'meta' => 'subscribe creator', 'charge_id' => $cp));
    check('cli: a paid subscribe is not sent from cron', count($sent) === 0);
    $r1 = $charge($ad, 'renewal', 4900); $made_charges[] = $r1;
    TikTokEventJob::from_event('plan_paid', $ad, array('value_cents' => 4900, 'meta' => 'renewal creator', 'charge_id' => $r1));
    $e = $last();
    check('cli: first charge after trial = Purchase', count($sent) === 1 && $e['payload']['data']['event'] === 'Purchase' && (float) $e['payload']['data']['properties']['value'] === 49.0);
    check('cli: event id cls_plan_<charge> + dedupe key', $e && $e['payload']['data']['event_id'] === 'cls_plan_' . $r1 && $e['dedupe'] === 'tiktok_plan:' . $r1);
    check('cli: no request IP/agent captured', $e && empty($e['payload']['data']['user']) && empty($e['payload']['data']['page']));
    $r2 = $charge($ad, 'renewal', 4900); $made_charges[] = $r2;
    TikTokEventJob::from_event('plan_paid', $ad, array('value_cents' => 4900, 'meta' => 'renewal creator', 'charge_id' => $r2));
    check('cli: the next renewal is not sent', count($sent) === 1);
    $na = $mk('na'); $n0 = $charge($na, 'subscribe', 0); $n1 = $charge($na, 'renewal', 4900); $made_charges[] = $n0; $made_charges[] = $n1;
    TikTokEventJob::from_event('plan_paid', $na, array('value_cents' => 4900, 'meta' => 'renewal creator', 'charge_id' => $n1));
    check('cli: not sent for an account without ad attribution', count($sent) === 1);
    TikTokEventJob::from_event('credits_bought', $ad, array('value_cents' => 1000));
    check('cli: other events still skipped', count($sent) === 1);

    // --- 4. InitiateCheckout: once per account per day, at the plan price ---
    TikTokEventJob::test_seam(array('configured' => true, 'web' => true, 'sink' => $sink, 'transport' => $transport));
    $_SESSION['user_id'] = $plain;
    $sent = array();
    $quote = array('mode' => 'subscribe', 'today' => '$0.00');
    TikTokEventJob::from_api('billing_quote', true, $quote, array('plan' => 'creator'));
    TikTokEventJob::from_api('billing_quote', true, $quote, array('plan' => 'creator'));
    check('checkout: value = plan price, not $0 due today', count($sent) === 2 && (float) $sent[0]['payload']['data']['properties']['value'] === (float) PlanTiers::price('creator'));
    $day = gmdate('Ymd');
    check('checkout: same event id + dedupe key both times', $sent[0]['payload']['data']['event_id'] === 'cls_ic_creatorplan_' . $plain . '_' . $day
        && $sent[1]['payload']['data']['event_id'] === $sent[0]['payload']['data']['event_id'] && $sent[0]['dedupe'] === 'tiktok_ic:creatorplan:' . $plain . ':' . $day && $sent[1]['dedupe'] === $sent[0]['dedupe']);
    // the queue keeps one job per dedupe key (run_after far ahead so no worker claims the probe; deleted below)
    $q = new DatabaseJobQueue();
    $j1 = $q->dispatch('tiktok_event_probe', array(), 'tiktok_probe:' . $tag, '2099-01-01 00:00:00');
    $j2 = $q->dispatch('tiktok_event_probe', array(), 'tiktok_probe:' . $tag, '2099-01-01 00:00:00');
    check('checkout: the queue drops the second job with the same key', $j1 > 0 && $j2 === 0);
    unset($_SESSION['user_id']);
} finally {
    TikTokEventJob::test_seam(null);
    $db->run("DELETE FROM jobs WHERE dedupe_key = :k", array('k' => 'tiktok_probe:' . $tag));
    $db->run("DELETE FROM billing_charges WHERE idempotency_key LIKE :k", array('k' => 'tttest-' . $tag . '-%'));
    $db->run("DELETE FROM analytics_events WHERE user_id IN (SELECT user_id FROM user_accounts WHERE u_name LIKE :u)", array('u' => 'zztt%' . $tag));
    $db->run("DELETE FROM user_accounts WHERE u_name LIKE :u", array('u' => 'zztt%' . $tag));
}
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
