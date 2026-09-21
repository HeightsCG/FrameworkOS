<?php
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });

$fail = 0;
set_error_handler(function ($no, $str, $file, $line) { echo "FAIL PHP warning: $str at $file:$line\n"; $GLOBALS['fail']++; return true; });
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }

$html = SeoMeta::head(array(
    'title' => 'Features', 'description' => 'What the studio does.', 'url' => SeoMeta::base() . '/features',
    'type' => 'product', 'jsonld' => SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Features', 'url' => '/features'))),
));
check('title tag',            strpos($html, '<title>Features · ' ) !== false);
check('description',          strpos($html, '<meta name="description" content="What the studio does.">') !== false);
check('canonical',            strpos($html, '<link rel="canonical" href="' . SeoMeta::base() . '/features">') !== false);
check('no noindex by default', strpos($html, 'noindex') === false);
check('og:type product',      strpos($html, '<meta property="og:type" content="product">') !== false);
check('twitter card',         strpos($html, 'twitter:card') !== false);
check('jsonld breadcrumb',    strpos($html, '"BreadcrumbList"') !== false && strpos($html, 'application/ld+json') !== false);
check('escapes quotes',       strpos(SeoMeta::head(array('title' => 'A "q" & b', 'description' => 'x', 'url' => 'https://x/')), '&quot;q&quot; &amp; b') !== false);
check('noindex when asked',   strpos(SeoMeta::head(array('title' => 't', 'description' => 'd', 'url' => 'https://x/', 'noindex' => true)), 'noindex, follow') !== false);
check('og:type defaults to website', strpos(SeoMeta::head(array('title' => 't', 'description' => 'd', 'url' => 'https://x/')), '<meta property="og:type" content="website">') !== false);

$faq = SeoMeta::faq(array(array('q' => 'Q1?', 'a' => 'A1.')));
check('faq schema', $faq['@type'] === 'FAQPage' && $faq['mainEntity'][0]['acceptedAnswer']['text'] === 'A1.');
check('org has name+url', SeoMeta::org()['@type'] === 'Organization' && !empty(SeoMeta::org()['url']));
check('internal links map', SeoMeta::internal_links()['pricing'] === '/pricing' && SeoMeta::internal_links()['fanvue alternative'] === '/compare/fanvue');

$art = SeoMeta::article(array('headline' => 'H', 'description' => 'D', 'url' => 'https://x/a', 'published' => '2026-09-21T00:00:00+00:00'));
check('article schema', $art['@type'] === 'Article' && $art['datePublished'] === '2026-09-21T00:00:00+00:00' && $art['publisher']['@type'] === 'Organization');
exit($fail ? 1 : 0);
