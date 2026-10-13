<?php
/**
 * Ninth one-time text pass (2026-10-13): thirteen articles called Free "the fan account". They now say what /pricing says:
 * a creator can build and preview a page on Free, and publishing and selling need Creator or Studio.
 * Exact-string edits, pure text, no AI calls. Ends by checking every published article (body and FAQ) against
 * SeoDrafter::plan_sum_errors() and by listing any sentence that still calls Free a fan account.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-13i.php [--dry-run|--apply]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }

// body_md: exact old string => new string
$edits = array(
    'custom-ai-influencer-training-when-to-invest' => array(
        '; the Free tier is a fan account that sells nothing and carries no fee.'
            => '; on Free a creator can build and preview a page, and publishing and selling need Creator or Studio, so Free carries no fee.',
    ),
    'best-ai-tools-consistent-ai-influencer-characters' => array(
        'Free is the fan account, sells nothing and carries no platform fee.'
            => 'On Free a creator can build and preview a page; publishing and selling need Creator or Studio, so Free carries no platform fee.',
    ),
    'how-to-price-a-subscription-tier' => array(
        'The Free plan is a fan login that sells nothing, so no platform fee attaches to one.'
            => 'The Free plan lets a creator build and preview a page, and publishing and selling need Creator or Studio, so no platform fee attaches to Free.',
    ),
    'lora-training-dataset-tips' => array(
        ', and the free tier is a fan account that sells nothing.'
            => ', and the Free tier lets a creator build and preview a page, with publishing and selling on Creator or Studio.',
    ),
    'online-creator-platform' => array(
        'Free accounts belong to fans, cannot sell anything and owe no platform fee at all.'
            => 'A creator can build and preview a page on Free, and publishing and selling need Creator or Studio, so Free owes no platform fee.',
    ),
    'automated-dm-replies-for-creators' => array(
        'Inbox automation and AI replies are missing from the free fan account, which costs nothing and cannot sell.'
            => 'Inbox automation and AI replies are not part of Free, where a creator can build and preview a page; publishing and selling need Creator or Studio.',
    ),
    'how-to-pick-a-creator-monetization-platform' => array(
        'Free is the fan account, sells nothing and carries no platform fee at all.'
            => 'Free is where a creator builds and previews a page; publishing and selling need Creator or Studio, so Free carries no platform fee.',
    ),
    'consistent-ai-character-across-images' => array(
        ', and the free tier is a fan account that cannot sell.'
            => ', and on the Free tier a creator can build and preview a page, with publishing and selling on Creator or Studio.',
    ),
    'ai-chat-for-creators' => array(
        'Free accounts belong to fans, sell nothing and carry no platform fee.'
            => 'Free lets a creator build and preview a page, and publishing and selling need Creator or Studio, so Free carries no platform fee.',
    ),
    'how-to-train-a-lora-character' => array(
        ', the Free account belongs to fans and sells nothing, and your balance'
            => ', the Free account lets a creator build and preview a page while publishing and selling need Creator or Studio, and your balance',
    ),
    'fanvue-alternative' => array(
        'The Free tier costs $0, exists for fan accounts, carries no selling tools and therefore no platform fee.'
            => 'The Free tier costs $0 and lets a creator build and preview a page; publishing and selling need Creator or Studio, so it carries no platform fee.',
        'is absent from the Free fan account,'
            => 'is absent from Free,',
    ),
    'how-to-choose-an-onlyfans-alternative' => array(
        'Free accounts belong to fans, sell nothing and carry no platform share at all.'
            => 'On Free a creator can build and preview a page, and publishing and selling need Creator or Studio, so Free carries no platform share.',
        '$0 for fan accounts'
            => '$0 on Free',
    ),
    'how-to-monetize-content-as-a-creator' => array(
        'The Free account is built for fans, who can buy but cannot sell, so no selling percentage attaches to a fan account.'
            => 'The Free account lets a creator build and preview a page, and publishing and selling need Creator or Studio, so no selling percentage attaches to Free.',
    ),
);
$fields = array();

// every article, body and FAQ answers: the balance is net of the platform's percentage, never "net of the plan fee" (which reads as the plan price)
$global = array('net of your plan fee' => 'net of your plan\'s platform fee', 'net of the plan fee' => 'net of the platform fee');

$articles = new SeoArticlesModel(); $n = 0; $missing = array(); $still = 0;
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($articles->published_markdown() as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    $slug = (string) $a['slug']; $notes = array();
    $body = (string) $a['body_md'];
    foreach ((array) ($edits[$slug] ?? array()) as $old => $new) {
        if (strpos($body, $old) === false) { if (strpos($body, $new) === false) { $missing[] = "/blog/$slug not found: \"" . mb_substr($old, 0, 70) . "\""; } continue; }
        $body = str_replace($old, $new, $body); $n++; $notes[] = '"' . mb_substr($old, 0, 40) . '..."';
    }
    foreach ($global as $old => $new) { if (strpos($body, $old) !== false) { $body = str_replace($old, $new, $body); $n++; $notes[] = '"' . $old . '"'; } }
    $set = array();
    $faq_raw = (string) ($a['faq'] ?? ''); $fq = json_decode($faq_raw, true); $faq_text = '';
    if (is_array($fq)) {
        $fq_new = $fq;
        foreach ($fq_new as $i => $f) { if (is_array($f) && isset($f['a'])) { $fq_new[$i]['a'] = str_replace(array_keys($global), array_values($global), (string) $f['a']); } }
        if ($fq_new !== $fq) { $set['faq'] = json_encode($fq_new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $n++; $notes[] = 'faq'; }
        foreach ($fq_new as $f) { if (is_array($f)) { $faq_text .= "\n" . (string) ($f['q'] ?? '') . "\n" . (string) ($f['a'] ?? ''); } }
    }
    foreach ((array) ($fields[$slug] ?? array()) as $f => $map) {
        $v = (string) ($a[$f] ?? '');
        foreach ($map as $old => $new) {
            if (strpos($v, $old) === false) { if (strpos($v, $new) === false) { $missing[] = "/blog/$slug $f not found: \"" . mb_substr($old, 0, 70) . "\""; } continue; }
            $v = str_replace($old, $new, $v); $n++; $notes[] = $f;
        }
        if ($v !== (string) ($a[$f] ?? '')) { $set[$f] = $v; }
    }
    if ($body !== (string) $a['body_md']) { $set['body_md'] = $body; $set['body_html'] = SeoDrafter::render_body($body); }
    // the closing check runs on the text as it will read after this pass
    $now = array_merge($a, $set);
    $plain = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $now['title'] . "\n" . $now['meta_description'] . "\n" . $now['excerpt'] . "\n" . $now['body_md'] . $faq_text);
    foreach (preg_split('/(?<=[.!?])\s+|\n+/', $plain) as $s) { if (SeoDrafter::plan_sum_errors($s)) { echo "STILL: /blog/$slug: " . trim($s) . "\n"; $still++; } }
    foreach (preg_split('/(?<=[.!?])\s+|\n+/', $plain) as $fs) { if (preg_match('/\bfree\b[^.]{0,60}\bfan (?:account|login)s?\b|\bfan (?:account|login)s?\b[^.]{0,60}\bfree\b|Free accounts belong to fans|built for fans/i', $fs)) { echo "FREE WORDING LEFT: /blog/$slug: " . trim($fs) . "\n"; } }
    if (empty($set)) { continue; }
    echo date('c'), " /blog/$slug: ", implode('; ', $notes), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix9-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], $set);
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nedits applied: $n, sentences still breaking the fee rule: $still\n"; foreach ($missing as $x) { echo "NOTE: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
