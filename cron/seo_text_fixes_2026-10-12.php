<?php
/**
 * One-time text fixes on the published articles (HeyCatch audit, Daniel's brief 2026-10-12). Pure text processing, no AI calls.
 *   1. c_paragraph_length: any stored paragraph over SeoDrafter::PARAGRAPH_MAX_SENTENCES is split at the sentence boundary nearest the middle.
 *   2. g_self_contained: the first sentence of a paragraph (or the subtitle) that carries it/they/them/this/these/those/he/she/such
 *      is replaced with the hand-written sentence below, naming the noun. Exact-string replacements per slug; a string that is
 *      not found is reported and skipped, never guessed.
 *   3. a_external_sources: articles citing only two hosts get one more primary source, as a hand-written sentence appended to
 *      the paragraph named below (exact anchor), linking a page from SeoDrafter::KNOWN_SOURCES.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-12.php [--dry-run]   (dry run prints what would change; --apply writes)
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }

/** 2. g_self_contained: slug => array(old fragment => new fragment). Fragments avoid links and apostrophes so they match body_md as stored. */
$pronouns = array(
    'ai-chat-for-creators' => array(
        'excerpt' => array('AI chat for creators works in the order you build it:' => 'AI chat for creators works in the order you build the system:'),
    ),
    'automated-dm-replies-for-creators' => array(
        'excerpt' => array('because of the notes behind them, not the model.' => 'because of the notes behind the replies, not the model.'),
    ),
    'fanvue-alternative' => array(
        'body_md' => array('The Studio plan lifts those limits to ten AI influencers' => 'The Studio plan lifts the Creator plan limits to ten AI influencers'),
    ),
    'how-to-choose-an-onlyfans-alternative' => array(
        'body_md' => array(
            'then cross off any route that cannot host all of them without a workaround.' => 'then cross off any route that cannot host every offer on the list without a workaround.',
            'Ownership reduces to a question you can answer this afternoon:' => 'Ownership reduces to a question you can answer today:',
        ),
    ),
    'how-to-make-money-with-an-ai-influencer' => array(
        'body_md' => array('Confirmation is a sentence said out loud: what a buyer gets this week in exchange for money.' => 'Confirmation is a sentence said out loud: what a buyer gets within the next seven days in exchange for money.'),
    ),
    'how-to-monetize-content-as-a-creator' => array(
        'body_md' => array('which is worth knowing before you plan a month around them.' => 'which is worth knowing before you plan a month around ad-share income.'),
    ),
    'how-to-pick-a-creator-monetization-platform' => array(
        'body_md' => array('because the two selling plans sit on opposite sides of it.' => 'because the two selling plans sit on opposite sides of the trade-off.'),
    ),
    'how-to-price-a-subscription-tier' => array(
        'body_md' => array('when existing members keep the rate they joined on and the new figure applies to new joiners only.' => 'when existing members keep the rate from the day each member joined and the new figure applies to new joiners only.'),
    ),
    'how-to-train-a-lora-character' => array(
        'body_md' => array('takes 3% and raises those allowances to ten influencers' => 'takes 3% and raises the allowances to ten influencers'),
    ),
    'lora-training-dataset-tips' => array(
        'body_md' => array('learns small rank decomposition matrices alongside them, as' => 'learns small rank decomposition matrices alongside the frozen weights, as'),
    ),
);

/** 3. a_external_sources: slug => array(anchor sentence that ends a paragraph => sentence to append after it). One more primary source each. */
$source_rules = array(   // slug => array('needle' => a phrase inside the paragraph to extend, 'sentence' => the sentence appended to that paragraph)
    'ai-chat-for-creators' => array('needle' => 'paid messages', 'sentence' => 'Payment processors report creator sales on a Form 1099-K once a threshold is passed, and the [IRS explains what the form covers](https://www.irs.gov/businesses/understanding-your-form-1099-k).'),
    'automated-dm-replies-for-creators' => array('needle' => 'fan', 'sentence' => 'A reply that promotes a brand deal is an endorsement, and the [FTC endorsement guides](https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking) explain what the disclosure has to say.'),
    'consistent-ai-character-across-images' => array('needle' => 'base model', 'sentence' => 'The FLUX family of image models that most character pipelines start from is documented by [Black Forest Labs](https://bfl.ai/models/flux-kontext).'),
    'how-to-price-a-subscription-tier' => array('needle' => 'card', 'sentence' => 'Membership income is self-employment income, and the [IRS page on Form 1099-K](https://www.irs.gov/businesses/understanding-your-form-1099-k) explains what the processor reports about it.'),
    'lora-training-dataset-tips' => array('needle' => 'DreamBooth', 'sentence' => 'The [DreamBooth paper](https://arxiv.org/abs/2208.12242) describes the subject-driven fine-tuning that a small reference set relies on, and the [Hugging Face DreamBooth training guide](https://huggingface.co/docs/diffusers/training/dreambooth) walks through the run.'),
    'online-creator-platform' => array('needle' => 'bank', 'sentence' => 'Whatever platform collects the money, the income is self-employment income; the [IRS self-employed individuals tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) sets out the estimated payments due on it.'),
);

$articles = new SeoArticlesModel();
$rows = (array) $articles->published_markdown();
$split_n = 0; $pron_n = 0; $src_n = 0; $missing = array();
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($rows as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    $slug = (string) $a['slug']; $f = array(); $notes = array();
    $body = (string) $a['body_md']; $excerpt = (string) $a['excerpt'];

    // 1. split long paragraphs
    $split = SeoDrafter::split_long_paragraphs($body);
    $before = count(array_filter(array_map('trim', preg_split('/\n\s*\n/', $body)))); $after = count(array_filter(array_map('trim', preg_split('/\n\s*\n/', $split))));
    if ($after > $before) { $notes[] = 'split ' . ($after - $before) . ' long paragraph(s)'; $split_n += ($after - $before); $body = $split; }   // whitespace-only differences are not a change

    // 2. pronoun first sentences
    foreach ((array) ($pronouns[$slug] ?? array()) as $field => $map) {
        foreach ($map as $old => $new) {
            $target = $field === 'excerpt' ? $excerpt : $body;
            if (strpos($target, $old) === false) { $missing[] = "/blog/$slug [$field] not found: \"" . mb_substr($old, 0, 60) . "\""; continue; }
            $target = str_replace($old, $new, $target);
            if ($field === 'excerpt') { $excerpt = $target; } else { $body = $target; }
            $notes[] = "pronoun: \"" . mb_substr($old, 0, 40) . "\" -> \"" . mb_substr($new, 0, 40) . "\""; $pron_n++;
        }
    }

    // 3. one more primary source where only two hosts are cited
    $hosts = array(); preg_match_all('/\]\((https?:\/\/[^)\s]+)\)/', $body, $m);
    foreach ($m[1] as $u) { $h = strtolower((string) parse_url($u, PHP_URL_HOST)); if ($h !== '' && strpos($h, 'creatorlinkstudio') === false) { $hosts[preg_replace('/^www\./', '', $h)] = true; } }
    if (isset($source_rules[$slug]) && count($hosts) < 3) {
        $rule = $source_rules[$slug]; $new_host = preg_replace('/^www\./', '', strtolower((string) parse_url(preg_replace('/.*\]\((https?:\/\/[^)\s]+)\).*/s', '$1', $rule['sentence']), PHP_URL_HOST)));
        if (isset($hosts[$new_host])) { $notes[] = 'source: host ' . $new_host . ' already cited, skipped'; }
        else {
            $blocks = preg_split('/\n\s*\n/', $body); $done = false;
            foreach ($blocks as $i => $b) {
                $t = trim($b);
                if ($t === '' || preg_match('/^(#|[-*+]\s|>|\||`|\d+\.\s)/', $t) || stripos($t, $rule['needle']) === false) { continue; }
                if (SeoDrafter::sentence_count($t) >= SeoDrafter::PARAGRAPH_MAX_SENTENCES) { continue; }   // never push a paragraph over the limit
                $blocks[$i] = rtrim($t) . ' ' . $rule['sentence']; $done = true; break;
            }
            if ($done) { $body = implode("\n\n", $blocks); $notes[] = 'source: added ' . $new_host; $src_n++; }
            else { $missing[] = "/blog/$slug source: no paragraph under the limit contains \"" . $rule['needle'] . "\""; }
        }
    }

    if ($body !== (string) $a['body_md']) { $f['body_md'] = $body; $f['body_html'] = SeoDrafter::render_body($body); }
    if ($excerpt !== (string) $a['excerpt']) { $f['excerpt'] = $excerpt; }
    if (empty($f)) { continue; }
    echo date('c'), ' /blog/', $slug, ': ', implode('; ', $notes), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], $f);
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nparagraphs split: $split_n | pronoun sentences replaced: $pron_n | sources added: $src_n\n";
foreach ($missing as $x) { echo "NOT FOUND: $x\n"; }
echo $apply ? "applied\n" : "dry run only\n";
