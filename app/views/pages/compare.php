<?php
$e = function ($s) { return Sections::e($s); }; $us = PagesController::our_facts(); $site = Main::site_name();
$dot = function ($s) { $s = trim((string) $s); return ($s === '' || preg_match('/[.!?]$/', $s)) ? $s : $s . '.'; };   // every cell and point is a sentence, so table text never runs together
$labels = array('fee' => 'Platform fee', 'payout' => 'Payouts', 'content' => 'What you can sell', 'socials' => 'Social publishing', 'ai' => 'AI tools', 'ownership' => 'Your audience');
$has_reported = false; foreach ($labels as $k => $l) { if (!empty($c[$k]['reported'])) { $has_reported = true; break; } }
echo Sections::panel_hero(array(
    'title' => $site . ' vs ' . $c['name'],
    'lead' => ($slug === 'onlyfans' ? PagesController::quotable_facts()['onlyfans'] : $c['summary']) . ' ' . (CompareProse::voice($slug, 'hero_tail') ?: 'Here is how the two compare, with sources.'),   // onlyfans: the quotable brand sentence replaces the summary; the tail is worded per competitor
    'buttons' => array(array('Start Free', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('What does ' . $c['name'] . ' charge, and what do we charge?', 'What each platform keeps from your sales.', Sections::pane_versus($site, $us['fee'], $c['name'], (string) ($c['fee_short'] ?? $c['fee']['value']), $c['fee']['source'])),
    'bg_image' => SiteImages::bg('compare_hero'),
    'after_html' => isset(AlternativesPages::PAGES[$slug]) ? '<a class="sx-more" href="/' . $e($slug) . '-alternatives">See All ' . $e($c['name']) . ' Alternatives</a>' : '',
));
echo Sections::open('alt', 'How do the features compare?', CompareProse::voice($slug, 'checked') !== '' ? str_replace('{date}', date('F j, Y', strtotime($c['checked'])), CompareProse::voice($slug, 'checked')) : $c['name'] . ' details checked on ' . date('F j, Y', strtotime($c['checked'])) . ' from the linked pages.');
$trows = array();
foreach ($labels as $k => $l) {
    $cell = $e($dot($c[$k]['value'])) . ' <a class="sx-src" href="' . $e($c[$k]['source']) . '" rel="noopener" target="_blank">source</a>' . (!empty($c[$k]['reported']) ? ' <span class="sx-src">reported</span>' : '');
    $trows[] = array($e($l), $e($dot($us[$k])), $cell);
}
echo Sections::table(array('', $site, $c['name']), $trows, 1);
if ($has_reported) { echo '<p class="sx-note">' . $e($c['name']) . ' blocks automated access to its help pages, so figures marked "reported" come from published third-party reporting linked in the table. If something has changed, tell us and we will update it.</p>'; }
echo Sections::close();

// One balanced choice: each side gets a one-line summary and its points, so neither column sits half empty.
// The fee point appears only where the competitor's own sourced rate is a single percentage above ours (PagesController::fee_point).
$us_points = array_values(array_filter(array_merge(CompareProse::voice_list($slug, 'why_points') ?: array(
    'You post to several social networks and want one studio that publishes to all of them',
    'You sell more than subscriptions: pay-per-view, bundles, services, events and links',
    'You want AI to draft captions and DM replies in your voice, with your approval'), array(PagesController::fee_point($c)))));
echo Sections::open('white', $c['name'] . ' or ' . $site . '?');
echo Sections::cards(array(
    array('title' => 'Why choose ' . $c['name'], 'text' => (string) ($c['best_for'] ?? ('Your audience is already on ' . $c['name'] . ' and it covers everything you sell.')),
          'points' => array_map($dot, (array) ($c['strengths'] ?? array()))),
    array('title' => 'Why choose ' . $site, 'text' => CompareProse::voice($slug, 'why_text') ?: 'You want one page that sells everything and a studio that publishes it everywhere.',
          'points' => array_map($dot, (array) ($c['us_points'] ?? $us_points))),
), 2);
echo Sections::close();

echo CompareProse::render(CompareProse::compare($slug), 'alt');   // this competitor's own prose: who it suits, what moving involves, the trade-offs, a computed scenario
$fee_block = PagesController::fee_example_section(array($slug), 'white', array(1000, 5000), '', $slug);   // the fee table and chart on round sales figures, our plans beside the competitor's sourced rate ('' for a tool that sells nothing)
if ($fee_block === '') {   // a generation tool with no sales side: the diagram shows where the two products sit in a creator's workflow (g_multimodal), from the facts already on this page
    $fee_block = Sections::open('white', 'Where does each product sit in the workflow?', $c['name'] . ' covers the first step; ' . $site . ' covers all four on one page.')
        . Sections::flow_svg(array('Generate the character', 'Publish to your socials', 'Sell memberships, pay-per-view and bundles', 'Cash out to your bank'), 'From a generated character to a payout on ' . $site)
        . Sections::close();
}
echo $fee_block;
echo CompareProse::links_line($slug);   // the shared product blocks live on /features, /pricing and /features/payouts, not here
if (!empty($faq)) { echo Sections::faq($faq); }
$cta_title = 'Try it alongside ' . $c['name'] . '.';
$cta_text = CompareProse::voice($slug, 'cta') ?: 'Memberships, pay-per-view, services and events, with payouts to your bank.';
