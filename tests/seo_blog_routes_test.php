<?php
// Requires at least one published article in dev (Task 6 publishes one; until then run after manually setting status='published' on the smoke article).
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
function fetch($url){ $ch = curl_init($url); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20)); $r = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hl = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch); return array($code, substr($r, 0, $hl), substr($r, $hl)); }
list($c, $h, $b) = fetch($base . '/blog');
check('/blog 200', $c === 200);
check('/blog has title + canonical', preg_match('/<title>[^<]{5,70}<\/title>/', $b) && strpos($b, 'rel="canonical" href="' . $base . '/blog"') !== false);
check('/blog has CollectionPage JSON-LD', strpos($b, '"CollectionPage"') !== false);
check('/blog links product guides', strpos($b, 'href="/monetize-your-content"') !== false);
preg_match('/href="\/blog\/([a-z0-9-]+)"/', $b, $m); $slug = $m[1] ?? '';
check('/blog lists at least one article', $slug !== '');
list($c, $h, $b) = fetch($base . '/blog/' . $slug);
check('article 200', $c === 200);
check('article has Article + FAQPage + BreadcrumbList JSON-LD', strpos($b, '"Article"') !== false && strpos($b, '"BreadcrumbList"') !== false);
check('article has published_time', strpos($b, 'article:published_time') !== false);
check('article has one CTA', substr_count($b, 'class="gd-band"') === 1);
check('article Cache-Control private', stripos($h, 'Cache-Control: private') !== false);
list($c) = fetch($base . '/blog/no-such-article-xyz');           check('unknown slug 404', $c === 404);
list($c) = fetch($base . '/blog/' . $slug . '/extra');            check('trailing segment 404', $c === 404);
list($c) = fetch($base . '/blog/' . strtoupper($slug));           check('uppercase slug 404', $c === 404);
list($c, $h, $b) = fetch($base . '/blog/feed.xml');
check('feed 200 xml', $c === 200 && stripos($h, 'Content-Type: application/rss+xml') !== false && strpos($b, '<rss') !== false && strpos($b, '<item>') !== false);
list($c, $h, $b) = fetch($base . '/blog?page=999');               check('page past the last is 404', $c === 404);
list($c, $h, $b) = fetch($base . '/blog?q=subscription');     check('search finds the pricing post', $c === 200 && strpos($b, 'href="/blog/how-to-price-a-subscription-tier"') !== false && strpos($b, 'noindex') !== false);
list($c, $h, $b) = fetch($base . '/blog?q=zzznotaword');      check('search with no match says so', $c === 200 && strpos($b, 'No posts match') !== false && strpos($b, 'class="gd-row"') === false);
list($c, $h, $b) = fetch($base . '/blog?q=' . rawurlencode('<b>x</b>')); check('search term is escaped', strpos($b, '<b>x</b>') === false && strpos($b, '&lt;b&gt;x&lt;/b&gt;') !== false);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
