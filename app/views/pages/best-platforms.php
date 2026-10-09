<?php
$e = function ($s) { return Sections::e($s); }; $us = PagesController::our_facts(); $site = Main::site_name();
$fee_rows = array(array($site, $us['fee'], true));
// The hero panel stays short: one example per kind of platform, as one-line fees. Every platform is in the tables below.
foreach (array('onlyfans', 'patreon', 'kofi', 'linktree') as $slug) {
    $cp = PagesController::COMPETITORS[$slug] ?? null;
    if ($cp) { $fee_rows[] = array($cp['name'], (string) ($cp['fee_short'] ?? $cp['fee']['value'])); }
}
echo Sections::panel_hero(array(
    'title' => 'Best creator monetization platforms in ' . date('Y'),
    'lead' => 'A creator monetization platform is a service that lets you charge fans for content, memberships or services and pays you out. There is no single best one; there is the best fit for how you sell. This guide compares the main options on fee, what you can sell, payouts, social publishing and ownership. We build ' . $site . ', and we say so where it matters.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('Platform fees', 'What each platform keeps from your sales.', Sections::pane_rows($fee_rows)),
    'bg_image' => SiteImages::bg('best_hero'),
));
echo Sections::open('white', 'How do you judge a creator platform?');
echo Sections::cards(array(
    array('icon' => 'bank', 'title' => 'Fee', 'text' => 'Subscription platforms keep about 20%. Link-in-bio tools charge a monthly price plus 0% to 12% of sales. ' . Main::site_name() . ' takes ' . PagesController::fee_short() . ', which matters once you pass a few thousand a month.'),
    array('icon' => 'package', 'title' => 'What you can sell', 'text' => 'Subscriptions only, or also pay-per-view, bundles, services and events.'),
    array('icon' => 'send', 'title' => 'Where your fans are', 'text' => 'A platform that publishes to your socials brings people in; one that does not makes you do it by hand.'),
    array('icon' => 'shield', 'title' => 'Ownership and payouts', 'text' => 'Can you export your audience and media if you leave, and how long is the payout hold?'),
), 4);
echo Sections::close();

// One scannable table: every platform, one line each. The full sourced detail lives on each /compare page.
$kind = array('fan' => 'Subscription platform', 'membership' => 'Memberships and tips', 'bio' => 'Link-in-bio storefront');
echo Sections::open('alt', 'All platforms at a glance', 'One line per platform. Open a comparison for payouts, what you can sell, social publishing and audience export, each with its source.');
$rows = array(array('<span class="bp-us">' . $e($site) . '</span>', 'Creator platform', '<strong>' . $e($us['fee']) . '</strong>', '<a class="sx-tlink" href="/features">See features</a>'));
foreach (PagesController::COMPETITOR_GROUPS as $type => $g) {
    foreach (PagesController::COMPETITORS as $slug => $c) {
        if (($c['type'] ?? '') !== $type) { continue; }
        $rows[] = array($e($c['name']), $e($kind[$type]), $e((string) ($c['fee_short'] ?? $c['fee']['value'])),
            '<a class="sx-tlink" href="/compare/' . $e($slug) . '" aria-label="' . $e($site . ' vs ' . $c['name']) . '">Compare</a>');
    }
}
echo Sections::table(array('Platform', 'Kind', 'Fee', ''), $rows, -1);
echo '<p class="sx-note">Figures come from each platform\'s own pricing, help or legal pages, linked and dated on its comparison page. Where a platform blocks automated access we use published reporting and label it "reported". We build ' . $e($site) . ', so read our row with that in mind.</p>';
echo '<p class="sx-note">Leaving one platform in particular? See the <a href="/onlyfans-alternatives">OnlyFans alternatives</a> and <a href="/fanvue-alternatives">Fanvue alternatives</a> lists.</p>';
echo Sections::close();

// Who each suits, grouped by kind so the nine options read as three short lists.
echo Sections::open('white', 'Which platform suits which creator?');
foreach (PagesController::COMPETITOR_GROUPS as $type => $g) {
    $cards = array();
    foreach (PagesController::COMPETITORS as $slug => $c) {
        if (($c['type'] ?? '') !== $type) { continue; }
        $cards[] = array('title' => $c['name'], 'text' => (string) ($c['best_for'] ?? $c['summary']), 'link' => array('Compare with ' . $c['name'], '/compare/' . $slug));
    }
    if (empty($cards)) { continue; }
    echo '<div class="bp-group"><h3 class="bp-group__title">' . $e(Sections::tc($g[0])) . '</h3><p class="bp-group__lead">' . $e($g[1]) . '</p>' . Sections::cards($cards, max(2, min(3, count($cards)))) . '</div>';
}
echo '<div class="bp-group bp-group--us"><h3 class="bp-group__title">' . $e($site) . '</h3><p class="bp-group__lead">For creators who sell more than subscriptions (pay-per-view, bundles, services and events) from one page, publish to all their socials from one studio, and want a fee that falls as they grow.</p>'
   . Sections::buttons(array(array('See the features', '/features', 'secondary'))) . '</div>';
echo Sections::close();

if (!empty($faq)) { echo Sections::faq($faq, 'alt'); }
$cta_title = 'See it with your own page.';
