<?php
$e = function ($s) { return Sections::e($s); }; $us = PagesController::our_facts(); $site = Main::site_name();
$labels = array('fee' => 'Platform fee', 'payout' => 'Payouts', 'content' => 'What you can sell', 'socials' => 'Social publishing', 'ai' => 'AI tools', 'ownership' => 'Your audience');
$has_reported = false; foreach ($labels as $k => $l) { if (!empty($c[$k]['reported'])) { $has_reported = true; break; } }
echo Sections::panel_hero(array(
    'title' => $site . ' vs ' . $c['name'],
    'lead' => ($slug === 'onlyfans' ? PagesController::quotable_facts()['onlyfans'] : $c['summary']) . ' Here is how the two compare, with sources.',   // onlyfans: the quotable brand sentence replaces the summary
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('Platform fee', 'What each platform keeps from your sales.', Sections::pane_versus($site, $us['fee'], $c['name'], (string) ($c['fee_short'] ?? $c['fee']['value']), $c['fee']['source'])),
    'bg_image' => SiteImages::bg('compare_hero'),
));
echo Sections::open('alt', 'Feature-by-Feature Comparison', $c['name'] . ' details checked on ' . date('F j, Y', strtotime($c['checked'])) . ' from the linked pages.');
$trows = array();
foreach ($labels as $k => $l) {
    $cell = $e($c[$k]['value']) . ' <a class="sx-src" href="' . $e($c[$k]['source']) . '" rel="nofollow noopener" target="_blank">source</a>' . (!empty($c[$k]['reported']) ? ' <span class="sx-src">reported</span>' : '');
    $trows[] = array($e($l), $e($us[$k]), $cell);
}
echo Sections::table(array('', $site, $c['name']), $trows, 1);
if ($has_reported) { echo '<p class="sx-note">' . $e($c['name']) . ' blocks automated access to its help pages, so figures marked "reported" come from published third-party reporting linked in the table. If something has changed, tell us and we will update it.</p>'; }
echo Sections::close();

// One balanced choice: each side gets a one-line summary and its points, so neither column sits half empty.
echo Sections::open('white', $c['name'] . ' or ' . $site . '?');
echo Sections::cards(array(
    array('title' => 'Why choose ' . $c['name'], 'text' => (string) ($c['best_for'] ?? ('Your audience is already on ' . $c['name'] . ' and it covers everything you sell.')),
          'points' => (array) ($c['strengths'] ?? array())),
    array('title' => 'Why choose ' . $site, 'text' => 'You want one page that sells everything and a studio that publishes it everywhere.', 'points' => array(
        'You post to several social networks and want one studio that publishes to all of them',
        'You sell more than subscriptions: pay-per-view, bundles, services, events and links',
        'You want AI to draft captions and DM replies in your voice, with your approval',
        'You want ' . PagesController::fee_short() . ' instead of a flat 20%')),
), 2);
echo Sections::close();

if (!empty($faq)) { echo Sections::faq($faq); }
$cta_title = 'Try it alongside ' . $c['name'] . '.';
