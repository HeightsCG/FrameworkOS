<?php
// one-off: add the alternatives-page link to the two alternative articles, re-render, ping IndexNow. Idempotent.
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$edits = array(
    'onlyfans-alternative-how-to-choose' => array('so you can check the claims yourself.', ' For a wider look at the options, see the list of [OnlyFans alternatives for creators](/onlyfans-alternatives).', '/onlyfans-alternatives'),
    'fanvue-alternative-how-to-compare-and-switch' => array('sets it out with sources rather than adjectives.', ' For the wider field, see the list of [Fanvue alternatives for creators](/fanvue-alternatives).', '/fanvue-alternatives'),
);
$m = new SeoArticlesModel();
foreach ($edits as $slug => $e) {
    $a = $m->get_by_slug($slug, false);
    if (!$a) { echo "missing $slug\n"; continue; }
    $body = (string) $a['body_md'];
    if (strpos($body, '](' . $e[2] . ')') !== false) { echo "already linked $slug\n"; continue; }
    if (substr_count($body, $e[0]) !== 1) { echo "anchor not found once in $slug\n"; continue; }
    $body = str_replace($e[0], $e[0] . $e[1], $body);
    $m->update_fields((int) $a['id'], array('body_md' => $body, 'body_html' => Markdown::render($body, SeoDrafter::allowed_paths())));
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $x) {}
    echo "linked $slug\n";
}
