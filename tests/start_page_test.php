<?php
/**
 * /start, the TikTok ads landing page (StartPage, 2026-10-16): the brief's copy, the ten platforms and no others, no price,
 * earnings, Stripe or founder name, noindex, Terms and Privacy as the only links out, images that exist with their size
 * set, the hero preloaded, and the in-app browser check that hides Google. Run: php tests/start_page_test.php
 */
$root = dirname(__DIR__);
spl_autoload_register(function ($c) use ($root) { foreach (array("$root/libs/Classes/$c.php", "$root/app/models/$c.php") as $f) { if (is_file($f)) { require_once $f; return; } } });
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$fail = 0; $ok = function ($c, $label) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$c) { $fail++; } };

$ch = curl_init($base . '/start'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $html = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$ok($code === 200, '/start answers 200');
$text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $html)), ENT_QUOTES, 'UTF-8');
$ok(strpos($text, "She's not real. You can make your own.") !== false && substr_count($html, '<h1') === 1, 'one H1 with the brief\'s headline');
$ok(strpos($text, 'Design her face once. Make her photos and videos. Post her everywhere.') !== false, 'the brief\'s subline');
$ok(!StartPage::trial_open() || substr_count($text, 'Build yours free for ' . Founding::TRIAL_DAYS . ' days') === 4, 'CTA on the hero, after the gallery, at the bottom and in the sticky bar (while the trial is open)');
$ok(strpos($html, '<p class="st-fine">Takes about a minute to start.</p>') !== false, 'the line under the hero CTA');
$ok(strpos($text, '1. Describe her') !== false && strpos($text, '2. Lock in her face') !== false && strpos($text, '3. Post her everywhere') !== false, 'the three steps');
$ok(strpos($html, 'class="st-type__text"') !== false && strpos($html, 'src="/landing/face.jpg"') !== false && substr_count($html, 'class="st-logos__i"') === 10, 'each step shows its output: typing prompt, face.jpg, the ten logos');
$ok(strpos($text, 'Same face. Every shot.') !== false, 'gallery title');
foreach (array('Is she really AI?', 'Do I need design skills?', 'Which platforms?', 'Can I cancel anytime?') as $q) { $ok(strpos($text, $q) !== false, 'FAQ: ' . $q); }
preg_match('#<div class="st-step__out st-logos"[^>]*>(.*?)</div>#s', $html, $m); preg_match_all('#title="([^"]+)"#', $m[1] ?? '', $p);
$ok(($p[1] ?? array()) === array('LinkedIn', 'Bluesky', 'X', 'Facebook', 'Instagram', 'Threads', 'TikTok', 'YouTube', 'Pinterest', 'Fanvue'), 'the logos are exactly the ten platforms');
$ok(preg_match('/\$\s?\d/', $text) === 0 && stripos($text, 'per month') === false && stripos($text, 'earn') === false && stripos($text, 'income') === false, 'no price or earnings in the copy');
$ok(stripos($html, 'stripe') === false || stripos($text, 'stripe') === false, 'no Stripe in the copy');
$ok(stripos($text, 'glauber') === false && stripos($text, 'daniel') === false, 'no founder name');
$ok(strpos($html, 'name="robots" content="noindex') !== false, 'noindex');
$ok(strpos($html, 'class="ld-nav') === false && strpos($html, 'pub-foot') === false, 'no site nav or footer');
preg_match_all('~<a [^>]*href="([^"#][^"]*)"~', $html, $links); $out = array_values(array_unique(array_diff($links[1], array('/account/google_start', '/?auth=login'))));
sort($out); $ok($out === array('/privacy', '/terms'), 'the only page links are Terms and Privacy (plus Sign In and Google in the sheet): ' . implode(' ', $out));
preg_match_all('#<img [^>]*>#', $html, $imgs); $all_sized = true; $all_exist = true;
foreach ($imgs[0] as $img) {
    if (!preg_match('#width="\d+"#', $img) || !preg_match('#height="\d+"#', $img)) { $all_sized = false; }
    if (preg_match_all('#/landing/[a-z0-9-]+\.webp#', $img, $f)) { foreach ($f[0] as $u) { if (!is_file("$root/public$u")) { $all_exist = false; echo "     missing $u\n"; } } }
}
$ok($all_sized, 'every image has width and height'); $ok($all_exist, 'every /landing/ image file exists');
$imgs_g = array_filter(StartPage::GALLERY, function ($g) { return $g[2] === 'img'; }); $clips_g = array_filter(StartPage::GALLERY, function ($g) { return $g[2] === 'clip'; });
$ok(count($imgs_g) === 8 && count($clips_g) === 2, 'gallery: gallery-1..8 and 2 clips');
foreach (array_merge(array('hero.mp4', 'hero-sm.mp4', 'hero.jpg', 'face.jpg'), array_map(function ($g) { return $g[0] . ($g[2] === 'clip' ? '.mp4' : '.jpg'); }, StartPage::GALLERY), array('clip-1.jpg', 'clip-2.jpg')) as $f) { if (!is_file("$root/public/landing/$f")) { $ok(false, "missing public/landing/$f"); } }
$ok(filesize("$root/public/landing/hero.mp4") < 2 * 1024 * 1024, 'hero video under 2MB (' . round(filesize("$root/public/landing/hero.mp4") / 1048576, 2) . 'MB)');
preg_match('#<video class="st-hero__video"[^>]*>#', $html, $hv); $hv = $hv[0] ?? '';
$ok(strpos($hv, ' autoplay') !== false && strpos($hv, ' muted') !== false && strpos($hv, ' loop') !== false && strpos($hv, ' playsinline') !== false && strpos($hv, 'poster="/landing/hero.jpg"') !== false
    && strpos($hv, 'data-src-sm="/landing/hero-sm.mp4"') !== false && strpos($hv, 'data-src="/landing/hero.mp4"') !== false, 'hero video: autoplay, muted, loop, playsinline, poster; phone and desktop files attached after first paint');
$ok(preg_match('#rel="preload" as="image" href="/landing/hero\.jpg"[^>]*fetchpriority="high"#', $html) === 1, 'hero poster preloaded');
$ok(preg_match_all('#<video class="st-clip"[^>]*data-src="[^"]+"[^>]*preload="none"#', $html) === 4 && preg_match('#<video class="st-clip"[^>]* src=#', $html) === 0, 'gallery clips load only when on screen (data-src, preload none)');
preg_match_all('#<img [^>]*>#', $html, $all_img); $eager = array_filter($all_img[0], function ($i) { return strpos($i, 'loading="lazy"') === false && strpos($i, 'logo-mark') === false; });
$ok(count($eager) === 0, 'every image below the hero is lazy');
$ok(strpos($html, "track('landing_view'") !== false && strpos($html, "track('cta_click'") !== false && strpos($html, "track('signup_started'") !== false && strpos($html, "track('signup_completed'") !== false, 'GA events wired: landing_view, cta_click, signup_started, signup_completed');
$ok(strpos($html, "tt('ViewContent'") !== false && strpos($html, 'window.CLSTikTokRegistration(o.tt_registration)') !== false && strpos($html, "tt('CompleteRegistration'") === false, 'TikTok ViewContent on load; CompleteRegistration only through the shared server-confirmed helper');

// the in-app check in the page script, against real user agents
preg_match('#var inapp = /(.*?)/i\.test#', $html, $rx); $re = '/' . ($rx[1] ?? 'x^') . '/i';
$uas = array(
    'TikTok iOS'      => array('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_35.1.0 JsSdk/2.0 NetType/WIFI Channel/App Store ByteLocale/en Region/US BytedanceWebview/d8a21c6', true),
    'TikTok Android'  => array('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0 Mobile Safari/537.36 trill_350102 JsSdk/1.0 NetType/WIFI Channel/googleplay AppName/musical_ly app_version/35.1.2', true),
    'Instagram'       => array('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 340.0.0.22.109 (iPhone15,2; iOS 17_5; en_US)', true),
    'Facebook'        => array('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.43.109;FBBV/612345]', true),
    'Safari iPhone'   => array('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', false),
    'Chrome Android'  => array('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', false),
    'Chrome desktop'  => array('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', false),
);
foreach ($uas as $name => $u) { $ok((bool) preg_match($re, $u[0]) === $u[1], 'in-app check: ' . $name . ($u[1] ? ' hides Google' : ' keeps Google')); }

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
