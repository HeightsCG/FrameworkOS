<?php
// Every chart or diagram <img> on the public pages points at a file that exists in the repo (public/images/charts, generated on dev by
// cron/build_charts.php and shipped with the deploy) and serves as SVG. Pages never write these files at runtime.
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$root = dirname(__DIR__);
$pages = array('/compare/fanvue', '/compare/onlyfans', '/compare/patreon', '/compare/fansly', '/compare/kofi', '/compare/linktree', '/compare/beacons', '/compare/stan', '/compare/eromify',
               '/onlyfans-alternatives', '/fanvue-alternatives', '/patreon-alternatives', '/linktree-alternatives', '/best-creator-monetization-platforms', '/monetize-your-content', '/pricing', '/features');
$fail = 0;
foreach ($pages as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60)); $html = (string) curl_exec($ch); curl_close($ch);
    preg_match('#<main[^>]*>(.*?)</main>#s', $html, $mm); $main = $mm[1] ?? $html;
    preg_match_all('#<img[^>]*alt="([^"]{41,})"[^>]*>#', $main, $imgs);
    $ok = count($imgs[0]) > 0;
    preg_match_all('#/images/charts/([a-z0-9-]+\.svg)#', $main, $m);
    foreach (array_unique($m[1]) as $f) {
        if (!is_file($root . '/public/images/charts/' . $f)) { $ok = false; echo "   missing file: $f\n"; continue; }
        $ch = curl_init($base . '/images/charts/' . $f); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true)); curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
        if ($code !== 200 || strpos($type, 'image/svg+xml') === false) { $ok = false; echo "   $f -> HTTP $code $type\n"; }
    }
    echo ($ok ? 'ok   ' : 'FAIL ') . "$p: " . count($imgs[0]) . " img with alt > 40 chars, " . count(array_unique($m[1])) . " chart file(s) present\n"; if (!$ok) { $fail++; }
}
echo $fail ? "$fail FAILED\n" : "ALL OK\n";
exit($fail ? 1 : 0);
