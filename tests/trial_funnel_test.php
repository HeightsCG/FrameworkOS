<?php
/*
 * The /start trial funnel after signup (UpgradePrompt, the /setup plan step, need_plan wording).
 * Makes throwaway Free creators with no email address (nothing is sent) and deletes them at the end. php tests/trial_funnel_test.php
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
    public function add($table, array $d) { return (int) parent::insert($table, $d); }
};
$users = new UsersModel();
$row = function ($uid) use ($users) { $r = $users->get_user_by_id($uid); return $r[0]; };
$mk = function ($tag, $founding) use ($db) {
    return $db->add('user_accounts', array('u_name' => 'zztrial' . $tag . time(), 'user_email' => '', 'first_name' => 'Trial', 'last_name' => 'Test', 'p_word' => 'x',
        'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 1, 'signup_role' => 'creator', 'signup_plan' => 'creator', 'signup_founding' => $founding ? 1 : 0, 'created_at' => gmdate('Y-m-d H:i:s')));
};
$a = $mk('a', true); $b = $mk('b', false);
unset($_COOKIE['cls_founding']);
try {
    check('founding signup, Free, spots left: trial applies', UpgradePrompt::founding($row($a)) === Founding::open());
    check('signup without the offer: no trial', UpgradePrompt::founding($row($b)) === false);
    check('plan from signup_plan', UpgradePrompt::plan($row($a)) === 'creator' && UpgradePrompt::plan(null) === 'creator');
    $u = UpgradePrompt::checkout_url('creator', true, '/studio?x=1');
    check('checkout link opens the trial checkout and returns', $u === '/account/billing?tab=plan&plan=creator&founding=1&checkout=1&return=%2Fstudio%3Fx%3D1');
    $p = UpgradePrompt::payload($row($a), '/influencers');
    check('need_plan payload carries plan, return and founding_eligible', $p['need_plan'] === true && $p['plan'] === 'creator' && $p['return'] === '/influencers' && $p['founding_eligible'] === Founding::open());
    check('generic gates get named actions', UpgradePrompt::action_words('influencer_create') === 'create an AI influencer' && UpgradePrompt::action_words('toggle_bundle') === 'turn on bundles' && UpgradePrompt::action_words('scene_vote') === 'use scenes' && UpgradePrompt::action_words('nothing_here') === '');

    $pa = SetupService::progress($a, true);
    $first = $pa['steps'][0] ?? array();
    check('/setup: first step is the plan step', ($first['key'] ?? '') === 'plan' && empty($first['done']));
    if (Founding::open()) {
        check('/setup: founding wording and checkout link', $first['title'] === 'Start your ' . Founding::TRIAL_DAYS . '-day free trial' && $first['cta'] === 'Start ' . Founding::TRIAL_DAYS . '-Day Free Trial' && strpos($first['url'], '&founding=1&checkout=1') !== false);
    }
    check('/setup: plan step is not skippable', ($first['skippable'] ?? true) === false);
    $pb = SetupService::progress($b, true);
    check('/setup: without the offer it is Choose a Plan', ($pb['steps'][0]['key'] ?? '') === 'plan' && $pb['steps'][0]['cta'] === 'Choose a Plan' && strpos($pb['steps'][0]['url'], 'founding=1') === false && strpos($pb['steps'][0]['url'], 'checkout=1') !== false);
    check('/setup: the step counts toward completion', $pa['required_total'] >= 1 && !$pa['complete']);
} finally {
    foreach (array($a, $b) as $uid) {
        $db->run('DELETE FROM creator_setup WHERE user_id = :u', array('u' => $uid));
        $db->run('DELETE FROM billing_accounts WHERE user_id = :u', array('u' => $uid));
        $db->run('DELETE FROM user_accounts WHERE user_id = :u', array('u' => $uid));
    }
    echo "ok   throwaway accounts removed\n";
}
echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
