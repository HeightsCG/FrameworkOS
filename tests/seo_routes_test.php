<?php
$base  = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$paths = array('/features', '/features/ai-influencer', '/features/dm-agent', '/features/payouts', '/pricing', '/compare/fanvue', '/compare/onlyfans', '/compare/patreon', '/compare/fansly', '/compare/kofi', '/compare/linktree', '/compare/beacons', '/compare/stan', '/best-creator-monetization-platforms', '/monetize-your-content', '/about', '/contact');
$paths = array_merge($paths, array('/onlyfans-alternatives', '/fanvue-alternatives'));
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

foreach (array('/features/extra', '/compare/FANVUE', '/creators/nope', '/creators/fitness/extra', '/creators?page=abc', '/creators?page=99', '/creators?sort=bogus&page=99') as $p) {
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
// An unknown sort falls back to the default order (200, canonical without sort).
$ch = curl_init($base . '/creators?sort=bogus'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$ok = $code === 200 && strpos($body, 'rel="canonical" href="' . $base . '/creators"') !== false;
echo ($ok ? 'ok   ' : 'FAIL ') . "/creators?sort=bogus 200, canonical /creators ($code)\n"; if (!$ok) { $fail++; }
// Alternatives list pages: FAQPage and ItemList markup parse, and nothing else under them answers.
foreach (array('/onlyfans-alternatives', '/fanvue-alternatives') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $body, $m); $types = array();
    foreach ($m[1] as $j) { $o = json_decode($j, true); if (is_array($o)) { $types[] = (string) ($o['@type'] ?? ''); } }
    $ok = in_array('FAQPage', $types, true) && in_array('ItemList', $types, true) && count($types) === count($m[1]);
    echo ($ok ? 'ok   ' : 'FAIL ') . "$p FAQPage + ItemList parse\n"; if (!$ok) { $fail++; }
}
foreach (array('/fanvue-alternatives/extra', '/onlyfans-alternatives/x') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo ($code === 404 ? 'ok   ' : 'FAIL ') . "$p → 404 ($code)\n"; if ($code !== 404) { $fail++; }
}
exit($fail ? 1 : 0);
