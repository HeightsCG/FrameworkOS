<?php
/**
 * Nightly database backup: mysqldump (--single-transaction --quick --routines) piped through gzip to a temp file,
 * uploaded PRIVATE to S3 at backups/db/<env>/<YYYY-MM-DD>.sql.gz, temp file removed. Prints the size and key.
 * Exits non-zero on any failure (dump, gzip, upload), so cron mail or the Jobs box on /admin shows it.
 * Credentials: db_* of the current app.ini section, passed in a 0600 temp defaults file (never on the command line).
 * Optional app.ini key `mysqldump_path` when mysqldump is not on cron's PATH. Restore: docs/ops.md.
 *
 *   Prod crontab:
 *   30 3 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/db_backup.php >> /tmp/cls-db-backup.log 2>&1
 *
 *   Bucket lifecycle (set once in the S3 console, content-os-bucket > Management > Lifecycle rules):
 *   rule "db-backups-30d", prefix filter backups/db/, expire current versions after 30 days
 *   (and, if versioning is on, permanently delete noncurrent versions after 1 day).
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
if (!CronRuns::lock('db_backup')) { echo date('c'), " another run is active\n"; exit(0); }
CronRuns::start('db_backup');

$env = Main::get_environment();
$cfg = Main::get_config()[$env] ?? array();
$fail = function ($why) {
    echo date('c'), " FAILED: $why\n";
    CronRuns::finish('db_backup', false, $why);
    exit(1);
};
if ((string) ($cfg['db_name'] ?? '') === '' || (string) ($cfg['db_user'] ?? '') === '') { $fail('db_name / db_user missing in app.ini [' . $env . ']'); }
if (!S3Service::configured()) { $fail('S3 is not configured for [' . $env . ']'); }

$tmp = sys_get_temp_dir() . '/cls-db-' . $env . '-' . gmdate('Ymd-His') . '.sql.gz';
$cnf = tempnam(sys_get_temp_dir(), 'cls-my');
chmod($cnf, 0600);
file_put_contents($cnf, "[client]\nuser=\"" . addcslashes((string) $cfg['db_user'], "\"\\") . "\"\npassword=\"" . addcslashes((string) ($cfg['db_pass'] ?? ''), "\"\\") . "\"\nhost=\""
    . addcslashes((string) ($cfg['db_host'] ?? 'localhost'), "\"\\") . "\"\n");
$bin = trim((string) ($cfg['mysqldump_path'] ?? '')) !== '' ? trim((string) $cfg['mysqldump_path']) : 'mysqldump';

// --defaults-extra-file must come first; pipefail so a failed dump is not hidden behind a successful gzip
$cmd = 'set -o pipefail; ' . escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf)
     . ' --single-transaction --quick --routines --no-tablespaces ' . escapeshellarg((string) $cfg['db_name'])
     . ' | gzip -c > ' . escapeshellarg($tmp);
$out = array(); $code = 0;
$t0 = microtime(true);
exec('bash -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $code);
@unlink($cnf);
if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 100) {
    @unlink($tmp);
    $fail('mysqldump exit ' . $code . ': ' . mb_substr(implode(' ', $out), 0, 200));
}
$size = filesize($tmp);

$key = 'backups/db/' . $env . '/' . gmdate('Y-m-d') . '.sql.gz';
$ok  = S3Service::put_private($key, $tmp, 'application/gzip');
@unlink($tmp);
if (!$ok) { $fail('S3 upload failed for ' . $key); }

$mb = number_format($size / 1048576, 2) . ' MB';
printf("%s uploaded %s (%s, dump %.1fs)\n", date('c'), $key, $mb, microtime(true) - $t0);
CronRuns::finish('db_backup', true, $key . ' ' . $mb);
exit(0);
