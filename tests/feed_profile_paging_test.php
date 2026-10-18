<?php
/**
 * Audit 2026-10-18 performance fixes, checked on their results:
 *  - home feed (FeedModel::recent) pages ids first: paging is seamless and the block filter works both ways;
 *  - the "N new posts" count (count_since) skips what the feed won't show: disabled creators, unscanned media, adult
 *    media for a viewer without adult on;
 *  - the profile grid (ProfilePosts) pages 24 at a time with the same gate and paywall: pages join up exactly, hidden
 *    posts are skipped, locked cards never carry media, and /@handle + /api/profile_posts serve it.
 * Creates a throwaway creator with its own posts and media rows, and removes everything afterwards.
 */
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }
$pm = new PostsModel(); $fm = new FeedModel();
$now = gmdate('Y-m-d H:i:s');
$handle = 'auditpg' . substr((string) getmypid(), -5);

// a paid creator with 60 posts (free / ppv / subscribers in turn), two approved images each
$cid = (int) $pm->insert('user_accounts', array('u_name' => $handle, 'user_email' => "audit+$handle@example.com", 'p_word' => 'x', 'first_name' => 'Audit', 'last_name' => 'Paging',
    'role_id' => (int) (new UsersModel())->get_role_id_by_name('Creator'), 'plan_tier' => 'studio', 'subscription_status' => 'active', 'user_status' => 'Active', 'is_demo' => 0, 'deleted' => 0));
$pm->insert('creator_profiles', array('user_id' => $cid, 'display_name' => 'Audit Paging', 'created_at' => $now, 'updated_at' => $now));
$media = function ($status, $adult = 0) use ($pm, $cid, $now) {
    return (int) $pm->insert('media_assets', array('creator_id' => $cid, 'type' => 'image', 'status' => 'ready', 'moderation_status' => $status, 'is_adult' => $adult,
        'thumb_key' => "test/$cid/thumb.jpg", 'display_key' => "test/$cid/display.jpg", 'blurred_key' => "test/$cid/blurred.jpg", 'original_key' => "test/$cid/original.jpg",
        'created_at' => $now, 'updated_at' => $now));
};
$ok1 = $media('approved'); $ok2 = $media('approved');
$posts = array();
for ($i = 1; $i <= 60; $i++) {
    $aud = array('free', 'ppv', 'subscribers')[$i % 3];
    $id = (int) $pm->insert('posts', array('creator_id' => $cid, 'caption' => "paging test $i", 'state' => 'published', 'audience' => $aud, 'ppv_price_credits' => $aud === 'ppv' ? 30 : 0,
        'on_cls' => 1, 'published_at' => gmdate('Y-m-d H:i:s', strtotime('2019-01-01') + $i * 3600), 'created_at' => $now, 'updated_at' => $now));
    $pm->insert('post_assets', array('post_id' => $id, 'asset_id' => $ok1, 'sort_order' => 0, 'is_cover' => 0));
    $pm->insert('post_assets', array('post_id' => $id, 'asset_id' => $ok2, 'sort_order' => 1, 'is_cover' => 1));
    $posts[] = $id;
}
$cleanup = function () use ($pm, $cid, &$posts) {
    $in = implode(',', array_map('intval', $posts));
    $pm->sql("DELETE FROM post_assets WHERE post_id IN ($in)");
    $pm->sql("DELETE FROM posts WHERE creator_id = :c", array('c' => $cid));
    $pm->sql("DELETE FROM media_assets WHERE creator_id = :c", array('c' => $cid));
    $pm->sql("DELETE FROM user_blocks WHERE user_id = :c1 OR blocked_user_id = :c2", array('c1' => $cid, 'c2' => $cid));
    $pm->sql("DELETE FROM creator_profiles WHERE user_id = :c", array('c' => $cid));
    $pm->sql("DELETE FROM user_accounts WHERE user_id = :c", array('c' => $cid));
};

try {
    // ---- profile grid ----
    $user = (new UsersModel())->get_user_by_id($cid)[0];
    check('creator_visible: an active paid creator', ProfilePosts::creator_visible($user, 0));
    $p1 = ProfilePosts::page($user, 'Audit Paging', 0, 0);
    check('first page: 24 cards and a next page', count($p1['cards']) === 24 && $p1['has_more'] && $p1['next_offset'] === 24);
    $all = array(); $off = 0; $pages = 0;
    do { $pg = ProfilePosts::page($user, 'Audit Paging', 0, $off); $all = array_merge($all, $pg['cards']); $off = $pg['next_offset']; $pages++; } while ($pg['has_more'] && $pages < 10);
    check('pages join up: 60 cards over 3 pages, newest first, no repeats', count($all) === 60 && $pages === 3 && array_column($all, 'id') === array_reverse($posts));
    $locked_ok = true; $open_ok = true;
    foreach ($all as $c) {
        if ($c['entitled']) { if (empty($c['assets']) || $c['audience'] !== 'free') { $open_ok = false; } }
        elseif (isset($c['assets']) || strpos((string) $c['cover'], 'blurred.jpg') === false) { $locked_ok = false; }
    }
    check('signed out: only free posts open, with their media', $open_ok);
    check('signed out: locked cards carry no media, only the blurred cover', $locked_ok);
    $one = $pm->get_assets($posts[0]); $batch = $pm->assets_for_posts(array($posts[0]))[$posts[0]] ?? array();
    foreach ($batch as &$b) { unset($b['post_id']); } unset($b);
    check('assets_for_posts matches get_assets', $one == $batch && count($batch) === 2);

    // hidden posts are skipped and the pages still join up
    $pending = $media('pending');
    $pm->insert('post_assets', array('post_id' => $posts[59], 'asset_id' => $pending, 'sort_order' => 2, 'is_cover' => 0));   // the newest post
    $adult = $media('approved', 1);
    $pm->insert('post_assets', array('post_id' => $posts[30], 'asset_id' => $adult, 'sort_order' => 2, 'is_cover' => 0));
    $all = array(); $off = 0; $pages = 0;
    do { $pg = ProfilePosts::page($user, 'Audit Paging', 0, $off); $all = array_merge($all, $pg['cards']); $off = $pg['next_offset']; $pages++; } while ($pg['has_more'] && $pages < 10);
    $ids = array_column($all, 'id');
    check('an unscanned post and an adult one (adult off) are skipped, nothing else lost', count($all) === 58 && !in_array($posts[59], $ids, true) && !in_array($posts[30], $ids, true));
    check('a page still fills to 24 past a hidden post', count(ProfilePosts::page($user, 'Audit Paging', 0, 0)['cards']) === 24);

    // the page itself and "Load More" over HTTP
    $base = 'http://framework.contentos.cvk';
    $html = (string) @file_get_contents("$base/@$handle");
    check('/@handle renders 24 cards and a Load More button', substr_count($html, 'class="pf-pc') - substr_count($html, 'class="pf-pc__') === 24 && strpos($html, '>Load More</button>') !== false);
    $jar = tempnam(sys_get_temp_dir(), 'cj');
    $ch = curl_init("$base/csrf-token"); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar));
    $tok = (string) (json_decode((string) curl_exec($ch), true)['csrf_token'] ?? ''); curl_close($ch);
    $ch = curl_init("$base/api/profile_posts"); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array('handle' => $handle, 'offset' => $p1['next_offset'], 'csrf_token' => $tok))));
    $api = json_decode((string) curl_exec($ch), true); curl_close($ch); @unlink($jar);
    check('/api/profile_posts gives the next 24 with their card markup', !empty($api['success']) && count($api['items']) === 24 && substr_count((string) $api['html'], 'data-post-id=') === 24 && !empty($api['has_more']));
    $leak = false; foreach ((array) ($api['items'] ?? array()) as $c) { if (!$c['entitled'] && isset($c['assets'])) { $leak = true; } }
    check('/api/profile_posts: locked cards carry no media', !$leak);

    // ---- home feed ----
    $viewers = array(0, 26, 27, 1);
    $seam = true;
    foreach ($viewers as $v) {
        $big = array_column($fm->recent(30, 0, $v), 'id'); $paged = array();
        for ($o = 0; $o < 30; $o += 4) { $paged = array_merge($paged, array_column($fm->recent(4, $o, $v), 'id')); }
        if (array_slice($paged, 0, 30) !== $big) { $seam = false; }
    }
    check('feed pages join up for a visitor, two fans and a creator', $seam);
    $in_feed = function ($viewer) use ($fm, $posts) { $ids = array_column($fm->recent(60, 0, $viewer), 'id'); $deep = array_column($fm->recent(60, 60, $viewer), 'id'); return (bool) array_intersect(array_merge($ids, $deep), $posts); };
    $pm->sql("UPDATE posts SET published_at = UTC_TIMESTAMP() WHERE id = :p", array('p' => $posts[0]));   // one post at the top of the feed
    check('the creator shows in the feed of a fan', $in_feed(26));
    $b1 = (int) $pm->insert('user_blocks', array('user_id' => 26, 'blocked_user_id' => $cid, 'created_at' => $now));
    check('a fan who blocked the creator no longer gets their posts', !$in_feed(26));
    $b2 = (int) $pm->insert('user_blocks', array('user_id' => $cid, 'blocked_user_id' => 27, 'created_at' => $now));
    check('a fan the creator blocked no longer gets their posts', !$in_feed(27));
    $pm->sql("DELETE FROM user_blocks WHERE id IN ($b1, $b2)");

    // ---- "N new posts" ----
    $since = $posts[0] - 1;
    $mk = function ($status, $adult = 0) use ($pm, $cid, $now, $media, &$posts) {
        $id = (int) $pm->insert('posts', array('creator_id' => $cid, 'caption' => 'new post test', 'state' => 'published', 'audience' => 'free', 'on_cls' => 1,
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now));
        $pm->insert('post_assets', array('post_id' => $id, 'asset_id' => $media($status, $adult), 'sort_order' => 0, 'is_cover' => 1));
        $posts[] = $id; return $id;
    };
    $base_n = $fm->count_since($posts[count($posts) - 1], 200, 26);
    $last = $posts[count($posts) - 1];
    $mk('approved'); $mk('pending'); $mk('blocked'); $mk('flagged'); $mk('approved', 1);
    check('new posts: an approved post counts, unscanned and blocked ones do not, adult ones not with adult off', $fm->count_since($last, 200, 26) - $base_n === 1);
    check('new posts: with adult on, the two adult posts count too', $fm->count_since($last, 200, 26, true) - $fm->count_since($last, 200, 26, false) === 2);
    $pm->sql("UPDATE user_accounts SET user_status = 'Disabled' WHERE user_id = :c", array('c' => $cid));
    $only_mine = $pm->select("SELECT COUNT(*) AS n FROM posts WHERE id > :l AND creator_id <> :c", array('l' => $last, 'c' => $cid));
    if ((int) $only_mine[0]['n'] === 0) { check('new posts: nothing counted once the creator is suspended', $fm->count_since($last, 200, 26, true) === 0); }
} finally {
    $cleanup();
}
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
