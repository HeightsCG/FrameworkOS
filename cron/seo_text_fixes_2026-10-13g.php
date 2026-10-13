<?php
/**
 * Seventh one-time text pass (SEO re-audit 2026-10-13): nine articles still folded the platform fee into the plan price, or said when a plan or a training spend "pays off"
 * ("a 10% cut plus a $49 plan takes $145", "the cost of selling that month totals $113.80") and named a sales level where the
 * better plan changes. The fee is stated as a percentage of sales alone; the plan price is a separate monthly line.
 * Exact-string edits, pure text, no AI calls. Ends by checking every published article against SeoDrafter::plan_sum_errors().
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-13g.php [--dry-run|--apply]
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
    'online-creator-platform' => array(
        'Comparing the two only works at a stated sales level, so pick a level and carry the figure through every option.'
            => 'Keep the two on separate lines, then pick a sales level and carry the percentage through every option.',
        'The split matters to you because a cheaper monthly plan suits low volume, while the smaller cut wins once sales are steady, so the right plan changes as the page grows.'
            => 'The percentage is taken from your sales, and the plan price is a separate monthly line that does not move with sales.',
        'A 10% cut plus a $49 plan takes $145 out of that figure, while a 3% cut plus a $199 plan takes $227.80. Five times the volume reverses the answer: at $4,800 gross, the first combination deducts $529 and the second deducts $343.'
            => 'A 10% cut on that figure is $96, and a 3% cut is $28.80. At five times the volume, $4,800 gross, the 10% cut is $480 and the 3% cut is $144. Plan prices are a separate monthly line, listed on the [pricing page](/pricing).',
        'Your own figures come from the same sum, rechecked every quarter instead of once.'
            => 'Your own figures come from the same percentages, rechecked every quarter instead of once.',
        'built from your own monthly sales, minus the cut, minus the plan charge.'
            => 'built from your own monthly sales and the cut, with the plan price noted in its own column.',
        'Apply the new cut to last month\'s real sales, subtract any plan charge, and compare the result'
            => 'Apply the new cut to last month\'s real sales, note any plan charge on its own line, and compare the result',
    ),
    'custom-ai-influencer-training-when-to-invest' => array(
        'Timing the spend decides whether training pays.'
            => 'Timing the spend matters more than the size of it.',
        'A month of sales reaches your balance minus two deductions: a percentage taken from each sale, and the flat monthly plan charge.'
            => 'A month of sales reaches your balance minus one deduction: a percentage taken from each sale.',
        'Creator Link Studio charges by plan, and the two prices pull in opposite directions.'
            => 'Creator Link Studio sets its fee by plan.',
        'The $49 plan charge is billed on its own, so the cost of selling that month totals $113.80, before card processing and before tax.'
            => 'The $49 plan charge is billed on its own, as a separate monthly line, and card processing and tax are separate again.',
    ),
    'how-to-monetize-content-as-a-creator' => array(
        'sales in, platform share out, plan price out, card costs out, tax set aside.'
            => 'sales in, platform share out, card costs out, tax set aside.',
        'The monthly plan charge comes off after the platform share, so $720 becomes $671 for the example month. Nothing about the charge responds to effort: a $100 month and an $800 month are billed the same. Quiet months, not good ones, show you whether a plan is carrying its weight.'
            => 'The monthly plan charge is a separate line and does not come out of the $720. Nothing about the charge responds to effort: a $100 month and an $800 month are billed the same.',
        'The amount reaching the bank in the example is a little under $671,'
            => 'The amount reaching the bank in the example is a little under $720,',
        'Treat the $671 as a figure'
            => 'Treat the $720 as a figure',
    ),
    'fanvue-alternative' => array(
        'Then add the monthly plan charge, because a flat subscription behaves nothing like a percentage once volume climbs.'
            => 'Then note the monthly plan charge on its own line, because a flat subscription is not a percentage of sales.',
        'Plan price plus a share of each sale: 10% on Creator, 3% on Studio'
            => 'A share of each sale: 10% on Creator, 3% on Studio. The plan price is a separate monthly line',
    ),
    'lora-training-dataset-tips' => array(
        'Budget matters because repeated dataset rounds spend credits, and the cut sets how much a finished character has to sell before the work is covered.'
            => 'Budget matters because repeated dataset rounds spend credits.',
        '[when training pays off](' => '[when to invest in training](',
    ),
    'how-to-make-money-with-an-ai-influencer' => array(
        '[when training pays off](' => '[when to invest in training](',
        'minus four deductions' => 'minus three deductions',
        'Deductions are the platform share, the plan charge, card processing and the tax owed at year end. Write all four down'
            => 'Deductions are the platform share, card processing and the tax owed at year end, and the plan charge is a separate monthly line. Write all of them down',
    ),
    'best-ai-tools-consistent-ai-influencer-characters' => array(
        'which is the honest way to see when training pays back.'
            => 'which is the honest way to judge the timing of a training spend.',
    ),
    'automated-dm-replies-for-creators' => array(
        'The numbers matter because the sales your inbox closes have to clear both the plan price and the fee before automation earns a place in your week.'
            => 'The numbers matter because the fee comes out of every sale your inbox closes, and the plan price is a separate monthly line.',
    ),
    'how-to-price-a-subscription-tier' => array(
        'Plan charges buy more than a lower rate, which matters when you divide the monthly cost across your members.'
            => 'Plan charges buy more than a lower rate.',
    ),
);
// other fields: slug => field => array(exact old string => new string)
$fields = array(
    'custom-ai-influencer-training-when-to-invest' => array('excerpt' => array(
        'what the fee and plan charge subtract, and when a trained character is worth paying for.' => 'what the platform fee takes, and how to stage the spend in three steps.')),
    'how-to-choose-an-onlyfans-alternative' => array('excerpt' => array(
        'what each costs after the plan charge' => 'what each keeps from a sale')),
    'how-to-monetize-content-as-a-creator' => array('meta_description' => array(
        'platform share, plan charge, card costs, tax and what reaches the bank.' => 'the platform share, card costs, tax and what reaches the bank.')),
);

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
    $set = array();
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
    $plain = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $now['title'] . "\n" . $now['meta_description'] . "\n" . $now['excerpt'] . "\n" . $now['body_md']);
    foreach (preg_split('/(?<=[.!?])\s+|\n+/', $plain) as $s) { if (SeoDrafter::plan_sum_errors($s)) { echo "STILL: /blog/$slug: " . trim($s) . "\n"; $still++; } }
    if (empty($set)) { continue; }
    echo date('c'), " /blog/$slug: ", implode('; ', $notes), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix7-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], $set);
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nedits applied: $n, sentences still breaking the fee rule: $still\n"; foreach ($missing as $x) { echo "NOTE: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
