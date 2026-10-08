<?php
// Feature-template pages (FeaturePages::PAGES under /features, FeaturePages::ROOT at the root) and /compare/eromify:
// 200, one H1, title <= 65 and description <= 160 chars, self canonical, FAQPage JSON-LD that parses, no dashes;
// the renamed page 301s; /features and the sitemap list every page.
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$fail = 0; function check($l, $c) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $l . "\n"; if (!$c) { $fail++; } }
function fetch($u) { $ch = curl_init($u); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15)); $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $r = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch); return array($c, $b, $r); }
$paths = array('/features/ai-influencer', '/features/dm-agent', '/features/payouts', '/features/memberships', '/features/pay-per-view', '/features/link-in-bio',
    '/features/publishing', '/features/services-and-events', '/features/custom-domains', '/lora-character-training', '/consistent-ai-model-face', '/ai-ofm-tools', '/compare/eromify');
foreach ($paths as $p) {
    list($c, $b) = fetch($base . $p);
    $title = preg_match('/<title>([^<]*)<\/title>/', $b, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
    $desc  = preg_match('/<meta name="description" content="([^"]*)"/', $b, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $b, $m); $types = array(); $parsed = true;
    foreach ($m[1] as $j) { $o = json_decode($j, true); if (!is_array($o)) { $parsed = false; continue; } $types[] = (string) ($o['@type'] ?? ''); }
    check("$p 200, one H1", $c === 200 && preg_match_all('/<h1[\s>]/', $b) === 1);
    check("$p title " . mb_strlen($title) . " <= 65, description " . mb_strlen($desc) . " <= 160", $title !== '' && mb_strlen($title) <= 65 && $desc !== '' && mb_strlen($desc) <= 160);
    check("$p canonical", strpos($b, 'rel="canonical" href="' . $base . $p . '"') !== false && strpos($b, 'noindex') === false);
    check("$p FAQPage JSON-LD parses", $parsed && in_array('FAQPage', $types, true));
    check("$p no em or en dash", !preg_match('/\x{2013}|\x{2014}/u', strip_tags(preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $b))));
}
list($c, $b, $r) = fetch($base . '/features/character-generation');
check('/features/character-generation 301 to /features/ai-influencer', $c === 301 && $r === $base . '/features/ai-influencer');
foreach (array('/lora-character-training/x', '/consistent-ai-model-face/x', '/features/character-generation/x', '/compare/Eromify') as $p) { list($c) = fetch($base . $p); check("$p 404", $c === 404); }
list($c, $b) = fetch($base . '/features');
list($sc, $sitemap) = fetch($base . '/sitemap.xml');
foreach ($paths as $p) {
    if (strpos($p, '/compare/') !== 0) { check("/features links $p", strpos($b, 'href="' . $p . '"') !== false); }
    check("sitemap has $p", strpos($sitemap, '<loc>' . $base . $p . '</loc>') !== false);
}
check('sitemap drops /features/character-generation', strpos($sitemap, '/features/character-generation') === false);
exit($fail ? 1 : 0);
