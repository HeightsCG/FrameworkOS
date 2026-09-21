<?php
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
$body = ''; for ($i = 1; $i <= 4; $i++) { $body .= "## Section $i\n\n" . str_repeat('word ', 320) . "See [features](/features).\n\n"; }
$good = array('title' => 'How creators price a membership tier', 'slug' => 'how-creators-price-a-membership-tier',
    'meta_description' => 'A practical guide to pricing membership tiers for creators, with the numbers that matter.',
    'excerpt' => 'Pricing tiers is mostly about the gap between them.', 'body_md' => $body,
    'faq' => array(array('q' => 'One?', 'a' => 'Yes.'), array('q' => 'Two?', 'a' => 'Yes.'), array('q' => 'Three?', 'a' => 'Yes.')),
    'secondary_keywords' => array('membership pricing', 'creator tiers'));
check('good article validates', SeoDrafter::validate($good) === array(), implode('; ', SeoDrafter::validate($good)));
$t = $good; $t['title'] = str_repeat('x', 71);                         check('title > 70 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['meta_description'] = str_repeat('x', 156);              check('meta > 155 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['slug'] = 'Bad Slug!';                                   check('bad slug rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] = "## A\n\nshort";                            check('short body rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](https://example.com)";          check('external link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](/nowhere-at-all)";               check('unknown internal link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nGreat 🚀";                            check('emoji rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nAs an AI language model I think";     check('"As an AI" rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['faq'] = array(array('q' => 'One?', 'a' => 'Yes.'));    check('fewer than 3 faq rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] = "## Only\n\n" . str_repeat('word ', 1300); check('fewer than 3 h2 rejected', SeoDrafter::validate($t) !== array());
check('slugify', SeoDrafter::slugify(" Hello, World! It's 2026 ") === 'hello-world-its-2026');
check('reading_minutes >= 1', SeoDrafter::reading_minutes('one two') === 1 && SeoDrafter::reading_minutes(str_repeat('w ', 900)) === 4);
check('allowed_paths has /features and /pricing', in_array('/features', SeoDrafter::allowed_paths(), true) && in_array('/pricing', SeoDrafter::allowed_paths(), true));
$parsed = SeoDrafter::parse_json("```json\n{\"title\":\"t\"}\n```");   check('parse_json strips fences', is_array($parsed) && $parsed['title'] === 't');
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
