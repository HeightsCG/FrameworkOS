<?php
/**
 * Audit 2026-10-18, trust & safety and content fixes: a suspended creator's work stops (scheduled posts, automations,
 * scheduled messages, links) and their team is shut out; report "Remove" archives the post; set_assets survives a
 * duplicate id; chunked uploads finish only when every part and byte is in; the signed-out feed fills its page past
 * hidden posts. Creates throwaway rows and removes them.
 *   APPLICATION_ENV=development php tests/audit_content_test.php
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
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
$db = new class extends Model {
    public function x($s, $p = array()) { return $this->sql($s, $p); }
    public function one($s, $p = array()) { $r = $this->select($s, $p); return $r[0] ?? null; }
    public function add($t, array $d) { return (int) $this->insert($t, $d); }
};
$now = gmdate('Y-m-d H:i:s');
$users = new UsersModel(); $posts = new PostsModel(); $media = new MediaAssetsModel();
$creator_role = (int) $users->get_role_id_by_name('Creator');

// ---- fixtures: an owner creator and an Editor on their team
$owner  = (int) $users->create_user('auditown' . mt_rand(10000, 99999), '!no-sign-in', 'Audit', 'Owner', '', 0, 0);
$member = (int) $users->create_user('auditmem' . mt_rand(10000, 99999), '!no-sign-in', 'Audit', 'Member', '', 0, 0);
$db->x("UPDATE user_accounts SET role_id = :r WHERE user_id = :u", array('r' => $creator_role, 'u' => $owner));
$db->x("UPDATE user_accounts SET team_role = 'editor', created_by = :o WHERE user_id = :u", array('o' => $owner, 'u' => $member));
$row = function ($id) use ($users) { $r = $users->get_user_by_id($id); return $r[0] ?? null; };
check('fixtures created', $owner > 0 && $member > 0);

// ---- 1. suspended creator
check('active owner: member not blocked by the owner check', LoginGate::blocked($row($member)) !== 'owner' && !Plan::team_owner_inactive($row($member)));
$post_due = (int) $posts->create_draft($owner, 'audit: scheduled post', 'free');
$posts->set_state($owner, $post_due, 'scheduled', gmdate('Y-m-d H:i:s', time() - 120));
$link_id = (int) (new CreatorLinksModel())->add($owner, 'Site', 'https://example.com');
check('active owner: link resolves', (new CreatorLinksModel())->get_public($link_id) !== null);
$sb = $db->add('scheduled_broadcasts', array('creator_id' => $owner, 'segments' => 'all', 'body' => 'audit test', 'send_at' => gmdate('Y-m-d H:i:s', time() - 60), 'status' => 'scheduled', 'created_at' => $now));
$due_ids = function () { return array_map(function ($b) { return (int) $b['id']; }, (new LaunchCampaignsModel())->due_broadcasts(500)); };
check('active owner: scheduled message is due', in_array($sb, $due_ids(), true));

$db->x("UPDATE user_accounts SET user_status = 'Disabled' WHERE user_id = :u", array('u' => $owner));
check('suspended owner: member sign-in blocked with the owner reason', LoginGate::blocked($row($member)) === 'owner' && LoginGate::message('owner') === Plan::OWNER_SUSPENDED_MESSAGE);
check('suspended owner: an existing member session is ended (team_member_locked)', Plan::team_member_locked($row($member)));
check('suspended owner: own sign-in still "suspended"', LoginGate::blocked($row($owner)) === 'suspended');
check('suspended owner: scheduled post not published', !isset($posts->publish_due($owner)[$post_due]) && (string) $posts->get_one($owner, $post_due)['state'] === 'scheduled');
$rule = array('id' => 0, 'creator_id' => $owner, 'topic' => 'x', 'kind' => 'post');
$r1 = AutoPostService::run_rule($rule, $row($owner));
check('suspended owner: post automation refuses', empty($r1['ok']) && strpos((string) $r1['message'], 'suspended') !== false, (string) ($r1['message'] ?? ''));
$r2 = MessageBlastService::run_rule(array('id' => 0, 'creator_id' => $owner, 'kind' => 'message'), $row($owner));
check('suspended owner: message automation refuses', empty($r2['ok']) && strpos((string) $r2['message'], 'suspended') !== false, (string) ($r2['message'] ?? ''));
check('suspended owner: scheduled message not due', !in_array($sb, $due_ids(), true));
check('suspended owner: /go link goes nowhere', (new CreatorLinksModel())->get_public($link_id) === null);
check('Plan::creator_active follows the status', !Plan::creator_active($owner));
$db->x("UPDATE user_accounts SET user_status = 'Active' WHERE user_id = :u", array('u' => $owner));
check('reinstated: scheduled post publishes', isset($posts->publish_due($owner)[$post_due]));
check('reinstated: member no longer blocked by the owner check', !Plan::team_owner_inactive($row($member)));
$db->x("DELETE FROM scheduled_broadcasts WHERE id = :i", array('i' => $sb));

// ---- 3. report takedown archives the post, text-only too
$text_post = (int) $posts->create_draft($owner, 'audit: text post to take down', 'free');
$posts->set_state($owner, $text_post, 'published', null);
(new ReportsModel())->takedown_post($text_post);
check('takedown: a text-only post is archived', (string) $posts->get_one($owner, $text_post)['state'] === 'archived');

// ---- 5. set_assets with a duplicate id keeps the media and does not throw
$a1 = (int) $media->add($owner, 'image', 'audit-a1.jpg', 'image/jpeg', 'ready');
$a2 = (int) $media->add($owner, 'image', 'audit-a2.jpg', 'image/jpeg', 'ready');
$mp = (int) $posts->create_draft($owner, 'audit: media post', 'free');
$posts->set_assets($owner, $mp, array($a1, $a2), $a2);
$threw = '';
try { $posts->set_assets($owner, $mp, array($a2, $a1, $a2, $a1), $a1); } catch (\Throwable $e) { $threw = $e->getMessage(); }
$pa = $db->select("SELECT asset_id, sort_order, is_cover FROM post_assets WHERE post_id = :p ORDER BY sort_order", array('p' => $mp));
check('set_assets: duplicate ids do not throw', $threw === '', $threw);
check('set_assets: each asset once, in order, cover kept', count($pa) === 2 && (int) $pa[0]['asset_id'] === $a2 && (int) $pa[1]['asset_id'] === $a1 && (int) $pa[1]['is_cover'] === 1 && (int) $pa[0]['is_cover'] === 0, json_encode($pa));
$posts->set_assets($owner, $mp, array(999999999, $a1), 0);
$pa = $db->select("SELECT asset_id, is_cover FROM post_assets WHERE post_id = :p", array('p' => $mp));
check('set_assets: an unowned id is skipped, the first kept one is the cover', count($pa) === 1 && (int) $pa[0]['asset_id'] === $a1 && (int) $pa[0]['is_cover'] === 1);
check('set_assets: no transaction left open', !$posts->db->inTransaction());

// ---- 6. chunked upload: complete only with every part and byte
$ups = new UploadSessionsModel(); $chunk = MediaLimits::CHUNK_SIZE;
$total = 2 * $chunk + 100;
$sid = (int) $ups->create($owner, 'audit.mp4', 'video/mp4', $total, 'test/audit.mp4', 'fake-upload', 'audit|token', 0);
$s = function () use ($ups, $owner, $sid) { return $ups->get_one($owner, $sid); };
check('upload: nothing in -> not complete', $ups->incomplete_reason($s(), $chunk) === 'none');
$ups->add_part($owner, $sid, 1, 'e1', $chunk); $ups->add_part($owner, $sid, 3, 'e3', 100);
check('upload: a gap (part 2 missing) -> not complete', $ups->incomplete_reason($s(), $chunk) === 'parts');
$ups->add_part($owner, $sid, 2, 'e2', $chunk - 5);
check('upload: short bytes -> not complete', $ups->incomplete_reason($s(), $chunk) === 'bytes');
$ups->add_part($owner, $sid, 2, 'e2b', $chunk);   // re-upload of part 2 replaces it
check('upload: every part and byte -> complete', $ups->incomplete_reason($s(), $chunk) === '' && (int) $s()['bytes_received'] === $total && count($ups->parts($s())) === 3);
check('upload: add_part leaves no transaction open', !$ups->db->inTransaction());
$db->x("DELETE FROM upload_sessions WHERE id = :i", array('i' => $sid));

// ---- 4. signed-out feed: the newest posts all hidden (still being scanned) and the page still fills
$seed = (new FeedModel())->recent(1, 0, 0);
if (!$seed) {
    echo "skip feed: no public feed post on dev\n";
} else {
    $fcid = (int) $seed[0]['creator_id'];
    $pend = (int) $media->add($fcid, 'image', 'audit-pending.jpg', 'image/jpeg', 'ready');
    $db->x("UPDATE media_assets SET moderation_status = 'pending' WHERE id = :i", array('i' => $pend));
    $hidden = array();
    for ($i = 0; $i < 14; $i++) {   // more than one 12-row batch of hidden posts on top
        $pid = $db->add('posts', array('creator_id' => $fcid, 'caption' => 'audit: hidden feed post ' . $i, 'state' => 'published', 'audience' => 'free', 'on_cls' => 1,
            'published_at' => gmdate('Y-m-d H:i:s', time() + 60 + $i), 'created_at' => $now, 'updated_at' => $now));
        $db->add('post_assets', array('post_id' => $pid, 'asset_id' => $pend, 'sort_order' => 0, 'is_cover' => 1));
        $hidden[] = $pid;
    }
    $visible_total = 0;
    foreach ((array) (new FeedModel())->recent(60, 0, 0) as $r) { if (!in_array((int) $r['id'], $hidden, true)) { $visible_total++; } }
    // signed-out POST /api/feed on the dev site (cookie + CSRF token from the home page)
    $jar = tempnam(sys_get_temp_dir(), 'cls');
    $base = 'http://framework.contentos.cvk';
    $ch = curl_init($base . '/'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30));
    $html = (string) curl_exec($ch); curl_close($ch);
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
    $token = (string) ($m[1] ?? '');
    $call = function ($offset) use ($base, $jar, &$token) {
        for ($try = 0; $try < 2; $try++) {
            $ch = curl_init($base . '/api/feed');
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(array('offset' => $offset, 'csrf_token' => $token)),
                CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30));
            $o = json_decode((string) curl_exec($ch), true); curl_close($ch);
            if (is_array($o) && !empty($o['csrf_expired'])) { $token = (string) $o['csrf_token']; continue; }   // the page's token, handed back once
            return is_array($o) ? $o : array();
        }
        return array();
    };
    $o = $call(0);
    $ids = array_map(function ($c) { return (int) $c['id']; }, (array) ($o['items'] ?? array()));
    check('feed: answered', !empty($o['success']), json_encode($o));
    check('feed: hidden posts are not shown', !array_intersect($ids, $hidden));
    check('feed: the first page still has cards (' . count($ids) . ' of ' . min(4, $visible_total) . ')', count($ids) === min(4, $visible_total));
    check('feed: next_offset moves past the hidden rows', (int) ($o['next_offset'] ?? 0) > 14);
    if (!empty($o['has_more'])) {
        $o2 = $call((int) $o['next_offset']);
        $ids2 = array_map(function ($c) { return (int) $c['id']; }, (array) ($o2['items'] ?? array()));
        check('feed: page 2 has no repeat of page 1', !array_intersect($ids, $ids2));
    }
    @unlink($jar);
    foreach ($hidden as $pid) { $db->x("DELETE FROM post_assets WHERE post_id = :p", array('p' => $pid)); $db->x("DELETE FROM posts WHERE id = :p", array('p' => $pid)); }
    $db->x("DELETE FROM media_assets WHERE id = :i", array('i' => $pend));
}

// ---- clean up
foreach (array($post_due, $text_post, $mp) as $pid) { $db->x("DELETE FROM post_assets WHERE post_id = :p", array('p' => $pid)); $db->x("DELETE FROM posts WHERE id = :p", array('p' => $pid)); }
$db->x("DELETE FROM media_assets WHERE id IN (:a, :b)", array('a' => $a1, 'b' => $a2));
$db->x("DELETE FROM creator_links WHERE user_id = :u", array('u' => $owner));
$db->x("DELETE FROM notifications WHERE user_id IN (:a, :b)", array('a' => $owner, 'b' => $member));
$db->x("DELETE FROM user_accounts WHERE user_id IN (:a, :b)", array('a' => $owner, 'b' => $member));
check('fixtures removed', $db->one("SELECT COUNT(*) n FROM user_accounts WHERE user_id IN (:a, :b)", array('a' => $owner, 'b' => $member))['n'] == 0);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
