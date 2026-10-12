<?php
/**
 * Monthly refresh of the DB-IP "IP to Country Lite" database (data/dbip-country-lite.mmdb) that GeoIp reads for the
 * analytics country column. No account and no key: the current month's file is downloaded from
 * https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.mmdb.gz (the previous month is tried when this month's is not
 * up yet), gunzipped, opened once to prove it reads, then swapped in atomically. If anything fails the existing file
 * stays. Only the file changes; no IP is ever stored. Attribution: "IP geolocation by DB-IP" on /privacy (CC BY 4.0).
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/geoip_refresh.php
 *   Prod crontab (monthly, the 3rd at 04:15 UTC):
 *   15 4 3 * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/geoip_refresh.php >> /tmp/cls-geoip.log 2>&1
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
CronRuns::start('geoip_refresh');
$done = function ($ok, $msg) { echo date('c'), ' ', $msg, "\n"; CronRuns::finish('geoip_refresh', $ok, $msg); exit($ok ? 0 : 1); };
$tmp = sys_get_temp_dir() . '/cls-geoip-' . getmypid(); @mkdir($tmp, 0700, true);
$gz = $tmp . '/dbip-country-lite.mmdb.gz';
$got = ''; $tried = array();
foreach (array(gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))) as $month) {   // this month's file, else last month's
    $url = 'https://download.db-ip.com/free/dbip-country-lite-' . $month . '.mmdb.gz'; $tried[] = $url;
    $fh = fopen($gz, 'wb'); $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'CreatorLinkStudio geoip refresh'));
    $okdl = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
    if ($okdl && $code === 200 && filesize($gz) > 100000) { $got = $url; break; }
    @unlink($gz);
}
if ($got === '') { $done(false, 'download failed (' . implode(', ', $tried) . '); existing file kept (' . (GeoIp::available() ? 'present' : 'MISSING') . ')'); }
$mmdb = $tmp . '/dbip-country-lite.mmdb';
try {
    $in = gzopen($gz, 'rb'); $out = fopen($mmdb, 'wb');
    if (!$in || !$out) { throw new RuntimeException('cannot open the archive'); }
    while (!gzeof($in)) { fwrite($out, gzread($in, 1 << 20)); }
    gzclose($in); fclose($out);
} catch (\Throwable $e) { $done(false, 'gunzip failed: ' . $e->getMessage() . '; existing file kept'); }
try { $r = new \MaxMind\Db\Reader($mmdb); $rec = $r->get('8.8.8.8'); $r->close(); if (empty($rec['country']['iso_code'])) { throw new RuntimeException('test lookup returned no country'); } }
catch (\Throwable $e) { $done(false, 'new file does not read: ' . $e->getMessage() . '; existing file kept'); }
$dest = GeoIp::db_path(); @mkdir(dirname($dest), 0755, true);
if (!@rename($mmdb, $dest . '.new') || !@rename($dest . '.new', $dest)) { $done(false, 'could not move the new file into data/; existing file kept'); }
@chmod($dest, 0644);
array_map('unlink', glob($tmp . '/*') ?: array()); @rmdir($tmp);
$done(true, 'refreshed ' . basename($dest) . ' from ' . basename($got) . ' (' . round(filesize($dest) / 1048576, 1) . ' MB)');
