<?php
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
SeoDrafter::$check_citation_urls = false;   // the rules are tested here; the live URL check is a network call (SEO_NET=1 runs it once below)
// Prose that passes the quotability rules: 13-word sentences, each paragraph naming its subject, two question headings, two citations.
$sentence = 'Creators price a tier by the gap between tiers and the value fans see. ';
$cite = "The IRS explains self-employment tax at [irs.gov](https://www.irs.gov/businesses/small-businesses-self-employed), and the FTC covers endorsements at [ftc.gov](https://www.ftc.gov/business-guidance).\n\n";
$define = "A membership tier is a monthly price for a set of posts and perks.\n\n";   // the first paragraph defines the subject
$sec = function ($n, $per) use ($sentence, $cite, $define) {
    $b = '';
    for ($i = 1; $i <= $n; $i++) {
        $h = ($i === 1) ? 'How do creators price a tier?' : (($i === 2) ? 'What should the gap be?' : "Part $i");
        $paras = ''; for ($left = max(1, (int) round($per / 13)); $left > 0; $left -= 5) { $paras .= str_repeat($sentence, min(5, $left)) . "\n\n"; }   // paragraphs of five sentences
        $b .= "## $h\n\n" . ($i === 1 ? $define . $cite : '') . $paras . "See [features](/features).\n\n";
    }
    return $b;
};
$body = $sec(4, 320);
$good = array('title' => 'How creators price a membership tier', 'slug' => 'how-creators-price-a-membership-tier',
    'meta_description' => 'A practical guide to pricing membership tiers for creators: how to set the gap between tiers, what fans compare, and when to raise a price.',
    'excerpt' => 'Pricing tiers is mostly about the gap between them.', 'body_md' => $body,
    'faq' => array(array('q' => 'One?', 'a' => 'Yes.'), array('q' => 'Two?', 'a' => 'Yes.'), array('q' => 'Three?', 'a' => 'Yes.')),
    'secondary_keywords' => array('membership pricing', 'creator tiers'), 'cluster' => 'creator-monetization');
check('good article validates', SeoDrafter::validate($good) === array(), implode('; ', SeoDrafter::validate($good)));
$t = $good; $t['title'] = str_repeat('x', 61);                         check('title > 60 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['meta_description'] = str_repeat('x', 156);              check('meta > 155 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['meta_description'] = str_repeat('x', 109);              check('meta < 110 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['faq'] = array();                                        check('no faq allowed', SeoDrafter::validate($t) === array(), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] = str_replace(array('How do creators price a tier?', 'What should the gap be?'), array('Pricing a tier', 'The gap'), $good['body_md']);
                                                                         check('no question headings required (structure is the piece\'s own)', SeoDrafter::validate($t) === array(), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] = str_replace('and the FTC covers endorsements at [ftc.gov](https://www.ftc.gov/business-guidance)', 'and more at [irs.gov](https://www.irs.gov/forms-pubs)', $good['body_md']);
                                                                         check('two citations to one host rejected', (bool) preg_grep('/citations/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] = str_replace($cite, '', $good['body_md']);   check('no citations rejected', (bool) preg_grep('/citations/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nThis is the part most creators skip, and it costs them later.";  check('paragraph opening with "This" rejected', (bool) preg_grep('/opens with/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] = $sec(2, 20) . "## Why?\n\n" . str_repeat('word ', 1200) . ".\n\n"; check('long sentences rejected', (bool) preg_grep('/average/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\n" . str_repeat($sentence, 7);     check('paragraph of 7 sentences rejected', (bool) preg_grep('/7 sentences/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\n" . str_repeat($sentence, 6);     check('paragraph of 6 sentences allowed', !preg_grep('/sentences;/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] = str_replace($define, '', $good['body_md']); check('no opening definition rejected', (bool) preg_grep('/define the subject/', SeoDrafter::validate($t)));
check('opens_with_definition', SeoDrafter::opens_with_definition('A bundle is a set of posts sold at one price.') && SeoDrafter::opens_with_definition('Pay-per-view posts are single posts with a price.') && !SeoDrafter::opens_with_definition('Start by picking a price that fans say yes to.'));
check('cta varies by article and is not a heading', SeoDrafter::cta_html(1) !== SeoDrafter::cta_html(2) && strpos(SeoDrafter::cta_html(3), '<h2') === false && strpos(SeoDrafter::cta_html(0), 'Start earning from your own page') === false);
check('h2_texts', SeoDrafter::h2_texts("## One?\n\ntext\n\n### not\n\n## Two\n") === array('One?', 'Two'));
if (getenv('SEO_NET')) { check('dead citation URL flagged', SeoDrafter::unreachable_citations(array('https://www.irs.gov/this-page-does-not-exist-zz9')) !== array() && SeoDrafter::unreachable_citations(array('https://www.irs.gov/')) === array()); }
$t = $good; $t['slug'] = 'Bad Slug!';                                   check('bad slug rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] = "## A\n\nshort";                            check('short body rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](https://example.com)";          check('external link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](/nowhere-at-all)";               check('unknown internal link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nGreat 🚀";                            check('emoji rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nAs an AI language model I think";     check('"As an AI" rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['faq'] = array(array('q' => 'One?', 'a' => 'Yes.'));    check('a single faq pair rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['faq'] = array(array('q' => 'One?', 'a' => 'Yes.'), array('q' => 'Two?', 'a' => 'Yes.'));    check('two faq pairs allowed', SeoDrafter::validate($t) === array(), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] = "## How do creators price a tier?\n\n" . $define . $cite . str_repeat($sentence, 100); check('one h2 allowed (no set number of sections)', !preg_grep('/section/', SeoDrafter::validate($t)), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] = $define . $cite . str_repeat($sentence, 100); check('zero h2 rejected', (bool) preg_grep('/section/', SeoDrafter::validate($t)));
check('slugify', SeoDrafter::slugify(" Hello, World! It's 2026 ") === 'hello-world-its-2026');
check('reading_minutes >= 1', SeoDrafter::reading_minutes('one two') === 1 && SeoDrafter::reading_minutes(str_repeat('w ', 900)) === 4);
check('allowed_paths has /features and /pricing', in_array('/features', SeoDrafter::allowed_paths(), true) && in_array('/pricing', SeoDrafter::allowed_paths(), true));
$parsed = SeoDrafter::parse_json("```json\n{\"title\":\"t\"}\n```");   check('parse_json strips fences', is_array($parsed) && $parsed['title'] === 't');

// v2 rules (2026-10-09): income promises, names, citation domains, length by intent, dashes, processor, prices, non-refundable
$ok = function ($a) { return SeoDrafter::validate($a) === array(); };
$t = $good; $t['body_md'] .= "\n\nYou will make \$5,000 a month with this.";        check('"you will make" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThis is guaranteed income.";                      check('"guaranteed" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThere is no guaranteed income here.";             check('"no guaranteed income" allowed', $ok($t), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nCreators earn \$3,000 a month on average.";       check('earnings stated as fact rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSome creators earn \$3,000 a month, but it varies."; check('hedged earnings range allowed', $ok($t), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nSarah grew her page in a month.";                 check('first name rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nAsk Dr. Holloway first.";                         check('honorific + name rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nJordan Peel said it best.";                       check('quote attributed to a person rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSee [the IRS](https://www.irs.gov/businesses/small-businesses-self-employed).";  check('citation domain allowed', $ok($t), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nSee [blog](https://medium.com/some-post).";       check('other external domain rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSee [irs](http://www.irs.gov/x).";               check('plain http citation rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSee [fake](https://irs.gov.evil.com/x).";        check('look-alike citation domain rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nShort answer \u{2014} yes.";                         check('em dash rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nPayouts run through Stripe.";                     check('payment processor rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nFans buy credits to unlock posts.";               check('credits without the refund line allowed (nothing is injected or required)', $ok($t), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nFans buy credits to unlock posts. " . PagesController::FINAL_NOTE; check('refund rule copied word for word rejected', (bool) preg_grep('/word for word/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nFees matter here. " . PagesController::fee_sentence(); check('fee sentence copied word for word rejected', (bool) preg_grep('/word for word/', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nThe Creator plan is \$99 a month.";               check('wrong plan price rejected', !$ok($t));
$creator_fee = null; foreach (PlanTiers::all() as $pt) { if ((string) $pt['name'] === 'Creator') { $creator_fee = (int) $pt['limits']['fee_percent']; } }
if ($creator_fee !== null) {
    $t = $good; $t['body_md'] .= "\n\nCreators on the Creator plan pay a $creator_fee% platform fee on each sale, which matters once sales grow."; check('fee stated once in own words allowed', $ok($t), implode('; ', SeoDrafter::validate($t)));
    $t['body_md'] .= "\n\nAgain, the Creator plan fee is $creator_fee% of each sale.";                                                      check('fee stated in two paragraphs rejected', (bool) preg_grep('/stated in 2 paragraphs/', SeoDrafter::validate($t)), implode('; ', SeoDrafter::validate($t)));
}
// the first sentence under a heading names the section's subject
$t = $good; $t['body_md'] .= "\n\n## Payout timing\n\nMost people wait too long before they decide anything at all.\n\n";  check('section opener without the subject rejected', (bool) preg_grep('/must name the subject of the section/', SeoDrafter::validate($t)), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\n## Payout timing\n\nPayouts arrive after you request them, once the balance reaches the minimum.\n\n";  check('section opener naming the subject allowed', !preg_grep('/must name the subject/', SeoDrafter::validate($t)), implode('; ', SeoDrafter::validate($t)));
// the signup aside moves with the article id and is never missing
$html = '<p>intro</p><h2 id="a">A</h2><p>one</p><h2 id="b">B</h2><p>two</p><h2 id="c">C</h2><p>three</p>';
check('place_cta: id 0 appends', substr(SeoDrafter::place_cta($html, 3), -8) === '</aside>');
check('place_cta: id 1 after the first section', strpos(SeoDrafter::place_cta($html, 1), '<p>one</p><aside') !== false);
check('place_cta: id 2 before the last section', strpos(SeoDrafter::place_cta($html, 2), '</aside><h2 id="c">') !== false);
check('place_cta: short article keeps it at the end', substr(SeoDrafter::place_cta('<h2>A</h2><p>x</p>', 1), -8) === '</aside>');
// clean_boilerplate: the injected sentences and their fragments go, long paragraphs split, everything else is untouched
$dirty = "## Fees\n\nFees shape the arithmetic. On Creator Link Studio, " . PagesController::fee_sentence() . " " . PagesController::FINAL_NOTE . "\n\n, with card processing separate.\n\n. Free accounts are for fans and cannot sell, so inbox automation is not included there.\n\n" . PagesController::plan_price_sentence() . " " . PagesController::FINAL_NOTE . "\n\n, and card processing fees are separate. Fifty members on a \$10 tier is \$500 before fees, and a trained character keeps that going.\n\nA normal paragraph stays [as it is](/pricing).\n\n- a list item with " . PagesController::FINAL_NOTE . "\n\n" . str_repeat('Creators price a tier by the gap. ', 8);
$clean = SeoDrafter::clean_boilerplate($dirty);
check('clean: fee sentence removed', strpos($clean, PagesController::fee_sentence()) === false && strpos($clean, 'Free accounts are for fans') === false, $clean);
check('clean: refund line removed from prose, list untouched', substr_count($clean, PagesController::FINAL_NOTE) === 1 && strpos($clean, '- a list item with ' . PagesController::FINAL_NOTE) !== false, $clean);
check('clean: plan price sentence removed', strpos($clean, 'all per month') === false, $clean);
check('clean: dangling lead-in cut back to the last full sentence', strpos($clean, "Fees shape the arithmetic.\n\n") !== false && strpos($clean, 'On Creator Link Studio,') === false, $clean);
check('clean: fragment paragraphs dropped', strpos($clean, 'card processing separate') === false && strpos($clean, 'inbox automation') === false, $clean);
check('clean: fragment clause dropped, the rest kept', strpos($clean, "Fifty members on a \$10 tier is \$500 before fees, and a trained character keeps that going.") !== false && strpos($clean, ', and card processing') === false, $clean);
check('clean: normal paragraph and heading untouched', strpos($clean, "## Fees\n\n") === 0 && strpos($clean, 'A normal paragraph stays [as it is](/pricing).') !== false, $clean);
check('clean: 8-sentence paragraph split into two of 4', substr_count($clean, "Creators price a tier by the gap. Creators price a tier by the gap. Creators price a tier by the gap. Creators price a tier by the gap.\n\n") === 1 && !preg_grep('/sentences;/', SeoDrafter::prose_errors($clean)), $clean);
check('clean: clean input unchanged', SeoDrafter::clean_boilerplate("## A\n\nOne. Two.\n\nThree.\n") === "## A\n\nOne. Two.\n\nThree.\n");
$bold = "**A source of truth.** Every tier with its price. " . PagesController::FINAL_NOTE . "\n\n- a list item with " . PagesController::FINAL_NOTE . "\n";
check('clean: a paragraph opening in bold is prose, a list item is not', SeoDrafter::clean_boilerplate($bold) === "**A source of truth.** Every tier with its price.\n\n- a list item with " . PagesController::FINAL_NOTE . "\n", SeoDrafter::clean_boilerplate($bold));
check('strip_boilerplate_text: FAQ answer', SeoDrafter::strip_boilerplate_text('Yes. ' . PagesController::fee_sentence() . ' ' . PagesController::FINAL_NOTE) === 'Yes.', SeoDrafter::strip_boilerplate_text('Yes. ' . PagesController::fee_sentence()));
check('split_long_paragraph: 13 sentences -> 7 + 6 -> four pieces', count(SeoDrafter::split_long_paragraph(str_repeat('Fans like it. ', 13))) === 3 || count(SeoDrafter::split_long_paragraph(str_repeat('Fans like it. ', 13))) === 4);
$t = $good; $t['body_md'] .= "\n\nWrite {{fee}} here.";                            check('unreplaced token rejected', !$ok($t));
// length by intent (+/- 20%)
$t = $good; $t['intent'] = 'quick'; $t['body_md'] = $sec(4, 175);  check('quick 700 words allowed', $ok($t), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['intent'] = 'quick'; $t['body_md'] = $sec(4, 320);  check('quick 1280 words rejected', !$ok($t));
$t = $good; $t['intent'] = 'howto'; $t['body_md'] = $sec(4, 250);  check('howto 1000 words allowed', $ok($t), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['intent'] = 'guide'; $t['body_md'] = $sec(4, 250);  check('guide 1000 words rejected', !$ok($t));
$t = $good; $t['intent'] = 'guide'; $t['body_md'] = $sec(4, 600);  check('guide 2400 words rejected', !$ok($t));
check('word_bounds quick = 480-960', SeoDrafter::word_bounds('quick') === array(480, 960));
check('intent: question = quick', SeoDrafter::intent_for('how much should i charge for a membership', 'memberships-and-ppv') === 'quick');
check('intent: alternative = guide', SeoDrafter::intent_for('patreon alternative', 'platform-comparisons') === 'guide');
check('intent: how to = howto', SeoDrafter::intent_for('how to sell content bundles', 'creator-monetization') === 'howto');
// clusters: every allowed link exists, pricing never reaches AI pages, AI clusters keep to AI pages
$all_paths = SeoDrafter::allowed_paths(); $missing = array();
foreach (SeoDrafter::CLUSTERS as $c => $def) { foreach (array_merge(array($def['feature']), $def['links']) as $l) { if (!in_array($l, $all_paths, true)) { $missing[] = "$c:$l"; } } foreach ($def['related'] as $r) { if (!isset(SeoDrafter::CLUSTERS[$r])) { $missing[] = "$c related $r"; } } }
check('every cluster link is a real page', $missing === array(), implode(', ', $missing));
$ai_pages = array('/features/ai-influencer', '/lora-character-training', '/consistent-ai-model-face', '/ai-ofm-tools', '/compare/eromify');
check('pricing cluster links no AI page', array_intersect(SeoDrafter::cluster_links('pricing-and-fees'), $ai_pages) === array());
check('pricing family has no AI cluster', array_intersect(SeoDrafter::cluster_family('pricing-and-fees'), array('ai-influencer-monetization', 'lora-character-training', 'ai-dm-chatter')) === array());
check('ai influencer cluster links only AI pages', array_diff(SeoDrafter::cluster_links('ai-influencer-monetization'), array_merge($ai_pages, array('/', '/blog'))) === array());
$t = $good; $t['cluster'] = 'pricing-and-fees'; $t['body_md'] .= "\n\nSee [ai](/features/ai-influencer).";
check('strict: pricing article linking an AI page rejected', (bool) preg_grep('/allow-list/', SeoDrafter::validate($t, 0, true)));
// fit(): nothing injected, dashes out, stray links to text, citations kept, wrong plan price corrected
$creator = null; foreach (PlanTiers::all() as $pt) { if ((string) $pt['name'] === 'Creator') { $creator = $pt; } }
$f = SeoDrafter::fit(array('cluster' => 'pricing-and-fees', 'meta_description' => 'm', 'body_md' => "The fee: {{fee}}\n\nA \u{2014} B, 10\u{2013}20 posts. [x](https://medium.com/a) [irs](https://www.irs.gov/a) [ai](/features/ai-influencer)\n\nThe Creator plan costs \$99 a month."));
check('fit: {{fee}} is not expanded (left for validate to reject)', strpos($f['body_md'], PagesController::fee_sentence()) === false && strpos($f['body_md'], '{{fee}}') !== false);
check('fit: nothing injected', stripos($f['body_md'], 'non-refundable') === false);
check('fit: dashes replaced', !preg_match('/[\x{2013}\x{2014}]/u', $f['body_md']) && strpos($f['body_md'], '10-20') !== false);
check('fit: non-citation link and off-cluster page become text', strpos($f['body_md'], 'medium.com') === false && strpos($f['body_md'], '(/features/ai-influencer)') === false);
check('fit: citation kept', strpos($f['body_md'], '(https://www.irs.gov/a)') !== false);
check('fit: wrong plan price set to config', $creator === null || strpos($f['body_md'], 'Creator plan costs $' . number_format((int) $creator['price']) . ' a month') !== false);
check('sentence_count', SeoDrafter::sentence_count('One two. Three four! Five [six](/x)? ') === 3);
// every offending paragraph is reported, not only the first
$t = $good; $t['body_md'] .= "\n\nThis one is first.\n\nThat one is second.\n\n" . str_repeat($sentence, 7) . "\n\n" . str_repeat($sentence, 8);
$errs = SeoDrafter::prose_errors($t['body_md']);
check('all opener paragraphs reported', count(preg_grep('/opens with/', $errs)) === 2, implode('; ', $errs));
check('all long paragraphs reported', count(preg_grep('/sentences;/', $errs)) === 2, implode('; ', $errs));
// bare citation URLs count as citations
$b = SeoDrafter::linkify_bare_citations("See https://www.irs.gov/forms-pubs. Also <https://www.ftc.gov/x> and [kept](https://www.irs.gov/a) and https://example.com/no.");
check('bare citation URL becomes a link, trailing period kept', strpos($b, '[www.irs.gov](https://www.irs.gov/forms-pubs).') !== false, $b);
check('autolink citation becomes a link', strpos($b, '[www.ftc.gov](https://www.ftc.gov/x)') !== false, $b);
check('existing link and off-list URL untouched', strpos($b, '[kept](https://www.irs.gov/a)') !== false && strpos($b, 'https://example.com/no.') !== false, $b);
check('fit counts a bare citation', count(SeoDrafter::citations(SeoDrafter::fit(array('meta_description' => 'm', 'body_md' => "See https://www.irs.gov/forms-pubs and https://www.ftc.gov/y."))['body_md'])) === 2);
$known = array_keys(SeoDrafter::KNOWN_SOURCES)[2];
check('known source is never fetched', SeoDrafter::unreachable_citations(array($known)) === array());
check('dead URL error names the known pages on that host', strpos(SeoDrafter::known_instead('https://www.ftc.gov/nope'), 'cite one of these on that site instead: https://www.ftc.gov/') !== false);
check('three attempts per draft', SeoDrafter::DRAFT_ATTEMPTS === 3);
$f = SeoDrafter::fit(array('meta_description' => 'm', 'body_md' => "Fans buy credits to unlock posts.\n\nMore text."));
check('fit: nothing appended where credits come up', $f['body_md'] === "Fans buy credits to unlock posts.\n\nMore text.", $f['body_md']);
$h = SeoDrafter::render_body("See [the IRS](https://www.irs.gov/a) and [x](https://medium.com/b) and [pricing](/pricing).");
check('render_body: citation anchor kept, others text', strpos($h, '<a href="https://www.irs.gov/a" rel="nofollow noopener" target="_blank">the IRS</a>') !== false && strpos($h, 'medium.com') === false && strpos($h, '<a href="/pricing">pricing</a>') !== false);

// fix round 1 (review B3)
$errs = function ($a) { return implode('; ', SeoDrafter::validate($a)); };
// I1: allow-list on every validate (no strict flag), fact check helper, stripper
$t = $good; $t['cluster'] = 'pricing-and-fees'; $t['body_md'] = str_replace('(/features)', '(/pricing)', $t['body_md']) . "\n\nSee [ai](/features/ai-influencer).";
check('I1 non-strict validate rejects an off-cluster page link', strpos($errs($t), "allow-list: /features/ai-influencer") !== false, $errs($t));
check('I1 link_errors flags an off-family article', SeoDrafter::link_errors(array('cluster' => 'pricing-and-fees', 'body_md' => '[x](/blog/some-ai-post)'), array('some-ai-post' => 'ai-influencer-monetization')) !== array());
check('I1 link_errors passes an in-family article', SeoDrafter::link_errors(array('cluster' => 'pricing-and-fees', 'body_md' => '[x](/blog/fees-post) [p](/pricing)'), array('fees-post' => 'platform-comparisons')) === array());
$st = SeoDrafter::strip_links('[a](/features/ai-influencer) [p](/pricing) [b](/blog/x)', 'pricing-and-fees', array('x' => 'lora-character-training'));
check('I1 strip_links keeps in-cluster, strips the rest', $st === 'a [p](/pricing) b', $st);
// I2: empty cluster is an error
$t = $good; $t['cluster'] = '';                                     check('I2 empty cluster rejected', strpos($errs($t), 'cluster is required') !== false);
$t = $good; unset($t['cluster']);                                   check('I2 missing cluster rejected', strpos($errs($t), 'cluster is required') !== false);
// I3: admin derives intent the same way as the drafter (word_bounds from intent_for)
check('I3 a quick-answer keyword gets the quick band', SeoDrafter::word_bounds(SeoDrafter::intent_for('how long do creator payouts take', 'creator-payouts')) === array(480, 960));
// I4: income promises
$t = $good; $t['body_md'] .= "\n\nYou can make \$3,000 a month if you post daily.";   check('I4 "you can make $" with "if" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSay you earn \$3,000 a month from fans.";            check('R2 worked example ("say you") allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nYou can make money from day one.";                   check('I4 "you can make money" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nCreators earn 2000 dollars a month here.";           check('I4 "N dollars a month" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThis can be a six-figure income.";                   check('I4 "six-figure" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nTop accounts earn \$900/mo from tips.";               check('I4 "$N/mo" earnings rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSome creators earn between \$500 and \$2,000 a month."; check('I4 honest range allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nWe cannot guarantee results.";                       check('I4 "We cannot guarantee results" allowed', $ok($t), $errs($t));
// I5: fee percentages and plan prices in any form
$t = $good; $t['body_md'] .= "\n\nCreator Link Studio takes 15% on every sale.";        check('I5 typed brand fee 15% rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThe platform fee is 3% on Creator. "; check('I5 fee on the wrong plan rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThe fee is " . PagesController::fee_short() . ". "; check('I5 configured fee_short allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\n" . PagesController::fee_sentence() . ' '; check('I5 configured fee_sentence copied word for word rejected', (bool) preg_grep('/word for word/', SeoDrafter::validate($t)), $errs($t));
$t = $good; $t['body_md'] .= "\n\nThe Creator plan costs \$99 a month. ";  check('I5 "costs $99 a month" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nStudio is \$49/mo. ";       check('I5 another plan\'s price on Studio rejected', !$ok($t));
$live = array(); foreach (PlanTiers::all() as $pt) { if (empty($pt['retired'])) { $live[$pt['name']] = number_format((int) $pt['price']); } }
$fx = SeoDrafter::fit(array('cluster' => 'pricing-and-fees', 'meta_description' => 'm', 'body_md' => "The Creator plan costs \$99/mo. Studio is \$49 a month. \$5 a month for Creator."));
check('I5 fit resets "$99/mo" and the wrong-plan price', isset($live['Creator'], $live['Studio']) && strpos($fx['body_md'], 'Creator plan costs $' . $live['Creator'] . '/mo') !== false && strpos($fx['body_md'], 'Studio is $' . $live['Studio'] . ' a month') !== false && strpos($fx['body_md'], '$' . $live['Creator'] . ' a month for Creator') !== false, $fx['body_md']);
$fx = SeoDrafter::fit(array('cluster' => 'pricing-and-fees', 'meta_description' => 'm', 'body_md' => "Creator Link Studio is \$10 a month for fans of one creator."));
check('I5 fit never touches the brand name', strpos($fx['body_md'], '$10 a month') !== false, $fx['body_md']);
// M1: names
$t = $good; $t['body_md'] .= "\n\nA tip from Jada on pricing.";                      check('M1 name after "from" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThe page of a creator named Jordan grew.";         check('M1 name after "named" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThat is why Brittany's page grew.";                 check('M1 name before "\'s" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nTrack it with Google Analytics, built with Maya, a Mason jar and Sienna tones from Instagram.";
check('M1 SAFE_WORDS pass (Google, Maya, Mason, Sienna, Instagram)', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nHugging Face says the model is open.";              check('M1 "Hugging Face says" allowed', $ok($t), $errs($t));

// fix round 2: fee per named plan from PlanTiers, worked examples, whole-token fee repair
$pf = array(); foreach (PlanTiers::all() as $pt) { if (empty($pt['retired'])) { $pf[$pt['name']] = (int) ($pt['limits']['fee_percent'] ?? 0); } }
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes " . ($pf['Free'] ?? 0) . "% with no monthly fee. ";   check('R2 Free plan at its configured fee allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes 15% with no monthly fee. "; check('R2 Free plan at a wrong fee rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSuppose a creator earns \$2,000 a month in sales.";          check('R2 "suppose" example allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nFor example, revenue of \$500 a month covers it.";            check('R2 "for example" allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nSomewhere above roughly \$2,100 a month gross the ordering flips.";  check('R2 "$X a month gross" worked example allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nYou can run the arithmetic for your own revenue at \$500 a month."; check('R2 "run the arithmetic" allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nIf you post daily you can make \$3,000 a month.";             check('R2 "you can make $X" still rejected inside an "if you"', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nYou will earn \$3,000 a month.";                               check('R2 "you will earn $X" still rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes " . ($pf['Free'] ?? 0) . "% with no monthly fee, Creator takes " . ($pf['Creator'] ?? 0) . "% at \$49 a month, and Studio takes " . ($pf['Studio'] ?? 0) . "% at \$199 a month. ";
check('R2 one sentence with each plan at its own fee allowed', $ok($t), $errs($t));
$fs = PagesController::fee_short();
check('R2 fix_fee_glue leaves intact fee wording alone', SeoDrafter::fix_fee_glue("It is $fs, and Creators know it.") === "It is $fs, and Creators know it.");
check('R2 fix_fee_glue rejoins a split plan name', SeoDrafter::fix_fee_glue('runs 10% on Creato r, 3% on Studi o.') === 'runs 10% on Creator, 3% on Studio.', SeoDrafter::fix_fee_glue('runs 10% on Creato r, 3% on Studi o.'));
check('R2 fix_fee_glue repairs a glued and split word', SeoDrafter::fix_fee_glue('3% on Studiodependin g on your plan') === '3% on Studio, depending on your plan', SeoDrafter::fix_fee_glue('3% on Studiodependin g on your plan'));
check('R2 fix_fee_glue separates a glued word', SeoDrafter::fix_fee_glue('3% on Studiodepending on plan') === '3% on Studio, depending on plan');

// fix round 3: fee figures per clause (competitor clauses skipped), plan prices only when monthly, modal promises
foreach (array("OnlyFans takes 20% while Creator Link Studio's Creator plan takes 10%.", "OnlyFans takes 20%, while the Creator plan takes 10%.", "The Creator plan takes 10%, versus 20% on OnlyFans.", "Fanvue keeps 15% and Creator Link Studio's Studio plan keeps 3%.") as $x) {
    $t = $good; $t['body_md'] .= "\n\n$x "; check('R3 competitor comparison passes: ' . $x, $ok($t), $errs($t));
}
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes 15%. ";       check('R3 "The Free plan takes 15%" still rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nCreator takes 20% of each sale.";                                 check('R3 "Creator takes 20%" still rejected', !$ok($t));
foreach (array('On Studio: $25 minimum payout, $1 = 10 credits.', 'Studio, $1 = 10 credits', '$25 for Creator payouts', 'The Creator plan: $1 = 10 credits.', 'Creator, $25 minimum payout.') as $x) {
    check('R3 fix_plan_prices leaves alone: ' . $x, SeoDrafter::fix_plan_prices($x) === $x, SeoDrafter::fix_plan_prices($x));
    $t = $good; $t['body_md'] .= "\n\n$x "; check('R3 validate accepts: ' . $x, $ok($t), $errs($t));
}
check('R3 "Creator costs $59 a month" reset to config', isset($live['Creator']) && SeoDrafter::fix_plan_prices('Creator costs $59 a month.') === 'Creator costs $' . $live['Creator'] . ' a month.', SeoDrafter::fix_plan_prices('Creator costs $59 a month.'));
$t = $good; $t['body_md'] .= "\n\nIf you post daily you might make \$5,000 a month.";     check('R3 "if you ... might make $X" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nCreators make \$5,000 a month if you post daily.";      check('R3 "Creators make $X a month if you post daily" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nYou'd earn \$800 a month from this.";                   check('R3 "you\'d earn $X" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSay you earn \$2,000 a month.";                          check('R3 "Say you earn $2,000 a month" passes', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nYou can run the arithmetic for your own revenue at \$500 a month."; check('R3 "run the arithmetic" passes', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nWork backwards from the income you want: 50 members at \$20 a month and 100 at \$10 a month."; check('R3 "work backwards" arithmetic passes', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nThe fee is the single biggest lever on your income, and it compounds: a ten-point difference on \$2,000 a month is \$200."; check('R3 "point difference" arithmetic passes', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nExtra AI influencers are \$15 a month each on the Creator plan, up to five. "; check('R3 add-on price next to a plan name passes', $ok($t), $errs($t));
check('R3 add-on price is not rewritten', strpos(SeoDrafter::fix_plan_prices('Extra AI influencers are $15 a month each on the Creator plan.'), '$15 a month') !== false, 'rewritten');
check('R3 a wrong plan price is still rewritten', strpos(SeoDrafter::fix_plan_prices('Creator costs $59 a month.'), '$49 a month') !== false, 'not rewritten');
// corpus shape (2026-10-11): too many FAQs or one section count across the blog flags the oldest articles for a rewrite
$mk = function ($i, $sections, $faq) { return array('id' => $i, 'slug' => 'a' . $i, 'published_at' => sprintf('2026-09-%02d 00:00:00', $i), 'faq' => $faq ? '[{"q":"Q?","a":"A."}]' : '[]', 'body_md' => str_repeat("## S\n\ntext\n\n", $sections)); };
$corpus = array(); for ($i = 1; $i <= 10; $i++) { $corpus[] = $mk($i, 5, true); }   // every article: 5 sections + FAQ
$flags = SeoDrafter::corpus_shape_flags($corpus);
check('shape: excess FAQs flagged, oldest first', count(array_filter($flags, function ($f) { return !empty($f['avoid']['faq']); })) === 7 && isset($flags['a1']['avoid']['faq']) && !isset($flags['a10']['avoid']['faq']), json_encode(array_keys($flags)));
check('shape: dominant section count flagged', count(array_filter($flags, function ($f) { return isset($f['avoid']['sections']) && $f['avoid']['sections'] === 5; })) === 6, json_encode($flags));
$varied = array(); for ($i = 1; $i <= 10; $i++) { $varied[] = $mk($i, 2 + ($i % 5), $i % 4 === 0); }
check('shape: a varied corpus is not flagged', SeoDrafter::corpus_shape_flags($varied) === array(), json_encode(SeoDrafter::corpus_shape_flags($varied)));
check('shape: a corpus under 5 articles is left alone', SeoDrafter::corpus_shape_flags(array_slice($corpus, 0, 4)) === array());
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
