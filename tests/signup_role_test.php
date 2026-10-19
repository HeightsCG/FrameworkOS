<?php
/*
 * Who is a creator at signup (2026-10-19):
 *  1. a plain /?auth=register opens the sign-up dialog with no role (landing.js opens it directly; it used to click the
 *     page's first register link, a creator CTA, and its ?role=creator tagged every such signup a creator);
 *  2. a Google sign-up from the /start Google button (?from=start) is a Creator at signup and fires TikTok
 *     CompleteRegistration, not FanSignup; a Google sign-up from anywhere else stays a User (FanSignup).
 * The Google callback itself needs Google, so part 2 runs the same calls the callback makes (record_signup_params with
 * the flow's start flag, then TikTokRegistration::settle). The browser half of part 1 is checked with Playwright on dev.
 * Throwaway accounts (no email address) are deleted at the end. php tests/signup_role_test.php
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

// --- 1. /?auth=register: the dialog opens directly, so no link's ?role= is picked up ---
$js = file_get_contents($root . '/public/js/landing.js');
$i  = strpos($js, "var auth_param = new URLSearchParams(location.search).get('auth');");
$block = $i === false ? '' : substr($js, $i, 900);
check('auto-open: opens the dialog with open_auth', strpos($block, "open_auth(auth_param === 'mfa' ? 'login' : auth_param);") !== false);
check('auto-open: never clicks a link on the page', strpos($block, '.click()') === false && strpos($block, 'querySelector(\'[data-auth=') === false);
check('auto-open: only the URL\'s own params are remembered', strpos($block, "if (auth_param === 'register') { remember_signup(location.search); }") !== false);
$params = UsersModel::signup_params(array('auth' => 'register'));
check('plain /?auth=register carries no role or plan', $params['role'] === '' && $params['plan'] === '');
$params = UsersModel::signup_params(array('auth' => 'register', 'role' => 'creator', 'plan' => 'creator'));
check('an explicit role=creator link still does', $params['role'] === 'creator' && $params['plan'] === 'creator');

// --- 2. Google sign-ups ---
check('the /start Google button marks its flow', strpos(file_get_contents($root . '/app/views/pages/start.php'), 'href="/account/google_start?from=start&amp;plan=creator&amp;role=creator"') !== false);
check('from=start is recognised, nothing else is', UsersModel::google_signup_from_start(array('from' => 'start')) && !UsersModel::google_signup_from_start(array())
    && !UsersModel::google_signup_from_start(array('from' => 'pricing')) && !UsersModel::google_signup_from_start(null));
$ac = file_get_contents($root . '/app/controllers/AccountController.php');
check('google_start keeps the flag, the callback makes the account a Creator from it', strpos($ac, "if (UsersModel::google_signup_from_start(\$_GET)) { \$flow['start'] = 1; }") !== false
    && strpos($ac, "record_signup_params(\$uid, UsersModel::signup_params(\$flow['signup'] ?? array()), !empty(\$flow['start']))") !== false);

$db = new class extends Model {
    public function run($sql, array $p = array()) { return parent::sql($sql, $p); }
    public function add($table, array $d) { return (int) parent::insert($table, $d); }
};
$users = new UsersModel();
$tag = (string) time();
$mk = function ($t) use ($db, $tag, $users) {
    return $db->add('user_accounts', array('u_name' => 'zzsr' . $t . $tag, 'user_email' => '', 'first_name' => 'Role', 'last_name' => 'Test',
        'p_word' => 'x', 'role_id' => $users->get_role_id_by_name('User'), 'user_status' => 'Active', 'email_verified' => 1, 'is_demo' => 0, 'created_at' => gmdate('Y-m-d H:i:s')));
};
$sent = array();
TikTokEventJob::test_seam(array('configured' => true, 'web' => true, 'sink' => function ($type, $payload, $dedupe) use (&$sent) { $sent[] = $payload['data']['event']; }));
// what google_callbackAction does for a new account: record_signup_params(..., !empty($flow['start'])), the event id, then settle on the next page
$google = function ($flow) use ($users, $mk) {
    static $n = 0; $n++;
    $uid = $mk('g' . $n . '_');
    $users->record_signup_params($uid, UsersModel::signup_params($flow['signup'] ?? array()), !empty($flow['start']));
    $users->start_tt_registration($uid);
    return array($uid, TikTokRegistration::settle($uid));
};
try {
    $sent = array();
    list($gs, $tt) = $google(array('start' => 1, 'signup' => array('plan' => 'creator', 'role' => 'creator')));
    check('Google from /start: Creator at signup', $users->is_creator($gs));
    check('Google from /start: CompleteRegistration, not FanSignup', is_array($tt) && $sent === array('CompleteRegistration'), json_encode($sent));

    $sent = array();
    list($gl, $tt) = $google(array('signup' => array('plan' => 'creator', 'role' => 'creator')));   // e.g. a pricing CTA, then Google
    check('Google from elsewhere with role=creator: stays a User', !$users->is_creator($gl));
    check('Google from elsewhere: FanSignup, no CompleteRegistration', $tt === null && $sent === array('FanSignup'), json_encode($sent));

    $sent = array();
    list($gp, $tt) = $google(array('start' => 1, 'signup' => array()));   // the flag alone never makes a creator: role=creator is still needed
    check('from=start without role=creator: stays a User', !$users->is_creator($gp) && $tt === null && $sent === array('FanSignup'), json_encode($sent));
} finally {
    TikTokEventJob::test_seam(null);
    $db->run("DELETE FROM analytics_events WHERE user_id IN (SELECT user_id FROM user_accounts WHERE u_name LIKE :u)", array('u' => 'zzsr%' . $tag));
    $db->run("DELETE FROM user_accounts WHERE u_name LIKE :u", array('u' => 'zzsr%' . $tag));
}
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
