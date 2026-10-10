<?php
$site = Main::site_name();
echo Sections::panel_hero(array(
    'title' => 'How to monetize your content',
    'lead' => 'Five ways creators get paid, what each one is good for, and how to price it. Everything here works on ' . $site . ', and most of it works anywhere.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('Where to start your prices.', 'Start higher than feels comfortable.', Sections::pane_rows(array(
        array('Entry tier', 'The price of a coffee where most of your fans live'), array('Top tier', 'Three to five times the entry tier'),
        array('Pay-per-view', 'A quarter of the entry tier or more'), array('Services', 'Your hourly worth times the real time, plus a third')))),
    'bg_image' => SiteImages::bg('monetize_hero'),
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

echo Sections::open('alt', 'Which way should you start with?', 'Start with one membership tier and one pay-per-view post, then add services when fans ask for them. The right order depends on how many people already follow you.');
echo Sections::cards(array(
    array('icon' => 'users', 'title' => 'A small audience', 'text' => 'Under a few hundred followers, services earn the most per fan. Offer one fixed-scope service, such as a custom piece or a short call, and one pay-per-view post of your best work. A membership can wait until a dozen people ask for more of you.', 'note' => 'One service, one unlock.'),
    array('icon' => 'layers', 'title' => 'A growing audience', 'text' => 'From a few hundred followers, open two membership tiers and post to them every week. Keep pay-per-view for the pieces that took the most effort, and send a paid message to the members who have bought before.', 'note' => 'Two tiers, weekly posts.'),
    array('icon' => 'ticket', 'title' => 'A large audience', 'text' => 'With thousands of followers, bundles and events do the heavy lifting. Group your back catalogue into themed bundles, sell seats to one live session a month, and record the session to sell as a bundle afterwards.', 'note' => 'Bundles and live events.'),
), 3);
echo Sections::close();

echo Sections::open('white', 'Pricing cheat sheet', 'Start higher than feels comfortable. You can add a discount code; you cannot easily raise a price.');
echo Sections::table(array('What', 'Starting point'), array(
    array('Entry tier', 'The price of a coffee where most of your fans live'),
    array('Top tier', 'Three to five times the entry tier'),
    array('Pay-per-view', 'A quarter of the entry tier or more'),
    array('Services', 'Your hourly worth times the real time it takes, plus a third'),
), -1);
echo Sections::close();

echo Sections::open('alt', 'How do you raise prices without losing members?', 'Raise the price for new members only, and leave existing members on what they pay today. A fan who joined at the old price keeps it for as long as the membership stays active.');
echo Sections::checks(array(
    'Announce the change a week ahead, so fans on the fence join at the current price. The deadline does more than any discount.',
    'Add something to the tier on the day the price changes: a weekly post, a monthly call, or early access to pay-per-view drops.',
    'Keep the entry tier easy to say yes to, and move the top tier up first. The gap between the tiers is where the value shows.',
    'Use a promo code for a short window instead of a permanent lower price. A code ends on a date, and a lower price is hard to take back.',
    'Watch renewals for one full billing cycle before you change anything else. One change at a time tells you what worked.',
), 1);
echo Sections::close();

echo Sections::open('white', 'How often should you post paid content?', 'Post to your members every week, and release one pay-per-view piece a month. A steady rhythm keeps renewals up more than any single post does.');
echo Sections::cards(array(
    array('icon' => 'layers', 'title' => 'Weekly for members', 'text' => 'Members pay for what arrives next month, not for the archive. A weekly post, even a short one, is the reason to stay. Put it on the same day each week so fans know when to look.'),
    array('icon' => 'lock', 'title' => 'Monthly for pay-per-view', 'text' => 'One pay-per-view drop a month gives members and non-members the same thing to look forward to. Announce it on your socials the day before, with the paid version behind the lock.'),
    array('icon' => 'package', 'title' => 'Bundles for the quiet weeks', 'text' => 'Group older posts into a themed set and let it sell while you make the next piece. A bundle needs no new work, only a title and a price below the sum of the parts.'),
    array('icon' => 'ticket', 'title' => 'Services and events in between', 'text' => 'Cap the number of services and events you take each month, so the delivery window stays one you can keep. A full calendar is the sign to raise the price, not to add slots.'),
), 4);
echo Sections::close();

echo Sections::open('alt');
echo Sections::rows(array(
    array('title' => 'Getting fans to the page', 'text' => 'Publish everywhere and point back to one place. A studio that posts to your socials and your page at once, with the paid version behind the lock, does the promotion for you every time you publish.', 'image' => SiteImages::url('monetize_fans'), 'link' => array('See how the studio works', '/features')),
));
echo Sections::close();
echo Sections::faq($faq);
$cta_title = 'Set up your tiers in an afternoon.';
