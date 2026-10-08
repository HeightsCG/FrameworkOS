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
$targets = array(
    'onlyfans' => array('/onlyfans-alternatives', 'For a wider look at the options, see the list of [OnlyFans alternatives for creators](/onlyfans-alternatives).'),
    'fanvue'   => array('/fanvue-alternatives',   'For the wider field, see the list of [Fanvue alternatives for creators](/fanvue-alternatives).'),
);
$m = new SeoArticlesModel();
foreach ($targets as $word => $t) {
    // the alternative article(s) about this platform, whatever their slug is on this environment
    $hits = array();
    foreach ((array) $m->search($word . ' alternative', 10) as $r) {
        $hay = strtolower((string) $r['slug'] . ' ' . (string) $r['title']);
        if (strpos($hay, $word) !== false && strpos($hay, 'alternative') !== false) { $hits[] = (string) $r['slug']; }
    }
    if (!$hits) { echo "no $word alternative article here\n"; continue; }
    foreach ($hits as $slug) {
        $a = $m->get_by_slug($slug, false);
        if (!$a) { continue; }
        $body = (string) $a['body_md'];
        if (strpos($body, '](' . $t[0] . ')') !== false) { echo "already linked $slug\n"; continue; }
        $body = rtrim($body) . "\n\n" . $t[1] . "\n";   // its own closing paragraph, so no sentence of the article has to match
        $m->update_fields((int) $a['id'], array('body_md' => $body, 'body_html' => Markdown::render($body, SeoDrafter::allowed_paths())));
        try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $x) {}
        echo "linked $slug\n";
    }
}
