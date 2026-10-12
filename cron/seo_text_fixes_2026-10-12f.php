<?php
/**
 * Sixth one-time text pass (SEO audit 2026-10-12, cannibalization): five article titles are reworded so each article and its
 * hub page aim at different searches. The list pages keep "X alternatives"; the articles become the "how to choose and move"
 * pieces. Also corrects two links that still said "seven steps" for an article that has five.
 * URLs do not change. Exact-string edits, pure text, no AI calls. A title is changed only while it still reads as listed here.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-12f.php [--dry-run|--apply]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }

// slug => array(current title, new title): 60 characters at most, sentence case
$titles = array(
    'fanvue-alternative'                    => array('Fanvue alternative: pick a route, then move',        'Switching from Fanvue: pick a route, then move'),
    'how-to-choose-an-onlyfans-alternative' => array('OnlyFans alternative: three routes compared',        'How to choose an OnlyFans alternative, then move'),
    'how-to-monetize-content-as-a-creator'  => array('How to monetize content as a creator',               'Monetizing content as a creator: one worked month'),
    'consistent-ai-character-across-images' => array('Character consistency techniques: a glossary entry', 'Consistent AI character across images, explained'),
    'how-to-train-a-lora-character'         => array('How to train a LoRA character that holds',           'LoRA character training: how to train one that holds'),
);
// body_md: exact old string => new string
$edits = array(
    'how-to-monetize-content-as-a-creator'  => array('seven platform steps' => 'five platform steps'),
    'how-to-choose-an-onlyfans-alternative' => array('seven ordered steps' => 'five ordered steps'),
);

$articles = new SeoArticlesModel(); $n = 0; $missing = array();
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($articles->published_markdown() as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    $slug = (string) $a['slug']; $set = array(); $notes = array();
    if (isset($titles[$slug])) {
        if ((string) $a['title'] === $titles[$slug][0]) {
            if (mb_strlen($titles[$slug][1]) > 60) { $missing[] = "/blog/$slug new title is over 60 characters"; }
            else { $set['title'] = $titles[$slug][1]; $n++; $notes[] = 'title'; }
        } elseif ((string) $a['title'] !== $titles[$slug][1]) { $missing[] = "/blog/$slug title is now \"" . $a['title'] . "\", left alone"; }
    }
    $body = (string) $a['body_md'];
    foreach ((array) ($edits[$slug] ?? array()) as $old => $new) {
        if (strpos($body, $old) === false) { if (strpos($body, $new) === false) { $missing[] = "/blog/$slug not found: \"$old\""; } continue; }
        $body = str_replace($old, $new, $body); $n++; $notes[] = '"' . $old . '"';
    }
    if ($body !== (string) $a['body_md']) { $set['body_md'] = $body; $set['body_html'] = SeoDrafter::render_body($body); }
    if (empty($set)) { continue; }
    echo date('c'), " /blog/$slug: ", implode('; ', $notes), (isset($set['title']) ? ' -> "' . $set['title'] . '"' : ''), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix6-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], $set);
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nedits applied: $n\n"; foreach ($missing as $x) { echo "NOTE: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
