<?php
/**
 * Launch Campaign: the schedule, the checks on edited drafts, and the scheduled-message sender.
 * Uses the dev database (creator #1). Nothing is posted: the campaign under test has no social accounts.
 *   APPLICATION_ENV=development php tests/ai_campaign_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $extra === '' ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
class CampaignTestModel extends Model {
    public function __construct(){ parent::__construct(); }
    public function rows($sql, $p = array()){ return parent::select($sql, $p); }
    public function wipe($campaign_id){
        parent::delete_all('post_assets', 'post_id IN (SELECT id FROM (SELECT id FROM posts WHERE campaign_id = :k) x)', array('k' => (int) $campaign_id));
        parent::delete_all('posts', 'campaign_id = :k', array('k' => (int) $campaign_id));
        parent::delete_all('scheduled_broadcasts', 'campaign_id = :k', array('k' => (int) $campaign_id));
        parent::delete_all('launch_campaigns', 'id = :k', array('k' => (int) $campaign_id));
    }
    public function make_due($campaign_id){ return parent::update('scheduled_broadcasts', array('send_at' => gmdate('Y-m-d H:i:s', time() - 60)), "campaign_id = :k AND status = 'scheduled'", array('k' => (int) $campaign_id)); }
    public function drop_promo($code){ parent::delete_all('creator_promo_codes', 'user_id = 1 AND code = :c', array('c' => $code)); }
}
$db = new CampaignTestModel();

/* ---- the schedule ---- */
$now = 1800000000; $launch = $now + 5 * 86400;
$s = LaunchCampaign::slots($launch, 3, $now);
$keys = array_column($s, 'key');
check('3 days: announcement, 3 anticipation, countdown, live', $keys === array('announcement_post', 'announcement_message', 'anticipation_3', 'anticipation_2', 'anticipation_1', 'countdown_post', 'countdown_message', 'live_post', 'live_message'), implode(',', $keys));
$at = array_column($s, 'at', 'key');
check('the announcement goes out first, right away',    $at['announcement_post'] === $now + LaunchCampaign::LEAD);
check('anticipation posts are a day apart',             $at['anticipation_2'] - $at['anticipation_3'] === 86400 && $at['anticipation_1'] === $launch - 86400);
check('the countdown is an hour before, live is at launch', $at['countdown_post'] === $launch - 3600 && $at['live_message'] === $launch);
check('times never go backwards',                       array_values($at) === (function ($a) { sort($a); return $a; })(array_values($at)));
$near = LaunchCampaign::slots($now + 2 * 3600, 3, $now);
check('a launch two hours away drops the days that are already past', array_column($near, 'key') === array('announcement_post', 'announcement_message', 'countdown_post', 'countdown_message', 'live_post', 'live_message'), implode(',', array_column($near, 'key')));
$soon = LaunchCampaign::slots($now + 2400, 0, $now);
check('a launch 40 minutes away is announcement and live only', array_column($soon, 'key') === array('announcement_post', 'announcement_message', 'live_post', 'live_message'));
check('days are capped',                                count(array_filter(LaunchCampaign::slots($now + 30 * 86400, 99, $now), function ($x) { return $x['kind'] === 'anticipation'; })) === LaunchCampaign::MAX_DAYS);

/* ---- time zones ---- */
$ts = LaunchCampaign::to_ts('2027-01-15T20:00', 'America/New_York');
check('a local time is read in the creator\'s zone',     gmdate('Y-m-d H:i', $ts) === '2027-01-16 01:00');
check('and written back the same',                      LaunchCampaign::local($ts, 'America/New_York') === '2027-01-15T20:00');
check('an unreadable time is refused',                  LaunchCampaign::to_ts('', 'UTC') === 0 && LaunchCampaign::to_ts('not a date', 'UTC') === 0);

/* ---- fallback text and edited drafts ---- */
check('fallback: the code is mentioned only when live',  strpos(LaunchCampaign::fallback(array('kind' => 'live', 'type' => 'post'), 'my new set', 'NOVA20'), 'NOVA20') !== false
    && strpos(LaunchCampaign::fallback(array('kind' => 'announcement', 'type' => 'post'), 'my new set', 'NOVA20'), 'NOVA20') === false);
list($items, $err) = LaunchCampaign::clean_items(array(
    array('key' => 'a', 'kind' => 'announcement', 'type' => 'post', 'at' => gmdate('Y-m-d\TH:i', time() + 7200), 'text' => '  Big news & more '),
    array('key' => 'b', 'kind' => 'live', 'type' => 'message', 'at' => gmdate('Y-m-d\TH:i', time() + 9000), 'text' => '   '),
    array('key' => 'c', 'kind' => 'live', 'type' => 'message', 'at' => gmdate('Y-m-d\TH:i', time() - 9000), 'text' => 'Out now'),
), 'UTC', time());
check('a blank piece is dropped, the rest kept',        $err === '' && count($items) === 2 && $items[0]['text'] === 'Big news & more');
check('text is taken as given (the web action decodes entities, not clean_items)', LaunchCampaign::clean_items(array(array('type' => 'post', 'at' => gmdate('Y-m-d\TH:i', time() + 7200), 'text' => 'a &amp; b')), 'UTC', time())[0][0]['text'] === 'a &amp; b');

/* ---- text-only campaigns cannot go to platforms that take media only ---- */
$conn = array(
    array('platform' => 'instagram', 'post_for_me_social_account_id' => 'ig1'),
    array('platform' => 'tiktok_business', 'post_for_me_social_account_id' => 'tt1'),
    array('platform' => 'x', 'post_for_me_social_account_id' => 'x1'),
    array('platform' => 'youtube', 'post_for_me_social_account_id' => 'yt1'),
);
check('Instagram and TikTok need media, X does not',    LaunchCampaign::media_needed($conn, array('ig1', 'tt1', 'x1')) === array('Instagram', 'TikTok'));
check('nothing needed for X alone or for no accounts',  LaunchCampaign::media_needed($conn, array('x1')) === array() && LaunchCampaign::media_needed($conn, array()) === array());
check('an account that is not connected is ignored',    LaunchCampaign::media_needed($conn, array('nope')) === array());
check('SocialShareService knows which platforms take media only', SocialShareService::needs_media('Instagram') && SocialShareService::needs_media('pinterest') && !SocialShareService::needs_media('facebook'));
check('a piece timed in the past goes out with the first batch', $items[1]['at'] >= time() + 60);
list($none, $err) = LaunchCampaign::clean_items(array(array('type' => 'post', 'at' => '', 'text' => 'x')), 'UTC', time());
check('a piece with no time is refused',                $err !== '' && empty($none));
list($none, $err) = LaunchCampaign::clean_items(array(), 'UTC', time());
check('an empty campaign is refused',                   $err !== '');

/* ---- confirm: one go creates the posts, the messages and the promo code ---- */
$user = (new UsersModel())->get_user_by_id(1); $user = isset($user[0]) ? $user[0] : $user;
$tz = (string) ($user['content_timezone'] ?? 'UTC');
$launch = time() + 4 * 86400;
$code = 'TESTLC' . strtoupper(substr(md5((string) microtime(true)), 0, 5));
$drafts = array();
foreach (LaunchCampaign::slots($launch, 2, time()) as $sl) { $drafts[] = array('key' => $sl['key'], 'kind' => $sl['kind'], 'type' => $sl['type'], 'at' => LaunchCampaign::local($sl['at'], $tz), 'text' => 'Test campaign piece ' . $sl['key']); }
$k = 0;
try {
    $bad = LaunchCampaign::confirm(1, $user, array('items' => $drafts, 'launch_at' => LaunchCampaign::local($launch, $tz), 'promo_code' => $code, 'promo_percent' => 0));
    check('a promo code needs a discount',                  empty($bad['success']));
    $r = LaunchCampaign::confirm(1, $user, array('items' => $drafts, 'launch_at' => LaunchCampaign::local($launch, $tz), 'days' => 2, 'segments' => array('followers'), 'share_accounts' => array(),
        'influencer_id' => 999999999, 'promo_code' => $code, 'promo_percent' => 20, 'promo_days' => 3, 'also_cls' => true));
    check('the campaign is created',                        !empty($r['success']) && (int) ($r['campaign_id'] ?? 0) > 0, json_encode($r));
    $k = (int) ($r['campaign_id'] ?? 0);
    if ($k > 0) {
        $row   = (new LaunchCampaignsModel())->get_one(1, $k);
        check('an influencer that is not the creator\'s is not stored', $row && (int) $row['influencer_id'] === 0);
        $posts = $db->rows("SELECT state, scheduled_at, caption, on_cls FROM posts WHERE campaign_id = :k ORDER BY scheduled_at", array('k' => $k));
        $msgs  = $db->rows("SELECT status, send_at, segments FROM scheduled_broadcasts WHERE campaign_id = :k ORDER BY send_at", array('k' => $k));
        check('5 posts scheduled (announcement, 2 days, countdown, live)', count($posts) === 5 && count(array_filter($posts, function ($p) { return $p['state'] === 'scheduled' && (int) $p['on_cls'] === 1; })) === 5, (string) count($posts));
        check('3 messages scheduled to followers',          count($msgs) === 3 && $msgs[0]['status'] === 'scheduled' && $msgs[0]['segments'] === 'followers', (string) count($msgs));
        check('the live post is at the launch time (UTC)',  abs(strtotime(end($posts)['scheduled_at'] . ' UTC') - $launch) < 60);
        $promo = $db->rows("SELECT percent_off, expires_at FROM creator_promo_codes WHERE user_id = 1 AND code = :c", array('c' => $code));
        check('the promo code exists and expires 3 days after launch', count($promo) === 1 && (int) $promo[0]['percent_off'] === 20 && abs(strtotime($promo[0]['expires_at'] . ' UTC') - ($launch + 3 * 86400)) < 60);
        $dup = LaunchCampaign::confirm(1, $user, array('items' => $drafts, 'launch_at' => LaunchCampaign::local($launch, $tz), 'promo_code' => $code, 'promo_percent' => 20));
        check('the same promo code cannot be made twice',   empty($dup['success']));

        /* ---- the sender ---- */
        check('nothing is sent before its time',            count(array_filter((new LaunchCampaignsModel())->due_broadcasts(200), function ($b) use ($k) { return (int) $b['campaign_id'] === $k; })) === 0);
        $cm = new LaunchCampaignsModel(); $all = $cm->broadcasts_for(1, $k);
        check('a message can be claimed only once',         $cm->claim_broadcast((int) $all[0]['id']) && !$cm->claim_broadcast((int) $all[0]['id']));
        $cm->fail_broadcast((int) $all[0]['id'], 'test');
        check('cancelling stops the unsent ones',           (int) $cm->cancel_broadcasts(1, $k) === 2 && count(array_filter($cm->broadcasts_for(1, $k), function ($b) { return $b['status'] === 'scheduled'; })) === 0);
    }
} finally {
    // Whatever happened above, the rows this run made for creator 1 are removed.
    if ($k > 0) { $db->wipe($k); }
    $db->drop_promo($code);
}
check('no test rows left behind',                       count($db->rows("SELECT id FROM posts WHERE caption LIKE 'Test campaign piece %'")) === 0);

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
