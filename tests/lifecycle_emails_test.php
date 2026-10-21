<?php
/*
 * Lifecycle emails (LifecycleEmails, cron/lifecycle.php, BillingService receipt $0 path, /webhook/postmark).
 * Nothing is sent: Notifications::$outbox collects every Postmark payload, and LifecycleEmails::$only_users keeps the
 * sweeps on this test's throwaway accounts. Everything it made is deleted at the end. php tests/lifecycle_emails_test.php
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
$users = new UsersModel();
$row = function ($uid) use ($users) { $r = $users->get_user_by_id($uid); return $r[0]; };
$made = array();
$tag = (string) time();
$mk = function ($name, $email, array $extra = array()) use ($db, &$made, $tag) {
    $uid = $db->add('user_accounts', $extra + array('u_name' => 'zzlife' . $name . $tag, 'user_email' => $email, 'first_name' => ucfirst($name), 'last_name' => 'Lifecycle',
        'p_word' => 'x', 'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 0, 'reset_pw' => 0, 'created_at' => gmdate('Y-m-d H:i:s')));
    $made[] = $uid;
    return $uid;
};
$age = function ($uid, $hours) use ($db) { $db->run('UPDATE user_accounts SET created_at = :t WHERE user_id = :u', array(':t' => gmdate('Y-m-d H:i:s', time() - (int) round($hours * 3600)), ':u' => $uid)); };
$clear_cap = function ($uid) use ($db) { $db->run("DELETE FROM login_attempts WHERE identifier = :i", array(':i' => 'uid:' . $uid)); };
$sent_to = function ($email) { return array_values(array_filter(Notifications::$outbox, function ($p) use ($email) { return $p['To'] === $email; })); };

Notifications::$outbox = array();
LifecycleEmails::$only_users = array();
try {
    // ---- undeliverable domains ----
    foreach (array('a@cls-test.local', 'a@x.test', 'a@x.invalid', 'a@x.example', 'a@example.com', 'a@example.net', 'a@example.org', 'a@foo.example.com') as $e) {
        check('undeliverable: ' . $e, Notifications::undeliverable($e));
    }
    check('deliverable: gmail.com', !Notifications::undeliverable('someone@gmail.com'));

    // ---- verification email copy ----
    $v0 = NotificationsModel::build_verification_email('https://x/verify?token=t', 'creator123456', array('founding' => true));
    check('founding sign-up: trial subject', $v0['subject'] === 'Confirm your email to start your free trial');
    check('auto-generated handle is not shown', strpos($v0['html'], 'creator123456') === false && strpos($v0['html'], 'Your username is') === false);
    $v1 = NotificationsModel::build_verification_email('https://x/verify?token=t', 'mariasings', array());
    check('plain sign-up subject unchanged', $v1['subject'] === 'Confirm your email');
    check('a chosen handle is still shown', strpos($v1['html'], 'mariasings') !== false);

    // ---- verification reminders ----
    $ea = 'lifecycle.a' . $tag . '@cls-mailtest.com';
    $a = $mk('ann', $ea, array('signup_founding' => 1, 'content_timezone' => 'America/New_York'));
    $b = $mk('ben', 'lifecycle.b' . $tag . '@cls-mailtest.com');
    $c = $mk('cat', 'lifecycle.c' . $tag . '@cls-mailtest.com');
    $d = $mk('dan', 'lifecycle.d' . $tag . '@cls-test.local');
    LifecycleEmails::$only_users = array($a, $b, $c, $d);
    $users->mark_email_bounced('lifecycle.c' . $tag . '@cls-mailtest.com', 'HardBounce: test');

    $age($a, 1);
    LifecycleEmails::run_verify_reminders();
    check('no reminder 1 hour after sign-up', count($sent_to($ea)) === 0 && (int) $row($a)['verify_reminders_sent'] === 0);

    foreach (array($a, $b, $c, $d) as $u) { $age($u, 3); }
    $tok0 = (string) $row($a)['email_verify_token'];
    $n = LifecycleEmails::run_verify_reminders();
    $m = $sent_to($ea);
    $ra = $row($a);
    check('reminder 1 at ~2h: sent once', count($m) === 1 && (int) $ra['verify_reminders_sent'] === 1);
    check('reminder 1 subject', ($m[0]['Subject'] ?? '') === Notifications::env_subject('Confirm your email to open your studio'));
    check('reminder 1 has a fresh token in the link', $ra['email_verify_token'] !== $tok0 && strpos($m[0]['HtmlBody'] ?? '', urlencode($ra['email_verify_token'])) !== false);
    check('fresh token valid ~24h', abs(strtotime($ra['email_verify_expires'] . ' UTC') - time() - 86400) < 120);
    check('plain-text part with the link', strpos($m[0]['TextBody'] ?? '', '/account/verify?token=' . urlencode($ra['email_verify_token'])) !== false && strpos($m[0]['TextBody'], '<') === false);
    check('Reply-To support', ($m[0]['ReplyTo'] ?? '') === 'support@creatorlinkstudio.com');
    check('no reminder to a bounced address', count($sent_to('lifecycle.c' . $tag . '@cls-mailtest.com')) === 0 && (int) $row($c)['verify_reminders_sent'] === 0);
    check('no email to a test domain (step used up)', count($sent_to('lifecycle.d' . $tag . '@cls-test.local')) === 0 && (int) $row($d)['verify_reminders_sent'] === 1);
    check('non-founding sign-up got reminder 1 too', count($sent_to('lifecycle.b' . $tag . '@cls-mailtest.com')) === 1);

    LifecycleEmails::run_verify_reminders();
    check('reminder 1 not sent twice', count($sent_to($ea)) === 1);

    foreach (array($a, $b) as $u) { $clear_cap($u); $age($u, 23.5); }
    $tok1 = (string) $row($a)['email_verify_token'];
    LifecycleEmails::run_verify_reminders();
    $m = $sent_to($ea);
    check('reminder 2 at ~23h', count($m) === 2 && ($m[1]['Subject'] ?? '') === Notifications::env_subject('Your link expires soon — here\'s a new one') && (int) $row($a)['verify_reminders_sent'] === 2);
    check('reminder 2 token differs from reminder 1', (string) $row($a)['email_verify_token'] !== $tok1);

    foreach (array($a, $b) as $u) { $clear_cap($u); $age($u, 80); }
    LifecycleEmails::run_verify_reminders();
    $m = $sent_to($ea);
    $open = Founding::open();
    if ($open) {
        check('reminder 3 at ~72h for a founding sign-up', count($m) === 3 && strpos($m[2]['Subject'] ?? '', Notifications::env_subject('Your account is waiting')) === 0 && (int) $row($a)['verify_reminders_sent'] === 3);
    } else {
        check('founding spots full: no reminder 3', count($m) === 2);
    }
    check('no reminder 3 for a non-founding sign-up', count($sent_to('lifecycle.b' . $tag . '@cls-mailtest.com')) === 2);
    $clear_cap($a); $age($a, 100);
    LifecycleEmails::run_verify_reminders();
    check('never more than 3 reminders', count($sent_to($ea)) === ($open ? 3 : 2));
    check('resend refuses a bounced address', LifecycleEmails::send_verify_link($row($c), '127.0.0.1') === false);
    check('sign-in message for a bounced address', LifecycleEmails::bounced($row($c)) && LifecycleEmails::bounced_message($row($c)) === "We couldn't deliver email to lifecycle.c" . $tag . "@cls-mailtest.com. Check it or use another.");

    // ---- trial started / trial ending ----
    $ee = 'lifecycle.e' . $tag . '@cls-mailtest.com';
    $e = $mk('eve', $ee, array('email_verified' => 1, 'content_timezone' => 'Pacific/Honolulu'));
    LifecycleEmails::$only_users = array($e);
    (new BillingAccountsModel())->save($e, array('stripe_payment_method_id' => 'pm_test_lifecycle_fake', 'card_brand' => 'visa', 'card_last4' => '4242'));
    Founding::present(true);
    $before = count(Notifications::$outbox);
    $r = BillingService::change_plan($e, Founding::PLAN, Founding::CODE, array());
    check('founding trial started', ($r['status'] ?? '') === 'succeeded');
    $m = $sent_to($ee);
    $acct = BillingService::account($e);
    $want_date = (new DateTimeImmutable($acct['next_charge_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Pacific/Honolulu'))->format('M j, Y');
    $price = BillingService::money(BillingService::plan_cents(Founding::PLAN));
    check('trial-started email sent once', count($m) === 1 && ($m[0]['Subject'] ?? '') === Notifications::env_subject('Your ' . Founding::TRIAL_DAYS . '-day free trial has started'));
    check('trial-started: amount + date in the user\'s timezone', strpos($m[0]['HtmlBody'] ?? '', $price . ' on ' . $want_date) !== false);
    check('trial-started: Manage Plan button + Billing', strpos($m[0]['HtmlBody'], 'Manage Plan') !== false && strpos($m[0]['HtmlBody'], '/account/billing?tab=plan') !== false && stripos($m[0]['HtmlBody'], 'cancel anytime in billing') !== false);
    check('trial-started: never names the processor', stripos($m[0]['HtmlBody'], 'stripe') === false);
    $charge = $db->rows("SELECT * FROM billing_charges WHERE user_id = :u AND kind = 'subscribe' ORDER BY id DESC LIMIT 1", array(':u' => $e))[0];
    check('trial-started exactly once', LifecycleEmails::trial_started($charge) === false && count($sent_to($ee)) === 1);

    $db->run('UPDATE billing_accounts SET next_charge_at = :t WHERE user_id = :u', array(':t' => gmdate('Y-m-d H:i:s', time() + 5 * 86400), ':u' => $e));
    LifecycleEmails::send_trial_ending();
    check('no trial-ending email 5 days out', count($sent_to($ee)) === 1);
    $at = gmdate('Y-m-d 06:00:00', time() + 2 * 86400);   // 06:00 UTC = the evening before in Honolulu
    $db->run('UPDATE billing_accounts SET next_charge_at = :t WHERE user_id = :u', array(':t' => $at, ':u' => $e));
    $n = LifecycleEmails::send_trial_ending();
    $m = $sent_to($ee);
    $local = (new DateTimeImmutable($at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Pacific/Honolulu'))->format('M j, Y');
    check('trial-ending email 2 days out', $n === 1 && count($m) === 2 && ($m[1]['Subject'] ?? '') === Notifications::env_subject('Your trial ends ' . $local));
    check('trial-ending date is local (not the UTC day)', $local !== gmdate('M j, Y', strtotime($at . ' UTC')));
    check('trial-ending: amount per month + card', strpos($m[1]['HtmlBody'], $price . ' / month') !== false && strpos($m[1]['HtmlBody'], 'Visa ending 4242') !== false);
    check('trial-ending exactly once', LifecycleEmails::send_trial_ending() === 0 && count($sent_to($ee)) === 2);

    // ---- abandoned checkout ----
    $ef = 'lifecycle.f' . $tag . '@cls-mailtest.com';
    $f = $mk('fay', $ef, array('email_verified' => 1));
    $g = $mk('gus', 'lifecycle.g' . $tag . '@cls-mailtest.com', array('email_verified' => 1));
    LifecycleEmails::$only_users = array($e, $f, $g);
    $ev = function ($uid, $min_ago, $meta) use ($db) {
        $db->add('analytics_events', array('ts' => gmdate('Y-m-d H:i:s', time() - $min_ago * 60), 'kind' => 'event', 'name' => 'checkout_started', 'user_id' => $uid, 'creator_id' => $uid, 'meta' => $meta));
    };
    $ev($f, 120, 'subscribe creator founding');
    $ev($g, 20, 'subscribe creator');
    $ev($e, 120, 'subscribe creator');   // e is on a paid plan now
    $n = LifecycleEmails::run_abandoned_checkouts();
    $m = $sent_to($ef);
    check('abandoned checkout email after ~1h', $n === 1 && count($m) === 1 && ($m[0]['Subject'] ?? '') === Notifications::env_subject('Your Creator plan didn\'t start'));
    check('abandoned: card not charged + plan tab link with founding', strpos($m[0]['HtmlBody'], 'card wasn&#039;t charged') !== false && strpos($m[0]['HtmlBody'], '/account/billing?tab=plan&amp;plan=creator&amp;founding=1') !== false);
    check('no email while the checkout is 20 minutes old', count($sent_to('lifecycle.g' . $tag . '@cls-mailtest.com')) === 0);
    check('no email to an account on a paid plan', count($sent_to($ee)) === 2);
    check('abandoned: marketing stream with postal address + unsubscribe', ($m[0]['MessageStream'] ?? '') === Notifications::STREAM_BROADCAST
        && strpos($m[0]['HtmlBody'], htmlspecialchars(Notifications::POSTAL_ADDRESS, ENT_QUOTES, 'UTF-8')) !== false && strpos($m[0]['HtmlBody'], '{{{ pm:unsubscribe }}}') !== false);
    $ev($f, 70, 'subscribe creator founding');
    LifecycleEmails::run_abandoned_checkouts();
    check('abandoned checkout at most once per 7 days', count($sent_to($ef)) === 1);
    $eo = 'lifecycle.o' . $tag . '@cls-mailtest.com';
    $o = $mk('ola', $eo, array('email_verified' => 1));
    (new NotificationPrefsModel())->save_prefs($o, array(NurtureEmails::CATEGORY => array('in_platform' => 0, 'email' => 0)));
    LifecycleEmails::$only_users = array($o);
    $ev($o, 120, 'subscribe creator');
    LifecycleEmails::run_abandoned_checkouts();
    check('abandoned: Tips and Offers off means no email', count($sent_to($eo)) === 0);

    // ---- Postmark webhook ----
    $eh = 'lifecycle.h' . $tag . '@cls-mailtest.com';
    $h = $mk('hal', $eh, array('email_verified' => 1));
    $s = $mk('sue', 'lifecycle.s' . $tag . '@cls-mailtest.com', array('email_verified' => 1));
    LifecycleEmails::$secret_for_test = '';
    check('webhook not configured: 503', LifecycleEmails::postmark_webhook('{}', 'x')[0] === 503);
    LifecycleEmails::$secret_for_test = 'test-secret-123';
    $hb = json_encode(array('RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => $eh, 'Description' => 'The server was unable to deliver your message'));
    check('webhook rejects a bad secret', LifecycleEmails::postmark_webhook($hb, 'wrong')[0] === 401 && LifecycleEmails::bounced($row($h)) === false);
    check('webhook rejects a missing secret', LifecycleEmails::postmark_webhook($hb, '')[0] === 401);
    check('hard bounce accepted', LifecycleEmails::postmark_webhook($hb, 'test-secret-123')[0] === 200);
    $rh = $row($h);
    check('hard bounce marks the account', LifecycleEmails::bounced($rh) && strpos((string) $rh['email_bounce_reason'], 'HardBounce') === 0);
    $soft = json_encode(array('RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'lifecycle.s' . $tag . '@cls-mailtest.com'));
    check('soft bounce ignored', LifecycleEmails::postmark_webhook($soft, 'test-secret-123')[1] === 'ignored' && !LifecycleEmails::bounced($row($s)));
    $spam = json_encode(array('RecordType' => 'SpamComplaint', 'Type' => 'SpamComplaint', 'Email' => 'lifecycle.s' . $tag . '@cls-mailtest.com'));
    LifecycleEmails::postmark_webhook($spam, 'test-secret-123');
    check('spam complaint marks the account', LifecycleEmails::bounced($row($s)));
    $ek = 'lifecycle.k' . $tag . '@cls-mailtest.com';
    $k = $mk('kim', $ek, array('email_verified' => 1));
    $mspam = json_encode(array('RecordType' => 'SpamComplaint', 'Type' => 'SpamComplaint', 'Email' => $ek, 'MessageStream' => Notifications::STREAM_BROADCAST));
    check('marketing spam complaint accepted', LifecycleEmails::postmark_webhook($mspam, 'test-secret-123')[0] === 200);
    check('marketing spam complaint: account emails still delivered', !LifecycleEmails::bounced($row($k)) && LifecycleEmails::deliverable($row($k)));
    check('marketing spam complaint: Tips and Offers off', !(new NotificationPrefsModel())->email_on($k, NurtureEmails::CATEGORY));
    $db->run('UPDATE user_accounts SET user_email = :e WHERE user_id = :u', array(':e' => 'lifecycle.h2' . $tag . '@cls-mailtest.com', ':u' => $h));
    check('a changed address is no longer bounced', !LifecycleEmails::bounced($row($h)));
    check('bad JSON: 400', LifecycleEmails::postmark_webhook('nope', 'test-secret-123')[0] === 400);
} finally {
    Notifications::$outbox = null;
    LifecycleEmails::$only_users = null;
    LifecycleEmails::$secret_for_test = null;
    foreach ($made as $uid) {
        foreach (array('founding_claims', 'billing_charges', 'billing_accounts', 'ai_credit_transactions', 'notifications', 'creator_profiles', 'creator_setup', 'user_notification_prefs', 'lifecycle_emails', 'analytics_events', 'affiliate_commissions') as $t) {
            try { $db->run("DELETE FROM `$t` WHERE user_id = :u", array(':u' => $uid)); } catch (\Throwable $e2) {}
        }
        $db->run("DELETE FROM login_attempts WHERE identifier = :i", array(':i' => 'uid:' . $uid));
        $db->run("DELETE FROM jobs WHERE dedupe_key IN (:k1, :k2) OR payload_json->'$.user_id' = :u", array(':k1' => 'membership_fee:' . $uid, ':k2' => 'directory_recheck:' . $uid, ':u' => $uid));
        $db->run('DELETE FROM user_accounts WHERE user_id = :u', array(':u' => $uid));
    }
    @unlink(sys_get_temp_dir() . '/cls_founding_taken.txt');
}
check('throwaway accounts removed', empty($made) || count($db->rows('SELECT user_id FROM user_accounts WHERE user_id IN (' . implode(',', array_map('intval', $made)) . ')')) === 0);
exit($fail ? 1 : 0);
