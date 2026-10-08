<?php
/**
 * Daily error digest: reads the last 24 hours of the PHP/Apache error log, groups identical messages and emails
 * every admin the 30 most frequent. Nothing is sent when the log has no errors for the day.
 * Log path: app.ini `error_log_path` (current environment section, optional), else php.ini error_log, else
 * /var/log/apache2/error.log. Only the last 64 MB of each file is read. logrotate runs at about 06:25, before this
 * job, so the rotated <log>.1 is read too when its mtime falls inside the 24 h window.
 *
 *   ... --log=<path>   read this file instead (testing)
 *   ... --dry-run      print the digest, send nothing
 *   Prod crontab (after the 03:30 backup):
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
$opts = getopt('', array('log::', 'dry-run'));
$dry  = isset($opts['dry-run']);
if (!$dry) { CronRuns::start('error_digest'); }

$config = Main::get_config();
$path = (string) ($opts['log'] ?? '');
if ($path === '') { $path = trim((string) ($config[Main::get_environment()]['error_log_path'] ?? '')); }
if ($path === '') { $path = (string) ini_get('error_log'); }
if ($path === '' || $path === 'syslog') { $path = '/var/log/apache2/error.log'; }
if (!is_readable($path)) {
    echo date('c'), " cannot read $path\n";
    if (!$dry) { CronRuns::finish('error_digest', false, 'cannot read ' . $path); }
    exit(1);
}

// apache stamps lines in the server's local time without a zone; php's own log carries one
$local = 'UTC';
$link  = (string) @readlink('/etc/localtime');
if (preg_match('#zoneinfo/(.+)$#', $link, $mz) && in_array($mz[1], timezone_identifiers_list(), true)) { $local = $mz[1]; }
$local_tz = new DateTimeZone($local);

$since = time() - 86400;
$files = array($path);
if (is_readable($path . '.1') && filemtime($path . '.1') >= $since) { array_unshift($files, $path . '.1'); }   // rotated this morning
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

if ($total === 0) {
    echo date('c'), " no errors in the last 24 h ($path)\n";
    if (!$dry) { CronRuns::finish('error_digest', true, 'no errors'); }
    exit(0);
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
        'The most frequent errors in ' . basename($path) . ' on ' . Main::site_name() . ' over the last 24 hours, by count.', $lines, '',
        'Open Admin', rtrim(Main::get_base_domain(), '/') . '/admin',
        'You get this email because you\'re an admin on ' . Main::site_name() . '.');
    if ($ok) { $sent++; }
}
echo date('c'), " sent to $sent admin(s)\n";
CronRuns::finish('error_digest', $sent > 0, count($counts) . ' distinct, sent to ' . $sent);
exit($sent > 0 ? 0 : 1);
