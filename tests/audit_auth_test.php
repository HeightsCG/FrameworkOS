<?php
/**
 * 2026-10-18 audit, signup and auth: honeypot name, every password rule in one error, ?next= with tab/backslash,
 * spoofed proxy headers, per-account caps on forgot / resend, UNIQUE live email, unverified re-signup resends,
 * reset marks the email verified, emails with ' and &, Main::config default, /signup /login /register redirects.
 * Talks to the dev site over HTTP (example.com addresses: Notifications never mails them) and removes its rows.
 *   APPLICATION_ENV=development php tests/audit_auth_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($extra !== '' && !$ok ? '  -> ' . $extra : '') . "\n"; if (!$ok) { $fail++; } }
$db = new class extends Model { public function x($s, $p = array()) { return $this->sql($s, $p); } public function rows($s, $p = array()) { return $this->select($s, $p); } };
$users = new UsersModel();
$jar = tempnam(sys_get_temp_dir(), 'cls_auth');
$token = '';
$req = function ($path, $post = null, $follow = false) use ($base, $jar) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest')));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string) curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return array('code' => (int) $info['http_code'], 'body' => $body, 'location' => (string) ($info['redirect_url'] ?? ''));
};
$api = function ($action, array $data) use ($req, &$token) {
    if ($token === '') { $token = (string) (json_decode($req('/csrf-token')['body'], true)['csrf_token'] ?? ''); }
    $r = json_decode($req('/api/' . $action, $data + array('csrf_token' => $token))['body'], true);
    if (is_array($r) && !empty($r['csrf_expired'])) { $token = (string) ($r['csrf_token'] ?? ''); $r = json_decode($req('/api/' . $action, $data + array('csrf_token' => $token))['body'], true); }
    return is_array($r) ? $r : array();
};
$max_attempt = (int) ($db->rows("SELECT COALESCE(MAX(id), 0) AS m FROM login_attempts")[0]['m'] ?? 0);
$max_job     = (int) ($db->rows("SELECT COALESCE(MAX(id), 0) AS m FROM jobs")[0]['m'] ?? 0);
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$email_a = "audit+auth{$tag}o'k&co@example.com";   // ' and & are valid in an address
$email_b = "audit+auth{$tag}b@example.com";
$free_ip_caps = function () use ($db, $max_attempt) { $db->x("DELETE FROM login_attempts WHERE id > :m AND action IN ('register','forgot','verify_resend','login','reset')", array(':m' => $max_attempt)); };
$ids = array();

/* ---- pure helpers ---- */
check('Main::config returns the default for a missing key, no warning', Main::config('global', 'no_such_key_' . $tag, 'dflt') === 'dflt');
foreach (array("/\t/evil.com", "/\n/evil.com", '/\\evil.com', '//evil.com', '/ /evil.com') as $bad) { check('safe_path rejects ' . json_encode($bad), CustomDomains::safe_path($bad) === '/'); }
check('safe_path keeps a normal path', CustomDomains::safe_path('/@h/events/12?x=1') === '/@h/events/12?x=1');
$js = (string) file_get_contents($root . '/libs/Layout/auth_head.php');
check('cls_after_login rejects control characters and backslashes', strpos($js, "/[\\u0000-\\u0020\\u007f\\\\]/.test(n)") !== false);

/* ---- pages ---- */
$start = $req('/start')['body'];
check('/start honeypot is display:none, meaningless name, autocomplete=new-password', preg_match('#<div style="display:none" aria-hidden="true"><input type="text" name="hp_field_x" id="st_hp" tabindex="-1" autocomplete="new-password"></div>#', $start) === 1 && strpos($start, 'name="company"') === false);
check('/start lists all five password rules', substr_count($start, '<li data-rule="') === 5 && strpos($start, 'id="st_rules"') !== false);
$home = $req('/?auth=register')['body'];
check('register dialog: honeypot renamed + five rules', strpos($home, 'name="hp_field_x"') !== false && strpos($home, 'id="register_company"') === false && substr_count($home, '<li data-rule="') === 5);
foreach (array('/signup?plan=creator&role=creator&ref=x1' => '/?auth=register&plan=creator&role=creator&ref=x1', '/register' => '/?auth=register', '/login' => '/?auth=login') as $from => $to) {
    $r = $req($from); check($from . ' 301s to ' . $to, $r['code'] === 301 && substr($r['location'], -strlen($to)) === $to, $r['code'] . ' ' . $r['location']);
}

/* ---- register ---- */
$r = $api('register', array('user_email' => $email_a, 'p_word' => 'password', 'p_word_confirm' => 'password'));
check('weak password: one error names every missing rule', ($r['success'] ?? true) === false && ($r['message'] ?? '') === 'Password needs an uppercase letter, a number and a symbol', $r['message'] ?? '');
$pw = 'Audit#Pass1';
$r = $api('register', array('user_email' => $email_b, 'p_word' => $pw, 'p_word_confirm' => $pw, 'hp_field_x' => 'Acme'));
check('filled honeypot: normal reply, no account', !empty($r['success']) && !$users->email_exists($email_b));
$r = $api('register', array('user_email' => $email_b, 'p_word' => $pw, 'p_word_confirm' => $pw, 'company' => 'Acme Inc'));   // the old trap name, as browser autofill fills it
$row = $users->email_exists($email_b);
check('an autofilled "company" field no longer drops the signup', !empty($r['success']) && count($row) === 1, json_encode($r));
if ($row) { $ids[] = (int) $row[0]['user_id']; }
$free_ip_caps();

$r = $api('register', array('user_email' => $email_a, 'p_word' => $pw, 'p_word_confirm' => $pw));
$row = $users->email_exists($email_a);
check("email with ' and & registers and is stored as typed", !empty($r['success']) && count($row) === 1 && $row[0]['user_email'] === $email_a, json_encode($r));
$uid_a = $row ? (int) $row[0]['user_id'] : 0; if ($uid_a) { $ids[] = $uid_a; }
$r = $api('register', array('user_email' => $email_a, 'p_word' => $pw, 'p_word_confirm' => $pw));
check('re-signup with the unverified email: normal reply, link resent, still one row', !empty($r['success']) && !empty($r['resent']) && count($users->email_exists($email_a)) === 1, json_encode($r));
$sent = (int) $db->rows("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = :i AND action = 'verify_sent'", array(':i' => 'uid:' . $uid_a))[0]['c'];
check('the resend went through the per-account cap', $sent === 1, (string) $sent);

$e = '';
try { $users->create_user('auditdup' . $tag, 'x', '', '', strtoupper($email_b)); } catch (\PDOException $x) { $e = $x->getMessage(); }
check('UNIQUE live email: a second row (any case) is refused', strpos($e, 'uq_user_email_live') !== false, $e);

/* ---- forgot: per-account cap, same reply ---- */
$free_ip_caps();
$msgs = array();
for ($i = 0; $i < 4; $i++) { $msgs[] = (string) ($api('forgot', array('u_name' => $email_a))['message'] ?? ''); }
$sent = (int) $db->rows("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = :i AND action = 'forgot_sent'", array(':i' => 'uid:' . $uid_a))[0]['c'];
check('forgot: 3 reset emails an hour per account, the 4th answers the same', $sent === 3 && count(array_unique($msgs)) === 1, $sent . ' ' . json_encode($msgs));

/* ---- reset marks verified, weak reset lists all ---- */
$rt = $users->set_reset_token($uid_a);
$r = $api('reset', array('reset_token' => $rt, 'p_word' => 'short'));
check('reset with a weak password: every missing rule', ($r['message'] ?? '') === 'Password needs at least 8 characters, an uppercase letter, a number and a symbol', $r['message'] ?? '');
$r = $api('reset', array('reset_token' => $rt, 'p_word' => $pw . 'x'));
check('a successful reset marks the email verified', !empty($r['success']) && (int) $users->get_user_by_id($uid_a)[0]['email_verified'] === 1, json_encode($r));
$r = $api('register', array('user_email' => $email_a, 'p_word' => $pw, 'p_word_confirm' => $pw));
check('re-signup with a verified email is still refused', ($r['success'] ?? true) === false);

/* ---- login with the ' & email ---- */
$free_ip_caps();
$r = $api('login', array('u_name' => $email_a, 'p_word' => $pw . 'x'));
check("login by an email with ' and & works", !empty($r['success']) || !empty($r['mfa_required']), json_encode($r));
$api('logout', array());

/* ---- resend_verification: per-account cap ---- */
$free_ip_caps();
$uid_b = $ids[0] ?? 0;
for ($i = 0; $i < 4; $i++) { $api('resend_verification', array('u_name' => $email_b)); }
$sent = (int) $db->rows("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = :i AND action = 'verify_sent'", array(':i' => 'uid:' . $uid_b))[0]['c'];
check('resend_verification: 3 an hour per account', $sent === 3, (string) $sent);

/* ---- login_attempts lookups use the index ---- */
$plan = $db->rows("EXPLAIN SELECT COUNT(*) AS cnt FROM login_attempts WHERE identifier = 'x' AND action = 'login' AND created_at > '2026-01-01'");
check('per-account limit query uses idx_identifier_action_time', ($plan[0]['key'] ?? '') === 'idx_identifier_action_time', json_encode($plan));
(new LoginAttemptsModel())->purge(LoginAttemptsModel::KEEP_DAYS);
check('purge leaves recent rows', (int) $db->rows("SELECT COUNT(*) AS c FROM login_attempts WHERE id > :m", array(':m' => $max_attempt))[0]['c'] > 0);

/* ---- clean up ---- */
foreach ($ids as $id) {
    $db->x("DELETE FROM notifications WHERE user_id = :u", array(':u' => $id));
    $db->x("DELETE FROM analytics_events WHERE user_id = :u", array(':u' => $id));
    $db->x("DELETE FROM login_attempts WHERE identifier = :i", array(':i' => 'uid:' . $id));
    $db->x("DELETE FROM user_accounts WHERE user_id = :u", array(':u' => $id));
}
$db->x("DELETE FROM jobs WHERE id > :m AND (payload_json LIKE :p1 OR payload_json LIKE :p2)", array(':m' => $max_job, ':p1' => '%audit+auth' . $tag . '%', ':p2' => '%"user_id":' . ($ids[0] ?? -1) . '%'));
foreach ($ids as $id) { $db->x("DELETE FROM jobs WHERE id > :m AND (dedupe_key LIKE :k OR payload_json LIKE :p)", array(':m' => $max_job, ':k' => '%:' . $id, ':p' => '%"user_id":' . $id . '%')); }
$db->x("DELETE FROM login_attempts WHERE id > :m AND identifier LIKE :i", array(':m' => $max_attempt, ':i' => 'audit+auth' . $tag . '%'));
$free_ip_caps();
@unlink($jar);
check('cleanup: no test accounts left', !$users->email_exists($email_a) && !$users->email_exists($email_b));

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
