<?php
/**
 * VideoTools: probing, frame extraction, joining clips and audio, against clips ffmpeg makes on the spot.
 * No DB, no network. Skips (exit 0) when ffmpeg is not installed.
 *   APPLICATION_ENV=development php tests/ai_video_tools_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $extra === '' ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }

if (!VideoTools::available()) { echo "SKIP ffmpeg is not installed\n"; exit(0); }
$ffmpeg = MediaService::bin('ffmpeg');
$dir = sys_get_temp_dir() . '/vt_' . bin2hex(random_bytes(4));
mkdir($dir);
$mk = function ($name, $args) use ($ffmpeg, $dir) {
    $out = $dir . '/' . $name;
    MediaService::run_with_timeout(escapeshellarg($ffmpeg) . ' -y -loglevel error ' . $args . ' ' . escapeshellarg($out), 60);
    return $out;
};
$a = $mk('a.mp4', '-f lavfi -i testsrc=duration=4:size=540x960:rate=30 -f lavfi -i sine=frequency=440:duration=4 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest');   // 9:16 with sound
$b = $mk('b.mp4', '-f lavfi -i testsrc2=duration=2:size=640x480:rate=24 -c:v libx264 -pix_fmt yuv420p');                                                            // 4:3, silent
$m1 = $mk('one.mp3', '-f lavfi -i sine=frequency=300:duration=1.5 -c:a libmp3lame');
$m2 = $mk('two.mp3', '-f lavfi -i sine=frequency=600:duration=2 -c:a libmp3lame');
$w  = $mk('w.wav', '-f lavfi -i sine=frequency=300:duration=1');

$p = VideoTools::probe($a);
check('probe reads size, length, frame rate and sound', $p['width'] === 540 && $p['height'] === 960 && abs($p['duration'] - 4) < 0.2 && abs($p['fps'] - 30) < 0.1 && $p['has_video'] && $p['has_audio'], json_encode($p));
check('a 540x960 clip reads as 9:16',                   Aspect::matches($p['width'], $p['height'], '9:16') && Aspect::nearest($p['width'], $p['height']) === '9:16');
$pb = VideoTools::probe($b);
check('probe sees a silent clip as silent',             $pb['has_video'] && !$pb['has_audio']);
check('probe of a missing file is all zeros',           VideoTools::probe($dir . '/nope.mp4')['duration'] === 0.0);
$pm = VideoTools::probe($m1);
check('probe reads an audio file',                      !$pm['has_video'] && $pm['has_audio'] && abs($pm['duration'] - 1.5) < 0.2);

$f0 = VideoTools::frame_at($a, 0);
$f2 = VideoTools::frame_at($a, 2.5);
$fe = VideoTools::frame_at($a, 99);
$i0 = $f0 !== '' ? @getimagesize($f0) : false;
check('frame 0 is a JPEG at the clip size',             $i0 && $i0[0] === 540 && $i0[1] === 960 && $i0[2] === IMAGETYPE_JPEG);
check('a later frame differs from frame 0',             $f2 !== '' && md5_file($f2) !== md5_file($f0));
check('a time past the end gives the last frame',       $fe !== '' && filesize($fe) > 0);
$frames = VideoTools::sample_frames($a, 8);
check('sample_frames spreads frames over the clip',     count($frames) === 2 && count(array_unique(array_map('md5_file', $frames))) === 2, (string) count($frames));

$j = VideoTools::concat(array($a, $b));
$pj = VideoTools::probe($j);
check('clips of different shapes join into one',        $j !== '' && abs($pj['duration'] - 6) < 0.4 && $pj['width'] === 540 && $pj['height'] === 960 && $pj['has_audio'], json_encode($pj));
check('joining nothing returns nothing',                VideoTools::concat(array()) === '');
$ja = VideoTools::concat_audio(array($m1, $m2));
check('audio takes join end to end',                    $ja !== '' && abs(VideoTools::probe($ja)['duration'] - 3.5) < 0.3);

check('MP3 is recognised',                              (MediaIngestService::verify_audio_file($m1)['ext'] ?? '') === 'mp3');
check('WAV is recognised',                              (MediaIngestService::verify_audio_file($w)['ext'] ?? '') === 'wav');
check('a video is not accepted as audio',               MediaIngestService::verify_audio_file($b) === null && MediaIngestService::verify_audio_file($a) === null);
check('a JPEG is not accepted as audio',                MediaIngestService::verify_audio_file($f0) === null);
check('file_hash is a SHA-256',                         strlen(VideoTools::file_hash($a)) === 64 && VideoTools::file_hash($a) === hash_file('sha256', $a));

foreach (array_merge(array($f0, $f2, $fe, $j, $ja), $frames, glob($dir . '/*')) as $t) { if ($t !== '' && is_file($t)) { @unlink($t); } }
@rmdir($dir);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
