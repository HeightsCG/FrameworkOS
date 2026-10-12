<?php
/**
 * Third one-time text pass (HeyCatch c_paragraph_length + g_self_contained, Daniel's brief 2026-10-12 evening). Pure text, no AI calls.
 *   1. Every published article: any paragraph over SeoDrafter::PARAGRAPH_MAX_SENTENCES (4, counted the audit's way: . ? ! or ")" followed
 *      by a space) is cut at real sentence ends, greedily from the left (SeoDrafter::split_long_paragraphs). A single sentence that still
 *      exceeds the cap on its own (citations in parentheses) is reported, never rewritten.
 *   2. The five named articles: the hand-written edits below, by exact string, so every paragraph names its subject in its first sentence,
 *      holds one specific, points at nothing on the page (below, above, here, earlier, later, previous, next, the following, as mentioned,
 *      the steps, this step) and carries at most two pronouns. Facts, links and meaning unchanged.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-12c.php [--dry-run|--apply]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }

$edits = array(
    'best-ai-tools-consistent-ai-influencer-characters' => array(
        'Test any route with the same twelve-image check before trusting results:' => 'A twelve-image check on any route comes before trusting results:',
        'Four calendar questions sort the routes faster than any feature list:' => 'Four calendar questions sort the three routes faster than any feature list a creator can read:',
        'saves an awkward discovery later (' => 'saves an awkward discovery after launch (',
        'For a direct look at a dedicated AI character generator next to a platform that also sells, the' => 'For a direct look at a dedicated AI character generator beside a platform that also sells, the',
        'Routes also combine. Sketch in a prompt-only tool, train once the look is fixed, and keep a local setup for the rare shot nothing else will produce.' => 'Routes also combine for many creators. Sketch in a prompt-only tool, train a LoRA once the look is fixed, and keep a local setup for the rare shot nothing else will produce.',
    ),
    'online-creator-platform' => array(
        'Work a hypothetical page to find the crossover.' => 'A hypothetical page finds the crossover.',
        'Export the subscriber list and download the media before you cancel anything, since' => 'The subscriber list and the media come out before you cancel anything, since',
        'Write every item you want to sell over the next two quarters on a single page' => 'Write every item you want to sell over the coming two quarters on a single page',
        'and mark everything else as later.' => 'and mark everything else as deferred.',
        'Traffic comes next, from the channels you already post on.' => 'Traffic comes second, from the channels you already post on.',
        'then revisit the choice a quarter later.' => 'then revisit the choice after one quarter.',
    ),
    'how-to-pick-a-creator-monetization-platform' => array(
        'keeps the arithmetic later short enough to finish in one sitting.' => 'keeps the arithmetic short enough to finish in one sitting.',
        'Take rates are the share a platform keeps from each sale, and a lower take rate does not reliably make a platform cheaper.' => 'Take rates are the share a platform keeps from each sale, and a lower take rate does not reliably make a platform cheaper for a creator.',
        'and the one you move to later, and' => 'and the one you move to afterwards, and',
    ),
    'how-to-train-a-lora-character' => array(
        'Counts, shot mix and the deletion pass are worked through in more depth in' => 'Counts, shot mix and the deletion pass for a 20 to 40 image set are worked through in more depth in',
        'describe only what should change later, because anything left unmentioned fuses to the trigger word.' => 'describe only what should change between images, because anything left unmentioned fuses to the trigger word for every LoRA render.',
    ),
    'how-to-make-money-with-an-ai-influencer' => array(
        'Take a hypothetical month built for the arithmetic, not drawn from anyone\'s results:' => 'A hypothetical month built for the arithmetic, not drawn from anyone\'s results, runs as follows:',
        'pictures first, product much later.' => 'pictures first, product long after.',
        'Write four lines before the next batch of images:' => 'Write four lines before another batch of images:',
        'what a buyer gets within the next seven days in exchange for money.' => 'what a buyer gets within seven days in exchange for money.',
        'Anchor images give you something to measure against weeks later, once memory' => 'Anchor images give you something to measure against after several weeks, once memory',
        'card processing and the tax owed later.' => 'card processing and the tax owed at year end.',
    ),
);
$five = array_keys($edits);
$pron = SeoDrafter::PRONOUNS; $back = SeoDrafter::BACK_REFERENCES;

$articles = new SeoArticlesModel(); $split_n = 0; $edit_n = 0; $missing = array(); $over = array();
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($articles->published_markdown() as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    $slug = (string) $a['slug']; $body = (string) $a['body_md']; $notes = array();
    foreach ((array) ($edits[$slug] ?? array()) as $old => $new) {
        if (strpos($body, $old) === false) { $missing[] = "/blog/$slug not found: \"" . mb_substr($old, 0, 70) . "\""; continue; }
        $body = str_replace($old, $new, $body); $edit_n++; $notes[] = 'edit "' . mb_substr($old, 0, 30) . '"';
    }
    $split = SeoDrafter::split_long_paragraphs($body);
    $before = count(array_filter(array_map('trim', preg_split('/\n\s*\n/', $body)))); $after = count(array_filter(array_map('trim', preg_split('/\n\s*\n/', $split))));
    if ($after > $before) { $notes[] = 'split ' . ($after - $before) . ' paragraph(s)'; $split_n += ($after - $before); $body = $split; }
    // measure what is left: cap and the four rules (the rules on the five articles only)
    foreach (preg_split('/\n\s*\n/', $body) as $p) {
        $p = trim($p); if ($p === '' || preg_match('/^(#|[-*+]\s|>|\||`|\d+\.\s)/', $p)) { continue; }
        if (SeoDrafter::conservative_sentence_count($p) > SeoDrafter::PARAGRAPH_MAX_SENTENCES) { $over[] = "/blog/$slug still over the cap (" . SeoDrafter::conservative_sentence_count($p) . "): \"" . mb_substr(preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $p), 0, 80) . "\""; }
        if (in_array($slug, $five, true)) {
            $first = preg_split('/(?<=[.!?])\s+/', preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $p))[0];
            foreach (SeoDrafter::self_contained_errors($p, $first) as $e) { $over[] = "/blog/$slug " . $e; }
            if (preg_match($pron, $first)) { $over[] = "/blog/$slug pronoun in a first sentence: \"" . mb_substr($first, 0, 80) . "\""; }
        }
    }
    if ($body === (string) $a['body_md']) { continue; }
    echo date('c'), " /blog/$slug: ", implode('; ', $notes), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix3-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], array('body_md' => $body, 'body_html' => SeoDrafter::render_body($body)));
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nparagraphs split: $split_n | edits applied: $edit_n\n";
foreach ($missing as $x) { echo "NOT FOUND: $x\n"; }
foreach ($over as $x) { echo "STILL FAILS: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
