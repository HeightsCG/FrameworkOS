<?php
$e = function ($s) { return Sections::e($s); }; $us = PagesController::our_facts(); $site = Main::site_name();
$fee_rows = array(array($site, $us['fee'], true));
foreach (PagesController::COMPETITORS as $slug => $cp) { $fee_rows[] = array($cp['name'], $cp['fee']['value']); }
echo Sections::panel_hero(array(
    'title' => 'Best creator monetization platforms in ' . date('Y'),
    'lead' => 'There is no single best platform; there is the best fit for how you sell. This guide compares the main options on fee, what you can sell, payouts, social publishing and ownership. We build ' . $site . ', and we say so where it matters.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('Platform fees', 'What each platform keeps from your sales.', Sections::pane_rows($fee_rows)),
    'bg_image' => SiteImages::url('best_hero'),
));
echo Sections::open('white', 'How to judge a platform');
echo Sections::cards(array(
    array('icon' => 'bank', 'title' => 'Fee', 'text' => 'Flat 20% is the norm. A fee that falls as you grow matters once you pass a few thousand a month.'),
    array('icon' => 'package', 'title' => 'What you can sell', 'text' => 'Subscriptions only, or also pay-per-view, bundles, services and events.'),
    array('icon' => 'send', 'title' => 'Where your fans are', 'text' => 'A platform that publishes to your socials brings people in; one that does not makes you do it by hand.'),
    array('icon' => 'shield', 'title' => 'Ownership and payouts', 'text' => 'Can you export your audience and media if you leave, and how long is the payout hold?'),
), 4);
echo Sections::close();

echo Sections::open('alt', 'The platforms side by side');
$rows = array(array('<strong>' . $e($site) . '</strong>', $e($us['fee']), $e($us['content']), $e($us['payout']), '<a href="/features">Features</a>'));
foreach (PagesController::COMPETITORS as $slug => $c) {
    $rows[] = array('<strong>' . $e($c['name']) . '</strong>', $e($c['fee']['value']), $e($c['content']['value']), $e($c['payout']['value']), '<a href="/compare/' . $e($slug) . '">Full comparison</a>');
}
$rows[] = array('<strong>Patreon, Ko-fi, Buy Me a Coffee</strong>', 'Published on each pricing page', 'Tips and simple memberships', 'Varies', '');
echo Sections::table(array('Platform', 'Fee', 'What you can sell', 'Payouts', ''), $rows, -1);
echo Sections::close();

echo Sections::open('white', 'Which one');
echo Sections::cards(array(
    array('title' => 'OnlyFans', 'text' => 'Subscriptions and messages only, with the largest existing audience.', 'link' => array('Compare with OnlyFans', '/compare/onlyfans')),
    array('title' => 'Fanvue', 'text' => 'Subscriptions with AI-creator features.', 'link' => array('Compare with Fanvue', '/compare/fanvue')),
    array('title' => 'Patreon or Ko-fi', 'text' => 'Tips and light memberships around a podcast, newsletter or creative project.'),
    array('title' => $site, 'text' => 'Everything you sell on one page, published to all your socials, with a fee that falls as you grow.', 'link' => array('See the features', '/features')),
), 4);
echo Sections::close();
$cta_title = 'See it with your own page.';
