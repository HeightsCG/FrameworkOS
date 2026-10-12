<?php
/**
 * Fifth one-time text pass (Daniel 2026-10-12, SEO audit of the same day): the platform fee is a percentage of sales and the plan
 * price is a separate monthly line. No article adds a fee amount to a plan price, weighs the gap between plan prices against the
 * fee, or names a sales level where one plan overtakes the other. Script d of this morning left such sums in place (and wrote one);
 * this pass removes them from the body, the meta description and the excerpt of the six affected articles.
 * Also labels the invented yoga creator as an example and drops one unsupported "most creators" claim.
 * Exact-string edits; links and everything else unchanged. Pure text, no AI calls.
 * Ends by checking every published article with SeoDrafter::plan_sum_errors() and printing a STILL line for anything left.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-12e.php [--dry-run|--apply]
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
    'how-to-monetize-content-as-a-creator' => array(
        'At what point does the arithmetic change?' => 'What does each plan keep from the same month?',
        'The arithmetic changes where seven percentage points of saved fee outweigh $150 of extra monthly price, so run both plans against your own sales.'
            => 'Each plan keeps a fixed percentage of your sales, and the monthly plan price is a separate line on your bill. Read the two apart, because one moves with every sale and the other stays where it is.',
        'Run the example month through both. At $800 of sales, the Creator plan costs $80 in share plus $49 in price, so $129. The Studio plan in the same month would cost $24 plus $199, so $223, and no argument is needed.'
            => 'Run the example month through both percentages. At $800 of sales the Creator plan keeps 10%, which is $80, and the Studio plan keeps 3%, which is $24. The plan prices sit apart from those figures as a fixed monthly charge.',
        'At $3,000 of monthly sales the sums are $300 plus $49, which is $349, against $90 plus $199, which is $289. Forty dollars a month at that volume is modest, and the difference widens with every additional sale.'
            => 'At $3,000 of monthly sales the same percentages come to $300 and $90. The share grows with every additional sale, while the plan price does not change.',
    ),
    'how-to-choose-an-onlyfans-alternative' => array(
        'Cost on any route is a share of every sale plus whatever you pay monthly, and the two numbers mean very little read apart. Read both together, then test them against the sales you actually expect.'
            => 'Cost on any route has two parts: a share of every sale and whatever you pay monthly. They are separate lines, so read each one for what it is, then test the share against the sales you actually expect.',
        'Both halves matter to you, because the share and the monthly charge together decide what reaches your balance, and the live figures sit on the'
            => 'Both lines matter to you, and the live figures for each sit on the',
        'The Creator plan deducts $216 as the 10% share, then the $49 plan charge, leaving roughly $1,895 in the balance.'
            => 'The Creator plan keeps $216 of that as its 10% share, so $1,944 reaches the balance.',
        'The Studio plan deducts about $65 as the 3% share, then the $199 plan charge, leaving roughly $1,896. Around a dollar separates the two outcomes at that volume, so included tools, not the percentage, settle the question.'
            => 'The Studio plan keeps about $65 as its 3% share, so roughly $2,095 reaches the balance. The plan price is billed separately each month, on either plan.',
        'The comparison is one sum you run once and rerun as sales change. Divide the $150 gap between the plan charges by the seven-point gap between the shares: the answer sits near $2,143 of monthly sales. Above that level the pricier plan starts covering itself, and below it the cheaper plan holds.'
            => 'The share is the line that moves with your sales, so rerun it whenever your sales change. The plan price is the line that stays fixed, and what it buys is set out below.',
        'Three ways of running that comparison, and the sales level each one suits, sit in this guide to'
            => 'Three ways of running that comparison sit in this guide to',
    ),
    'custom-ai-influencer-training-when-to-invest' => array(
        'Follow one hypothetical page through the arithmetic: sales in, platform fee and plan price out, balance to the bank, and the sales level where the better plan changes.'
            => 'Follow one hypothetical page through the arithmetic: sales in, the platform fee out, and the balance that reaches the bank.',
        'The plan choice comes after the training decision, and the arithmetic behind the choice is simple. A seven-point difference in fee sits against a $150 difference in plan price, so run both plans against your own monthly gross sales. At $2,000 gross, Creator costs $200 in fees plus $49, so $249, while Studio costs $60 plus $199, so $259. At $3,000 gross, Creator costs $349 and Studio costs $289.'
            => 'The plan choice comes after the training decision, and it has two separate lines. The Creator plan keeps 10% of each sale and the Studio plan keeps 3%, so $2,000 of gross sales means $200 or $60 to the platform. The monthly plan price is its own line and does not change with sales.',
        'ten to fifty varied photos, and most creators undercount those hours by half.' => 'ten to fifty varied photos, and those hours are easy to undercount.',
    ),
    'how-to-pick-a-creator-monetization-platform' => array(
        'How do I work out which plan is cheapest for my sales?' => 'How do I read the fee and the plan charge for my sales?',
        'Net arithmetic answers the question directly: take one month of sales, subtract the percentage, subtract the plan charge, read the balance. Twenty minutes with real figures settles a decision that no pricing page can settle, because the answer moves with volume.'
            => 'Net arithmetic answers the question directly: take one month of sales, apply the percentage, and read what reaches the balance. The plan charge is a separate monthly line, so note it beside the result. Twenty minutes with real figures shows more than any pricing page can.',
        'Creator Link Studio shows the trade-off plainly, because the two selling plans sit on opposite sides of the trade-off.'
            => 'Creator Link Studio publishes both lines for each selling plan.',
        'The pairing matters to you because the cheaper plan changes as sales grow, not as your opinion of percentages changes.'
            => 'The percentage matters to you because it grows with every sale, while the plan charge stays fixed.',
        'Included allowances belong in the same sum, since a bundled tool removes a separate subscription from the list.'
            => 'Included allowances belong beside the plan charge, since a bundled tool removes a separate subscription from the list.',
        'On Creator the platform fee comes to $160, the plan charge adds $49, and $1,391 remains before card costs and tax. On Studio the same sales produce a $48 fee and a $199 charge, leaving $1,353. Creator stays ahead by $38, so the page does not move.'
            => 'On Creator the platform keeps 10%, which is $160, and $1,440 reaches the balance before card costs and tax. On Studio the platform keeps 3%, which is $48, and $1,552 reaches the balance.',
        'Creator arithmetic gives $340 in fees plus $49, leaving $3,011. Studio arithmetic gives $102 in fees plus $199, leaving $3,099. Studio is ahead by $88, and the gap widens with every extra sale.'
            => 'The Creator share comes to $340 and the Studio share to $102. The share grows with every extra sale, and the plan charge stays a separate monthly line.',
        'The $150 difference between plan charges weighs against the seven point difference in fee, so both plans belong in the same sum. Rerunning the sum beats knowing today\'s cheaper plan, because the figures tell you in advance when to switch. Run the sum at your current level, run it again at double, then keep both answers somewhere visible.'
            => 'Rerun the percentages whenever your sales change, because the share is the figure that moves. Run them at your current level, run them again at double, then keep both answers somewhere visible.',
        'Percentage plus plan charge against one real month' => 'The percentage applied to one real month, plan charge noted beside it',
        'The plan that costs you least right now' => 'What reaches your balance on each plan',
    ),
    'fanvue-alternative' => array(
        'Plan choice reaches your bank balance directly, because the monthly charge and the percentage pull against each other.'
            => 'Plan choice reaches your bank balance through two separate lines, the percentage and the monthly charge.',
        'keeps both numbers in one view so you can test the trade before committing.' => 'lists both for each plan.',
        'Arithmetic settles that trade faster than instinct does.' => 'Arithmetic shows the percentage faster than instinct does.',
        'Dropping from 10% to 3% leaves roughly $67 more of that total in the balance, well short of the $150 difference between the two plan prices. The lower percentage matters more at higher volume, so run the same sum'
            => 'At 10% the platform keeps $96 of that total, and at 3% it keeps about $29. The percentage weighs more as sales grow, so run the same sum',
    ),
    'how-to-make-money-with-an-ai-influencer' => array(
        'Volume decides which plan costs less, so run the comparison on the sales you have, not the ones you want.'
            => 'The share moves with volume, so run the arithmetic on the sales you have, not the ones you want.',
        'On the Creator plan the platform share is $37.20, leaving $334.80, and the $49 plan charge brings the balance to $285.80 before card fees and tax.'
            => 'On the Creator plan the platform share is $37.20, so $334.80 reaches the balance before card fees and tax. The plan charge is a separate monthly line.',
    ),
    'ai-chat-for-creators' => array(
        'Follow one yoga creator through three steps.' => 'Follow one invented yoga creator, used here as an example, through three steps.',
    ),
);
// meta_description and excerpt: replaced whole, and only when the stored value still breaks the rule
$fields = array(
    'how-to-monetize-content-as-a-creator' => array(
        'meta_description' => 'Follow one hypothetical creator page through $800 of sales: platform share, plan charge, card costs, tax and what reaches the bank.',
        'excerpt'          => 'A full cost walkthrough of monetizing content: what arrives from memberships, unlocks and bookings, what each deduction removes, and what reaches the bank.',
    ),
    'custom-ai-influencer-training-when-to-invest' => array(
        'meta_description' => 'Follow one hypothetical page through training costs: gross sales, the platform fee, what reaches your bank, and how to stage the spend in three steps.',
        'excerpt'          => 'Follow one hypothetical page through the arithmetic: sales in, the platform fee out, and the balance that reaches the bank.',
    ),
);

$broken = function ($text) { return SeoDrafter::plan_sum_errors((string) $text) !== array() || preg_match('/where the maths\.\s*$/', (string) $text) === 1; };   // the second case is a description that was cut off mid-sentence
$articles = new SeoArticlesModel(); $n = 0; $missing = array(); $left = array();
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($articles->published_markdown() as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    $slug = (string) $a['slug']; $body = (string) $a['body_md']; $notes = array(); $set = array();
    foreach ((array) ($edits[$slug] ?? array()) as $old => $new) {
        if (strpos($body, $old) === false) { $missing[] = "/blog/$slug not found: \"" . mb_substr($old, 0, 70) . "\""; continue; }
        $body = str_replace($old, $new, $body); $n++; $notes[] = '"' . mb_substr($old, 0, 34) . '"';
    }
    if ($body !== (string) $a['body_md']) { $set['body_md'] = $body; $set['body_html'] = SeoDrafter::render_body($body); }
    foreach ((array) ($fields[$slug] ?? array()) as $f => $new) {
        if ($broken($a[$f] ?? '') && (string) $a[$f] !== $new) { $set[$f] = $new; $n++; $notes[] = $f; }
    }
    $after = array_merge($a, $set);
    $plain = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $after['title'] . "\n" . $after['meta_description'] . "\n" . $after['excerpt'] . "\n" . $after['body_md']);
    foreach (preg_split('/(?<=[.!?])\s+|\n+/', $plain) as $sentence) { if (SeoDrafter::plan_sum_errors($sentence)) { $left[] = "/blog/$slug: " . mb_substr(trim($sentence), 0, 150); } }
    if (empty($set)) { continue; }
    echo date('c'), " /blog/$slug: ", implode('; ', $notes), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix5-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], $set);
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nedits applied: $n\n"; foreach ($missing as $x) { echo "NOT FOUND: $x\n"; } foreach ($left as $x) { echo "STILL: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
