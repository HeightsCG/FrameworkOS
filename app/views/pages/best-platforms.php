<?php
$e = function ($s) { return Sections::e($s); }; $us = PagesController::our_facts(); $site = Main::site_name();
$fee_rows = array(array($site, $us['fee'], true));
foreach (PagesController::COMPETITORS as $slug => $cp) { $fee_rows[] = array($cp['name'], $cp['fee']['value']); }
echo Sections::panel_hero(array(
    'title' => 'Best creator monetization platforms in ' . date('Y'),
    'lead' => 'There is no single best platform; there is the best fit for how you sell. This guide compares the main options on fee, what you can sell, payouts, social publishing and ownership. We build ' . $site . ', and we say so where it matters.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('Platform fees', 'What each platform keeps from your sales.', Sections::pane_rows($fee_rows)),
    'bg_image' => SiteImages::bg('best_hero'),
));
echo Sections::open('white', 'How to judge a platform');
echo Sections::cards(array(
    array('icon' => 'bank', 'title' => 'Fee', 'text' => 'Subscription platforms keep about 20%. Link-in-bio tools charge a monthly price plus 0% to 12% of sales. A fee that falls as you grow matters once you pass a few thousand a month.'),
    array('icon' => 'package', 'title' => 'What you can sell', 'text' => 'Subscriptions only, or also pay-per-view, bundles, services and events.'),
    array('icon' => 'send', 'title' => 'Where your fans are', 'text' => 'A platform that publishes to your socials brings people in; one that does not makes you do it by hand.'),
    array('icon' => 'shield', 'title' => 'Ownership and payouts', 'text' => 'Can you export your audience and media if you leave, and how long is the payout hold?'),
), 4);
echo Sections::close();

foreach (PagesController::COMPETITOR_GROUPS as $type => $g) {
    $rows = array();
    foreach (PagesController::COMPETITORS as $slug => $c) {
        if (($c['type'] ?? '') !== $type) { continue; }
        $rows[] = array('<strong>' . $e($c['name']) . '</strong>', $e($c['fee']['value']), $e($c['content']['value']), $e($c['payout']['value']), '<a href="/compare/' . $e($slug) . '" aria-label="' . $e($site . ' vs ' . $c['name']) . '">vs ' . $e($c['name']) . '</a>');
    }
    if (empty($rows)) { continue; }
    echo Sections::open($type === 'membership' ? 'white' : 'alt', $g[0], $g[1]);
    echo Sections::table(array('Platform', 'Fee', 'What you can sell', 'Payouts', ''), $rows, -1);
    echo Sections::close();
}

echo Sections::open('white', 'Who each platform suits');
$fit = array();
foreach (PagesController::COMPETITORS as $slug => $c) {
    $fit[] = array('title' => $c['name'], 'text' => (string) ($c['best_for'] ?? $c['summary']), 'link' => array('Compare with ' . $c['name'], '/compare/' . $slug));
}
$fit[] = array('title' => $site, 'text' => 'Creators who sell more than subscriptions (pay-per-view, bundles, services and events) from one page, publish to all their socials from one studio, and want a fee that falls as they grow.', 'link' => array('See the features', '/features'));
echo Sections::cards($fit, 3);
echo Sections::close();

echo Sections::open('alt', 'How we compared');
echo '<p class="sx-p">Every figure comes from the platform\'s own pricing, help or legal pages, linked on each comparison and checked on the date shown there. Where a platform blocks automated access, we use published third-party reporting and label it "reported". "Not published" means we could not find the platform stating it. We build ' . $e($site) . ', so read our own row with that in mind, and tell us if anything has changed.</p>';
echo Sections::close();

if (!empty($faq)) { echo Sections::faq($faq, 'white'); }
$cta_title = 'See it with your own page.';
