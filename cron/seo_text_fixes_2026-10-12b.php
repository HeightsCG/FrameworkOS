<?php
/**
 * Second one-time text pass on the published articles (HeyCatch g_self_contained, Daniel's brief 2026-10-12 afternoon). Pure text, no AI calls.
 * The six named articles already meet the pronoun rules on the live HTML (no pronoun in any paragraph's first sentence, never more
 * than two in a paragraph; the script re-measures and prints the counts). What still ties them to the page are references to
 * position, "below", "above", "here", "see the": each is replaced by the thing it points at, by exact string, nothing else changed.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-12b.php [--dry-run|--apply]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }

$edits = array(
    'online-creator-platform' => array(
        'Work the five steps below and the shortlist narrows' => 'Work the five steps in order and the shortlist narrows',
        'Work a hypothetical page to see the crossover.' => 'Work a hypothetical page to find the crossover.',
        'Memberships are billed to a card here, while' => 'Memberships are billed to a card on Creator Link Studio, while',
    ),
    'how-to-train-a-lora-character' => array(
        'Work the checklists below in order' => 'Work the three checklists in order',
    ),
    'how-to-monetize-content-as-a-creator' => array(
        'One hypothetical page below runs every subtraction' => 'One hypothetical page runs every subtraction',
        'Round numbers here were chosen' => 'Round numbers were chosen',
        'Below that line the cheaper subscription wins, above it the lower take rate does.' => 'Under that line the cheaper subscription wins, and over the line the lower take rate does.',
        'because every deduction above is a percentage' => 'because every deduction in the example is a percentage',
    ),
    'how-to-pick-a-creator-monetization-platform' => array(
        'The worked example below follows one hypothetical page' => 'The worked example follows one hypothetical page',
        'then rerun the arithmetic from the stage above.' => 'then rerun the arithmetic from the gross-sales stage.',
    ),
    'how-to-make-money-with-an-ai-influencer' => array(
        'Below are the faults that stall that plan, and the fix for each.' => 'The faults that stall that plan follow, with the fix for each.',
    ),
);
$six = array('best-ai-tools-consistent-ai-influencer-characters', 'online-creator-platform', 'how-to-train-a-lora-character', 'how-to-monetize-content-as-a-creator', 'how-to-pick-a-creator-monetization-platform', 'how-to-make-money-with-an-ai-influencer');
$pron = '/\b(it|its|they|them|their|this|these|those|he|she|such)\b/i';

$articles = new SeoArticlesModel(); $n = 0; $missing = array();
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($articles->published_markdown() as $row) {
    $slug = (string) $row['slug']; if (!in_array($slug, $six, true)) { continue; }
    $a = $articles->get((int) $row['id']); $body = (string) $a['body_md']; $notes = array();
    foreach ((array) ($edits[$slug] ?? array()) as $old => $new) {
        if (strpos($body, $old) === false) { $missing[] = "/blog/$slug not found: \"$old\""; continue; }
        $body = str_replace($old, $new, $body); $notes[] = '"' . mb_substr($old, 0, 34) . '" -> "' . mb_substr($new, 0, 34) . '"'; $n++;
    }
    // measure the pronoun rules on the result: first sentence of every paragraph (and the subtitle) carries none; no paragraph has more than two
    $fail = 0; $paras = 0;
    foreach (array_merge(array((string) $a['excerpt']), preg_split('/\n\s*\n/', preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $body))) as $p) {
        $p = trim($p); if ($p === '' || preg_match('/^(#|[-*+]\s|>|\||`|\d+\.\s)/', $p)) { continue; }
        $paras++; $first = preg_split('/(?<=[.!?])\s+/', $p)[0];
        if (preg_match($pron, $first) || preg_match_all($pron, $p) > 2) { $fail++; echo "  pronoun rule still fails in /blog/$slug: \"" . mb_substr($p, 0, 90) . "\"\n"; }
    }
    echo date('c'), " /blog/$slug: ", ($notes ? implode('; ', $notes) : 'no page references'), " | pronoun rules: $fail of $paras paragraphs fail\n";
    if ($body === (string) $a['body_md'] || !$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix2-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], array('body_md' => $body, 'body_html' => SeoDrafter::render_body($body)));
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nsentences replaced: $n\n"; foreach ($missing as $x) { echo "NOT FOUND: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
