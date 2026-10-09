<?php
/**
 * SEO fact check: do the published articles and the public pages still state the plans, prices and platform
 * fee that PlanTiers defines? The content engine writes prices into articles, and plans change, so this runs
 * nightly and tells the admins what drifted (an in-app notice plus this output).
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_fact_check.php            # report only
 *   ... --fix-fee     replace the known fee-range phrasings in article bodies with the per-plan fee (safe, exact)
 *   ... --rewrite     rewrite an article that drifts or breaks the content rules through the drafter (Claude), one call
 *                     each; needs --slug=<slug> or --id=<n>, or --all for every flagged article
 *   ... --quiet       no admin notice (for ad-hoc runs)
 *   ... --slug=<slug> only that article (try a rewrite on one before running it on all)
 *   ... --id=<n>      only that article, in any status (a draft too), and no public page pass
 *   ... --fix-rules   mechanical rule fixes on published articles: dashes become a period or comma, the non-refundable
 *                     sentence is added as its own last paragraph where plans or credits come up; re-rendered and pinged.
 *                     Links outside the article's cluster allow-list become plain text. Each changed row is first
 *                     saved as JSON to <tmp>/cls_seo_backup_<date>/. Add --dry-run to list the changes without writing.
 *                     Income promises and names are only reported (fix those with --rewrite or the editor).
 *   Every article is also held to the drafter's content rules (SeoDrafter::content_errors: income promises, people's
 *   names, dashes, the payment processor, citation domains, plan prices, the non-refundable line); those are reported, never auto-fixed.
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
$opts = getopt('', array('fix-fee', 'fix-rules', 'dry-run', 'rewrite', 'all', 'quiet', 'slug:', 'id:'));   // --slug x / --slug=x both work
if (isset($opts['rewrite']) && empty($opts['slug']) && empty($opts['id']) && !isset($opts['all'])) { echo "--rewrite needs --slug=<slug> or --id=<n> (or --all to rewrite every flagged article, one Claude call each)\n"; exit(1); }   // --slug=<slug> / --id=<n> limit the article pass to one article
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

/** The drafter's content rules for one stored article. */
$article_clusters = (new SeoArticlesModel())->published_clusters();
$rules = function (array $a) use ($article_clusters) {
    $faq = json_decode((string) ($a['faq'] ?? ''), true);
    $row = array('title' => (string) $a['title'], 'meta_description' => (string) $a['meta_description'], 'excerpt' => (string) ($a['excerpt'] ?? ''), 'body_md' => (string) $a['body_md'], 'faq' => is_array($faq) ? $faq : array(), 'cluster' => (string) ($a['cluster'] ?? ''));
    // content rules + link rules + the quotability rules (citations, question headings, prose): an older article that
    // predates a rule is flagged here, and --rewrite regenerates it through the drafter (same slug, goes live on success)
    return array_merge(SeoDrafter::content_errors($row), SeoDrafter::link_errors($row, $article_clusters), SeoDrafter::quotability_errors($row));
};

/** Dashes out, sensibly: a pair around an aside becomes commas, a lone dash between two full clauses a period, else a comma (a colon in headings). */
$undash = function ($text) {
    $text = preg_replace('/(\d)\s*\x{2013}\s*(\d)/u', '$1-$2', (string) $text);
    $text = str_replace("\u{2013}", "\u{2014}", $text);
    $out = array();
    foreach (explode("\n", $text) as $line) {
        if (strpos($line, "\u{2014}") === false) { $out[] = $line; continue; }
        if (preg_match('/^\s*#/', $line)) { $out[] = preg_replace('/\s*\x{2014}\s*/u', ': ', $line); continue; }
        $parts = preg_split('/(?<=[.!?])(\s+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $seg) {
            $n = preg_match_all('/\x{2014}/u', $seg);
            if ($n === 0) { continue; }
            if ($n >= 2) { $parts[$i] = preg_replace('/\s*\x{2014}\s*/u', ', ', $seg); continue; }
            list($l, $r) = preg_split('/\s*\x{2014}\s*/u', $seg, 2);
            $full = str_word_count(strip_tags($l)) >= 4 && str_word_count(strip_tags($r)) >= 4 && preg_match('/^[a-z]/', $r);
            $parts[$i] = $full ? rtrim($l, ' ,;:') . '. ' . ucfirst($r) : rtrim($l) . ', ' . ltrim($r);
        }
        $out[] = implode('', $parts);
    }
    return $out === array() ? $text : implode("\n", $out);
};
$kind = function ($e) { return trim(preg_replace('/[:(].*$/', '', (string) $e)); };
$rule_counts = array('before' => array(), 'after' => array());

$articles = new SeoArticlesModel();
$flagged = array();
$rows = !empty($opts['id']) ? array(array('id' => (int) $opts['id'])) : $articles->published(500, 0);
foreach ($rows as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    if (!empty($opts['slug']) && (string) $a['slug'] !== (string) $opts['slug']) { continue; }
    $text = (string) $a['title'] . "\n" . (string) $a['meta_description'] . "\n" . (string) $a['body_md'] . "\n" . (string) $a['faq'];
    $issues = $drift($text);
    $rule_issues = $rules($a);
    if (isset($opts['fix-rules']) && (string) $a['status'] === 'published') {
        foreach ($rule_issues as $e) { $rule_counts['before'][$kind($e)] = ($rule_counts['before'][$kind($e)] ?? 0) + 1; }
        $f = array();
        foreach (array('title', 'meta_description', 'excerpt', 'body_md') as $col) { $v = $undash((string) $a[$col]); if ($v !== (string) $a[$col]) { $f[$col] = $v; } }
        $faq = json_decode((string) $a['faq'], true);
        if (is_array($faq)) {
            foreach ($faq as $i => $q) { if (is_array($q)) { foreach (array('q', 'a') as $k) { $faq[$i][$k] = $undash((string) ($q[$k] ?? '')); } } }
            $faq_json = json_encode($faq, JSON_UNESCAPED_UNICODE);
            if ($faq_json !== json_encode(json_decode((string) $a['faq'], true), JSON_UNESCAPED_UNICODE)) { $f['faq'] = $faq_json; }
        }
        $body = SeoDrafter::strip_links($f['body_md'] ?? (string) $a['body_md'], (string) $a['cluster'], $article_clusters);   // off-cluster links become their text
        if ($body !== (string) $a['body_md']) { $f['body_md'] = $body; }
        if (preg_grep('/non-refundable line/', $rule_issues) && stripos($body, 'non-refundable') === false) { $f['body_md'] = rtrim($body) . "\n\n" . PagesController::FINAL_NOTE . "\n"; }   // own paragraph, last before the FAQ
        if (!empty($f) && isset($opts['dry-run'])) {
            echo date('c'), ' would fix rules (', implode(', ', array_keys($f)), ') in /blog/', $a['slug'], "\n";
            $rule_issues = $rules(array_merge($a, $f));
        } elseif (!empty($f)) {
            if (isset($f['body_md'])) { $f['body_html'] = SeoDrafter::render_body($f['body_md']); }
            $dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');   // the row as it was, to undo by hand
            if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
            if (@file_put_contents($dir . '/' . (int) $a['id'] . '-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo date('c'), ' backup failed for /blog/', $a['slug'], ", skipped\n"; continue; }
            $articles->update_fields((int) $a['id'], $f);
            echo date('c'), ' fixed rules (', implode(', ', array_keys($f)), ') in /blog/', $a['slug'], "\n";
            try { IndexNow::ping(array('/blog/' . $a['slug']), true); } catch (\Throwable $e) {}
            $a = $articles->get((int) $a['id']);
            $rule_issues = $rules($a);
        }
        foreach ($rule_issues as $e) { $rule_counts['after'][$kind($e)] = ($rule_counts['after'][$kind($e)] ?? 0) + 1; }
    }
    if (!empty($rule_issues)) { $flagged[] = '/blog/' . $a['slug'] . ' (rules): ' . implode('; ', $rule_issues); }
    $needs_rewrite = isset($opts['rewrite']) && !empty($rule_issues);   // --rewrite also takes articles that break the content rules
    if (empty($issues) && !$needs_rewrite && !(isset($opts['fix-fee']) && SeoDrafter::fix_fee_glue($text) !== $text)) { continue; }
    if (isset($opts['fix-fee'])) {
        $body = (string) $a['body_md']; $faq = (string) $a['faq']; $meta = (string) $a['meta_description'];
        foreach ($fee_fixes as $re => $to) { $body = preg_replace($re, $to, $body); $faq = preg_replace($re, $to, $faq); $meta = preg_replace($re, $to, $meta); }
        foreach (array('body', 'faq', 'meta') as $v) { $$v = SeoDrafter::fix_fee_glue($$v); }   // whole plan names only: never splits a word
        if ($body !== (string) $a['body_md'] || $faq !== (string) $a['faq'] || $meta !== (string) $a['meta_description']) {
            $articles->update_fields((int) $a['id'], array('body_md' => $body, 'body_html' => SeoDrafter::render_body($body), 'faq' => $faq, 'meta_description' => $meta));
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
        $note = 'Bring every plan name, price and fee in line with the current plans: ' . PagesController::plan_price_sentence() . ' ' . PagesController::fee_sentence() . ' Remove any retired plan.' . (!empty($rule_issues) ? ' Also fix: ' . implode('; ', $rule_issues) . '.' : '') . ' Keep everything else as it is.';
        echo date('c'), ' rewriting /blog/', $a['slug'], ' (', implode('; ', array_merge($issues, $rule_issues)), ")\n";
        try { $r = SeoDrafter::draft($kw, $note, (int) $a['id']); echo '  -> ', !empty($r['ok']) ? 'ok' : ('failed: ' . ($r['error'] ?? '')), "\n"; } catch (\Throwable $e) { echo '  -> error: ', $e->getMessage(), "\n"; }
        if (!empty($r['ok'])) {   // the rewrite went live: the summary reports what is on the site now, not the reasons it was taken
            $flagged = array_values(array_filter($flagged, function ($f) use ($a) { return strpos($f, '/blog/' . $a['slug'] . ' ') !== 0 && strpos($f, '/blog/' . $a['slug'] . ':') !== 0; }));
            $fresh = $articles->get((int) $a['id']); $left = $fresh ? $rules($fresh) : array();
            if (!empty($left)) { $flagged[] = '/blog/' . $a['slug'] . ' (rules, after rewrite): ' . implode('; ', $left); }
        }
        continue;
    }
    $flagged[] = '/blog/' . $a['slug'] . ': ' . implode('; ', $issues);
}

// ---- the public pages, as rendered (catches a stale constant or view)
$base = Main::get_base_domain();
foreach (!empty($opts['id']) ? array() : SeoController::public_pages() as $p) {
    $path = (string) $p['path'];
    if (in_array($path, array('/terms', '/privacy'), true)) { continue; }
    $html = @file_get_contents($base . $path);
    if ($html === false || $html === '') { continue; }
    // headings, list items and table cells end a sentence even without a full stop: otherwise a bullet ending in "20%" runs
    // straight into the next heading's brand name and reads as "20% next to the brand"
    $html = preg_replace('#</(h[1-6]|li|p|td|th|dt|dd|summary|figcaption)\s*>#i', '. ', preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html));
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    // competitor rows legitimately say 20%: keep only sentences that mention our name
    $ours = array();
    foreach (preg_split('/(?<=[.!?])\s+/', $text) as $sentence) { if (stripos($sentence, $site) !== false) { $ours[] = $sentence; } }
    $issues = $drift(implode(' ', $ours));
    if (!empty($issues)) { $flagged[] = $path . ': ' . implode('; ', $issues); }
}

if (isset($opts['fix-rules'])) {
    echo "rule issues on published articles, before -> after --fix-rules:\n";
    foreach (array_unique(array_merge(array_keys($rule_counts['before']), array_keys($rule_counts['after']))) as $k) { printf("  %-70s %3d -> %3d\n", $k, $rule_counts['before'][$k] ?? 0, $rule_counts['after'][$k] ?? 0); }
}
if (empty($flagged)) { echo date('c'), " plans, prices and fee are consistent everywhere\n"; CronRuns::finish('seo_fact_check', true, 'consistent'); exit(0); }
echo date('c'), ' ', count($flagged), " place(s) drifted:\n";
foreach ($flagged as $f) { echo '  ', $f, "\n"; }
if (!isset($opts['quiet'])) {
    try {
        Notify::many((new UsersModel())->admin_ids(), 'system', 'Prices, fee or content rules off on ' . count($flagged) . ' page(s)',
            implode("\n", array_slice($flagged, 0, 12)) . (count($flagged) > 12 ? "\n…" : '') . "\nFix: cron/seo_fact_check.php --fix-fee (fee wording) or --rewrite (full rewrite); content rules need an edit in the article editor.", '/admin?tab=content');
    } catch (\Throwable $e) { error_log('[seo] fact check notice: ' . $e->getMessage()); }
}
CronRuns::finish('seo_fact_check', false, count($flagged) . ' place(s) drifted');
exit(1);
