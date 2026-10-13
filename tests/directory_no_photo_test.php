<?php
/** Turning the directory listing on without a profile photo saves the choice (2026-10-13); the creator shows once a photo is added. Restores the row it touches. */
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }
$m = new CreatorProfileModel();
$row = $m->select("SELECT user_id, directory_listed, directory_category FROM creator_profiles WHERE COALESCE(avatar_url, '') = '' ORDER BY user_id DESC LIMIT 1");
if (!$row) { echo "no creator without a photo on dev, skipped\n"; exit(0); }
$uid = (int) $row[0]['user_id']; $was = $row[0];
$cat = array_key_first(DirectoryService::categories());
$r = DirectoryService::save($uid, true, $cat);
check('listing on with no photo is saved, not refused', !empty($r['ok']));
check('the message says what is still needed', strpos((string) $r['message'], 'add a profile photo') !== false);
$now = $m->get_for_user($uid);
check('the choice and the category are stored', !empty($now['directory_listed']) && (string) $now['directory_category'] === $cat);
check('they do not show in the directory without a photo', $m->directory_eligible($uid) === false);
check('no category is still refused', empty(DirectoryService::save($uid, true, 'not-a-category')['ok']));
$m->set_directory($uid, !empty($was['directory_listed']), (string) ($was['directory_category'] ?? ''));
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
