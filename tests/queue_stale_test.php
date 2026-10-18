<?php
/**
 * Queue stale-run handling (2026-10-18 audit H6): a run past the cutoff is given back only when its worker process is
 * gone, a live worker keeps it until the hard ceiling, and a worker that lost its run can't finish or requeue it.
 * Inserts throwaway rows in the dev `jobs` table (type 'test_stale') and deletes them at the end.
 *   APPLICATION_ENV=development php tests/queue_stale_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$db = new class extends Model { public function x($s, $p = array()) { return $this->sql($s, $p); } public function rows($s, $p = array()) { return $this->select($s, $p); } };
$jobs = new JobsModel();
$host = substr((string) gethostname(), 0, 50);
$live = $host . ':' . getmypid();
$dead = $host . ':' . 999999;   // no such process
$old  = gmdate('Y-m-d H:i:s', time() - 1200);        // 20 minutes: past the 15-minute cutoff
$ancient = gmdate('Y-m-d H:i:s', time() - 4 * 3600); // past the 3-hour ceiling

function make($db, $locked_by, $locked_at, $attempts = 1){
    $id = (int) (new JobsModel())->add('test_stale', array(), null, gmdate('Y-m-d H:i:s'), 3);
    $db->x("UPDATE jobs SET state = 'running', locked_by = :w, locked_at = :t, attempts = :a WHERE id = :id", array(':w' => $locked_by, ':t' => $locked_at, ':a' => $attempts, ':id' => $id));
    return $id;
}
function state($db, $id){ $r = $db->rows("SELECT state FROM jobs WHERE id = :id", array('id' => $id)); return (string) ($r[0]['state'] ?? ''); }

check('this process counts as alive', JobsModel::worker_alive($live));
check('a missing pid counts as dead', !JobsModel::worker_alive($dead));
check('another host counts as dead', !JobsModel::worker_alive('some-other-host:' . getmypid()));

$a = make($db, $live, $old);
$b = make($db, $dead, $old);
$c = make($db, $live, $ancient);
$d = make($db, $dead, $old, 3);
$q = new DatabaseJobQueue();
$q->release_stale(900);
check('a live worker keeps its long run', state($db, $a) === 'running');
check('a dead worker\'s run goes back to the queue', state($db, $b) === 'queued');
check('a live worker past the ceiling is released anyway', state($db, $c) === 'queued');
check('a dead run with no attempts left is failed', state($db, $d) === 'failed');

// The original worker of $b finishes late: it no longer owns the run, so nothing changes.
$db->x("UPDATE jobs SET state = 'running', locked_by = :w, locked_at = :t WHERE id = :id", array(':w' => 'new-owner:1', ':t' => gmdate('Y-m-d H:i:s'), ':id' => $b));
$q->complete($b, $dead);
check('a worker that lost the run cannot complete it', state($db, $b) === 'running');
$q->fail($b, 'boom', $dead);
check('a worker that lost the run cannot requeue it', state($db, $b) === 'running');
$q->complete($b, 'new-owner:1');
check('the current owner completes it', state($db, $b) === 'done');
$q->fail($b, 'late error', 'new-owner:1');
check('a done job is not put back in the queue', state($db, $b) === 'done');

$db->x("DELETE FROM jobs WHERE type = 'test_stale'");
echo $fail === 0 ? "\nall passed\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
