<?php
$site = Main::site_name();
echo Sections::panel_hero(array(
    'title' => 'How to monetize your content',
    'lead' => 'Five ways creators get paid, what each one is good for, and how to price it. Everything here works on ' . $site . ', and most of it works anywhere.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('Where to start your prices.', 'Start higher than feels comfortable.', Sections::pane_rows(array(
        array('Entry tier', 'The price of a coffee where most of your fans live'), array('Top tier', 'Three to five times the entry tier'),
        array('Pay-per-view', 'A quarter of the entry tier or more'), array('Services', 'Your hourly worth times the real time, plus a third')))),
    'bg_image' => SiteImages::url('monetize_hero'),
));
echo Sections::open('white', 'Five ways to get paid');
echo Sections::cards(array(
    array('icon' => 'layers', 'title' => 'Memberships', 'text' => 'A monthly price for ongoing access. Start with two tiers: an entry tier priced where a fan says yes without thinking, and a higher tier for the people who want more of you.', 'note' => 'Raise prices for new members only.'),
    array('icon' => 'lock', 'title' => 'Pay-per-view posts', 'text' => 'One post, one price, for everyone including members. Best for your strongest single pieces. Price by effort and scarcity, not length.', 'note' => 'Never below a quarter of your entry tier.'),
    array('icon' => 'package', 'title' => 'Bundles', 'text' => 'Group past media into a set at a discount to the sum of the parts. Bundles turn your back catalogue into a product.', 'note' => 'The easiest upsell after a single unlock.'),
    array('icon' => 'users', 'title' => 'Services', 'text' => 'Custom content, shout-outs, coaching, reviews. Fixed price, clear scope, a delivery window you can keep.', 'note' => 'Where a small audience earns the most per fan.'),
    array('icon' => 'ticket', 'title' => 'Events', 'text' => 'Live sessions, Q&As, watch parties, workshops. Sell seats ahead of time and cap the room.', 'note' => 'Record it and sell it as a bundle afterwards.'),
), 3);
echo Sections::close();

echo Sections::open('alt', 'Pricing cheat sheet', 'Start higher than feels comfortable. You can add a discount code; you cannot easily raise a price.');
echo Sections::table(array('What', 'Starting point'), array(
    array('Entry tier', 'The price of a coffee where most of your fans live'),
    array('Top tier', 'Three to five times the entry tier'),
    array('Pay-per-view', 'A quarter of the entry tier or more'),
    array('Services', 'Your hourly worth times the real time it takes, plus a third'),
), -1);
echo Sections::close();

echo Sections::open('white');
echo Sections::rows(array(
    array('title' => 'Getting fans to the page', 'text' => 'Publish everywhere and point back to one place. A studio that posts to your socials and your page at once, with the paid version behind the lock, does the promotion for you every time you publish.', 'image' => SiteImages::url('monetize_fans'), 'link' => array('See how the studio works', '/features')),
));
echo Sections::close();
echo Sections::faq($faq);
$cta_title = 'Set up your tiers in an afternoon.';
