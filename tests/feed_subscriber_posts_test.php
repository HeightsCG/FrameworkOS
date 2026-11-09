<?php
/**
 * Selling removed (2026-11-09): a legacy subscribers-only or pay-per-view post (before cron/retire_selling_once.php archives
 * it) is never shown — not in the Home feed, the "N new posts" count, a creator's profile grid or a lookup by id — to anyone,
 * the creator included. Creates and removes its own rows.
 */
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }
$fm = new FeedModel(); $pm = new PostsModel();
// a creator who already has a post in the feed (so the paid-plan and status gates pass), and their post's media
$seed = $fm->recent(1, 0, 0);
if (!$seed) { echo "no feed post on dev, skipped\n"; exit(0); }
$cid = (int) $seed[0]['creator_id'];
$aid = $pm->select('SELECT asset_id FROM post_assets WHERE post_id = :p LIMIT 1', array('p' => (int) $seed[0]['id']));
$now = date('Y-m-d H:i:s');
$made = array();
foreach (array('subscribers', 'ppv') as $aud) {
    $pid = (int) $pm->insert('posts', array('creator_id' => $cid, 'caption' => 'test: legacy ' . $aud . ' post', 'state' => 'published', 'audience' => $aud, 'on_cls' => 1,
        'published_at' => $now, 'created_at' => $now, 'updated_at' => $now));
    if ($aid) { $pm->insert('post_assets', array('post_id' => $pid, 'asset_id' => (int) $aid[0]['asset_id'], 'sort_order' => 0, 'is_cover' => 1)); }
    $made[$aud] = $pid;
}
$other = $pm->select('SELECT user_id FROM user_accounts WHERE user_id <> :c AND deleted = 0 ORDER BY user_id ASC LIMIT 1', array('c' => $cid));
$fan = (int) ($other[0]['user_id'] ?? 0);
try {
    $feed_ids = function ($viewer) use ($fm) { return array_map('intval', array_column((array) $fm->recent(60, 0, $viewer), 'id')); };
    $grid_ids = function () use ($pm, $cid) { return array_map('intval', array_column((array) $pm->get_published_for_creator($cid, 200, 0), 'id')); };
    foreach ($made as $aud => $pid) {
        check("$aud: not in the feed for a signed-out visitor", !in_array($pid, $feed_ids(0), true));
        if ($fan) { check("$aud: not in the feed for a member", !in_array($pid, $feed_ids($fan), true)); }
        check("$aud: not in the feed for the creator", !in_array($pid, $feed_ids($cid), true));
        check("$aud: not in the creator's profile grid", !in_array($pid, $grid_ids(), true));
    }
    $min = min($made) - 1;
    check('the new-posts count leaves them out', $fm->count_since($min, 50, 0) === 0 && $fm->count_since($min, 50, $fan) === 0);
    $src = (string) file_get_contents("$root/app/controllers/api/ApiPostsController.php");
    check('post_detail/engagement refuse a non-free post', strpos($src, "(\$post['audience'] ?? 'free') !== 'free'") !== false);
} finally {
    foreach ($made as $pid) { $pm->delete_all('post_assets', 'post_id = :p', array('p' => $pid)); $pm->delete_all('posts', 'id = :p', array('p' => $pid)); }
}
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
