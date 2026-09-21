<?php
/**
 * Blocking: model-level checks against the dev DB (creator #1 "admin", fan #27 "test").
 *   APPLICATION_ENV=development php tests/blocks_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$creator = 1; $fan = 27;
$blocks  = new BlocksModel();
$follows = new FollowsModel();
$msgs    = new MessagesModel();
$feed    = new FeedModel();
$search  = new SearchModel();
$aud     = new AudienceModel();

// Clean slate, then a relationship so messaging is allowed.
$blocks->remove_block($creator, $fan); $blocks->remove_block($fan, $creator);
$follows->follow($fan, $creator);

check('either_blocked false at start',            !$blocks->either_blocked($creator, $fan));
check('can_message fan→creator (following)',      $msgs->can_message($fan, $creator));
$names = array_map(function ($r) { return $r['handle']; }, $search->creators('adm', 6, $fan));
check('search creators shows admin to fan',       in_array('admin', $names, true));
$aud_ids = array_map(function ($r) { return (int) $r['id']; }, $aud->list_for_creator($creator));
check('audience lists the fan (follower)',        in_array($fan, $aud_ids, true));

// Creator blocks the fan.
$blocks->add_block($creator, $fan);
check('either_blocked true after creator blocks', $blocks->either_blocked($creator, $fan) && $blocks->either_blocked($fan, $creator));
check('related_ids includes each other',          isset($blocks->related_ids($creator)[$fan]) && isset($blocks->related_ids($fan)[$creator]));
check('can_message fan→creator now false',        !$msgs->can_message($fan, $creator));
check('can_message creator→fan now false',        !$msgs->can_message($creator, $fan));
$names = array_map(function ($r) { return $r['handle']; }, $search->creators('adm', 6, $fan));
check('search creators hides admin from fan',     !in_array('admin', $names, true));
$names = array_map(function ($r) { return $r['handle']; }, $search->creators('adm', 6, 0));
check('search creators still shows admin to guests', in_array('admin', $names, true));
$aud_ids = array_map(function ($r) { return (int) $r['id']; }, $aud->list_for_creator($creator));
check('audience hides the blocked fan',           !in_array($fan, $aud_ids, true));
// Queries with the block clause must at least execute (no placeholder / SQL errors).
check('feed recent() runs with viewer',           is_array($feed->recent(5, 0, $fan)));
check('feed count_since() runs with viewer',      is_int($feed->count_since(1, 50, $fan)));
check('search posts() runs with viewer',          is_array($search->posts('a', true, 6, $fan)));
check('inbox_rows() runs',                        is_array($msgs->inbox_rows($fan)));
check('connections() runs',                       is_array($msgs->connections($fan)));
$conn = array_map(function ($r) { return (int) $r['id']; }, $msgs->connections($fan));
check('connections hides the creator from fan',   !in_array($creator, $conn, true));

// Unblock restores everything.
$blocks->remove_block($creator, $fan);
check('either_blocked false after unblock',       !$blocks->either_blocked($creator, $fan));
check('can_message restored',                     $msgs->can_message($fan, $creator));
$follows->unfollow($fan, $creator);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
