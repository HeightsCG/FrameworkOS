<?php
/**
 * Fourth one-time text pass: no break-even figure or "pays for itself" / "crossover" claim anywhere (Daniel 2026-10-12). Exact-string
 * edits on the live articles; facts, links and meaning otherwise unchanged. Pure text, no AI calls.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-12d.php [--dry-run|--apply]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }

$edits = array(
    'custom-ai-influencer-training-when-to-invest' => array(
        'The plan crossover arrives later than the training decision, and the arithmetic behind the crossover is simple.' => 'The plan choice comes after the training decision, and the arithmetic behind the choice is simple.',
        'A seven-point difference in fee against a $150 difference in plan price flips near $2,143 in monthly gross sales.' => 'A seven-point difference in fee sits against a $150 difference in plan price, so run both plans against your own monthly gross sales.',
    ),
    'fanvue-alternative' => array(
        'The lower percentage pays for itself only at higher volume, so run the same sum' => 'The lower percentage matters more at higher volume, so run the same sum',
    ),
    'how-to-choose-an-onlyfans-alternative' => array(
        'Crossover is one division you run once and reuse for years.' => 'The comparison is one sum you run once and rerun as sales change.',
    ),
    'how-to-make-money-with-an-ai-influencer' => array(
        'Break-even reasoning for a trained character is worked through in' => 'The cost reasoning for a trained character is worked through in',
    ),
    'how-to-monetize-content-as-a-creator' => array(
        'outweigh $150 of extra monthly price, which lands near $2,143 of monthly sales.' => 'outweigh $150 of extra monthly price, so run both plans against your own sales.',
        ' Under that line the cheaper subscription wins, and over the line the lower take rate does.' => '',
        'At $3,000 of monthly sales the order flips: $300 plus $49 is $349, against $90 plus $199, which is $289.' => 'At $3,000 of monthly sales the sums are $300 plus $49, which is $349, against $90 plus $199, which is $289.',
        'Forty dollars a month just past the crossover is modest, and the difference widens' => 'Forty dollars a month at that volume is modest, and the difference widens',
    ),
    'how-to-pick-a-creator-monetization-platform' => array(
        'The crossover sits near $2,150 in monthly sales, where the $150 difference between plan charges matches the seven point difference in fee.' => 'The $150 difference between plan charges weighs against the seven point difference in fee, so both plans belong in the same sum.',
        'Knowing the crossover beats knowing today\'s cheaper plan, because the number tells you in advance when to switch.' => 'Rerunning the sum beats knowing today\'s cheaper plan, because the figures tell you in advance when to switch.',
    ),
    'online-creator-platform' => array(
        'A hypothetical page finds the crossover.' => 'A hypothetical page shows the two plans side by side.',
        'Your own crossover point comes from the same sum, rechecked every quarter instead of once.' => 'Your own figures come from the same sum, rechecked every quarter instead of once.',
    ),
);
$pat = '/break-?even|pays for itself|once you sell|once monthly sales pass|crossover|cross(?:es)? (?:at|near|around)|lines cross|swap places|the order flips|pays? for its price/i';

$articles = new SeoArticlesModel(); $n = 0; $missing = array(); $left = array();
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($articles->published_markdown() as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    $slug = (string) $a['slug']; $body = (string) $a['body_md']; $notes = array();
    foreach ((array) ($edits[$slug] ?? array()) as $old => $new) {
        if (strpos($body, $old) === false) { $missing[] = "/blog/$slug not found: \"" . mb_substr($old, 0, 70) . "\""; continue; }
        $body = str_replace($old, $new, $body); $n++; $notes[] = '"' . mb_substr($old, 0, 34) . '"';
    }
    $plain = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $body);
    if (preg_match_all($pat, $plain, $m)) { foreach (array_unique($m[0]) as $w) { $left[] = "/blog/$slug still says \"$w\""; } }
    if ($body === (string) $a['body_md']) { continue; }
    echo date('c'), " /blog/$slug: ", implode('; ', $notes), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix4-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], array('body_md' => $body, 'body_html' => SeoDrafter::render_body($body)));
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nedits applied: $n\n"; foreach ($missing as $x) { echo "NOT FOUND: $x\n"; } foreach ($left as $x) { echo "STILL: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
