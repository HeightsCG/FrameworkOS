<?php
$base  = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$paths = array('/features', '/features/ai-influencer', '/features/dm-agent', '/features/payouts', '/pricing', '/compare/fanvue', '/compare/onlyfans', '/compare/patreon', '/compare/fansly', '/compare/kofi', '/compare/linktree', '/compare/beacons', '/compare/stan', '/best-creator-monetization-platforms', '/monetize-your-content', '/about', '/contact');
$paths = array_merge($paths, array('/onlyfans-alternatives', '/fanvue-alternatives'));
$paths = array_merge($paths, array('/founding'));
$paths = array_merge($paths, array('/affiliates'));
$paths = array_merge($paths, array('/tools/fan-questions', '/tools/ai-influencer-persona'));
$paths = array_merge($paths, array('/author/dr-glauber'));   // the author page behind every article byline (2026-10-12)
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
// Founding offer: FAQPage parses, the spots counter is on the page, nothing answers under it.
$ch = curl_init($base . '/founding'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $body, $m); $types = array();
foreach ($m[1] as $j) { $o = json_decode($j, true); if (is_array($o)) { $types[] = (string) ($o['@type'] ?? ''); } }
$ok = in_array('FAQPage', $types, true) && count($types) === count($m[1]) && strpos($body, 'Spots left') === false && strpos($body, 'founding=1') !== false && !preg_match('/[\x{2013}\x{2014}]/u', strip_tags($body));
echo ($ok ? 'ok   ' : 'FAIL ') . "/founding FAQPage parse + counter + claim link\n"; if (!$ok) { $fail++; }
$ch = curl_init($base . '/founding/x'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo ($code === 404 ? 'ok   ' : 'FAIL ') . "/founding/x → 404 ($code)\n"; if ($code !== 404) { $fail++; }
// /affiliates: FAQPage JSON-LD parses, the apply link, no dashes; /affiliates/x is a 404; the app pages send signed-out visitors to sign up
$ch = curl_init($base . '/affiliates'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m); $types = array();
foreach ($m[1] as $j) { $d = json_decode($j, true); if (is_array($d)) { foreach ((isset($d['@type']) ? array($d) : $d) as $b) { $types[] = $b['@type'] ?? ''; } } }
$ok = in_array('FAQPage', $types, true) && strpos($body, 'next=%2Faffiliates%2Fapply') !== false && !preg_match('/[\x{2013}\x{2014}]/u', strip_tags($body)) && stripos($body, 'stripe') === false;
echo ($ok ? 'ok   ' : 'FAIL ') . "/affiliates FAQPage parse + apply link\n"; if (!$ok) { $fail++; }
$ch = curl_init($base . '/affiliates/x'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo ($code === 404 ? 'ok   ' : 'FAIL ') . "/affiliates/x → 404 ($code)\n"; if ($code !== 404) { $fail++; }
foreach (array('/affiliates/apply', '/affiliates/dashboard') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false)); curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    $ok = $code === 302 && strpos($loc, 'next=') !== false;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$p signed out → sign up ($code)\n"; if (!$ok) { $fail++; }
}
// Free tools: FAQPage + WebApplication markup parse; one URL per tool (no /tools index, no hyphenless twin).
foreach (array('/tools/fan-questions', '/tools/ai-influencer-persona') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $body, $m); $types = array();
    foreach ($m[1] as $j) { $o = json_decode($j, true); if (is_array($o)) { $types[] = (string) ($o['@type'] ?? ''); } }
    $ok = in_array('FAQPage', $types, true) && in_array('WebApplication', $types, true) && count($types) === count($m[1]) && strpos($body, 'href="' . $base . $p . '"') !== false;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$p FAQPage + WebApplication parse\n"; if (!$ok) { $fail++; }
}
foreach (array('/tools', '/tools/fanquestions', '/tools/fan-questions/x', '/tools/nope') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo ($code === 404 ? 'ok   ' : 'FAIL ') . "$p → 404 ($code)\n"; if ($code !== 404) { $fail++; }
}
// Author page (2026-10-12): ProfilePage markup, the name, no first name anywhere; /author/<other> is a 404; the name appears only on the allowed pages.
$ch = curl_init($base . '/author/dr-glauber'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
$ok = strpos($body, '"@type":["ProfilePage","WebPage"]') !== false && strpos($body, 'Dr. Glauber') !== false && strpos($body, 'Cybersecurity in the Age of AI') !== false && stripos($body, 'daniel') === false && strpos($body, '<article') === false;
echo ($ok ? 'ok   ' : 'FAIL ') . "/author/dr-glauber ProfilePage + bio, no first name, no <article>\n"; if (!$ok) { $fail++; }
$ch = curl_init($base . '/author/x'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo ($code === 404 ? 'ok   ' : 'FAIL ') . "/author/x → 404 ($code)\n"; if ($code !== 404) { $fail++; }
// The first name is in no public file either (a code comment in a stylesheet or an inline script is public), and a signed-out
// reader of a public page gets no session cookie.
foreach (array('/css/sx.css', '/css/public.css', '/css/landing.css', '/js/landing.js') as $asset) {
    $ch = curl_init($base . $asset); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
    $ok = $body !== '' && stripos($body, 'daniel') === false && stripos($body, 'glauber') === false;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$asset carries no name\n"; if (!$ok) { $fail++; }
}
foreach (array('/', '/pricing', '/compare/fanvue', '/blog') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true)); $h = (string) curl_exec($ch); curl_close($ch);
    $ok = stripos($h, 'Set-Cookie:') === false && stripos($h, 'no-store') === false;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$p sets no cookie for a signed-out reader and is cacheable\n"; if (!$ok) { $fail++; }
}
foreach (array('/', '/features', '/pricing', '/compare/fanvue', '/blog') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
    $ok = strpos($body, 'Glauber') === false && stripos($body, 'daniel') === false && strpos($body, '/author/') === false && ($p === '/blog' || strpos($body, '<article') === false);
    echo ($ok ? 'ok   ' : 'FAIL ') . "$p carries no author name, no author link, no <article>\n"; if (!$ok) { $fail++; }
}
$ch = curl_init($base . '/about'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $body = (string) curl_exec($ch); curl_close($ch);
$ok = substr_count(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $body)), 'Dr. Glauber') === 1 && stripos($body, 'daniel') === false;   // scripts out first: a '<' inside the TikTok pixel confuses strip_tags
echo ($ok ? 'ok   ' : 'FAIL ') . "/about names Dr. Glauber exactly once\n"; if (!$ok) { $fail++; }
// Link-earning pages (2026-10-15): /integrations + ten platforms, three browser-only tools, /press, /alternatives.
// Each answers 200 with one title, description, canonical, og and twitter set, JSON-LD that parses, no founder name, no
// platform outside the ten; every public_pages() row is in the sitemap with a title and description no other page has.
$new = array('/integrations', '/press', '/alternatives', '/tools/ai-influencer-name-generator', '/tools/ai-influencer-bio-generator', '/tools/ai-disclosure-caption-checker');
foreach (array('linkedin', 'bluesky', 'x', 'facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'pinterest', 'fanvue') as $i) { $new[] = '/integrations/' . $i; }
$ch = curl_init($base . '/sitemap.xml'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $sitemap = (string) curl_exec($ch); curl_close($ch);
$seen_t = array(); $seen_d = array();
foreach ($new as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15)); $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $one = function ($re) use ($body) { return preg_match_all($re, $body) === 1; };
    $text = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $body));   // what a reader sees
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $body, $m); $parsed = 0; $types = array();
    foreach ($m[1] as $j) { $o = json_decode($j, true); if (is_array($o)) { $parsed++; $types[] = (string) (is_array($o['@type'] ?? '') ? implode(',', $o['@type']) : ($o['@type'] ?? '')); } }
    $ok = $code === 200 && $one('/<title>[^<]{10,70}<\/title>/') && $one('/<meta name="description" content="[^"]{50,170}"/') && $one('/<link rel="canonical" href="' . preg_quote($base . $p, '/') . '"/')
        && $one('/<meta property="og:title"/') && $one('/<meta property="og:description"/') && $one('/<meta property="og:url"/') && $one('/<meta property="og:image"/') && $one('/<meta name="twitter:card"/')
        && count($m[1]) > 0 && $parsed === count($m[1]) && in_array('BreadcrumbList', $types, true) && strpos($body, '<loc>' . $base . $p . '</loc>') === false
        && strpos($sitemap, '<loc>' . $base . $p . '</loc>') !== false
        && stripos($body, 'glauber') === false && stripos($body, 'daniel') === false && !preg_match('/\b(Reddit|Discord|Snapchat|30[ -]day)\b/i', $text);
    if (preg_match('/<details/', $body)) { $ok = $ok && in_array('FAQPage', $types, true); }
    preg_match('/<title>([^<]*)<\/title>/', $body, $t); preg_match('/<meta name="description" content="([^"]*)"/', $body, $d);
    if (isset($seen_t[$t[1] ?? '']) || isset($seen_d[$d[1] ?? ''])) { $ok = false; }
    $seen_t[$t[1] ?? ''] = 1; $seen_d[$d[1] ?? ''] = 1;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$p meta, og, twitter, JSON-LD, sitemap, no founder name ($code)\n"; if (!$ok) { $fail++; }
}
foreach (array('/integrations/nope', '/integrations/x/y', '/press/x', '/alternatives/x', '/tools/ai-influencer-name-generator/x') as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo ($code === 404 ? 'ok   ' : 'FAIL ') . "$p → 404 ($code)\n"; if ($code !== 404) { $fail++; }
}
// Every sitemap URL: a unique title and description (the whole public set, not only the new pages).
preg_match_all('/<loc>([^<]+)<\/loc>/', $sitemap, $locs); $titles = array(); $descs = array(); $dupes = array();
foreach ($locs[1] as $u) {
    if (strpos($u, '/blog/') !== false || strpos($u, '/@') !== false) { continue; }   // articles and profiles carry their own meta
    $ch = curl_init($u); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15)); $body = (string) curl_exec($ch); curl_close($ch);
    preg_match('/<title>([^<]*)<\/title>/', $body, $t); preg_match('/<meta name="description" content="([^"]*)"/', $body, $d);
    $tt = $t[1] ?? ''; $dd = $d[1] ?? '';
    if ($tt === '' || $dd === '' || isset($titles[$tt]) || isset($descs[$dd])) { $dupes[] = $u; }
    $titles[$tt] = 1; $descs[$dd] = 1;
}
echo (empty($dupes) ? 'ok   ' : 'FAIL ') . count($locs[1]) . " sitemap URLs, unique titles and descriptions" . (empty($dupes) ? '' : ': ' . implode(', ', $dupes)) . "\n"; if (!empty($dupes)) { $fail++; }
// The browser-only tools and the embed send nothing anywhere: no fetch, XHR or beacon in either script.
foreach (array('/js/free-tools.js', '/js/embed.js') as $asset) {
    $ch = curl_init($base . $asset); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $js = (string) curl_exec($ch); curl_close($ch);
    $ok = $js !== '' && !preg_match('/\b(fetch\(|XMLHttpRequest|sendBeacon|\$\.ajax|ApiDataSvc)/', $js) && stripos($js, 'daniel') === false;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$asset makes no network call\n"; if (!$ok) { $fail++; }
}
exit($fail ? 1 : 0);
