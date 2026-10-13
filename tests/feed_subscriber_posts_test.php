<?php
/**
 * Home feed (2026-10-13): a subscriber post appears only for a viewer with an active subscription to its creator. Signed-out
 * visitors, other members and the creator themself get free and pay-per-view posts only. Creates and removes its own rows.
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
$pid = (int) $pm->insert('posts', array('creator_id' => $cid, 'caption' => 'test: subscriber post in the feed', 'state' => 'published', 'audience' => 'subscribers', 'on_cls' => 1,
    'published_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')));
if ($aid) { $pm->insert('post_assets', array('post_id' => $pid, 'asset_id' => (int) $aid[0]['asset_id'], 'sort_order' => 0, 'is_cover' => 1)); }
$other = $pm->select('SELECT user_id FROM user_accounts WHERE user_id <> :c AND deleted = 0 ORDER BY user_id ASC LIMIT 2', array('c' => $cid));
$fan = (int) $other[0]['user_id']; $stranger = (int) ($other[1]['user_id'] ?? 0);
$sees = function ($viewer) use ($fm, $pid) { foreach ((array) $fm->recent(60, 0, $viewer) as $r) { if ((int) $r['id'] === $pid) { return true; } } return false; };
$audiences = function ($viewer) use ($fm) { return array_values(array_unique(array_column((array) $fm->recent(60, 0, $viewer), 'audience'))); };
check('a signed-out visitor does not get the subscriber post', !$sees(0));
check('a signed-out visitor gets only free and pay-per-view posts', !array_diff($audiences(0), array('free', 'ppv')));
check('a member who is not subscribed does not get it', !$sees($fan));
check('the creator does not get their own subscriber post on the shared feed', !$sees($cid));
$plan = $pm->select('SELECT id FROM creator_plans WHERE user_id = :c ORDER BY id ASC LIMIT 1', array('c' => $cid));
$sub = (int) $pm->insert('creator_subscriptions', array('subscriber_id' => $fan, 'creator_id' => $cid, 'plan_id' => (int) ($plan[0]['id'] ?? 0), 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')));
check('a subscriber of that creator gets it', $sees($fan));
if ($stranger) { check('another member still does not', !$sees($stranger)); }
check('the new-posts count follows the same rule', $fm->count_since($pid - 1, 50, $fan) >= 1 && $fm->count_since($pid - 1, 50, 0) === 0);
$pm->sql("UPDATE creator_subscriptions SET status = 'canceled' WHERE id = :i", array('i' => $sub));
check('a canceled subscription loses it again', !$sees($fan));
$pm->delete_all('creator_subscriptions', 'id = :i', array('i' => $sub));
$pm->delete_all('post_assets', 'post_id = :p', array('p' => $pid));
$pm->delete_all('posts', 'id = :p', array('p' => $pid));
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
