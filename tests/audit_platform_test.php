<?php
/**
 * Audit 2026-10-18, platform fixes: the Viewer team role reads, deleted accounts free their handle and Google link,
 * free AI runs are one-at-a-time with a daily cap, the queue sweep fails/refunds dead work, jobs are pruned, and the
 * inbox counts and pages correctly. Throwaway users (audit+plat*@example.com) and rows are deleted at the end.
 *   APPLICATION_ENV=development php tests/audit_platform_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$_SESSION = array();
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$db = new class extends Model { public function x($s, $p = array()) { return $this->sql($s, $p); } public function rows($s, $p = array()) { return $this->select($s, $p); } };
$um = new UsersModel();
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$made = array();
function mk_user($tag, $n){
    global $um, $made;
    $id = (int) $um->create_user('auditplat' . $tag . $n, password_hash('x', PASSWORD_DEFAULT), 'Audit', 'Plat' . $n, 'audit+plat' . $tag . $n . '@example.com');
    $made[] = $id; return $id;
}

try {
    /* ---- 1. Viewer role ---- */
    $_SESSION = array('user_id' => 5, 'created_by' => 4, 'team_role' => 'viewer');
    check('viewer passes view', Permissions::team_allows('view'));
    check('viewer still fails content', !Permissions::team_allows('content'));
    check('viewer still fails manage', !Permissions::team_allows('manage'));
    $_SESSION['team_role'] = 'editor';
    check('editor passes view and content', Permissions::team_allows('view') && Permissions::team_allows('content'));
    $_SESSION = array('user_id' => 5);
    check('owner passes view', Permissions::team_allows('view'));
    $src = file_get_contents($root . '/app/controllers/api/ApiMediaController.php') . file_get_contents($root . '/app/controllers/api/ApiCreatorStudioController.php');
    foreach (array('media_list', 'collections_list', 'posts_list', 'scheduler_list', 'post_get') as $fn) {
        check($fn . ' is a view-level read', (bool) preg_match('/function ' . $fn . 'Action\(\)\{\s*\$\w+\s*=\s*\$this->require_creator\(\'view\'/', $src));
    }
    check('media_delete still needs content', (bool) preg_match('/function media_deleteAction\(\)\{\s*\$\w+\s*=\s*\$this->require_creator\(\'content\'/', $src));

    /* ---- 2. deleted accounts free their identifiers ---- */
    $u1 = mk_user($tag, 1);
    $handle = 'auditplat' . $tag . '1';
    $sub = 'sub' . $tag;
    $um->link_google($u1, $sub);
    $um->delete_account($u1, $u1);
    $r = $db->rows("SELECT u_name, google_sub, deleted FROM user_accounts WHERE user_id = :u", array('u' => $u1));
    check('deleted account handle becomes deleted_<id>', ($r[0]['u_name'] ?? '') === 'deleted_' . $u1);
    check('deleted account Google link cleared', isset($r[0]) && array_key_exists('google_sub', $r[0]) && $r[0]['google_sub'] === null);
    $h = $db->rows("SELECT u_name, reserved_until FROM username_history WHERE user_id = :u", array('u' => $u1));
    check('old handle archived, not reserved', count($h) === 1 && $h[0]['u_name'] === $handle && strtotime($h[0]['reserved_until']) <= time());
    $u2 = (int) $um->create_user($handle, 'x', 'Audit', 'Again', 'audit+plat' . $tag . '1@example.com'); $made[] = $u2;
    check('the same handle can sign up again', $u2 > 0);
    $um->link_google($u2, $sub);
    check('the same Google account can link again', ($um->get_by_google_sub($sub)['user_id'] ?? 0) == $u2);
    check('handles starting deleted_ are refused', (new UsernameModel())->validate_format('deleted_99') !== '');
    // team removal frees the member's handle too
    $owner = mk_user($tag, 3); $mem = mk_user($tag, 4);
    $db->x("UPDATE user_accounts SET created_by = :o WHERE user_id = :m", array(':o' => $owner, ':m' => $mem));
    (new TeamModel())->mark_as_member($mem, 'viewer');
    (new TeamModel())->remove($owner, $mem);
    $r = $db->rows("SELECT u_name FROM user_accounts WHERE user_id = :u", array('u' => $mem));
    check('removed team member handle freed', ($r[0]['u_name'] ?? '') === 'deleted_' . $mem);
    $re = (int) $um->create_user('auditplat' . $tag . '4', 'x', 'Audit', 'Reinvite', 'audit+plat' . $tag . '4@example.com', $owner); $made[] = $re;
    check('removed member can be re-invited with the same handle', $re > 0);

    /* ---- 4. free run caps ---- */
    $iid = 990000 + random_int(1, 9999);
    $infl = array('id' => $iid, 'name' => 'Test');
    $jm = new InfluencerJobsModel();
    check('no block before any run', InfluencerActions::free_run_block($infl, 'reference') === '');
    $j1 = $jm->create($owner, $iid, 'reference', array('origin' => 'wizard', 'model_key' => 'x'));
    check('a queued reference blocks another', InfluencerActions::free_run_block($infl, 'reference') !== '');
    check('a queued reference does not block training', InfluencerActions::free_run_block($infl, 'training') === '');
    $db->x("UPDATE influencer_jobs SET status = 'done' WHERE id = :id", array(':id' => $j1));
    check('done reference no longer blocks', InfluencerActions::free_run_block($infl, 'reference') === '');
    for ($i = 1; $i < InfluencerActions::FREE_DAILY['reference']; $i++) { $id = $jm->create($owner, $iid, 'reference', array('origin' => 'wizard', 'model_key' => 'x')); $db->x("UPDATE influencer_jobs SET status = 'failed' WHERE id = :id", array(':id' => $id)); }
    check('daily reference cap reached', strpos(InfluencerActions::free_run_block($infl, 'reference'), 'daily limit') !== false);
    $db->x("UPDATE influencer_jobs SET created_at = :t WHERE influencer_id = :i", array(':t' => gmdate('Y-m-d H:i:s', time() - 90000), ':i' => $iid));
    check('runs older than a day do not count', InfluencerActions::free_run_block($infl, 'reference') === '');
    $size = (int) InfluencerConfig::get('training_set_size', 10);
    for ($i = 0; $i < InfluencerActions::FREE_DAILY['training_set'] - $size + 1; $i++) { $id = $jm->create($owner, $iid, 'training_set', array('origin' => 'wizard', 'model_key' => 'x')); $db->x("UPDATE influencer_jobs SET status = 'done' WHERE id = :id", array(':id' => $id)); }
    check('a full training set over the cap is refused', InfluencerActions::free_run_block($infl, 'training_set', $size) !== '');
    check('a single slot redo still fits', InfluencerActions::free_run_block($infl, 'training_set', 1, false) === '');
    $b = $jm->create($owner, $iid, 'angle', array('origin' => 'wizard', 'model_key' => 'x', 'params' => array('angle' => 'full_front')));
    check('a running body reference blocks another', InfluencerActions::free_run_block($infl, 'body') !== '');
    $db->x("UPDATE influencer_jobs SET status = 'done' WHERE id = :id", array(':id' => $b));
    check('a body reference counts once done', $jm->count_free_runs($iid, 'body', gmdate('Y-m-d H:i:s', time() - 60)) === 1);
    $r = InfluencerTrainingService::start($owner, array('id' => $iid, 'name' => 'Test') + array('pending_model_id' => null), array(), 'wizard');
    check('training start still validates first', empty($r['ok']));
    $db->x("DELETE FROM influencer_jobs WHERE influencer_id = :i", array(':i' => $iid));

    /* ---- 5. sweep ---- */
    $am = new AiCreditsModel();
    $am->apply_delta($owner, 50, 'purchase', 'audit test');
    $before = $am->get_balance($owner);
    $old = date('Y-m-d H:i:s', time() - 7200);
    $dead = $jm->create($owner, $iid, 'image', array('origin' => 'studio', 'model_key' => 'x', 'credits_charged' => 30));
    $db->x("UPDATE influencer_jobs SET status = 'running', deadline_at = :d, updated_at = :u WHERE id = :id", array(':d' => date('Y-m-d H:i:s', time() - 3600), ':u' => $old, ':id' => $dead));
    $orphan = $jm->create($owner, $iid, 'image', array('origin' => 'studio', 'model_key' => 'x'));
    $db->x("UPDATE influencer_jobs SET updated_at = :u WHERE id = :id", array(':u' => $old, ':id' => $orphan));
    $alive = $jm->create($owner, $iid, 'image', array('origin' => 'studio', 'model_key' => 'x'));
    $db->x("UPDATE influencer_jobs SET updated_at = :u WHERE id = :id", array(':u' => $old, ':id' => $alive));
    (new JobsModel())->add('influencer_job', array('job_id' => $alive), 'infl_job:' . $alive . ':1', gmdate('Y-m-d H:i:s', time() + 3600), 3);
    $sw = InfluencerJobService::sweep_orphans(25);
    $st = function ($id) use ($db) { $r = $db->rows("SELECT status FROM influencer_jobs WHERE id = :id", array('id' => $id)); return (string) ($r[0]['status'] ?? ''); };
    check('a job past its deadline is failed', $st($dead) === 'failed');
    check('its credits come back once', $am->get_balance($owner) === $before + 30);
    InfluencerJobService::sweep_orphans(25);
    check('a second sweep refunds nothing more', $am->get_balance($owner) === $before + 30);
    $q = $db->rows("SELECT COUNT(*) AS n FROM jobs WHERE dedupe_key LIKE :k AND state = 'queued'", array('k' => 'infl_job:' . $orphan . ':%'));
    check('an orphan with no queue row is re-dispatched', (int) $q[0]['n'] === 1 && $st($orphan) === 'queued');
    $q = $db->rows("SELECT COUNT(*) AS n FROM jobs WHERE dedupe_key LIKE :k", array('k' => 'infl_job:' . $alive . ':%'));
    check('a job with a live queue row is left alone', (int) $q[0]['n'] === 1);
    $db->x("DELETE FROM jobs WHERE dedupe_key LIKE :a OR dedupe_key LIKE :b", array(':a' => 'infl_job:' . $orphan . ':%', ':b' => 'infl_job:' . $alive . ':%'));
    $db->x("DELETE FROM influencer_jobs WHERE influencer_id = :i", array(':i' => $iid));

    // a Studio video whose job died: failed and refunded once
    $mm = new MediaAssetsModel();
    $vid = (int) $mm->add($owner, 'video', 'Generated · test.mp4', 'video/mp4', 'processing', 'generated');
    $db->x("UPDATE media_assets SET created_at = :t WHERE id = :id", array(':t' => $old, ':id' => $vid));
    $qj = (new JobsModel())->add('media_video', array('creator_id' => $owner, 'asset_id' => $vid, 'credits' => 20), null, gmdate('Y-m-d H:i:s'), 3);
    $db->x("UPDATE jobs SET state = 'running', locked_by = 'gone:1' WHERE id = :id", array(':id' => $qj));
    check('a video with a live job is not swept', MediaVideoJob::sweep_stuck() === 0);
    $db->x("UPDATE jobs SET state = 'failed', locked_by = NULL WHERE id = :id", array(':id' => $qj));
    $b2 = $am->get_balance($owner);
    $n = MediaVideoJob::sweep_stuck();
    $a = $db->rows("SELECT status FROM media_assets WHERE id = :id", array('id' => $vid));
    check('a stuck video is failed', $n >= 1 && ($a[0]['status'] ?? '') === 'failed');
    check('its credits come back', $am->get_balance($owner) === $b2 + 20);
    $db->x("UPDATE media_assets SET status = 'processing' WHERE id = :id", array(':id' => $vid));
    MediaVideoJob::sweep_stuck();
    check('never refunded twice', $am->get_balance($owner) === $b2 + 20);
    $db->x("DELETE FROM media_assets WHERE id = :id", array(':id' => $vid));
    $db->x("DELETE FROM jobs WHERE id = :id", array(':id' => $qj));

    // a clip export stuck rendering
    $ep = (new EditProjectsModel())->create($owner, 'audit');
    $db->x("UPDATE edit_projects SET status = 'rendering', updated_at = :t WHERE id = :id", array(':t' => $old, ':id' => $ep));
    ClipRenderJob::sweep_stuck();
    $p = $db->rows("SELECT status FROM edit_projects WHERE id = :id", array('id' => $ep));
    check('a stuck clip export is failed', ($p[0]['status'] ?? '') === 'failed');
    $db->x("DELETE FROM edit_projects WHERE id = :id", array(':id' => $ep));

    /* ---- 9. jobs prune ---- */
    $pj = (new JobsModel())->add('test_prune', array(), null, gmdate('Y-m-d H:i:s'), 3);
    $db->x("UPDATE jobs SET state = 'done', updated_at = :t WHERE id = :id", array(':t' => gmdate('Y-m-d H:i:s', time() - 15 * 86400), ':id' => $pj));
    $fresh = (new JobsModel())->add('test_prune', array(), null, gmdate('Y-m-d H:i:s'), 3);
    $db->x("UPDATE jobs SET state = 'done' WHERE id = :id", array(':id' => $fresh));
    (new JobsModel())->prune_finished(14, 500);
    check('old finished jobs are pruned', (new JobsModel())->get($pj) === null);
    check('recent ones stay', (new JobsModel())->get($fresh) !== null);
    $db->x("DELETE FROM jobs WHERE type = 'test_prune'");

    /* ---- 10. messaging ---- */
    $fan = mk_user($tag, 5);
    $msg = new MessagesModel();
    $conv = (int) $msg->get_or_create($owner, $fan);
    for ($i = 1; $i <= 7; $i++) { $msg->send($conv, $owner, 'hello ' . $i); }
    check('unread counts every send', $msg->total_unread($fan) === 7);
    $page = $msg->thread($conv, 0, 3);
    check('a thread page is the newest messages, oldest first', count($page) === 3 && $page[2]['body'] === 'hello 7' && $page[0]['body'] === 'hello 5');
    $older = $msg->thread($conv, 0, 3, (int) $page[0]['id']);
    check('the page before it', count($older) === 3 && $older[2]['body'] === 'hello 4');
    $newer = $msg->thread($conv, (int) $page[1]['id']);
    check('only messages after the last one shown', count($newer) === 1 && $newer[0]['body'] === 'hello 7');
    $fan2 = mk_user($tag, 6);
    $conv2 = (int) $msg->get_or_create($owner, $fan2);
    $msg->send($conv2, $owner, 'other');
    $rows = $msg->inbox_rows($owner, 1);
    check('inbox pages one conversation at a time', count($rows) === 1);
    $rows2 = $msg->inbox_rows($owner, 1, array($rows[0]['last_message_at'], (int) $rows[0]['id']));
    check('the next inbox page is the other conversation', count($rows2) === 1 && (int) $rows2[0]['id'] !== (int) $rows[0]['id']);
    check('since returns only conversations with new activity', count($msg->inbox_rows($owner, 0, array(), gmdate('Y-m-d H:i:s', time() + 60))) === 0);
    (new BlocksModel())->add_block($fan, $owner);
    check('a blocked conversation leaves the unread badge', $msg->total_unread($fan) === 0);
    (new BlocksModel())->remove_block($fan, $owner);
    check('unblocking brings it back', $msg->total_unread($fan) === 7);
    $msg->mark_read($conv, $fan);
    check('mark read clears it', $msg->total_unread($fan) === 0);

    /* ---- 3. host after the window ---- */
    check('LiveAccess lets the host past the closed window (code check)', strpos(file_get_contents($root . '/libs/Classes/LiveAccess.php'), "\$phase === 'closed' && !\$host") !== false);

    /* ---- 11. domains ---- */
    $dm = new CreatorDomainsModel();
    $host = 'audit' . $tag . '.example.com';
    $did = $dm->add($owner, $host, 'subdomain', 'tok', true);
    $db->x("UPDATE creator_domains SET date_created = :t WHERE id = :id", array(':t' => date('Y-m-d H:i:s', time() - 8 * 86400), ':id' => $did));
    $dm->expire_unverified(7);
    check('an unverified claim expires after 7 days', !$dm->hostname_taken($host));
    $did2 = $dm->add($owner, $host, 'subdomain', 'tok', true);
    $um->delete_account($owner, $owner);
    check('deleting the account releases its domains', !$dm->hostname_taken($host));
    $db->x("DELETE FROM creator_domains WHERE id IN (" . (int) $did . ',' . (int) $did2 . ")");
} catch (\Throwable $e) {
    check('no exception: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), false);
}

// cleanup
if (!empty($made)) {
    $in = implode(',', array_map('intval', $made));
    $db->x("DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE creator_id IN ($in) OR user_id IN ($in))");
    $db->x("DELETE FROM conversations WHERE creator_id IN ($in) OR user_id IN ($in)");
    $db->x("DELETE FROM user_blocks WHERE user_id IN ($in) OR blocked_user_id IN ($in)");
    $db->x("DELETE FROM ai_credit_transactions WHERE user_id IN ($in)");
    $db->x("DELETE FROM username_history WHERE user_id IN ($in)");
    $db->x("DELETE FROM creator_domains WHERE user_id IN ($in)");
    $db->x("DELETE FROM user_accounts WHERE user_id IN ($in)");
}
echo $fail === 0 ? "\nALL OK\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
