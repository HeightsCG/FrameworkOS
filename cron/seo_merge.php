<?php
/**
 * Merge overlapping published articles (Daniel's approved list, 2026-10-11). For each group the survivor is rewritten
 * through the drafter in the given format with the merged articles' content folded in (one Claude call, up to three
 * attempts, same slug, goes live only when every check passes), then each merged article is archived behind a 301 to
 * the survivor, every link to it in other published articles is rewritten, and the keywords that pointed at the merged
 * articles are marked covered. The sitemap and the RSS feed read published articles only, so they follow.
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_merge.php --approved            # the five approved groups, in order
 *   ... --into=<slug> --from=<slug,slug> --format=<guide|checklist|comparison|cost|troubleshooting|glossary>   # one group
 *   ... --into=<slug> --format=<x> [--link=/path]        # restructure only (no sources), link to a page the piece must reference
 *   ... --dry-run     print what would happen, no Claude call, nothing written
 * A group whose rewrite fails is left exactly as it was (nothing archived, no redirect) and printed with the reasons.
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
$opts = getopt('', array('approved', 'restructure::', 'into:', 'from:', 'format:', 'link:', 'sections:', 'rhythm:', 'dry-run'));   // --restructure (the first set) or --restructure=slop2
$dry = isset($opts['dry-run']);

/** The approved merges, in run order (formats differ from one group to the next, so no two consecutive articles share one). */
const APPROVED = array(
    array('into' => 'how-to-monetize-content-as-a-creator',    'from' => array('how-to-monetize-digital-content', '30-day-plan-to-monetize-online-content'), 'format' => 'guide',           'link' => '/monetize-your-content'),
    array('into' => 'how-to-train-a-lora-character',          'from' => array('lora-training-for-ai-influencers', 'flux-lora-training'),                    'format' => 'checklist',       'link' => '/lora-character-training'),
    array('into' => 'how-to-make-money-with-an-ai-influencer', 'from' => array('ai-influencer-monetization', 'virtual-influencer-income'),                  'format' => 'cost',            'link' => '/features/ai-influencer'),
    array('into' => 'fanvue-alternative',                     'from' => array(),                                                                          'format' => 'troubleshooting', 'link' => '/fanvue-alternatives'),
    array('into' => 'how-to-choose-an-onlyfans-alternative',  'from' => array(),                                                                          'format' => 'comparison',      'link' => '/onlyfans-alternatives'),
);

/** The a_ai_slop restructure (2026-10-12): five articles, each into a different format, with a different section count than now. */
const RESTRUCTURE = array(
    array('into' => 'custom-ai-influencer-training-when-to-invest',     'from' => array(), 'format' => 'cost',            'link' => '/lora-character-training'),
    array('into' => 'best-ai-tools-consistent-ai-influencer-characters', 'from' => array(), 'format' => 'comparison',      'link' => '/consistent-ai-model-face'),
    array('into' => 'how-to-price-a-subscription-tier',                 'from' => array(), 'format' => 'guide',           'link' => '/features/memberships'),
    array('into' => 'automated-dm-replies-for-creators',                'from' => array(), 'format' => 'troubleshooting', 'link' => '/features/dm-agent'),
    array('into' => 'lora-training-dataset-tips',                       'from' => array(), 'format' => 'checklist',       'link' => '/lora-character-training'),
);

/** The second a_ai_slop set (2026-10-12): five more articles, each a format none of them had, with a named section count and rhythm. */
const RESTRUCTURE2 = array(   // live corpus 2026-10-12: section counts 5,6,7 dominate (6 x5, 7 x4, 5 x2), 8 and 9 once each, every section 100-320 words; these five take counts and rhythms the corpus does not have
    array('into' => 'consistent-ai-character-across-images',    'from' => array(), 'format' => 'glossary',        'link' => '/consistent-ai-model-face',  'sections' => 2,  'rhythm' => 'one long section and one short'),
    array('into' => 'how-to-train-a-lora-character',           'from' => array(), 'format' => 'checklist',       'link' => '/lora-character-training',   'sections' => 3,  'rhythm' => 'three list-heavy sections of very different lengths: the middle one three times the first'),
    array('into' => 'how-to-make-money-with-an-ai-influencer',  'from' => array(), 'format' => 'troubleshooting', 'link' => '/features/ai-influencer',   'sections' => 10, 'rhythm' => 'ten short symptom sections of one or two paragraphs each'),
    array('into' => 'how-to-choose-an-onlyfans-alternative',   'from' => array(), 'format' => 'comparison',      'link' => '/onlyfans-alternatives',     'sections' => 4,  'rhythm' => 'the longest section in the middle'),
    array('into' => 'how-to-monetize-content-as-a-creator',    'from' => array(), 'format' => 'cost',            'link' => '/monetize-your-content',     'sections' => 8,  'rhythm' => 'two long sections and the rest one paragraph each'),
);

$groups = array();
if (isset($opts['approved'])) { $groups = APPROVED; }
elseif (isset($opts['restructure'])) { $groups = ((string) $opts['restructure'] === 'slop2') ? RESTRUCTURE2 : RESTRUCTURE; }
elseif (!empty($opts['into'])) { $groups[] = array('into' => (string) $opts['into'], 'from' => array_values(array_filter(array_map('trim', explode(',', (string) ($opts['from'] ?? ''))))), 'format' => (string) ($opts['format'] ?? ''), 'link' => (string) ($opts['link'] ?? ''), 'sections' => (int) ($opts['sections'] ?? 0), 'rhythm' => (string) ($opts['rhythm'] ?? '')); }
else { echo "usage: --approved | --restructure[=slop2] | --into=<slug> [--from=<slug,slug>] --format=<x> [--link=/path] [--dry-run]\n"; exit(1); }

$articles = new SeoArticlesModel(); $keywords = new SeoKeywordsModel();
$ok_all = true; $streak = 0;
foreach ($groups as $g) {
    $into = $articles->get_by_slug($g['into'], true);
    if (!$into) { echo date('c'), " SKIP: survivor /blog/{$g['into']} is not a published article here\n"; $ok_all = false; continue; }
    $sources = array(); $missing = array();
    foreach ($g['from'] as $slug) { $a = $articles->get_by_slug($slug, true); if ($a) { $sources[] = $a; } else { $missing[] = $slug; } }
    if ($missing) { echo date('c'), " note: not published here, left out of the merge: ", implode(', ', $missing), "\n"; }
    $format = (string) $g['format'];
    if (!isset(SeoDrafter::FORMATS[$format])) { echo date('c'), " SKIP: unknown format \"$format\" for /blog/{$g['into']}\n"; $ok_all = false; continue; }
    printf("%s %s /blog/%s as a %s%s%s\n", date('c'), $sources ? 'merging into' : 'restructuring', $into['slug'], SeoDrafter::FORMATS[$format]['label'],
        $sources ? ' (folding in ' . implode(', ', array_map(function ($s) { return '/blog/' . $s['slug']; }, $sources)) . ')' : '', $g['link'] !== '' ? ', linking to ' . $g['link'] : '');
    if ($dry) { continue; }

    // the keyword row behind the survivor (the drafter needs one; a synthetic row works when none is linked)
    $kw = null; foreach ($keywords->all() as $k) { if ((int) $k['article_id'] === (int) $into['id']) { $kw = $k; break; } }
    if (!$kw) { $kw = array('id' => 0, 'keyword' => (string) $into['target_keyword'], 'cluster' => (string) $into['cluster'], 'volume' => 0, 'priority' => 100); }
    $now_sections = count(SeoDrafter::h2_texts((string) $into['body_md']));
    $note = 'Rewrite this article as a ' . SeoDrafter::FORMATS[$format]['label'] . ' (structure: ' . SeoDrafter::FORMATS[$format]['structure'] . '). Use a different number of sections than it has now (' . $now_sections . ') and different section lengths; word every sentence for this piece, never a stock line used in other articles.';
    if ($g['link'] !== '') { $note .= ' Link once to ' . $g['link'] . ' where it fits.'; }
    if ($sources) {
        $note .= ' The articles below are being merged into this one and will redirect here: fold their useful content in (facts, steps, numbers, examples), drop what repeats, keep the slug and the subject, and cover their search intents too (add their target keywords to secondary_keywords).';
        foreach ($sources as $s) { $note .= "\n\n--- Merged article: \"" . $s['title'] . "\" (keyword: " . $s['target_keyword'] . ")\n" . (string) $s['body_md']; }
    }
    $avoid = array(); if (!empty($g['sections'])) { $avoid['sections_exact'] = (int) $g['sections']; } if (!empty($g['rhythm'])) { $avoid['rhythm'] = (string) $g['rhythm']; }
    $r = SeoDrafter::draft($kw, $note, (int) $into['id'], false, $avoid, $format);
    if (empty($r['ok'])) { echo "  -> rewrite FAILED, group left as it was: ", $r['error'], "\n"; $ok_all = false; if (++$streak >= 2) { echo date('c'), " two groups in a row failed: stopping so a bad rule cannot burn every article (fix the rule, rerun)\n"; break; } continue; }
    $streak = 0;
    echo "  -> survivor rewritten and live\n";
    foreach ($sources as $s) {
        $articles->archive_into((int) $s['id'], $into['slug']);
        echo "  -> /blog/{$s['slug']} archived, 301 to /blog/{$into['slug']}\n";
        // links to the merged article in every other published article
        foreach ($articles->published_markdown() as $row) {
            $body = str_replace('(/blog/' . $s['slug'] . ')', '(/blog/' . $into['slug'] . ')', (string) $row['body_md']);
            $faq  = str_replace('/blog/' . $s['slug'], '/blog/' . $into['slug'], (string) $row['faq']);
            if ($body !== (string) $row['body_md'] || $faq !== (string) $row['faq']) {
                $articles->update_fields((int) $row['id'], array('body_md' => $body, 'body_html' => SeoDrafter::render_body($body), 'faq' => $faq));
                echo "  -> relinked /blog/{$row['slug']}\n";
            }
        }
        foreach ($keywords->all() as $k) { if ((int) $k['article_id'] === (int) $s['id']) { $keywords->set_status((int) $k['id'], 'skipped', (int) $into['id'], 'merged into /blog/' . $into['slug'] . ' (covered)'); echo "  -> keyword \"{$k['keyword']}\" marked covered\n"; } }
        try { IndexNow::ping(array('/blog/' . $s['slug'], '/blog/' . $into['slug'], '/blog'), true); } catch (\Throwable $e) {}
    }
}
exit($ok_all ? 0 : 1);
