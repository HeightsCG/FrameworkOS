<?php
$base  = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$paths = array('/features', '/features/character-generation', '/features/dm-agent', '/features/payouts', '/pricing', '/compare/fanvue', '/compare/onlyfans', '/compare/patreon', '/compare/fansly', '/compare/kofi', '/compare/linktree', '/compare/beacons', '/compare/stan', '/best-creator-monetization-platforms', '/monetize-your-content');
$fail  = 0;
foreach ($paths as $p) {
    $ch = curl_init($base . $p);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15));
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $ok = $code === 200
        && preg_match('/<title>[^<]{10,70}<\/title>/', $body)
        && strpos($body, 'rel="canonical" href="' . $base . $p . '"') !== false
        && strpos($body, 'application/ld+json') !== false
        && strpos($body, 'noindex') === false
        && preg_match('/<h1[^>]*>/', $body);
    echo ($ok ? 'ok   ' : 'FAIL ') . $p . ' (' . $code . ")\n";
    if (!$ok) { $fail++; }
}
$ch = curl_init($base . '/features/nope'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo ($code === 404 ? 'ok   ' : 'FAIL ') . "/features/nope \xe2\x86\x92 404 ($code)\n"; if ($code !== 404) { $fail++; }

$ch = curl_init($base . '/compare/nope'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo ($code === 404 ? 'ok   ' : 'FAIL ') . "/compare/nope → 404 ($code)\n"; if ($code !== 404) { $fail++; }

foreach (array('/features/extra', '/compare/FANVUE', '/creators/nope', '/creators/fitness/extra', '/creators?page=abc', '/creators?page=99') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo ($code === 404 ? 'ok   ' : 'FAIL ') . "$p → 404 ($code)\n"; if ($code !== 404) { $fail++; }
}
// The directory always answers; it is noindex only while nobody is listed (no thin empty page in search).
$ch = curl_init($base . '/creators'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$empty = strpos($body, 'class="dir-card"') === false;
$ok = $code === 200 && ($empty ? strpos($body, 'noindex') !== false : strpos($body, 'noindex') === false);
echo ($ok ? 'ok   ' : 'FAIL ') . "/creators 200, " . ($empty ? 'empty → noindex' : 'listed → indexable') . "\n"; if (!$ok) { $fail++; }
exit($fail ? 1 : 0);
