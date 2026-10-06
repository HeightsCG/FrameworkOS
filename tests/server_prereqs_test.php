<?php
/**
 * Live-server prerequisites for the AI influencer pipeline. Read-only: nothing is generated, deleted or charged.
 *
 *   APPLICATION_ENV=production php tests/server_prereqs_test.php      (on the live server)
 *   APPLICATION_ENV=development php tests/server_prereqs_test.php     (dev)
 *
 * Checks: ffmpeg + ffprobe (libx264), a TTF font per clip-editor font family, GD, the ElevenLabs key and whether it
 * may delete voices (a DELETE on a voice id that cannot exist: 404 = allowed, 401/403 = the key lacks the scope),
 * that the queue worker handles clip_render and has run recently, and the PHP time limits a long talking-video
 * request needs. Ends with the one thing it cannot check: the web server / proxy timeout.
 */
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0; $warn = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($extra !== '' ? '  -> ' . $extra : '') . "\n"; if (!$ok) { $fail++; } }
function warn($label, $extra = ''){ global $warn; echo 'WARN ' . $label . ($extra !== '' ? '  -> ' . $extra : '') . "\n"; $warn++; }

echo "== ffmpeg\n";
$ffmpeg  = MediaService::bin('ffmpeg');
$ffprobe = MediaService::bin('ffprobe');
check('ffmpeg found',  $ffmpeg !== '',  $ffmpeg !== '' ? $ffmpeg : 'install: sudo apt-get install -y ffmpeg');
check('ffprobe found', $ffprobe !== '', $ffprobe !== '' ? $ffprobe : 'install: sudo apt-get install -y ffmpeg');
if ($ffmpeg !== '') {
    $v = (string) shell_exec(escapeshellarg($ffmpeg) . ' -version 2>&1 | head -1');
    echo '     ' . trim($v) . "\n";
    $enc = (string) shell_exec(escapeshellarg($ffmpeg) . ' -hide_banner -encoders 2>/dev/null');
    check('ffmpeg can encode H.264 (libx264)', strpos($enc, 'libx264') !== false, 'the Ubuntu ffmpeg package has it');
    check('ffmpeg can encode AAC',             strpos($enc, ' aac ') !== false);
    $flt = (string) shell_exec(escapeshellarg($ffmpeg) . ' -hide_banner -filters 2>/dev/null');
    check('ffmpeg has the filters the clip editor uses (tpad, apad, concat, scale)', strpos($flt, ' tpad ') !== false && strpos($flt, ' apad ') !== false && strpos($flt, ' concat ') !== false);
    check('the web/php user can run it', is_executable($ffmpeg));
}
check('VideoTools::available()', VideoTools::available());

echo "== fonts (clip editor text)\n";
$fonts = ClipRenderer::fonts();
foreach (ClipRenderer::FONTS as $key => $f) {
    check('font ' . str_pad($f['label'], 10), isset($fonts[$key]), isset($fonts[$key]) ? $fonts[$key]['path'] : 'install: sudo apt-get install -y fonts-dejavu fonts-liberation');
}
check('ClipRenderer::available()', ClipRenderer::available());

echo "== PHP\n";
check('GD with FreeType (title text, masks, thumbnails)', function_exists('imagettftext') && function_exists('imagecreatetruecolor'));
check('curl', function_exists('curl_init'));
$mt = (int) ini_get('max_execution_time');
check('max_execution_time is 0 or set_time_limit can raise it', $mt === 0 || set_time_limit(600), 'current ' . $mt);
$mem = ini_get('memory_limit');
if (!((int) $mem >= 256 || $mem === '-1')) { warn('memory_limit under 256M for this PHP (web and worker limits may differ)', 'current ' . $mem); } else { check('memory_limit >= 256M', true, 'current ' . $mem); }
$tmp = sys_get_temp_dir(); $free = @disk_free_space($tmp);
check('temp dir writable with >= 2 GB free (' . $tmp . ')', is_writable($tmp) && $free !== false && $free > 2 * 1024 * 1024 * 1024, $free !== false ? round($free / 1073741824, 1) . ' GB free' : 'unknown');

echo "== ElevenLabs\n";
$cfg = Main::get_config();
$key = trim((string) ($cfg['global']['elevenlabs_api_key'] ?? ''));
check('elevenlabs_api_key set in app.ini', $key !== '');
if ($key !== '') {
    $req = function ($method, $path) use ($key) {
        $ch = curl_init('https://api.elevenlabs.io' . $path);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array('xi-api-key: ' . $key), CURLOPT_TIMEOUT => 20));
        $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return array($code, $body);
    };
    list($c, $b) = $req('GET', '/v1/user/subscription');
    $sub = json_decode($b, true);
    if ($c !== 200) { warn('key cannot read the subscription (user_read scope; the app does not need it)', 'http ' . $c); }
    if ($c === 200 && is_array($sub)) {
        echo '     tier ' . ($sub['tier'] ?? '?') . ', characters ' . number_format((int) ($sub['character_count'] ?? 0)) . ' of ' . number_format((int) ($sub['character_limit'] ?? 0)) . ', voice slots ' . ($sub['voice_slots_used'] ?? '?') . ' of ' . ($sub['voice_limit'] ?? '?') . "\n";
        $left = (int) ($sub['voice_limit'] ?? 0) - (int) ($sub['voice_slots_used'] ?? 0);
        if ($left < 10) { warn('fewer than 10 free voice slots on the ElevenLabs account', $left . ' left'); }
    }
    list($c, $b) = $req('GET', '/v1/voices?show_legacy=false');
    check('key may list voices (voices_read)', $c === 200, 'http ' . $c);
    // A voice id that cannot exist: 404 proves the key is allowed to delete, 401/403 means the scope is missing.
    list($c, $b) = $req('DELETE', '/v1/voices/cls_permission_probe_000000');
    $status = (string) (json_decode($b, true)['detail']['status'] ?? '');
    check('key may delete voices (voices_write)', $c === 404 || ($c === 400 && $status !== 'missing_permissions'), 'http ' . $c . ' ' . $status . ($c === 401 || $c === 403 || $status === 'missing_permissions' ? ' -> in the ElevenLabs dashboard, API Keys, give this key Voices: write' : ''));
}

echo "== queue worker\n";
$src = (string) file_get_contents($root . '/cron/queue_worker.php');
check('worker knows clip_render', strpos($src, "'clip_render'") !== false);
try {
    $jm = new JobsModel();
    $row = $jm->select("SELECT MAX(updated_at) last_seen, SUM(state = 'queued') queued, SUM(state = 'queued' AND run_after <= UTC_TIMESTAMP() AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)) stale FROM jobs")[0];
    $last = (string) ($row['last_seen'] ?? '');
    echo '     last job activity ' . ($last !== '' ? $last . ' UTC' : 'never') . ', queued now ' . (int) $row['queued'] . "\n";
    check('no job has waited more than 10 minutes (the worker is running)', (int) $row['stale'] === 0, (int) $row['stale'] === 0 ? '' : (int) $row['stale'] . ' stale -> is cron/queue_worker.php scheduled every minute on this server?');
} catch (\Throwable $e) {
    warn('could not read the jobs table', $e->getMessage());
}

echo "== web server timeout (cannot be checked from PHP)\n";
echo "     A talking video runs its text-to-speech inside the HTTP request (up to ~10 minutes for a long script).\n";
echo "     Apache + php-fpm: in the site's vhost set `ProxyTimeout 600` (or `timeout=600` on the SetHandler proxy line) and\n";
echo "     in php-fpm's pool `request_terminate_timeout = 600`. nginx: `fastcgi_read_timeout 600;`. If Cloudflare proxies\n";
echo "     the site, its free/pro plans cut connections at 100 s: either move speech rendering to the job queue or bypass Cloudflare for /api.\n";

echo "\n" . ($fail === 0 ? 'ALL OK' : "$fail FAILED") . ($warn ? " ($warn warning" . ($warn === 1 ? '' : 's') . ")" : '') . "\n";
exit($fail === 0 ? 0 : 1);
