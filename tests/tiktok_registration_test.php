<?php
/*
 * TikTok signup conversions (TikTokRegistration, 2026-10-19): CompleteRegistration for creators only (browser payload +
 * server copy, same event id, once), a server-only FanSignup for everyone else (once), a fan who later becomes a creator
 * fires CompleteRegistration then, accounts from before the change never fire, and the admin Funnel's Creators column
 * uses the same rule (UsersModel::creator_sql). No network: the TikTokEventJob seam captures queued events.
 * Throwaway accounts (no email address) are deleted at the end. php tests/tiktok_registration_test.php
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
function check($label, $ok, $info = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $info === '' ? '' : '  ' . $info) . "\n"; if (!$ok) { $fail++; } }

$db = new class extends Model {
    public function run($sql, array $p = array()) { return parent::sql($sql, $p); }
    public function rows($sql, array $p = array()) { return parent::select($sql, $p); }
    public function add($table, array $d) { return (int) parent::insert($table, $d); }
};
$users = new UsersModel();
$tag = (string) time();
$user_role = $users->get_role_id_by_name('User');
$mk = function ($t) use ($db, $tag, $user_role) {
    return $db->add('user_accounts', array('u_name' => 'zzreg' . $t . $tag, 'user_email' => '', 'first_name' => 'Reg', 'last_name' => 'Test',
        'p_word' => 'x', 'role_id' => $user_role, 'user_status' => 'Active', 'email_verified' => 1, 'is_demo' => 0, 'created_at' => gmdate('Y-m-d H:i:s')));
};
$sent = array();
TikTokEventJob::test_seam(array('configured' => true, 'web' => true, 'sink' => function ($type, $payload, $dedupe) use (&$sent) { $sent[] = array('payload' => $payload, 'dedupe' => $dedupe); }));
$events = function () use (&$sent) { return array_map(function ($e) { return $e['payload']['data']['event']; }, $sent); };

$funnel_today = function () {
    foreach ((new AdminModel())->funnel(1) as $r) { if ($r['day'] === gmdate('Y-m-d')) { return array((int) $r['signups'], (int) $r['became_creator']); } }
    return array(0, 0);
};
list($s0, $c0) = $funnel_today();

try {
    // --- a creator signup (the email form with role=creator: Creator at creation) ---
    $cr = $mk('cr');
    $users->start_tt_registration($cr);
    $users->make_creator($cr, $cr);
    $sent = array();
    $tt = TikTokRegistration::settle($cr);
    $eid = (string) ($db->rows('SELECT tt_reg_event_id FROM user_accounts WHERE user_id = :u', array('u' => $cr))[0]['tt_reg_event_id'] ?? '');
    check('creator: browser payload with the account event id', is_array($tt) && $tt['event_id'] === $eid && $eid !== '' && $tt['external_id'] === hash('sha256', (string) $cr));
    check('creator: exactly one server CompleteRegistration', $events() === array('CompleteRegistration'), json_encode($events()));
    check('creator: server copy shares the browser event id', ($sent[0]['payload']['data']['event_id'] ?? '') === $eid && $sent[0]['payload']['data']['properties']['content_name'] === 'creator_signup');
    $sent = array();
    check('creator: refresh / second tab / later sign-in get nothing', TikTokRegistration::settle($cr) === null && TikTokRegistration::settle($cr) === null && count($sent) === 0);

    // --- a fan signup ---
    $fan = $mk('fan');
    $users->start_tt_registration($fan);
    $sent = array();
    check('fan: no browser payload', TikTokRegistration::settle($fan) === null);
    check('fan: one FanSignup, no CompleteRegistration', $events() === array('FanSignup'), json_encode($events()));
    check('fan: FanSignup is server only with its own id', strpos((string) ($sent[0]['payload']['data']['event_id'] ?? ''), 'cls_fan_signup_') === 0 && $sent[0]['dedupe'] === 'tiktok_fan:' . $fan);
    $sent = array();
    TikTokRegistration::settle($fan); TikTokRegistration::settle($fan);
    check('fan: refresh / re-login send nothing', count($sent) === 0);

    // --- the fan becomes a creator later (Become a Creator): CompleteRegistration then, once ---
    $users->make_creator($fan, $fan);
    $sent = array();
    $tt2 = TikTokRegistration::settle($fan);
    check('fan turned creator: CompleteRegistration fires at that moment', is_array($tt2) && $events() === array('CompleteRegistration') && $sent[0]['payload']['data']['event_id'] === $tt2['event_id']);
    $sent = array();
    check('fan turned creator: never again', TikTokRegistration::settle($fan) === null && count($sent) === 0);

    // --- accounts from before the change ---
    $old = $mk('old');   // before 2026-10-16: no event id
    $users->make_creator($old, $old);
    $closed = $mk('closed');   // 2026-10-16 .. 2026-10-19, closed by the SQL file
    $users->start_tt_registration($closed);
    $db->run('UPDATE user_accounts SET tt_reg_sent_at = UTC_TIMESTAMP(), tt_fan_sent_at = UTC_TIMESTAMP() WHERE user_id = :u', array('u' => $closed));
    $sent = array();
    $a = TikTokRegistration::settle($old);
    $b = TikTokRegistration::settle($closed);
    $users->make_creator($closed, $closed);
    $c = TikTokRegistration::settle($closed);
    check('old accounts: nothing, fan or creator', $a === null && $b === null && $c === null && count($sent) === 0, json_encode($events()));

    // --- one rule: the Funnel's Creators column counts exactly the accounts the TikTok code treats as creators ---
    list($s1, $c1) = $funnel_today();
    $made = array($cr, $fan, $old, $closed);
    $rule = count(array_filter($made, function ($u) use ($users) { return $users->is_creator($u); }));
    check('funnel: signups +4, Creators + the accounts meeting the creator rule', $s1 - $s0 === 4 && $c1 - $c0 === $rule && $rule === 4, "signups +" . ($s1 - $s0) . " creators +" . ($c1 - $c0) . " rule $rule");
    $src = file_get_contents($root . '/app/models/AdminModel.php');
    check('funnel and TikTok share UsersModel::creator_sql', substr_count($src, 'UsersModel::creator_sql(') === 2
        && strpos(file_get_contents($root . '/app/models/UsersModel.php'), "tt_reg_sent_at IS NULL AND ' . self::creator_sql(") !== false);
} finally {
    TikTokEventJob::test_seam(null);
    $db->run("DELETE FROM analytics_events WHERE user_id IN (SELECT user_id FROM user_accounts WHERE u_name LIKE :u)", array('u' => 'zzreg%' . $tag));
    $db->run("DELETE FROM user_accounts WHERE u_name LIKE :u", array('u' => 'zzreg%' . $tag));
}
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
