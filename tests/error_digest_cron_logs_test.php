<?php
/**
 * The daily error digest reads the cron logs too (audit 2026-10-18): error lines only, from where the last run stopped,
 * nothing older than 24 h, its own log left out, and a log over 20 MB rotated. Runs cron/error_digest.php --no-mail
 * against a temp directory; nothing is emailed.
 */
$root = dirname(__DIR__);
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }
$dir = sys_get_temp_dir() . '/digest_test_' . getmypid();
@mkdir($dir);
file_put_contents("$dir/web.txt", '');   // an empty web log (not *.log, so it isn't taken for a cron log)
$iso = function ($ago) { return gmdate('Y-m-d\TH:i:s+00:00', time() - $ago); };
file_put_contents("$dir/cls-queue.log", implode("\n", array(
    $iso(3 * 86400) . ' job 9 old_job: FAILED long ago',
    $iso(7200) . ' job 1 signup_alert: sent to 1 admin(s) (0.4s)',
    '[s3] delete_key failed: test boom',
    '[influencer prompt] job 5 image prompt="a calm beach"',
    'PHP Warning:  Undefined array key "x" in /a.php on line 3',
    'Stack trace:',
    '#0 /a.php(3): f()',
    $iso(3600) . ' job 2 influencer_job: FAILED test provider down',
)) . "\n");
file_put_contents("$dir/cls-error-digest.log", "[s3] digest own line failed\n");
$run = function () use ($root, $dir) {
    $out = array();
    exec('APPLICATION_ENV=development php ' . escapeshellarg("$root/cron/error_digest.php") . ' --no-mail --log=' . escapeshellarg("$dir/web.txt") . ' --cron-dir=' . escapeshellarg($dir) . ' 2>&1', $out);
    return implode("\n", $out);
};

$o = $run();
check('an error_log() line from a cron log is counted, labelled with its file', strpos($o, '[cron cls-queue.log] [s3] delete_key failed: test boom') !== false);
check('a PHP warning is counted', strpos($o, '[cron cls-queue.log] PHP Warning:  Undefined array key') !== false);
check('a job reporting FAILED is counted without its timestamp', strpos($o, '[cron cls-queue.log] job 2 influencer_job: FAILED test provider down') !== false);
check('a line stamped three days ago is not', strpos($o, 'FAILED long ago') === false);
check('progress and prompt lines are not errors', strpos($o, 'signup_alert') === false && strpos($o, 'calm beach') === false);
check('trace lines are not counted', strpos($o, 'Stack trace') === false && strpos($o, '#0 /a.php') === false);
check("the digest's own log is left out", strpos($o, 'digest own line') === false);

$o = $run();
check('a second run reads only what was added since: nothing', strpos($o, 'no errors in the last 24 h') !== false);

file_put_contents("$dir/cls-queue.log", "[fal] transport: Operation timed out after 1000 ms\n", FILE_APPEND);
$o = $run();
check('a new unstamped error after the last run is counted', strpos($o, '[cron cls-queue.log] [fal] transport: Operation timed out') !== false && strpos($o, 'test boom') === false);

// over 20 MB: copied to .1 and emptied
$fh = fopen("$dir/cls-big.log", 'w'); $line = str_repeat('x', 1023) . "\n"; for ($i = 0; $i < 21 * 1024; $i++) { fwrite($fh, $line); } fclose($fh);
$o = $run();
clearstatcache();
check('a log over 20 MB is rotated', strpos($o, 'rotated ' . "$dir/cls-big.log") !== false && filesize("$dir/cls-big.log") === 0 && filesize("$dir/cls-big.log.1") >= 21 * 1048576);
file_put_contents("$dir/cls-big.log", "[x] after rotation failed\n", FILE_APPEND);
$o = $run();
check('after rotation the emptied log is read from the start', strpos($o, '[cron cls-big.log] [x] after rotation failed') !== false);

foreach (glob("$dir/*") as $f) { @unlink($f); }
@rmdir($dir);
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
