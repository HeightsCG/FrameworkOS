<?php
/**
 * One alternatives list page (/onlyfans-alternatives, /fanvue-alternatives), from PagesController::alternatives_page().
 * Locals: $p (the filled page row with 'us' first), $faq.
 */
$e = function ($s) { return Sections::e($s); }; $site = Main::site_name();
$signup = '/?auth=register&role=creator';
$rows = array(array($p['us']['name'], $p['us']['kind'], true));
foreach ($p['others'] as $o) { $rows[] = array($o['name'], $o['kind']); }
echo Sections::panel_hero(array(
    'title'   => $p['title'],
    'lead'    => $p['intro'],
    'buttons' => array(array('Start Free', $signup, 'primary', 'register'), array('Compare All Platforms', '/best-creator-monetization-platforms', 'secondary')),
    'panel'   => Sections::pane('On this list', count($rows) . ' platforms, in the order below.', Sections::pane_rows($rows)),
));

// The numbered list: us first (we build it and say so), then each platform with its kind of platform and who it suits.
echo Sections::open('white', 'Which ' . $p['name'] . ' alternatives are worth considering?', 'We build ' . $site . ', so it is first, and we say so. The others are described by the kind of platform each one is, not by prices or fees, which change. Check their own pages for current numbers.');
$cards = array(array('title' => '1. ' . $p['us']['name'], 'text' => $p['us']['text'], 'points' => $p['us']['points'], 'note' => 'Best for: ' . $p['us']['best_for'], 'link' => array('See All Features', '/features')));
$n = 2;
foreach ($p['others'] as $o) {
    $card = array('title' => ($n++) . '. ' . $o['name'], 'text' => $o['text'], 'note' => 'Best for: ' . $o['best_for']);
    if ($o['compare'] !== '') { $card['link'] = array('Compare ' . $site . ' with ' . $o['name'], '/compare/' . $o['compare']); }
    $cards[] = $card;
}
echo '<div class="alt-list">' . Sections::cards($cards, 2) . '</div>';
echo '<p class="sx-note">' . $e($p['us']['note']) . ' For a sourced, side-by-side look, read <a href="/compare/' . $e(strtolower($p['name'])) . '">' . $e($site . ' vs ' . $p['name']) . '</a>.</p>';
echo Sections::close();

echo PagesController::what_you_get_section();   // the product facts already published on /features, /pricing and /features/payouts
if (!empty($faq)) { echo Sections::faq($faq); }

// Closing band (the layout band is off for this page so the button can carry the creator role).
echo '<section class="sx sx--cta"><div class="ld-wrap sx__in sx-cta"><div><h2 class="sx-cta__title">' . $e(Sections::tc('Try it alongside ' . $p['name'] . '.')) . '</h2>'
   . '<p class="sx-cta__text">' . $e('Build your page, publish to your socials and keep your ' . $p['name'] . ' page live while your fans move.') . '</p></div>'
   . '<div class="sx-acts"><a class="sx-btn sx-btn--secondary" href="' . $e($signup) . '" data-auth="register">Start Free</a><a class="sx-btn sx-btn--ghost" href="/pricing">See Pricing</a></div></div></section>';
