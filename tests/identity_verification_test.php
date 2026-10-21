<?php
/**
 * Creator verification by ID check (IdentityVerification, 2026-10-21): a creator opens a Didit session, Didit's signed
 * webhook decides, the badge follows, staff can remove it. Opens real Didit sessions (nobody completes them, so nothing
 * is billed) and sends the webhook to the dev site signed with the configured secret. Throwaway accounts with no email.
 *   APPLICATION_ENV=development php tests/identity_verification_test.php
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
$vm    = new VerificationsModel();
$made  = array();
$mk = function () use ($users, $db, &$made) {
    $uid = (int) $users->create_user('idv' . mt_rand(100000, 999999), '!no-sign-in', 'Id', 'Test', '', 0, 0);
    $made[] = $uid;
    $db->x("UPDATE user_accounts SET role_id = (SELECT id FROM user_roles WHERE role_name = 'Creator'), email_verified = 1, is_demo = 1 WHERE user_id = :u", array(':u' => $uid));
    return $uid;
};
$verified = function ($uid) use ($users) { return (int) ($users->get_user_by_id($uid)[0]['verified'] ?? 0) === 1; };
// Didit's webhook as it arrives: signed with the destination secret (DiditProvider::verify_webhook_with)
$webhook = function ($ref, $uid, $status) {
    $secret = (string) (Main::get_config()['global']['didit_webhook_secret'] ?? '');
    $body = json_encode(array('session_id' => $ref, 'status' => $status, 'vendor_data' => (string) $uid, 'webhook_type' => 'status.updated'));
    $ch = curl_init('http://framework.contentos.cvk/webhook/didit');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Timestamp: ' . time(), 'X-Signature: ' . hash_hmac('sha256', $body, $secret))));
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
};

try {
    echo "== start\n";
    $a = $mk();
    check('no check yet', IdentityVerification::state($a)['status'] === 'none');
    $r = IdentityVerification::start($a);
    $row = $vm->latest_for($a);
    check('the ID check opens on Didit', !empty($r['ok']) && strpos($r['url'], 'didit.me') !== false, json_encode($r));
    check('the session is recorded as in progress', $row && $row['status'] === 'pending' && $row['provider_ref'] !== '' && IdentityVerification::state($a)['status'] === 'pending');
    $r2 = IdentityVerification::start($a);
    check('pressing again carries on with the same session', !empty($r2['ok']) && $r2['url'] === $r['url'] && count($db->q("SELECT id FROM verifications WHERE user_id = :u", array(':u' => $a))) === 1);
    check('not verified while in progress', !$verified($a));

    echo "== Didit approves\n";
    check('the signed webhook is accepted', $webhook($row['provider_ref'], $a, 'Approved') === 200);
    $row = $vm->get((int) $row['id']);
    check('approved and the badge is on, no admin step', $row['status'] === 'approved' && $verified($a) && $row['decided_at'] !== null);
    check('a second start is refused', empty(IdentityVerification::start($a)['ok']));
    check('the creator is told', (int) $db->q("SELECT COUNT(*) n FROM notifications WHERE user_id = :u AND title = 'You''re verified'", array(':u' => $a))[0]['n'] === 1);
    check('a forged webhook is refused', (function () use ($row, $a) {
        $ch = curl_init('http://framework.contentos.cvk/webhook/didit'); $body = json_encode(array('session_id' => $row['provider_ref'], 'status' => 'Declined', 'vendor_data' => (string) $a));
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => array('X-Timestamp: ' . time(), 'X-Signature: bad')));
        curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c === 401;
    })());
    check('...and changed nothing', $verified($a));
    check('it shows in the admin record', (bool) array_filter($vm->recent_for_admin(200), function ($v) use ($a) { return (int) $v['user_id'] === $a && $v['status'] === 'approved'; }));

    echo "== staff remove the badge\n";
    $vm->revoke($a, 1);
    check('badge off, row marked removed', !$verified($a) && $vm->latest_for($a)['status'] === 'revoked');

    echo "== Didit declines\n";
    $b = $mk();
    IdentityVerification::start($b);
    $row = $vm->latest_for($b);
    $webhook($row['provider_ref'], $b, 'Declined');
    $st = IdentityVerification::state($b);
    check('declined, no badge, a reason for the creator', $st['status'] === 'rejected' && !$verified($b) && $st['reason'] !== '', json_encode($st));
    $r = IdentityVerification::start($b);
    check('Try Again opens a new session', !empty($r['ok']) && $vm->latest_for($b)['status'] === 'pending' && $vm->latest_for($b)['id'] !== $row['id']);

    echo "== the age check is untouched\n";
    check('an age-check webhook for an unknown session still answers 200', $webhook('00000000-0000-0000-0000-000000000000', 0, 'Approved') === 200);
} finally {
    foreach ($made as $uid) {
        foreach (array('verifications', 'notifications') as $tb) { $db->x("DELETE FROM $tb WHERE user_id = :u", array(':u' => $uid)); }
        $db->x("DELETE FROM user_accounts WHERE user_id = :u AND user_email = ''", array(':u' => $uid));
    }
}
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
