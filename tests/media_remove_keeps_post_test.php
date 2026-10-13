<?php
/**
 * A post's image is part of the post (2026-10-13): removing a file from the library or an influencer gallery while a post
 * uses it only hides it from the library, and the post keeps showing it. A file no post uses is deleted as before. When the
 * last post that used a hidden file is deleted, the file is deleted too. Runs on real rows it creates and removes.
 */
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }
$mm = new MediaAssetsModel(); $pm = new PostsModel();
$src = $mm->select("SELECT ma.* FROM media_assets ma JOIN user_accounts ua ON ua.user_id = ma.creator_id WHERE ma.deleted_at IS NULL AND ma.status = 'ready' AND ma.type = 'image' ORDER BY ma.id DESC LIMIT 1");
if (!$src) { echo "no ready image on dev, skipped\n"; exit(0); }
$src = $src[0]; $cid = (int) $src['creator_id'];
$copy = function () use ($mm, $src) { $row = $src; unset($row['id']); $row['library_hidden_at'] = null; $row['deleted_at'] = null; $row['filename'] = 'test-keep-' . bin2hex(random_bytes(4)) . '.jpg'; return (int) $mm->insert('media_assets', $row); };
$a1 = $copy(); $a2 = $copy();
$pid = (int) $pm->insert('posts', array('creator_id' => $cid, 'caption' => 'test: file removal keeps the post image', 'state' => 'draft', 'audience' => 'free', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')));
$pm->set_assets($cid, $pid, array($a1));
$in_library = function ($id) use ($mm, $cid) { foreach ((array) $mm->get_for_creator($cid, array()) as $r) { if ((int) $r['id'] === (int) $id) { return true; } } return false; };
$row = function ($id) use ($mm) { $r = $mm->select('SELECT deleted_at, library_hidden_at FROM media_assets WHERE id = :i', array('i' => (int) $id)); return $r[0]; };
$post_assets = function () use ($pm, $pid) { return array_map('intval', array_column((array) $pm->select("SELECT pa.asset_id FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id WHERE pa.post_id = :p AND ma.deleted_at IS NULL", array('p' => $pid)), 'asset_id')); };
check('both files start in the library', $in_library($a1) && $in_library($a2));
$r = $mm->remove_from_library($cid, $a1);
check('removing a file a post uses reports the post', $r['hidden'] === true && (int) $r['posts'] === 1);
check('it is gone from the library', !$in_library($a1));
check('it is not deleted', $row($a1)['deleted_at'] === null && $row($a1)['library_hidden_at'] !== null);
check('the post still has its image', $post_assets() === array($a1));
$p = $pm->get_one($cid, $pid);
check('the post did not change state and is not marked missing', $p && (string) $p['state'] === 'draft' && empty($p['media_missing']));
$r2 = $mm->remove_from_library($cid, $a2);
check('removing a file no post uses deletes it', $r2['hidden'] === false && $row($a2)['deleted_at'] !== null);
$pm->delete_post($cid, $pid);
check('deleting the post releases the hidden file', $row($a1)['deleted_at'] !== null);
// clean up the test rows
$mm->delete_all('media_assets', 'id IN (' . (int) $a1 . ',' . (int) $a2 . ')', array());
check('no delete path bypasses the rule', strpos((string) file_get_contents("$root/app/controllers/api/ApiMediaController.php"), '->detach_asset(') === false
    && strpos((string) file_get_contents("$root/libs/Classes/InfluencerActions.php"), 'remove_from_library') !== false
    && strpos((string) file_get_contents("$root/libs/Classes/McpTools.php"), 'remove_from_library') !== false);
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
