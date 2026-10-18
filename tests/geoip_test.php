<?php
/* GeoIp: the visitor IP from X-Forwarded-For (right-most untrusted hop, as Controller::request_ip), private/reserved addresses rejected, no database = '' (Unknown), never an exception. */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 203.0.113.9, 198.51.100.2'; $_SERVER['REMOTE_ADDR'] = '172.31.3.119';
check('right-most untrusted hop in X-Forwarded-For wins (a client cannot prepend one)', GeoIp::client_ip() === '198.51.100.2', GeoIp::client_ip());
$_SERVER['HTTP_X_REAL_IP'] = '1.2.3.4'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4'; $_SERVER['HTTP_CLIENT_IP'] = '1.2.3.4';
check('spoofed X-Real-IP / CF-Connecting-IP / Client-IP are ignored', Controller::request_ip() === '198.51.100.2', Controller::request_ip());
unset($_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CLIENT_IP']);
$_SERVER['REMOTE_ADDR'] = '8.8.4.4'; check('X-Forwarded-For ignored when REMOTE_ADDR is not a trusted proxy', Controller::request_ip() === '8.8.4.4', Controller::request_ip());
$_SERVER['REMOTE_ADDR'] = '172.31.3.119';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.4'; check('private forwarded hop falls back to REMOTE_ADDR, itself private = none', GeoIp::client_ip() === '');
$_SERVER['HTTP_X_FORWARDED_FOR'] = ''; $_SERVER['REMOTE_ADDR'] = '8.8.8.8'; check('public REMOTE_ADDR used when nothing forwarded', GeoIp::client_ip() === '8.8.8.8');
check('reserved and private addresses are not public', !GeoIp::is_public('127.0.0.1') && !GeoIp::is_public('10.1.2.3') && !GeoIp::is_public('') && GeoIp::is_public('1.1.1.1') && GeoIp::is_public('2606:4700::1111'));
check('reader class installed (composer maxmind-db/reader)', class_exists('\\MaxMind\\Db\\Reader'));
if (GeoIp::available()) {
    check('database present: 8.8.8.8 resolves to a country code', preg_match('/^[A-Z]{2}$/', GeoIp::country('8.8.8.8')) === 1, GeoIp::country('8.8.8.8'));
    check('database present: private IP = Unknown', GeoIp::country('10.0.0.1') === '');
} else {
    check('no database file: lookup answers Unknown without an exception', GeoIp::country('8.8.8.8') === '');
    echo "note: data/dbip-country-lite.mmdb is not present; run cron/geoip_refresh.php\n";
}
check('Analytics::country never throws', is_string(Analytics::country()));
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
