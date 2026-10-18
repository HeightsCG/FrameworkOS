<?php
/**
 * "Download All" on /purchases streams its zip (MediaService::stream_zip, audit 2026-10-18). Builds zips from local files
 * through a fake fetcher (no S3), plain and with zip64 forced, and checks them with ZipArchive and `unzip -t`.
 */
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }

$dir = sys_get_temp_dir() . '/zipstream_test_' . getmypid();
@mkdir($dir);
$src = array(
    'k/a.jpg' => random_bytes(300000),
    'k/b.mp4' => random_bytes(1200000),
    'k/c.jpg' => str_repeat('same name ', 1000),
);
foreach ($src as $k => $bytes) { file_put_contents($dir . '/' . md5($k), $bytes); }
$fetch = function ($key, $dest) use ($dir) { $f = $dir . '/' . md5($key); return is_file($f) && copy($f, $dest); };
$assets = array(
    array('id' => 1, 'type' => 'image', 'original_key' => 'k/a.jpg', 'display_name' => 'Beach Day.jpg'),
    array('id' => 2, 'type' => 'video', 'original_key' => 'k/b.mp4', 'display_name' => 'Clip — één.mp4'),
    array('id' => 3, 'type' => 'image', 'original_key' => 'k/c.jpg', 'display_name' => 'Beach Day.jpg'),   // same name: gets " (2)"
    array('id' => 4, 'type' => 'image', 'original_key' => 'k/missing.jpg', 'display_name' => 'gone.jpg'),   // fetch fails: skipped
);
$expect = array('Beach Day.jpg' => $src['k/a.jpg'], 'Clip — één.mp4' => $src['k/b.mp4'], 'Beach Day (2).jpg' => $src['k/c.jpg']);

foreach (array('plain' => 0xFFFFFFFF, 'zip64 forced' => 1000) as $label => $limit) {
    MediaService::$zip64_at = $limit;
    $path = $dir . '/out.zip';
    $out = fopen($path, 'wb');
    $n = MediaService::stream_zip($assets, 'original', 'x.zip', $out, $fetch);
    fclose($out);
    check("$label: three files streamed, the missing one skipped", $n === 3);
    $z = new ZipArchive();
    check("$label: ZipArchive opens it", $z->open($path, ZipArchive::CHECKCONS) === true);
    $names = array(); $same = true;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i); $names[] = $st['name'];
        if (!isset($expect[$st['name']]) || $z->getFromIndex($i) !== $expect[$st['name']]) { $same = false; }
    }
    $z->close();
    check("$label: names (UTF-8, de-duplicated) and contents match", $same && count($names) === 3 && !array_diff(array_keys($expect), $names));
    exec('unzip -tq ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
    check("$label: unzip -t passes (" . trim(implode(' ', $o)) . ')', $rc === 0);
    $o = array();
    @unlink($path);
}
MediaService::$zip64_at = 0xFFFFFFFF;

// nothing fetchable: nothing sent, 0 back (the controller then sends the buyer back to /purchases)
$out = fopen('php://memory', 'w+b');
check('no fetchable file: returns 0 and writes nothing', MediaService::stream_zip(array($assets[3]), 'original', 'x.zip', $out, $fetch) === 0 && ftell($out) === 0);
fclose($out);

foreach (glob($dir . '/*') as $f) { @unlink($f); }
@rmdir($dir);
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
