<?php
/**
 * Daily error digest: reads the last 24 hours of the PHP/Apache error log, groups identical messages and emails
 * every admin the 30 most frequent. Nothing is sent when the log has no errors for the day.
 * Log path: app.ini `error_log_path` (current environment section, optional), else php.ini error_log, else
 * /var/log/apache2/error.log. Only the last 64 MB of each file is read. logrotate runs at about 06:25, before this
 * job, so the rotated <log>.1 is read too when its mtime falls inside the 24 h window.
 *
 * Cron logs too (audit 2026-10-18): CLI error_log() and PHP errors go to stderr, which the crontab sends to /tmp/cls-*.log
 * (and cron/*.log). Those lines carry no timestamp, so each log is read from where the last run stopped (byte offset in
 * a small state file next to the logs); a line stamped older than 24 h is still skipped. Only error-looking lines count,
 * grouped as "[cron <file>] message". A cron log over 20 MB is copied to <log>.1 and emptied after it is read
 * (copy-truncate: a job still writing with >> just carries on), so they no longer grow forever.
 *
 *   ... --log=<path>       read this file instead (testing)
 *   ... --cron-dir=<dir>   read <dir>/*.log as the cron logs instead (testing; the state file goes there too)
 *   ... --dry-run          print the digest, send nothing (and leave offsets and logs alone)
 *   ... --no-mail          print the digest, send nothing, but do move the offsets and rotate (testing)
 *   Prod crontab:
 *   45 7 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/error_digest.php >> /tmp/cls-error-digest.log 2>&1
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$opts = getopt('', array('log::', 'cron-dir::', 'dry-run', 'no-mail'));
$dry  = isset($opts['dry-run']) || isset($opts['no-mail']);
$persist = !isset($opts['dry-run']);   // --no-mail (testing) still moves the cron-log offsets and rotates
if (!$dry) { CronRuns::start('error_digest'); }

$config = Main::get_config();
$path = (string) ($opts['log'] ?? '');
if ($path === '') { $path = trim((string) ($config[Main::get_environment()]['error_log_path'] ?? '')); }
if ($path === '') { $path = (string) ini_get('error_log'); }
if ($path === '' || $path === 'syslog') { $path = '/var/log/apache2/error.log'; }
$web_ok = is_readable($path);
if (!$web_ok) { echo date('c'), " cannot read $path\n"; }   // the cron logs are still read below

// apache stamps lines in the server's local time without a zone; php's own log carries one
$local = 'UTC';
$link  = (string) @readlink('/etc/localtime');
if (preg_match('#zoneinfo/(.+)$#', $link, $mz) && in_array($mz[1], timezone_identifiers_list(), true)) { $local = $mz[1]; }
$local_tz = new DateTimeZone($local);

$since = time() - 86400;
$files = $web_ok ? array($path) : array();
if ($web_ok && is_readable($path . '.1') && filemtime($path . '.1') >= $since) { array_unshift($files, $path . '.1'); }   // rotated this morning
$counts = array(); $total = 0;
foreach ($files as $file) {
    $fh = fopen($file, 'r');
    $size = filesize($file);
    if ($size > 64 * 1048576) { fseek($fh, $size - 64 * 1048576); fgets($fh); }   // skip the partial first line
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line);
        $ts = null; $msg = '';
        if (preg_match('/^\[(\w{3} \w{3} \d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)? (\d{4})\] \[([\w-]*):(\w+)\] (.*)$/', $line, $m)) {
            // apache: [Thu Oct 08 10:59:46.619896 2026] [php:notice] [pid 1] [client 1.2.3.4:5] message, referer: ...
            if (in_array($m[4], array('debug', 'info', 'trace1', 'trace2', 'trace3', 'trace4', 'trace5', 'trace6', 'trace7', 'trace8'), true)) { continue; }
            $d = DateTime::createFromFormat('D M d H:i:s Y', $m[1] . ' ' . $m[2], $local_tz);
            $ts = $d ? $d->getTimestamp() : null;
            $msg = preg_replace(array('/^\[pid \d+(:tid \d+)?\] /', '/^\S+\(\d+\): /', '/\[client [^\]]+\] /', '/, referer: \S*$/'), '', $m[5]);
        } elseif (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}) ([\w\/+-]+)\] (.*)$/', $line, $m)) {
            // php: [08-Oct-2026 14:59:46 UTC] PHP Warning: ...
            $ts = strtotime($m[1] . ' ' . $m[2]) ?: null;
            $msg = $m[3];
        } else {
            continue;   // stack-trace continuation lines and anything unstamped
        }
        if ($ts === null || $ts < $since) { continue; }
        $msg = trim(mb_substr($msg, 0, 300));
        if ($msg === '') { continue; }
        $counts[$msg] = ($counts[$msg] ?? 0) + 1;
        $total++;
    }
    fclose($fh);
}

// ---- cron logs ----
$cron_dir   = (string) ($opts['cron-dir'] ?? '');
$cron_logs  = $cron_dir !== '' ? (array) glob(rtrim($cron_dir, '/') . '/*.log') : array_merge((array) glob('/tmp/cls-*.log'), (array) glob($root . '/cron/*.log'));
$cron_logs  = array_values(array_filter($cron_logs, function ($f) { return is_file($f) && is_readable($f) && !preg_match('/error[-_]digest/', basename($f)); }));
$state_file = ($cron_dir !== '' ? rtrim($cron_dir, '/') : '/tmp') . '/cls-error-digest-offsets.json';
$state      = is_readable($state_file) ? (array) json_decode((string) file_get_contents($state_file), true) : array();
$new_state  = array();
$error_word = '/\b(fatal|error|errors|exception|failed|failure|fails|warning|timed out|could not|cannot|unable to|denied|refused|uncaught)\b/i';
$rotate_at  = 20 * 1048576;
foreach ($cron_logs as $file) {
    clearstatcache(true, $file);
    $st = @stat($file); if (!$st) { continue; }
    $prev = $state[$file] ?? null;
    $from = 0; $first = ($prev === null);
    if ($prev && (int) $prev['ino'] === (int) $st['ino'] && (int) $prev['size'] <= (int) $st['size']) { $from = (int) $prev['size']; }
    if ($first && $st['mtime'] < $since) { $from = (int) $st['size']; }            // untouched for a day: nothing new in it
    if ($st['size'] - $from > 64 * 1048576) { $from = $st['size'] - 64 * 1048576; } // same 64 MB cap as the web log
    $fh = fopen($file, 'r');
    if ($from > 0) { fseek($fh, $from); if ($prev === null || $from !== (int) $prev['size']) { fgets($fh); } }
    $clock = null;   // the last timestamp seen: an unstamped line is about that old
    $label = '[cron ' . basename($file) . '] ';
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line);
        if ($line === '') { continue; }
        $ts = null; $msg = ''; $is_error = false;
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)?)\s+(.*)$/', $line, $m)) {
            // the job's own progress line: 2026-10-18T12:00:00+00:00 job 209 influencer_job: FAILED ...
            $ts = strtotime($m[1]) ?: null; $clock = $ts;
            $msg = $m[2]; $is_error = (bool) preg_match($error_word, $msg);
        } elseif (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}) ([\w\/+-]+)\] (.*)$/', $line, $m)) {
            // php log format, when a script sets its own error_log
            $ts = strtotime($m[1] . ' ' . $m[2]) ?: null; $clock = $ts;
            $msg = $m[3]; $is_error = true;
        } elseif (preg_match('/^(Stack trace:|#\d+ |\s+thrown in )/', $line)) {
            continue;   // trace continuation lines
        } else {
            $ts = $clock; $msg = $line;
            $is_error = (strpos($line, 'PHP ') === 0) || (bool) preg_match($error_word, $line);   // PHP Warning: ... / error_log() lines
        }
        if (!$is_error) { continue; }
        if ($ts !== null && $ts < $since) { continue; }
        if ($ts === null && $first) { continue; }   // first look at this log: an unstamped line could be from any day
        $msg = trim(mb_substr($label . trim($msg), 0, 300));
        $counts[$msg] = ($counts[$msg] ?? 0) + 1;
        $total++;
    }
    $end = ftell($fh);
    fclose($fh);
    $new_state[$file] = array('ino' => (int) $st['ino'], 'size' => (int) $end);
    // size cap: copy to <log>.1 and empty it in place
    if ($persist && $end > $rotate_at) {
        if (@copy($file, $file . '.1') && ($w = @fopen($file, 'r+'))) {
            ftruncate($w, 0); fclose($w);
            clearstatcache(true, $file);
            $new_state[$file]['size'] = (int) @filesize($file);
            echo date('c'), ' rotated ', $file, ' (', round($end / 1048576, 1), " MB)\n";
        } else {
            echo date('c'), " could not rotate $file\n";
        }
    }
}
if ($persist && $cron_logs) { @file_put_contents($state_file, json_encode($new_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }

if ($total === 0) {
    echo date('c'), " no errors in the last 24 h ($path, " . count($cron_logs) . " cron log(s))\n";
    if (!$dry) { CronRuns::finish('error_digest', $web_ok, $web_ok ? 'no errors' : 'cannot read ' . $path); }
    exit($web_ok ? 0 : 1);
}
arsort($counts);
$top = array_slice($counts, 0, 30, true);
$subject = 'Error digest: ' . number_format($total) . ' error line(s), ' . count($counts) . ' distinct, last 24 h';
echo date('c'), ' ', $subject, "\n";
foreach ($top as $msg => $n) { printf("%6d  %s\n", $n, $msg); }
if ($dry) { echo "dry run: nothing sent\n"; exit(0); }

$lines = array();
foreach ($top as $msg => $n) { $lines[] = array($msg, number_format($n) . 'x'); }
$users = new UsersModel();
$mail  = new NotificationsModel();
$sent  = 0;
foreach ($users->admin_ids() as $aid) {
    $ar = $users->get_user_by_id((int) $aid);
    $a  = (is_array($ar) && count($ar) === 1) ? $ar[0] : null;
    if (!$a || (string) ($a['user_email'] ?? '') === '') { continue; }
    $ok = $mail->send_billing_email((string) $a['user_email'], trim((string) $a['first_name'] . ' ' . (string) $a['last_name']), $subject,
        'The most frequent errors in ' . basename($path) . ($cron_logs ? ' and the cron logs' : '') . ' on ' . Main::site_name() . ' over the last 24 hours, by count.', $lines, '',
        'Open Admin', rtrim(Main::get_base_domain(), '/') . '/admin',
        'You get this email because you\'re an admin on ' . Main::site_name() . '.');
    if ($ok) { $sent++; }
}
echo date('c'), " sent to $sent admin(s)\n";
// Nothing went out: say why on the Jobs page (e.g. Postmark refusing a suppressed address), not just "sent to 0"
$why = ($sent === 0 && Notifications::$last_error !== '') ? ': Postmark said ' . mb_substr(Notifications::$last_error, 0, 200) : '';
CronRuns::finish('error_digest', $sent > 0, count($counts) . ' distinct, sent to ' . $sent . $why);
exit($sent > 0 ? 0 : 1);
