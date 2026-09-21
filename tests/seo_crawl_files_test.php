<?php
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
function get($u) { $ch = curl_init($u); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15)); $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $t = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch); return array($c, $b, $t); }
function head($u) { $ch = curl_init($u); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 15)); $h = (string) curl_exec($ch); curl_close($ch); return $h; }
$fail = 0; function check($l, $c) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $l . "\n"; if (!$c) { $fail++; } }
list($c, $b) = get("$base/sitemap.xml");
foreach (array('/features', '/pricing', '/compare/fanvue', '/compare/onlyfans', '/best-creator-monetization-platforms', '/monetize-your-content') as $p) { check("sitemap has $p", strpos($b, "<loc>$base$p</loc>") !== false); }
list($c, $b) = get("$base/robots.txt");
check('robots allows llms-full', strpos($b, 'Allow: /llms-full.txt') !== false);
check('robots still disallows /admin', strpos($b, 'Disallow: /admin') !== false);
list($c, $b, $t) = get("$base/llms.txt");
check('llms.txt 200 text/plain', $c === 200 && strpos($t, 'text/plain') !== false);
check('llms.txt lists pricing', strpos($b, "$base/pricing") !== false);
check('llms.txt cache-control 3600', stripos(head("$base/llms.txt"), 'Cache-Control: private, max-age=3600') !== false);
list($c, $b, $t) = get("$base/llms-full.txt");
check('llms-full 200 text/plain', $c === 200 && strpos($t, 'text/plain') !== false);
check('llms-full has features heading', strpos($b, '# Features') !== false || strpos($b, '## Features') !== false);
check('llms-full under 2MB', strlen($b) < 2 * 1024 * 1024);
check('llms-full cache-control 3600', stripos(head("$base/llms-full.txt"), 'Cache-Control: private, max-age=3600') !== false);
exit($fail ? 1 : 0);
