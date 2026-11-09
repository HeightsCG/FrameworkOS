<?php
/**
 * "Stop Being a Creator" deletion (CreatorDeletion, 2026-10-18): a throwaway creator with two posts,
 * a file sent in a message, a link and a collection.
 * After the run: what others were sent is kept, everything else is gone.
 * Files use made-up S3 keys (deleting a missing key is harmless).
 * Also checks the job refuses to run for an account that is a creator again.
 *   APPLICATION_ENV=development php tests/creator_deletion_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($extra !== '' && !$ok ? '  -> ' . $extra : '') . "\n"; if (!$ok) { $fail++; } }
$db = new class extends Model { public function x($s, $p = array()) { return $this->sql($s, $p); } public function one($s, $p = array()) { $r = $this->select($s, $p); return $r[0] ?? null; } };
$n = function ($sql, $p = array()) use ($db) { return (int) ($db->one($sql, $p)['n'] ?? 0); };

$users = new UsersModel();
$cid = (int) $users->create_user('auditdel' . mt_rand(10000, 99999), '!no-sign-in', 'Audit', 'Delete', '', 0, 0);
$now = gmdate('Y-m-d H:i:s');
$media = new MediaAssetsModel();
$asset = function ($tag) use ($media, $cid) {
    $id = (int) $media->add($cid, 'image', $tag . '.jpg', 'image/jpeg', 'ready');
    (new class extends Model { public function k($id, $c, $t) { return $this->update('media_assets', array('original_key' => "test/creator_delete/$c/$t.jpg", 'moderation_status' => 'approved'), 'id = :id', array('id' => $id)); } })->k($id, $cid, $tag);
    return $id;
};
// posts
$posts = new PostsModel();
$post_a = (int) $posts->create_draft($cid, 'Post A'); $a_one = $asset('one');
$free_post = (int) $posts->create_draft($cid, 'Free post'); $a_free = $asset('free');
foreach (array(array($post_a, $a_one), array($free_post, $a_free)) as $pa) { $db->x("INSERT INTO post_assets (post_id, asset_id, sort_order, is_cover) VALUES (:p, :a, 0, 1)", array(':p' => $pa[0], ':a' => $pa[1])); }
$a_sent = $asset('sent'); $db->x("INSERT INTO message_assets (message_id, asset_id, sort_order) VALUES (0, :a, 0)", array(':a' => $a_sent));
$a_loose = $asset('loose');
// other things
(new CreatorLinksModel())->add($cid, 'Site', 'https://example.com');
$col = (int) (new CollectionsModel())->add($cid, 'Faves'); $db->x("INSERT INTO collection_assets (collection_id, asset_id, sort_order) VALUES (:c, :a, 0)", array(':c' => $col, ':a' => $a_loose));

// the job refuses while the account is a creator
$db->x("UPDATE user_accounts SET role_id = :r WHERE user_id = :u", array(':r' => (int) $users->get_role_id_by_name('Creator'), ':u' => $cid));
check('the job refuses an account that is a creator again', strpos(CreatorDeleteJob::handle(array('user_id' => $cid)), 'SKIP') === 0 && $n("SELECT COUNT(*) n FROM posts WHERE creator_id = :c", array('c' => $cid)) === 2);
$db->x("UPDATE user_accounts SET role_id = :r WHERE user_id = :u", array(':r' => (int) $users->get_role_id_by_name('User'), ':u' => $cid));

$r = CreatorDeleteJob::handle(array('user_id' => $cid));
check('the job runs for a former creator', strpos($r, 'OK') === 0, $r);
check('every post is deleted', $n("SELECT COUNT(*) n FROM posts WHERE id IN (:p1, :p2)", array('p1' => $post_a, 'p2' => $free_post)) === 0);
check('a file sent in a message is kept', $n("SELECT COUNT(*) n FROM media_assets WHERE id = :a", array('a' => $a_sent)) === 1);
check('other files are deleted for good', $n("SELECT COUNT(*) n FROM media_assets WHERE id IN (:a1, :a2)", array('a1' => $a_free, 'a2' => $a_loose)) === 0 && $n("SELECT COUNT(*) n FROM media_assets WHERE id = :a", array('a' => $a_one)) === 0);
check('links and collections are deleted', $n("SELECT COUNT(*) n FROM creator_links WHERE user_id = :c", array('c' => $cid)) === 0
    && $n("SELECT COUNT(*) n FROM collections WHERE creator_id = :c", array('c' => $cid)) === 0
    && $n("SELECT COUNT(*) n FROM collection_assets WHERE collection_id = :c", array('c' => $col)) === 0);

// clean up what is left
$db->x("DELETE FROM message_assets WHERE message_id = 0 AND asset_id = :a", array(':a' => $a_sent));
$db->x("DELETE FROM post_assets WHERE post_id IN (:p1, :p2)", array(':p1' => $post_a, ':p2' => $free_post));
$db->x("DELETE FROM posts WHERE creator_id = :c", array(':c' => $cid));
$db->x("DELETE FROM media_assets WHERE creator_id = :c", array(':c' => $cid));
$db->x("DELETE FROM refunds WHERE creator_id = :c", array(':c' => $cid));
$db->x("DELETE FROM user_accounts WHERE user_id = :c AND user_email = ''", array(':c' => $cid));
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
