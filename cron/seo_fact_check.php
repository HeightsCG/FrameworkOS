<?php
/**
 * SEO fact check: do the published articles and the public pages still state the plans, prices and platform
 * fee that PlanTiers defines? The content engine writes prices into articles, and plans change, so this runs
 * nightly and tells the admins what drifted (an in-app notice plus this output).
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_fact_check.php            # report only
 *   ... --fix-fee     replace the known fee-range phrasings in article bodies with the per-plan fee (safe, exact)
 *   ... --rewrite     rewrite every article that still drifts through the drafter (Claude), one call per article
 *   ... --quiet       no admin notice (for ad-hoc runs)
 *   ... --slug=<slug> only that article (try a rewrite on one before running it on all)
 *   Prod crontab (after the 09:00 article; no --quiet, the admin notice is the point):
 *   30 9 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_fact_check.php >> /tmp/cls-seo-fact-check.log 2>&1
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
$opts = getopt('', array('fix-fee', 'rewrite', 'quiet', 'slug::'));   // --slug=<slug> limits the article pass to one article
$site = Main::site_name();
CronRuns::start('seo_fact_check');

// ---- what is true now, from config
$fee_short = PagesController::fee_short();
$plans = array(); $fees = array(); $prices = array('0');
foreach (PlanTiers::all() as $t) {
    if (!empty($t['retired'])) { continue; }
    $plans[] = (string) $t['name']; $prices[] = (string) (int) $t['price'];
    if ((int) $t['price'] > 0) { $fees[(string) $t['name']] = (int) $t['limits']['fee_percent']; }
}
foreach (PlanTiers::ADDONS as $a) { $prices[] = (string) (int) $a['price']; }
$retired = array();
foreach (PlanTiers::all() as $t) { if (!empty($t['retired'])) { $retired[] = (string) $t['name']; } }

// ---- the exact fee phrasings the old facts block produced; replaced word for word
$fee_fixes = array(
    '/\brange from 3% to 20% depending on (?:the |your |a )?plan\b/i' => 'is ' . $fee_short,
    '/\b(?:from )?3% to 20%(?: by plan)?(?: \(falls as you grow\)| depending on (?:the |your |a )?plan)?/i' => $fee_short,
    '/\b20% on the free plan\b/i' => 'no fee on Free, which cannot sell',
    '/\b20% on (?:the\s+)?Free\b/i' => 'no fee on Free, which cannot sell',
);

/** What is wrong with one piece of text, as short labels; empty = fine. */
$drift = function ($text) use ($retired, $prices, $fee_fixes, $site, $plans) {
    $issues = array();
    foreach ($retired as $r) { if (preg_match('/\b' . preg_quote($r, '/') . ' plan\b|\bthe ' . preg_quote($r, '/') . '\b/i', $text)) { $issues[] = 'names the retired ' . $r . ' plan'; } }
    foreach ($fee_fixes as $re => $to) { if (preg_match($re, $text)) { $issues[] = 'fee range'; break; } }
    if (preg_match('/\b' . preg_quote($site, '/') . '[^.]{0,80}\b20%/i', $text) || preg_match('/\b20%[^.]{0,60}\b' . preg_quote($site, '/') . '/i', $text)) { $issues[] = '20% next to the brand'; }
    // A monthly price counts only when one of our plan names sits beside it: "$10 a month" as a fan's tier price is fine.
    $names = implode('|', array_map(function ($n) { return preg_quote($n, '/'); }, array_merge($plans, $retired)));
    if ($names !== '' && preg_match_all('/(?:\b(?:' . $names . ')\b[^.$]{0,60}\$(\d{2,4})\s*(?:a|per|\/)\s*month|\$(\d{2,4})\s*(?:a|per|\/)\s*month[^.$]{0,40}\b(?:' . $names . ')\b)/', $text, $m)) {
        $amts = array_filter(array_merge($m[1], $m[2]));
        foreach (array_unique($amts) as $amt) { if (!in_array($amt, $prices, true)) { $issues[] = 'plan price $' . $amt . '/month is not current'; } }
    }
    return array_values(array_unique($issues));
};

$articles = new SeoArticlesModel();
$flagged = array();
foreach ($articles->published(500, 0) as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    if (!empty($opts['slug']) && (string) $a['slug'] !== (string) $opts['slug']) { continue; }
    $text = (string) $a['title'] . "\n" . (string) $a['meta_description'] . "\n" . (string) $a['body_md'] . "\n" . (string) $a['faq'];
    $issues = $drift($text);
    if (empty($issues) && !(isset($opts['fix-fee']) && preg_match('/\d% on [A-Z][a-z]+[a-z]/', $text))) { continue; }
    if (isset($opts['fix-fee'])) {
        $body = (string) $a['body_md']; $faq = (string) $a['faq']; $meta = (string) $a['meta_description'];
        foreach ($fee_fixes as $re => $to) { $body = preg_replace($re, $to, $body); $faq = preg_replace($re, $to, $faq); $meta = preg_replace($re, $to, $meta); }
        foreach (array('body', 'faq', 'meta') as $v) { $$v = preg_replace('/(\d% on [A-Z][a-z]+)(?=[a-z])/', '$1 ', $$v); }   // a word glued to the fee by an earlier replacement
        if ($body !== (string) $a['body_md'] || $faq !== (string) $a['faq'] || $meta !== (string) $a['meta_description']) {
            $articles->update_fields((int) $a['id'], array('body_md' => $body, 'body_html' => Markdown::render($body, SeoDrafter::allowed_paths()), 'faq' => $faq, 'meta_description' => $meta));
            echo date('c'), ' fixed fee wording in /blog/', $a['slug'], "\n";
            try { IndexNow::ping(array('/blog/' . $a['slug']), true); } catch (\Throwable $e) {}
            $issues = $drift($a['title'] . "\n" . $meta . "\n" . $body . "\n" . $faq);
            if (empty($issues)) { continue; }
        }
    }
    if (isset($opts['rewrite'])) {
        $kw = null;
        foreach ((new SeoKeywordsModel())->all() as $k) { if ((int) $k['article_id'] === (int) $a['id']) { $kw = $k; break; } }
        if (!$kw) { $kw = array('id' => 0, 'keyword' => (string) $a['target_keyword'], 'cluster' => (string) $a['cluster'], 'volume' => 0); }
        $note = 'Bring every plan name, price and fee in line with the current plans: ' . PagesController::plan_price_sentence() . ' ' . PagesController::fee_sentence() . ' Remove any retired plan. Keep everything else as it is.';
        echo date('c'), ' rewriting /blog/', $a['slug'], ' (', implode('; ', $issues), ")\n";
        try { $r = SeoDrafter::draft($kw, $note, (int) $a['id']); echo '  -> ', !empty($r['ok']) ? 'ok' : ('failed: ' . ($r['error'] ?? '')), "\n"; } catch (\Throwable $e) { echo '  -> error: ', $e->getMessage(), "\n"; }
        continue;
    }
    $flagged[] = '/blog/' . $a['slug'] . ': ' . implode('; ', $issues);
}

// ---- the public pages, as rendered (catches a stale constant or view)
$base = Main::get_base_domain();
foreach (SeoController::public_pages() as $p) {
    $path = (string) $p['path'];
    if (in_array($path, array('/terms', '/privacy'), true)) { continue; }
    $html = @file_get_contents($base . $path);
    if ($html === false || $html === '') { continue; }
    $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html)), ENT_QUOTES, 'UTF-8');
    // competitor rows legitimately say 20%: keep only sentences that mention our name
    $ours = array();
    foreach (preg_split('/(?<=[.!?])\s+/', $text) as $sentence) { if (stripos($sentence, $site) !== false) { $ours[] = $sentence; } }
    $issues = $drift(implode(' ', $ours));
    if (!empty($issues)) { $flagged[] = $path . ': ' . implode('; ', $issues); }
}

if (empty($flagged)) { echo date('c'), " plans, prices and fee are consistent everywhere\n"; CronRuns::finish('seo_fact_check', true, 'consistent'); exit(0); }
echo date('c'), ' ', count($flagged), " place(s) drifted:\n";
foreach ($flagged as $f) { echo '  ', $f, "\n"; }
if (!isset($opts['quiet'])) {
    try {
        Notify::many((new UsersModel())->admin_ids(), 'system', 'Prices or fee out of date on ' . count($flagged) . ' page(s)',
            implode("\n", array_slice($flagged, 0, 12)) . (count($flagged) > 12 ? "\n…" : '') . "\nFix: cron/seo_fact_check.php --fix-fee (fee wording) or --rewrite (full rewrite).", '/admin?tab=content');
    } catch (\Throwable $e) { error_log('[seo] fact check notice: ' . $e->getMessage()); }
}
CronRuns::finish('seo_fact_check', false, count($flagged) . ' place(s) drifted');
exit(1);
