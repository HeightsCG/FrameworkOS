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
$body = ''; for ($i = 1; $i <= 4; $i++) { $body .= "## Section $i\n\n" . str_repeat('word ', 320) . "See [features](/features).\n\n"; }
$good = array('title' => 'How creators price a membership tier', 'slug' => 'how-creators-price-a-membership-tier',
    'meta_description' => 'A practical guide to pricing membership tiers for creators, with the numbers that matter.',
    'excerpt' => 'Pricing tiers is mostly about the gap between them.', 'body_md' => $body,
    'faq' => array(array('q' => 'One?', 'a' => 'Yes.'), array('q' => 'Two?', 'a' => 'Yes.'), array('q' => 'Three?', 'a' => 'Yes.')),
    'secondary_keywords' => array('membership pricing', 'creator tiers'), 'cluster' => 'creator-monetization');
check('good article validates', SeoDrafter::validate($good) === array(), implode('; ', SeoDrafter::validate($good)));
$t = $good; $t['title'] = str_repeat('x', 71);                         check('title > 70 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['meta_description'] = str_repeat('x', 156);              check('meta > 155 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['slug'] = 'Bad Slug!';                                   check('bad slug rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] = "## A\n\nshort";                            check('short body rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](https://example.com)";          check('external link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](/nowhere-at-all)";               check('unknown internal link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nGreat 🚀";                            check('emoji rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nAs an AI language model I think";     check('"As an AI" rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['faq'] = array(array('q' => 'One?', 'a' => 'Yes.'));    check('fewer than 3 faq rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] = "## Only\n\n" . str_repeat('word ', 1300); check('fewer than 3 h2 rejected', SeoDrafter::validate($t) !== array());
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
$t = $good; $t['body_md'] .= "\n\nFans buy credits to unlock posts.";               check('credits without non-refundable rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nFans buy credits to unlock posts. " . PagesController::FINAL_NOTE; check('credits with non-refundable allowed', $ok($t), implode('; ', SeoDrafter::validate($t)));
$t = $good; $t['body_md'] .= "\n\nThe Creator plan is \$99 a month. " . PagesController::FINAL_NOTE; check('wrong plan price rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nWrite {{fee}} here.";                            check('unreplaced token rejected', !$ok($t));
// length by intent (+/- 20%)
$sec = function ($n, $per) { $b = ''; for ($i = 1; $i <= $n; $i++) { $b .= "## Part $i\n\n" . str_repeat('word ', $per) . "\n\n"; } return $b; };
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
// fit(): config sentences for tokens, dashes out, stray links to text, citations kept, wrong plan price corrected
$creator = null; foreach (PlanTiers::all() as $pt) { if ((string) $pt['name'] === 'Creator') { $creator = $pt; } }
$f = SeoDrafter::fit(array('cluster' => 'pricing-and-fees', 'meta_description' => 'm', 'body_md' => "The fee: {{fee}}\n\nA \u{2014} B, 10\u{2013}20 posts. [x](https://medium.com/a) [irs](https://www.irs.gov/a) [ai](/features/ai-influencer)\n\nThe Creator plan costs \$99 a month."));
check('fit: {{fee}} becomes fee_sentence', strpos($f['body_md'], PagesController::fee_sentence()) !== false && strpos($f['body_md'], '{{') === false);
check('fit: dashes replaced', !preg_match('/[\x{2013}\x{2014}]/u', $f['body_md']) && strpos($f['body_md'], '10-20') !== false);
check('fit: non-citation link and off-cluster page become text', strpos($f['body_md'], 'medium.com') === false && strpos($f['body_md'], '(/features/ai-influencer)') === false);
check('fit: citation kept', strpos($f['body_md'], '(https://www.irs.gov/a)') !== false);
check('fit: wrong plan price set to config', $creator === null || strpos($f['body_md'], 'Creator plan costs $' . number_format((int) $creator['price']) . ' a month') !== false);
check('fit: non-refundable present', stripos($f['body_md'], 'non-refundable') !== false);
$f = SeoDrafter::fit(array('meta_description' => 'm', 'body_md' => "Fans buy credits to unlock posts.\n\nMore text."));
check('fit: non-refundable appended where credits come up', strpos($f['body_md'], 'Fans buy credits to unlock posts. ' . PagesController::FINAL_NOTE) !== false);
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
$t = $good; $t['body_md'] .= "\n\nThe platform fee is 3% on Creator. " . PagesController::FINAL_NOTE; check('I5 fee on the wrong plan rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThe fee is " . PagesController::fee_short() . ". " . PagesController::FINAL_NOTE; check('I5 configured fee_short allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\n" . PagesController::fee_sentence() . ' ' . PagesController::FINAL_NOTE; check('I5 configured fee_sentence allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nThe Creator plan costs \$99 a month. " . PagesController::FINAL_NOTE;  check('I5 "costs $99 a month" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nStudio is \$49/mo. " . PagesController::FINAL_NOTE;       check('I5 another plan\'s price on Studio rejected', !$ok($t));
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
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes " . ($pf['Free'] ?? 0) . "% with no monthly fee. " . PagesController::FINAL_NOTE;   check('R2 Free plan at its configured fee allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes 15% with no monthly fee. " . PagesController::FINAL_NOTE; check('R2 Free plan at a wrong fee rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSuppose a creator earns \$2,000 a month in sales.";          check('R2 "suppose" example allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nFor example, revenue of \$500 a month covers it.";            check('R2 "for example" allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nSomewhere above roughly \$2,100 a month gross the ordering flips.";  check('R2 "$X a month gross" worked example allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nYou can run the arithmetic for your own revenue at \$500 a month."; check('R2 "run the arithmetic" allowed', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nIf you post daily you can make \$3,000 a month.";             check('R2 "you can make $X" still rejected inside an "if you"', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nYou will earn \$3,000 a month.";                               check('R2 "you will earn $X" still rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes " . ($pf['Free'] ?? 0) . "% with no monthly fee, Creator takes " . ($pf['Creator'] ?? 0) . "% at \$49 a month, and Studio takes " . ($pf['Studio'] ?? 0) . "% at \$199 a month. " . PagesController::FINAL_NOTE;
check('R2 one sentence with each plan at its own fee allowed', $ok($t), $errs($t));
$fs = PagesController::fee_short();
check('R2 fix_fee_glue leaves intact fee wording alone', SeoDrafter::fix_fee_glue("It is $fs, and Creators know it.") === "It is $fs, and Creators know it.");
check('R2 fix_fee_glue rejoins a split plan name', SeoDrafter::fix_fee_glue('runs 10% on Creato r, 3% on Studi o.') === 'runs 10% on Creator, 3% on Studio.', SeoDrafter::fix_fee_glue('runs 10% on Creato r, 3% on Studi o.'));
check('R2 fix_fee_glue repairs a glued and split word', SeoDrafter::fix_fee_glue('3% on Studiodependin g on your plan') === '3% on Studio, depending on your plan', SeoDrafter::fix_fee_glue('3% on Studiodependin g on your plan'));
check('R2 fix_fee_glue separates a glued word', SeoDrafter::fix_fee_glue('3% on Studiodepending on plan') === '3% on Studio, depending on plan');

// fix round 3: fee figures per clause (competitor clauses skipped), plan prices only when monthly, modal promises
foreach (array("OnlyFans takes 20% while Creator Link Studio's Creator plan takes 10%.", "OnlyFans takes 20%, while the Creator plan takes 10%.", "The Creator plan takes 10%, versus 20% on OnlyFans.", "Fanvue keeps 15% and Creator Link Studio's Studio plan keeps 3%.") as $x) {
    $t = $good; $t['body_md'] .= "\n\n$x " . PagesController::FINAL_NOTE; check('R3 competitor comparison passes: ' . $x, $ok($t), $errs($t));
}
$t = $good; $t['body_md'] .= "\n\nThe Free plan takes 15%. " . PagesController::FINAL_NOTE;       check('R3 "The Free plan takes 15%" still rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nCreator takes 20% of each sale.";                                 check('R3 "Creator takes 20%" still rejected', !$ok($t));
foreach (array('On Studio: $25 minimum payout, $1 = 10 credits.', 'Studio, $1 = 10 credits', '$25 for Creator payouts', 'The Creator plan: $1 = 10 credits.', 'Creator, $25 minimum payout.') as $x) {
    check('R3 fix_plan_prices leaves alone: ' . $x, SeoDrafter::fix_plan_prices($x) === $x, SeoDrafter::fix_plan_prices($x));
    $t = $good; $t['body_md'] .= "\n\n$x " . PagesController::FINAL_NOTE; check('R3 validate accepts: ' . $x, $ok($t), $errs($t));
}
check('R3 "Creator costs $59 a month" reset to config', isset($live['Creator']) && SeoDrafter::fix_plan_prices('Creator costs $59 a month.') === 'Creator costs $' . $live['Creator'] . ' a month.', SeoDrafter::fix_plan_prices('Creator costs $59 a month.'));
$t = $good; $t['body_md'] .= "\n\nIf you post daily you might make \$5,000 a month.";     check('R3 "if you ... might make $X" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nCreators make \$5,000 a month if you post daily.";      check('R3 "Creators make $X a month if you post daily" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nYou'd earn \$800 a month from this.";                   check('R3 "you\'d earn $X" rejected', !$ok($t));
$t = $good; $t['body_md'] .= "\n\nSay you earn \$2,000 a month.";                          check('R3 "Say you earn $2,000 a month" passes', $ok($t), $errs($t));
$t = $good; $t['body_md'] .= "\n\nYou can run the arithmetic for your own revenue at \$500 a month."; check('R3 "run the arithmetic" passes', $ok($t), $errs($t));
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
