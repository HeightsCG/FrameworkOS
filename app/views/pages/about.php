<?php
/**
 * /about: what the platform is, who it is for, the AI influencer tools, how creators get paid, who owns it.
 * Plans, fees and the payout minimum come from config (PagesController / Price), never typed here.
 */
$site = Main::site_name();
echo Sections::panel_hero(array(
    'title'   => 'About ' . $site,
    'lead'    => $site . ' is one page where creators sell memberships, pay-per-view posts, bundles, services and live events, publish to their social accounts and get paid to their bank.',
    'buttons' => array(
        array('See Features', '/features', 'ghost'),
        array('Contact Us', '/contact', 'ghost'),
    ),
));

echo Sections::open('white', 'What it is', 'Everything a creator sells, in one place, under one handle.');
echo Sections::cards(array(
    array('icon' => 'page', 'title' => 'One public page', 'text' => 'Your page holds your posts, membership tiers, pay-per-view content, bundles, services, events and tracked links. Fans follow, join and buy without leaving it.'),
    array('icon' => 'send', 'title' => 'A studio that publishes', 'text' => 'Write a post once and publish it to your page and your connected social accounts at the same time, with AI captions and engagement pulled back into analytics.'),
    array('icon' => 'inbox', 'title' => 'An inbox that sells', 'text' => 'Message fans one to one or in broadcasts, attach paid media, and let AI draft replies so no message waits.'),
), 3);
echo Sections::close();

echo Sections::open('alt', 'Who it is for');
echo Sections::cards(array(
    array('icon' => 'star', 'title' => 'Independent creators', 'text' => 'Creators who want memberships, paid posts, services and events on a page they control, with their audience list exportable at any time.'),
    array('icon' => 'users', 'title' => 'Agencies', 'text' => 'Teams that run accounts for creators: invite collaborators with roles, act on the owner\'s account and keep every sale in the owner\'s balance.'),
    array('icon' => 'bot', 'title' => 'AI influencer operators', 'text' => 'People who build and run AI characters as a business, from the first image to paid content and fan conversations.'),
), 3);
echo Sections::close();

echo Sections::open('white', 'The AI influencer toolset');
echo Sections::rows(array(
    array('title' => 'Character generation and training', 'text' => 'Create a character from your photos or a description, then train it so every new image shows the same person.', 'link' => array('Explore AI Influencers', '/features/ai-influencer')),
    array('title' => 'Images and video', 'text' => 'Generate photos and short videos of your character in any scene, then sell them as pay-per-view posts, paid messages or membership content.'),
    array('title' => 'DM agent', 'text' => 'An AI agent answers fan messages in your character\'s voice, sends welcome and trigger messages, and can attach paid media.', 'link' => array('Explore the DM Agent', '/features/dm-agent')),
));
echo Sections::close();

echo Sections::open('alt', 'How creators get paid');
echo Sections::checks(array(
    'Every sale lands in one balance, net of your plan\'s platform fee (' . PagesController::fee_short() . ').',
    'Cash out on request to your bank, from a ' . Price::PAYOUT_MIN_LABEL . ' minimum, with no platform hold.',
    'Payouts are available in every country our payment processor supports.',
    'Plan charges and credit purchases are final and non-refundable.',
));
echo '<p class="sx-p"><a href="/features/payouts">How payouts work</a></p>';
echo Sections::close();

echo Sections::open('white', 'Who we are');
echo '<p class="sx-p">' . Sections::e($site) . ' is a Heights Consulting Group LLC product. Questions about your account, billing or payouts go to <a href="/contact">our support team</a>.</p>';
echo Sections::close();
