<?php
/*
 * Free-creator nurture emails (NurtureEmails, cron/lifecycle.php step, Tips and Offers switch, Postmark broadcast stream).
 * Nothing is sent: Notifications::$outbox collects every Postmark payload, and LifecycleEmails::$only_users keeps the
 * sweep on this test's throwaway accounts. The Founding Creator end date (app_settings creator_promo_ends) is changed
 * for the deadline checks and put back. Everything it made is deleted at the end. php tests/nurture_emails_test.php
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
$made = array();
$tag = (string) time();
$creator_role = (int) (new UsersModel())->get_role_id_by_name('Creator');
$fan_role     = (int) (new UsersModel())->get_role_id_by_name('User');
$mail = function ($name) use ($tag) { return 'nurture.' . $name . $tag . '@cls-mailtest.com'; };
$mk = function ($name, $hours, array $extra = array()) use ($db, &$made, $tag, $creator_role, $mail) {
    $uid = $db->add('user_accounts', $extra + array('u_name' => 'zznur' . $name . $tag, 'user_email' => $mail($name), 'first_name' => ucfirst($name), 'last_name' => 'Rivera',
        'p_word' => 'x', 'role_id' => $creator_role, 'signup_role' => 'creator', 'user_status' => 'Active', 'email_verified' => 1, 'reset_pw' => 0,
        'created_at' => gmdate('Y-m-d H:i:s', time() - (int) round($hours * 3600))));
    $made[] = $uid;
    return $uid;
};
$age = function ($uid, $hours) use ($db) { $db->run('UPDATE user_accounts SET created_at = :t WHERE user_id = :u', array(':t' => gmdate('Y-m-d H:i:s', time() - (int) round($hours * 3600)), ':u' => $uid)); };
$sent_to = function ($name) use ($mail) { $e = $mail($name); return array_values(array_filter(Notifications::$outbox, function ($p) use ($e) { return $p['To'] === $e; })); };
$backdate = function ($uid, $hours) use ($db) { $db->run('UPDATE lifecycle_emails SET created_at = :t WHERE user_id = :u', array(':t' => gmdate('Y-m-d H:i:s', time() - $hours * 3600), ':u' => $uid)); };
$claims = function ($uid, $kind) use ($db) { return (int) $db->rows('SELECT COUNT(*) n FROM lifecycle_emails WHERE user_id = :u AND kind = :k', array(':u' => $uid, ':k' => $kind))[0]['n']; };

$settings = new AdminSettingsModel();
$had_end = $db->rows("SELECT v FROM app_settings WHERE k = :k", array(':k' => CreatorPromo::SETTING));
$set_end = function ($ymd) use ($settings) { $settings->set(CreatorPromo::SETTING, $ymd); CreatorPromo::reset(); };
$eastern = function ($ymd, $hour) { return (new DateTimeImmutable($ymd . ' ' . sprintf('%02d', $hour) . ':00:00', new DateTimeZone(CreatorPromo::TZ)))->getTimestamp(); };

Notifications::$outbox = array();
LifecycleEmails::$only_users = array();
try {
    // ---- the Tips and Offers category ----
    check('category exists, email on by default', isset(NotificationPrefsModel::$categories['tips_offers']) && NotificationPrefsModel::$categories['tips_offers'][1] === 1);
    check('email-only in Settings', in_array('tips_offers', NotificationPrefsModel::$email_only, true));

    // ---- eligibility ----
    $a = $mk('ann', 25);                                                    // Free creator, signed up yesterday
    $b = $mk('ben', 25, array('subscription_status' => 'active'));          // paid
    $c = $mk('cat', 25);                                                    // opted out
    $d = $mk('dan', 25);                                                    // bounced
    $e = $mk('eve', 25, array('role_id' => $fan_role, 'signup_role' => null));   // fan
    $f = $mk('fay', 24 * 30);                                               // old sign-up
    $g = $mk('gus', 25, array('email_verified' => 0));                      // unverified
    $s = $mk('sue', 25, array('user_status' => 'Disabled'));                // suspended
    $r = $mk('rae', 25, array('role_id' => $fan_role));                     // signed up as a creator, role not switched yet
    LifecycleEmails::$only_users = array($a, $b, $c, $d, $e, $f, $g, $s, $r);
    (new NotificationPrefsModel())->save_prefs($c, array('tips_offers' => array('in_platform' => 0, 'email' => 0)));
    (new UsersModel())->mark_email_bounced($mail('dan'), 'HardBounce: test');

    $n = NurtureEmails::run();
    $m = $sent_to('ann');
    check('Free creator gets day 1', count($m) === 1 && ($m[0]['Subject'] ?? '') === 'Your page isn\'t public yet');
    check('signup_role creator counts too', count($sent_to('rae')) === 1);
    check('paid creator: nothing', count($sent_to('ben')) === 0);
    check('opted out: nothing', count($sent_to('cat')) === 0);
    check('bounced: nothing', count($sent_to('dan')) === 0);
    check('fan: nothing', count($sent_to('eve')) === 0);
    check('old sign-up: nothing', count($sent_to('fay')) === 0 && $claims($f, 'nurture_day1') === 0);
    check('unverified / suspended: nothing', count($sent_to('gus')) === 0 && count($sent_to('sue')) === 0);
    check('run returns the count', $n === 2);

    // ---- compliance ----
    $p = $m[0];
    check('broadcast stream', ($p['MessageStream'] ?? '') === 'broadcast');
    check('HTML footer: postal address', strpos($p['HtmlBody'], htmlspecialchars(Notifications::POSTAL_ADDRESS, ENT_QUOTES, 'UTF-8')) !== false);
    check('HTML footer: unsubscribe placeholder in a link', strpos($p['HtmlBody'], 'href="{{{ pm:unsubscribe }}}"') !== false);
    check('text part: address + unsubscribe placeholder', strpos($p['TextBody'], Notifications::POSTAL_ADDRESS) !== false && strpos($p['TextBody'], '{{{ pm:unsubscribe }}}') !== false);
    check('Email Settings link', strpos($p['HtmlBody'], '/account/settings?section=notifications') !== false);
    check('no Stripe, Title Case button', stripos($p['HtmlBody'], 'stripe') === false && (strpos($p['HtmlBody'], 'Start Free Trial') !== false || strpos($p['HtmlBody'], 'Choose a Plan') !== false));
    check('button follows Founding::eligible', strpos($p['HtmlBody'], Founding::eligible($a) ? 'founding=1&amp;checkout=1' : 'Choose a Plan') !== false);
    $before = count(Notifications::$outbox);
    Notifications::$outbox = Notifications::$outbox;   // transactional mail unchanged
    (new Notifications())->send_email(array(array('email' => $mail('ann'), 'name' => 'Ann')), 0, 'Transactional check', '<p>x</p>');
    check('transactional mail stays on outbound', (end(Notifications::$outbox)['MessageStream'] ?? '') === 'outbound');
    array_pop(Notifications::$outbox);

    // ---- exactly once + spacing + timing ----
    NurtureEmails::run();
    check('day 1 exactly once', count($sent_to('ann')) === 1 && $claims($a, 'nurture_day1') === 1);
    $age($a, 73);
    NurtureEmails::run();
    check('day 3 waits for the gap after day 1', count($sent_to('ann')) === 1);
    $backdate($a, 21);
    NurtureEmails::run();
    $m = $sent_to('ann');
    check('day 3 after the gap', count($m) === 2 && ($m[1]['Subject'] ?? '') === 'Make your first AI photo');
    check('day 3 returns to /influencers/create', strpos($m[1]['HtmlBody'], '%2Finfluencers%2Fcreate') !== false);
    $backdate($a, 21);
    NurtureEmails::run();
    check('day 3 exactly once', count($sent_to('ann')) === 2);
    $age($a, 170);
    $backdate($a, 21);
    NurtureEmails::run();
    check('day 7 only for sign-ups from ' . NurtureEmails::FROM, $claims($a, 'nurture_day7') === (strtotime(gmdate('Y-m-d H:i:s', time() - 170 * 3600) . ' UTC') >= strtotime(NurtureEmails::FROM . ' UTC') ? 1 : 0));
    $d7 = NurtureEmails::build('nurture_day7', array('user_id' => $a), false);
    $rows = array(); foreach ($d7['facts'] as $x) { $rows[$x[0]] = $x[1]; }
    check('day 7: price and fee on separate lines', isset($rows['Price'], $rows['Platform fee']) && strpos($rows['Platform fee'], '%') !== false && strpos($rows['Platform fee'], '$') === false && strpos($rows['Price'], '%') === false);

    // ---- stop when a plan starts ----
    $h = $mk('hal', 25);
    LifecycleEmails::$only_users = array($h);
    $db->run("UPDATE user_accounts SET subscription_status = 'trialing' WHERE user_id = :u", array(':u' => $h));
    NurtureEmails::run();
    check('trial started: nothing sent', count($sent_to('hal')) === 0);
    check('send-time check refuses a paid account', NurtureEmails::send($h, 'nurture_day1') === false && $claims($h, 'nurture_day1') === 0);
    $db->run("UPDATE user_accounts SET subscription_status = NULL WHERE user_id = :u", array(':u' => $h));
    check('back on Free: send works', NurtureEmails::send($h, 'nurture_day1') === true && count($sent_to('hal')) === 1);

    // ---- Postmark unsubscribe (SubscriptionChange webhook) ----
    $k = $mk('kim', 25);
    LifecycleEmails::$only_users = array($k);
    check('unsubscribe on another stream is ignored', NurtureEmails::subscription_change(array('Recipient' => $mail('kim'), 'MessageStream' => 'outbound', 'SuppressSending' => true)) === 0);
    check('broadcast unsubscribe turns the switch off', NurtureEmails::subscription_change(array('Recipient' => $mail('kim'), 'MessageStream' => 'broadcast', 'SuppressSending' => true)) === 1
        && !(new NotificationPrefsModel())->email_on($k, 'tips_offers'));
    NurtureEmails::run();
    check('unsubscribed: nothing sent', count($sent_to('kim')) === 0);
    NurtureEmails::subscription_change(array('Recipient' => $mail('kim'), 'MessageStream' => 'broadcast', 'SuppressSending' => false));
    check('reactivated: switch back on', (new NotificationPrefsModel())->email_on($k, 'tips_offers'));
    LifecycleEmails::$secret_for_test = 'nurture-secret';
    NurtureEmails::subscription_change(array('Recipient' => $mail('kim'), 'MessageStream' => 'broadcast', 'SuppressSending' => true));
    $res = LifecycleEmails::postmark_webhook(json_encode(array('RecordType' => 'SubscriptionChange', 'Recipient' => $mail('kim'), 'MessageStream' => 'broadcast', 'SuppressSending' => false)), 'nurture-secret');
    check('webhook routes SubscriptionChange', $res[0] === 200 && (new NotificationPrefsModel())->email_on($k, 'tips_offers'));

    // ---- deadline follows CreatorPromo ----
    $q = $mk('quinn', 24 * 10);   // 10 days old: past the day 1/3 windows
    LifecycleEmails::$only_users = array($q);
    $today = (new DateTimeImmutable('now', new DateTimeZone(CreatorPromo::TZ)));
    $end = $today->modify('+3 days')->format('Y-m-d');
    $set_end($end);
    NurtureEmails::$now = $eastern($today->format('Y-m-d'), 8);
    check('no deadline email before 9 AM Eastern', NurtureEmails::deadline_stage() === null);
    NurtureEmails::$now = $eastern($today->format('Y-m-d'), 10);
    check('3 days before: stage 3d', (NurtureEmails::deadline_stage()[1] ?? '') === '3d');
    NurtureEmails::run();
    $m = $sent_to('quinn');
    $label = CreatorPromo::ends_label();
    check('deadline email names the admin end date', count($m) === 1 && ($m[0]['Subject'] ?? '') === 'The $' . CreatorPromo::PRICE . ' Founding Creator price ends ' . $label);
    check('deadline: broadcast + footer', ($m[0]['MessageStream'] ?? '') === 'broadcast' && strpos($m[0]['TextBody'], '{{{ pm:unsubscribe }}}') !== false);
    NurtureEmails::run();
    check('deadline 3d exactly once', count($sent_to('quinn')) === 1);
    NurtureEmails::$now = $eastern($today->modify('+1 day')->format('Y-m-d'), 10);
    check('in between: no stage', NurtureEmails::deadline_stage() === null);
    NurtureEmails::$now = $eastern($end, 10);
    NurtureEmails::run();
    $m = $sent_to('quinn');
    check('last day email', count($m) === 2 && strpos($m[1]['HtmlBody'], 'last day') !== false);
    NurtureEmails::run();
    check('never more than two per end date', count($sent_to('quinn')) === 2);
    $new_end = (new DateTimeImmutable($end, new DateTimeZone(CreatorPromo::TZ)))->modify('+7 days')->format('Y-m-d');
    $set_end($new_end);
    NurtureEmails::$now = $eastern((new DateTimeImmutable($new_end, new DateTimeZone(CreatorPromo::TZ)))->modify('-3 days')->format('Y-m-d'), 10);
    NurtureEmails::run();
    $m = $sent_to('quinn');
    check('extended deadline sends again for the new date', count($m) === 3 && strpos($m[2]['Subject'], CreatorPromo::ends_label()) !== false && CreatorPromo::ends_label() !== $label);
    NurtureEmails::$now = $eastern((new DateTimeImmutable($new_end, new DateTimeZone(CreatorPromo::TZ)))->modify('+1 day')->format('Y-m-d'), 10);
    check('after the offer ends: no stage', NurtureEmails::deadline_stage() === null);
    $set_end($today->modify('-1 day')->format('Y-m-d'));
    NurtureEmails::$now = null;
    check('offer over: no stage now', NurtureEmails::deadline_stage() === null);
} finally {
    NurtureEmails::$now = null;
    LifecycleEmails::$secret_for_test = null;
    if ($had_end) { $settings->set(CreatorPromo::SETTING, (string) $had_end[0]['v']); }
    else { $db->run('DELETE FROM app_settings WHERE k = :k', array(':k' => CreatorPromo::SETTING)); }
    CreatorPromo::reset();
    foreach ($made as $uid) {
        foreach (array('lifecycle_emails', 'user_notification_prefs', 'billing_accounts', 'notifications', 'creator_profiles', 'creator_setup') as $t) {
            try { $db->run("DELETE FROM `$t` WHERE user_id = :u", array(':u' => $uid)); } catch (\Throwable $e2) {}
        }
        $db->run('DELETE FROM user_accounts WHERE user_id = :u', array(':u' => $uid));
    }
    Notifications::$outbox = null;
}
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
