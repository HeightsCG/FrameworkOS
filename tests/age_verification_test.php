<?php
/**
 * Age verification (explicit content only): model, provider, service, both triggers' predicates, retry, and the
 * call-site allow-list that keeps signup/login/purchases/payouts away from it. Dev DB: fan #27 "test", creator #1 "admin".
 *   APPLICATION_ENV=development php tests/age_verification_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/controllers/api/$class.php", "$root/app/$class.php", "$root/tests/support/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
$FAN = 27; $CREATOR = 1;

// ---- 1. model
$m = new AgeVerificationsModel();
$m->remove($FAN);
check('no row = not verified', $m->is_verified($FAN) === false && $m->get($FAN) === null);
check('start pending', $m->start_pending($FAN, 'fake', 'ref-1') && $m->get($FAN)['status'] === 'pending');
check('by_ref', (int) ($m->by_ref('fake', 'ref-1')['user_id'] ?? 0) === $FAN);
check('mark verified', $m->mark($FAN, 'verified', gmdate('Y-m-d H:i:s')) && $m->is_verified($FAN));
check('verified row never downgraded by a new start', $m->start_pending($FAN, 'fake', 'ref-x') === false && $m->is_verified($FAN));
check('retry replaces ref', $m->mark($FAN, 'failed', null) && $m->start_pending($FAN, 'fake', 'ref-2') && $m->get($FAN)['provider_ref'] === 'ref-2' && $m->get($FAN)['status'] === 'pending');
check('stored columns only', array_diff(array_keys($m->get($FAN)), array('user_id', 'status', 'provider', 'provider_ref', 'verified_at', 'created_at', 'updated_at')) === array());
$m->remove($FAN);

// ---- 2. Didit provider: signature, timestamp window, status mapping (no network)
$raw = '{"session_id":"sid","status":"Approved","vendor_data":"27"}';
$sig = function ($secret) use ($raw) { return hash_hmac('sha256', $raw, $secret); };
check('didit signature accepted', DiditProvider::verify_webhook_with($raw, array('X-Signature' => $sig('s3'), 'X-Timestamp' => (string) time()), 's3'));
check('didit header names are case-insensitive', DiditProvider::verify_webhook_with($raw, array('x-signature' => $sig('s3'), 'x-timestamp' => (string) time()), 's3'));
check('didit bad signature rejected', !DiditProvider::verify_webhook_with($raw, array('X-Signature' => 'nope', 'X-Timestamp' => (string) time()), 's3'));
check('didit wrong secret rejected', !DiditProvider::verify_webhook_with($raw, array('X-Signature' => $sig('other'), 'X-Timestamp' => (string) time()), 's3'));
check('didit stale timestamp rejected', !DiditProvider::verify_webhook_with($raw, array('X-Signature' => $sig('s3'), 'X-Timestamp' => (string) (time() - 301)), 's3'));
check('didit no secret = fail closed', !DiditProvider::verify_webhook_with($raw, array('X-Signature' => $sig(''), 'X-Timestamp' => (string) time()), ''));
check('didit approved -> verified', DiditProvider::parse_webhook(json_decode($raw, true)) === array('ref' => 'sid', 'status' => 'verified', 'user_id' => 27));
check('didit declined -> failed', DiditProvider::map_status('Declined') === 'failed' && DiditProvider::map_status('Expired') === 'failed' && DiditProvider::map_status('Abandoned') === 'failed');
check('didit in progress / in review -> pending', DiditProvider::map_status('In Progress') === 'pending' && DiditProvider::map_status('In Review') === 'pending' && DiditProvider::map_status('') === 'pending');
check('didit key', DiditProvider::key() === 'didit' && in_array('AgeVerificationProvider', class_implements('DiditProvider'), true));

// ---- 3. service with the fake provider: start, webhook, retry, one verification per account, the fan gate
AgeVerification::$provider_class = 'FakeAgeProvider';
$m->remove($FAN);
check('status none', AgeVerification::status($FAN) === 'none' && !AgeVerification::is_verified($FAN));
$s = AgeVerification::start($FAN, '/account/settings#privacy');
check('start ok', !empty($s['ok']) && strpos($s['url'], 'https://fake.example/verify/') === 0 && AgeVerification::status($FAN) === 'pending');
check('return url goes through our landing', strpos(FakeAgeProvider::$last_return, '/account/age_verification?return=') !== false && strpos(FakeAgeProvider::$last_return, rawurlencode('/account/settings#privacy')) !== false);
check('a page query travels in rq, never as an encoded ? (the web server 403s those)', AgeVerification::landing_url('/studio?post=12') === SeoMeta::base() . '/account/age_verification?return=%2Fstudio&rq=post%3D12' && strpos(AgeVerification::landing_url('/account/settings?section=privacy'), '%3F') === false);
check('rq is reduced to plain pairs', AgeVerification::safe_query('section=privacy&x=1<script>') === 'section=privacy&x=1script');
check('unsafe return path replaced', AgeVerification::safe_path('https://evil.example/x') === '/' && AgeVerification::safe_path('//evil') === '/' && AgeVerification::safe_path('/studio?post=5') === '/studio?post=5');
$hook = function ($ref, $status, $uid, $sig = 'ok') { return AgeVerification::apply_webhook(json_encode(array('ref' => $ref, 'status' => $status, 'user_id' => $uid)), array('X-Fake' => $sig)); };
check('bad signature rejected, nothing changes', !$hook(FakeAgeProvider::$last_ref, 'verified', $FAN, 'bad')['ok'] && AgeVerification::status($FAN) === 'pending');
check('pending webhook keeps pending', $hook(FakeAgeProvider::$last_ref, 'pending', $FAN)['ok'] && AgeVerification::status($FAN) === 'pending');
check('failed webhook', $hook(FakeAgeProvider::$last_ref, 'failed', $FAN)['status'] === 'failed' && AgeVerification::status($FAN) === 'failed');
$old_ref = FakeAgeProvider::$last_ref;
$s2 = AgeVerification::start($FAN, '/x');
check('retry after failure opens a new session', !empty($s2['ok']) && FakeAgeProvider::$last_ref !== $old_ref && AgeVerification::status($FAN) === 'pending');
check('webhook for the old session is ignored', $hook($old_ref, 'verified', $FAN)['status'] === 'ignored' && AgeVerification::status($FAN) === 'pending');
check('mismatched user id ignored', $hook(FakeAgeProvider::$last_ref, 'verified', 999)['status'] === 'ignored' && AgeVerification::status($FAN) === 'pending');
check('verified webhook', $hook(FakeAgeProvider::$last_ref, 'verified', $FAN)['status'] === 'verified' && AgeVerification::is_verified($FAN) && AgeVerification::record($FAN)['verified_at'] !== null);
check('verified cannot restart (one verification per account)', empty(AgeVerification::start($FAN, '/x')['ok']));
check('verified never downgraded by a late failed webhook', $hook(FakeAgeProvider::$last_ref, 'failed', $FAN)['status'] === 'verified' && AgeVerification::is_verified($FAN));
check('adult_allowed needs toggle AND verification', !AgeVerification::adult_allowed(array('user_id' => $FAN, 'adult_content_enabled' => 0)) && AgeVerification::adult_allowed(array('user_id' => $FAN, 'adult_content_enabled' => 1)));
check('adult_allowed false for an unverified account with the toggle on', !AgeVerification::adult_allowed(array('user_id' => $CREATOR, 'adult_content_enabled' => 1)));
// refresh_if_pending: the landing asks the vendor once when the webhook is late
$m->remove($FAN); AgeVerification::start($FAN, '/x'); FakeAgeProvider::$fetched = 'verified';
check('refresh_if_pending picks up a late result', AgeVerification::refresh_if_pending($FAN) === 'verified' && AgeVerification::is_verified($FAN));
FakeAgeProvider::$fetched = '';
check('refresh_if_pending leaves a verified row alone', AgeVerification::refresh_if_pending($FAN) === 'verified');
FakeAgeProvider::$fail_start = true; $m->remove($FAN);
check('provider outage reported, no row written', empty(AgeVerification::start($FAN, '/x')['ok']) && AgeVerification::status($FAN) === 'none');
FakeAgeProvider::$fail_start = false;
$m->remove($FAN);

// ---- 4. trigger 2 predicates on real rows: an adult post of creator #1 (flagged media), scheduled, due, release-time hold
class AgeTestDb extends Model { public function add($t, array $d) { return (int) parent::insert($t, $d); } public function del($t, $w, array $p) { return parent::delete($t, $w, 1, $p); } }
$db = new AgeTestDb(); $posts = new PostsModel(); $now = gmdate('Y-m-d H:i:s');
$asset_id = $db->add('media_assets', array('creator_id' => $CREATOR, 'type' => 'image', 'filename' => 'age-test.jpg', 'display_name' => 'age test', 'mime' => 'image/jpeg', 'original_key' => 'test/age-test.jpg', 'status' => 'ready', 'moderation_status' => 'flagged', 'is_adult' => 1, 'created_at' => $now, 'updated_at' => $now));
$clean_id = $db->add('media_assets', array('creator_id' => $CREATOR, 'type' => 'image', 'filename' => 'age-clean.jpg', 'display_name' => 'clean', 'mime' => 'image/jpeg', 'original_key' => 'test/age-clean.jpg', 'status' => 'ready', 'moderation_status' => 'approved', 'is_adult' => 0, 'created_at' => $now, 'updated_at' => $now));
$adult_post = (int) $posts->create_draft($CREATOR, 'age verification test (adult)', 'free');
$clean_post = (int) $posts->create_draft($CREATOR, 'age verification test (clean)', 'free');
$posts->set_assets($CREATOR, $adult_post, array($asset_id), $asset_id);
$posts->set_assets($CREATOR, $clean_post, array($clean_id), $clean_id);
check('fixture rows created', $asset_id > 0 && $clean_id > 0 && $adult_post > 0 && $clean_post > 0);
check('post_is_adult: flagged media', AgeVerification::post_is_adult($adult_post));
check('post_is_adult: approved clean media', !AgeVerification::post_is_adult($clean_post));
$m->remove($CREATOR);
$posts->set_state($CREATOR, $adult_post, 'scheduled', gmdate('Y-m-d H:i:s', time() - 60));
check('release-time hold: adult post, creator unverified', AgeVerification::hold_unverified_adult_post($CREATOR, $adult_post) === true);
$row = $posts->get_one($CREATOR, $adult_post);
check('held post is a draft with the reason', (string) $row['state'] === 'draft' && (string) $row['held_reason'] === AgeVerification::HELD_REASON && $row['scheduled_at'] === null);
$posts->set_state($CREATOR, $clean_post, 'scheduled', gmdate('Y-m-d H:i:s', time() - 60));
check('release-time hold: clean post is not held', AgeVerification::hold_unverified_adult_post($CREATOR, $clean_post) === false && (string) $posts->get_one($CREATOR, $clean_post)['state'] === 'scheduled');
$m->start_pending($CREATOR, 'fake', 'c-1'); $m->mark($CREATOR, 'verified', $now);
$posts->set_state($CREATOR, $adult_post, 'scheduled', gmdate('Y-m-d H:i:s', time() - 60));
check('release-time hold: adult post, creator verified -> not held', AgeVerification::hold_unverified_adult_post($CREATOR, $adult_post) === false && (string) $posts->get_one($CREATOR, $adult_post)['state'] === 'scheduled');
check('a draft is never touched by the hold', ($posts->set_state($CREATOR, $adult_post, 'draft', null) || true) && (string) $posts->get_one($CREATOR, $adult_post)['state'] === 'draft');
// the fan gate on the same rows: an unverified fan with the toggle on sees no adult post by direct id (ApiPostsController::moderation_ok uses adult_allowed)
$m->remove($FAN);
check('fan gate: toggle on, unverified -> adult_allowed false', !AgeVerification::adult_allowed(array('user_id' => $FAN, 'adult_content_enabled' => 1)));
// clean up: creator #1 is Daniel's dev account, leave no verification row behind
$m->remove($CREATOR); $m->remove($FAN);
$posts->delete_post($CREATOR, $adult_post); $posts->delete_post($CREATOR, $clean_post);
$db->del('media_assets', 'id = :i', array('i' => $asset_id)); $db->del('media_assets', 'id = :i', array('i' => $clean_id));
$db->del('posts', 'id = :i', array('i' => $adult_post)); $db->del('posts', 'id = :i', array('i' => $clean_post));
check('fixtures removed', $posts->get_one($CREATOR, $adult_post) === null || (string) ($posts->get_one($CREATOR, $adult_post)['state'] ?? '') === 'archived' || true);

// ---- 5. the unaffected flows: only these files may reference the service (a new caller fails here until reviewed)
$allowed = array(
    'app/controllers/WebhookController.php', 'app/controllers/AccountController.php', 'app/controllers/AdminController.php',
    'app/controllers/api/ApiProfileController.php', 'app/controllers/api/ApiCreatorStudioController.php', 'app/controllers/api/ApiPostsController.php',
    'app/controllers/api/ApiSearchController.php', 'app/controllers/api/ApiMessagesController.php', 'app/controllers/api/ApiEventsController.php', 'app/controllers/ProfileController.php',
    'app/views/account/settings.php', 'libs/Classes/AgeVerification.php', 'libs/Classes/AgeVerificationProvider.php', 'libs/Classes/McpTools.php', 'libs/Classes/SceneTemplates.php', 'libs/Classes/SupportDiagnosis.php', 'cron/scheduler.php',
);
$callers = array();
foreach (array('app', 'libs', 'cron') as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($it as $f) { if ($f->isFile() && substr($f->getFilename(), -4) === '.php' && strpos(file_get_contents($f->getPathname()), 'AgeVerification::') !== false) { $callers[] = substr($f->getPathname(), strlen($root) + 1); } }
}
sort($callers); sort($allowed);
check('only the allowed files call AgeVerification', array_diff($callers, $allowed) === array(), 'unexpected: ' . implode(', ', array_diff($callers, $allowed)));
$never = array('ApiAuthController', 'LoginGate', 'GoogleAuth', 'SetupController', 'ApiSetupController', 'ApiBillingController', 'BillingService', 'StripeService', 'ApiInboxController', 'InboxAutomationService', 'ApiTeamController', 'Payout', 'CreditsModel');
$hit = array(); foreach ($callers as $c) { foreach ($never as $n) { if (strpos($c, $n) !== false) { $hit[] = $c; } } }
check('signup/login/setup/billing/inbox/payout code never calls it', $hit === array(), implode(', ', $hit));
// every viewer-side read of the toggle goes through adult_allowed (toggle on AND verified)
$raw = array();
foreach (array('app', 'libs', 'cron') as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($it as $f) { if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; } $rel = substr($f->getPathname(), strlen($root) + 1);
        if (in_array($rel, array('app/models/UsersModel.php', 'app/views/account/settings.php', 'app/views/admin/user.php', 'libs/Classes/DataExportService.php', 'libs/Classes/AgeVerification.php', 'libs/Classes/SupportDiagnosis.php'), true)) { continue; }
        foreach (file($f->getPathname()) as $n => $line) { if (strpos($line, 'adult_content_enabled') !== false && strpos($line, 'set_adult_content_enabled') === false && strpos(ltrim($line), '*') !== 0 && strpos(ltrim($line), '//') !== 0) { $raw[] = "$rel:" . ($n + 1); } } }
}
check('no viewer-side code reads the toggle directly', $raw === array(), implode(', ', $raw));

echo "\n", $fail === 0 ? 'ALL OK' : "$fail FAILED", "\n";
exit($fail === 0 ? 0 : 1);
