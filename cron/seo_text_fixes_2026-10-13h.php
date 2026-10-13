<?php
/**
 * Eighth one-time text pass (independent read of production, 2026-10-13): five sentences in three articles still said the balance
 * is what is left "once the plan fee comes off", or pointed at the sales level where the plan choice changes; one of them also
 * lacked the event exception beside "no platform hold". Exact-string edits, pure text, no AI calls. Ends by checking every
 * published article (body and FAQ) against SeoDrafter::plan_sum_errors().
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-13h.php [--dry-run|--apply]
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
    'how-to-choose-an-onlyfans-alternative' => array(
        'collect into one balance once the plan fee comes off, and a cash-out request sends money to your bank with no platform hold.'
            => 'collect into one balance net of your plan\'s platform fee, and a cash-out request sends money to your bank with no platform hold; ticket earnings release after the event.',
        'One balance after the plan fee, paid to your bank on request'
            => 'One balance after the platform fee, paid to your bank on request',
    ),
    'how-to-pick-a-creator-monetization-platform' => array(
        'Two sums name both the plan you belong on now and the one you move to afterwards, and the '
            => 'The two sums show what each plan keeps at each level, and the ',
        'Monthly charges distort the picture the moment a plan price sits beside a small percentage. A platform keeping 3% looks obviously cheaper than one keeping 10%, until you read the subscription attached to the smaller number.'
            => 'Monthly charges are a separate line from the percentage. A platform keeping 3% and one keeping 10% may also charge different monthly prices.',
    ),
    'how-to-monetize-content-as-a-creator' => array(
        'because a percentage climbs with every sale while a flat charge does not, and only one of the two gets cheaper as the page grows.'
            => 'because a percentage climbs with every sale while a flat charge does not.',
        'already net of the plan share,' => 'already net of the platform share,',
    ),
    'fanvue-alternative' => array(
        'The percentage weighs more as sales grow, so run the same sum at the sales level you expect in six months, not today\'s.'
            => 'Run the same percentage at the sales level you expect in six months, not only today\'s.',
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
    if (empty($set)) { continue; }
    echo date('c'), " /blog/$slug: ", implode('; ', $notes), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix8-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], $set);
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nedits applied: $n, sentences still breaking the fee rule: $still\n"; foreach ($missing as $x) { echo "NOTE: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
