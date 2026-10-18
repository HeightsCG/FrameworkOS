<?php
/**
 * Import a hand-written blog article (e.g. one supplied by HeyCatch) from a file in app/articles/ (.txt: a global gitignore drops .md files from commits) into seo_articles,
 * through the same SeoDrafter::validate() rules as a drafted article. Run on demand, never from cron.
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/import_article.php app/articles/<slug>.txt [--cover] [--publish]
 *
 * File format: "key: value" header lines (title, slug, target_keyword, cluster, meta_description, excerpt; others ignored),
 * a "---" line, then the Markdown body. A trailing "## FAQ" section of "### question" + answer paragraphs becomes the faq field.
 * Creates the article in review (or updates the unpublished one with the same slug) and prints the validator's findings.
 * --cover makes a cover image (one fal render). --publish publishes only when the validator has nothing to say.
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

$file = '';
foreach (array_slice($argv, 1) as $a) { if (substr($a, 0, 2) !== '--') { $file = $a; } }
$cover   = in_array('--cover', $argv, true);
$publish = in_array('--publish', $argv, true);
if ($file !== '' && !is_file($file) && is_file($root . '/' . $file)) { $file = $root . '/' . $file; }
if ($file === '') { fwrite(STDERR, "usage: php cron/import_article.php app/articles/<slug>.txt [--cover] [--publish]\n"); exit(1); }
if (!is_file($file)) { fwrite(STDERR, "file not found: $file (is it committed and deployed?)\n"); exit(1); }

$raw = str_replace("\r\n", "\n", (string) file_get_contents($file));
$parts = preg_split('/^---\s*$/m', $raw, 2);
if (count($parts) !== 2) { fwrite(STDERR, "missing the --- line between the header and the body\n"); exit(1); }
$head = array();
foreach (explode("\n", $parts[0]) as $line) { if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $m)) { $head[$m[1]] = trim($m[2]); } }
$body = trim($parts[1]) . "\n";

// The FAQ section: "## FAQ" then "### question" + answer, to the end of the file.
$faq = array();
if (preg_match('/^## FAQ\s*$/m', $body, $m, PREG_OFFSET_CAPTURE)) {
    $section = substr($body, $m[0][1]);
    $body = rtrim(substr($body, 0, $m[0][1])) . "\n";
    foreach (preg_split('/^### /m', $section) as $i => $chunk) {
        if ($i === 0) { continue; }
        $lines = explode("\n", trim($chunk), 2);
        $faq[] = array('q' => trim($lines[0]), 'a' => trim(preg_replace('/\s+/', ' ', (string) ($lines[1] ?? ''))));
    }
}

$cluster = SeoDrafter::cluster((string) ($head['cluster'] ?? ''));
if ($cluster === '') { fwrite(STDERR, "unknown cluster: " . ($head['cluster'] ?? '') . "\n"); exit(1); }
$keyword = (string) ($head['target_keyword'] ?? '');
$f = array(
    'title' => (string) ($head['title'] ?? ''), 'slug' => SeoDrafter::slugify((string) ($head['slug'] ?? '')),
    'meta_description' => (string) ($head['meta_description'] ?? ''), 'excerpt' => (string) ($head['excerpt'] ?? ''),
    'body_md' => $body, 'faq' => $faq, 'cluster' => $cluster, 'target_keyword' => $keyword,
    'intent' => SeoDrafter::intent_for($keyword, $cluster),
);

$m = new SeoArticlesModel();
$existing = $m->get_by_slug($f['slug'], false);
if ($existing && $existing['status'] === 'published') { fwrite(STDERR, "/blog/{$f['slug']} is already published; unpublish it in Admin to re-import\n"); exit(1); }
$errors = SeoDrafter::validate($f, $existing ? (int) $existing['id'] : 0, true);

$row = array(
    'slug' => $f['slug'], 'title' => $f['title'], 'meta_description' => $f['meta_description'], 'excerpt' => $f['excerpt'],
    'body_md' => $body, 'body_html' => SeoDrafter::render_body($body), 'target_keyword' => $keyword, 'cluster' => $cluster,
    'author' => Main::site_name() . ' team', 'secondary_keywords' => '[]', 'faq' => json_encode($faq, JSON_UNESCAPED_UNICODE),
    'reading_minutes' => SeoDrafter::reading_minutes($body), 'model' => 'import:' . (string) ($head['source'] ?? 'manual'),
);
if ($existing) { $id = (int) $existing['id']; $m->update_fields($id, $row); }
else { $row['status'] = 'review'; $id = $m->create($row); }
echo "article $id /blog/{$f['slug']} (" . Markdown::word_count($body) . " words, " . count($faq) . " FAQ)\n";
foreach ($errors as $e) { echo '  - ' . (SeoDrafter::is_soft($e) ? '[advisory] ' : '') . $e . "\n"; }
$hard = SeoDrafter::split_errors($errors)[0] ?? $errors;
if (empty($errors)) { echo "  validator: clean\n"; }

if ($cover) {
    $a = $m->get($id);
    $url = SeoDrafter::make_cover($a);
    echo $url !== '' ? "  cover: $url\n" : "  cover: failed, try again\n";
}
if ($publish) {
    if (!empty($hard)) { echo "  not published: fix the findings above first\n"; exit(2); }
    $m->set_status($id, 'published');
    try { IndexNow::ping(array('/blog/' . $f['slug'], '/blog')); } catch (\Throwable $e) { error_log('[import_article] indexnow: ' . $e->getMessage()); }
    echo "  published: /blog/{$f['slug']}\n";
}
