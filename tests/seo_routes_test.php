<?php
$base  = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$paths = array('/features', '/pricing', '/compare/fanvue', '/compare/onlyfans', '/best-creator-monetization-platforms', '/monetize-your-content');
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
$ch = curl_init($base . '/compare/nope'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo ($code === 404 ? 'ok   ' : 'FAIL ') . "/compare/nope → 404 ($code)\n"; if ($code !== 404) { $fail++; }

foreach (array('/features/extra', '/compare/FANVUE') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo ($code === 404 ? 'ok   ' : 'FAIL ') . "$p → 404 ($code)\n"; if ($code !== 404) { $fail++; }
}
exit($fail ? 1 : 0);
