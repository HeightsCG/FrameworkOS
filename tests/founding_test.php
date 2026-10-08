<?php
/*
 * Founding creator offer (Founding, BillingService FOUNDING promo, Plan::fee_percent override, lapse hook, featured order).
 * Makes two throwaway Free creators with no email address (nothing is sent) and a fake saved card id (a $0 charge never
 * reaches the payment processor), and deletes everything it made at the end. php tests/founding_test.php
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
$mk = function ($tag) use ($db) {
    $now = gmdate('Y-m-d H:i:s');
    $uid = $db->add('user_accounts', array('u_name' => 'zzfound' . $tag . time(), 'user_email' => '', 'first_name' => 'Founding', 'last_name' => 'Test ' . strtoupper($tag),
        'p_word' => 'x', 'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 1, 'created_at' => $now));
    (new BillingAccountsModel())->save($uid, array('stripe_payment_method_id' => 'pm_test_founding_fake', 'card_brand' => 'visa', 'card_last4' => '4242'));
    return $uid;
};

$a = $mk('a'); $b = $mk('b');
Founding::present(true);   // as the billing API does when founding=1 comes with the request
try {
    $creator = PlanTiers::get(Founding::PLAN);
    $t0 = Founding::taken();
    check('fee locked at the Studio fee from PlanTiers (' . Founding::fee() . ')', Founding::fee() === (float) PlanTiers::get('studio')['limits']['fee_percent'] && Founding::fee() < (float) $creator['limits']['fee_percent']);
    check('eligible while on Free with spots left', Founding::eligible($a));

    // quote: the FOUNDING promo takes the first month to $0, no payment processor call
    $q = BillingService::quote_plan($a, Founding::PLAN, Founding::CODE);
    check('quote ok, $0 today', !empty($q['ok']) && (int) $q['today'] === 0);
    check('quote has the founding promo line', !empty($q['lines'][1]) && strpos($q['lines'][1][0], 'Promo ' . Founding::CODE) === 0 && (int) $q['lines'][1][1] === -BillingService::plan_cents(Founding::PLAN));
    check('quote still renews at the plan price', (int) $q['recurring'] === BillingService::plan_cents(Founding::PLAN));
    $qs = BillingService::quote_plan($a, 'studio', Founding::CODE);
    check('founding promo refused for Studio', empty($qs['ok']));
    Founding::present(false);
    check('typed FOUNDING without the founding flag is not a promo', empty(BillingService::quote_plan($a, Founding::PLAN, Founding::CODE)['ok']));
    Founding::present(true);

    // subscribe: claim, $0 charge applied, spot active, fee locked
    $r = BillingService::change_plan($a, Founding::PLAN, Founding::CODE, array());
    check('change_plan succeeded', ($r['status'] ?? '') === 'succeeded');
    $ua = $row($a);
    $claim = (new FoundingClaimsModel())->for_user($a);
    check('is_founding = 1', (int) $ua['is_founding'] === 1);
    check('fee override 3.00 on billing_accounts', (new BillingAccountsModel())->fee_override($a) === Founding::fee());
    check('Plan::fee_percent returns the locked fee', Plan::fee_percent($ua) === Founding::fee());
    check('claim active with activated_at', $claim && $claim['status'] === 'active' && !empty($claim['activated_at']));
    check('spots taken went up by one', Founding::taken() === $t0 + 1);
    check('on the Creator plan, active', Plan::tier($ua) === Founding::PLAN);
    $acct = BillingService::account($a); $next = BillingService::next_charge($acct);
    check('promo used up: next charge is the full plan price', $next && (int) $next['total'] === BillingService::plan_cents(Founding::PLAN) && empty($acct['promo_code']));
    check('not eligible a second time', !Founding::eligible($a) && empty(BillingService::quote_plan($a, Founding::PLAN, Founding::CODE)['ok']));

    // spots full: refused before any charge
    Founding::set_spots_for_test(Founding::taken());
    $qb = BillingService::quote_plan($b, Founding::PLAN, Founding::CODE);
    check('quote refused when spots are full', empty($qb['ok']) && $qb['message'] === Founding::FULL_MESSAGE);
    $charges_before = count($db->rows('SELECT id FROM billing_charges WHERE user_id = :u', array('u' => $b)));
    $rb = BillingService::change_plan($b, Founding::PLAN, Founding::CODE, array());
    check('change_plan refused with the full message', ($rb['status'] ?? '') === 'failed' && $rb['message'] === Founding::FULL_MESSAGE);
    check('no charge row and no claim for the refused account', count($db->rows('SELECT id FROM billing_charges WHERE user_id = :u', array('u' => $b))) === $charges_before && !(new FoundingClaimsModel())->for_user($b));
    check('B still on Free', !BillingService::is_paid(BillingService::account($b)));

    // the last spot: the claim is conditional under a lock, the second one fails
    Founding::set_spots_for_test(Founding::taken() + 1);
    $c1 = Founding::claim($b); $c2 = Founding::claim(2000000000);
    check('last spot: first claim holds it, second is refused', $c1 === '' && $c2 === Founding::FULL_MESSAGE);
    Founding::release($b);
    check('released spot frees the count', !(new FoundingClaimsModel())->for_user($b));
    Founding::set_spots_for_test(null);

    // featured rotation: founding creators come before other Creator-plan creators, whatever the day's hash.
    // Both throwaways get a listed profile and one approved post so the directory takes them; B gets a Creator plan mirror.
    $listed = function ($uid) use ($db) {
        $now = gmdate('Y-m-d H:i:s'); $av = 'https://example.invalid/zzfound' . $uid . '.jpg';
        $db->run('DELETE FROM creator_profiles WHERE user_id = :u', array(':u' => $uid));   // the plan start may have made one
        $db->add('creator_profiles', array('user_id' => $uid, 'display_name' => 'Founding Test', 'avatar_url' => $av, 'directory_listed' => 1, 'directory_media_ok' => sha1($av . '|'), 'created_at' => $now, 'updated_at' => $now));
        $mid = $db->add('media_assets', array('creator_id' => $uid, 'type' => 'image', 'moderation_status' => 'approved', 'is_adult' => 0, 'created_at' => $now, 'updated_at' => $now));
        $pid = $db->add('posts', array('creator_id' => $uid, 'state' => 'published', 'on_cls' => 1, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now));
        $db->add('post_assets', array('post_id' => $pid, 'asset_id' => $mid));
    };
    $listed($a); $listed($b);
    $db->run("UPDATE user_accounts SET plan_tier = 'creator', subscription_status = 'active' WHERE user_id = :u", array(':u' => $b));
    $cpm = new CreatorProfileModel(); $day = gmdate('Y-m-d');
    $order = function () use ($cpm, $day, $a, $b) {
        $ids = array_map('intval', array_column(array_filter($cpm->directory_featured('', $day, 50), function ($f) { return $f['plan_tier'] !== 'studio'; }), 'user_id'));
        return array_values(array_filter($ids, function ($i) use ($a, $b) { return $i === $a || $i === $b; }));
    };
    check('founding A ahead of non-founding B in the featured rotation', $order() === array($a, $b));
    $db->run('UPDATE user_accounts SET is_founding = (user_id = :b) WHERE user_id IN (:a, :b2)', array(':a' => $a, ':b' => $b, ':b2' => $b));
    check('flags swapped: B ahead of A (not the day hash)', $order() === array($b, $a));
    $db->run('UPDATE user_accounts SET is_founding = (user_id = :a) WHERE user_id IN (:a2, :b)', array(':a' => $a, ':a2' => $a, ':b' => $b));
    $show = array_map('intval', array_column($cpm->founding_showcase($day, 6), 'user_id'));
    check('home founding row lists A only', in_array($a, $show, true) && !in_array($b, $show, true));
    $db->run("UPDATE user_accounts SET plan_tier = NULL, subscription_status = NULL WHERE user_id = :u", array(':u' => $b));

    // a missed lapse never keeps the lock: the override counts only with an active claim
    $db->run("UPDATE founding_claims SET status = 'lapsed' WHERE user_id = :u", array(':u' => $a)); Founding::release(0);
    check('fee falls back to the plan fee when the claim is not active', Plan::fee_percent($row($a)) === (float) $creator['limits']['fee_percent']);
    $db->run("UPDATE founding_claims SET status = 'active' WHERE user_id = :u", array(':u' => $a)); Founding::release(0);
    check('and returns with the active claim', Plan::fee_percent($row($a)) === Founding::fee());

    // stale held spot (a checkout that died) is released by the cron sweep
    Founding::claim($b);
    $db->run("UPDATE founding_claims SET claimed_at = UTC_TIMESTAMP() - INTERVAL 61 MINUTE WHERE user_id = :u", array(':u' => $b));
    check('stale claimed spot released after 60 minutes', Founding::release_stale(60) >= 1 && !(new FoundingClaimsModel())->for_user($b));

    // testimonial: due 14 days after activation, once per claim (no email: the throwaway has no address)
    $db->run('UPDATE founding_claims SET activated_at = UTC_TIMESTAMP() - INTERVAL 15 DAY WHERE user_id = :u', array(':u' => $a));
    $due = array_map('intval', array_column((new FoundingClaimsModel())->testimonial_due(Founding::TESTIMONIAL_DAYS), 'user_id'));
    check('testimonial due after 14 days', in_array($a, $due, true));
    $c = (new FoundingClaimsModel())->for_user($a);
    check('testimonial request sent once', Founding::request_testimonial($c) && !Founding::request_testimonial($c));
    check('in-app notice links to the testimonial page', count($db->rows("SELECT id FROM notifications WHERE user_id = :u AND link = :l", array('u' => $a, 'l' => Founding::TESTIMONIAL_PATH))) === 1);
    $html = NotificationsModel::build_notification_email('t', 'b', Founding::TESTIMONIAL_PATH);
    check('email button goes to the absolute testimonial url', strpos($html, 'href="' . rtrim(Main::get_base_domain(), '/') . Founding::TESTIMONIAL_PATH . '"') !== false);
    $fc = (new FoundingClaimsModel())->for_user($a);
    (new FoundingClaimsModel())->save_testimonial((int) $fc['id'], 'Payouts were simple.', true);
    $fc = (new FoundingClaimsModel())->for_user($a);
    check('testimonial text and consent saved', $fc['testimonial_text'] === 'Payouts were simple.' && (int) $fc['testimonial_consent'] === 1 && !empty($fc['testimonial_submitted_at']));

    // leaving the Creator plan ends the founding terms
    BillingService::to_free($a, true, 'test');
    $ua = $row($a);
    check('to Free: is_founding cleared', (int) $ua['is_founding'] === 0);
    check('to Free: override cleared', (new BillingAccountsModel())->fee_override($a) === null);
    check('to Free: claim lapsed', (new FoundingClaimsModel())->for_user($a)['status'] === 'lapsed');
    check('to Free: spot no longer counted', Founding::taken() === $t0);
} finally {
    Founding::set_spots_for_test(null);
    foreach (array($a, $b) as $uid) {
        $db->run('DELETE pa FROM post_assets pa JOIN posts p ON p.id = pa.post_id WHERE p.creator_id = :u', array(':u' => $uid));
        $db->run('DELETE FROM posts WHERE creator_id = :u', array(':u' => $uid));
        $db->run('DELETE FROM media_assets WHERE creator_id = :u', array(':u' => $uid));
        foreach (array('founding_claims', 'billing_charges', 'billing_accounts', 'ai_credit_transactions', 'notifications', 'creator_profiles', 'creator_setup', 'user_notification_prefs') as $t) {
            $db->run("DELETE FROM `$t` WHERE user_id = :u", array(':u' => $uid));
        }
        $db->run("DELETE FROM jobs WHERE dedupe_key IN (:k1, :k2) OR payload_json->'$.purge.creator' = :u OR payload_json->'$.user_id' = :u2", array(':k1' => 'membership_fee:' . $uid, ':k2' => 'directory_recheck:' . $uid, ':u' => $uid, ':u2' => $uid));
        $db->run('DELETE FROM user_accounts WHERE user_id = :u', array(':u' => $uid));
    }
    @unlink(sys_get_temp_dir() . '/cls_founding_taken.txt');
}
check('throwaway accounts removed', count($db->rows('SELECT user_id FROM user_accounts WHERE user_id IN (' . (int) $a . ',' . (int) $b . ')')) === 0);
exit($fail ? 1 : 0);
