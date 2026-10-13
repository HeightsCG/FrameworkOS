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
    'Cash out on request to your bank, from a ' . Price::PAYOUT_MIN_LABEL . ' minimum, with no platform hold. Earnings from an event are released after the event has taken place.',
    'Payouts are available in every country our payment processor supports.',
));
echo '<p class="sx-p"><a href="/features/payouts">How payouts work</a></p>';
echo Sections::close();

// "Why we built this" (D3.3): Daniel's own words go in PagesController::WHY_WE_BUILT_THIS; the section stays hidden while either paragraph is empty.
$why = array_filter(array_map('trim', (array) PagesController::WHY_WE_BUILT_THIS));
if (count($why) === count(PagesController::WHY_WE_BUILT_THIS)) {
    echo Sections::open('alt', 'Why we built this');
    foreach ($why as $wp) { echo Sections::paragraphs($wp, 'sx-p'); }
    echo Sections::close();
}

echo Sections::open('white', 'Who we are');
echo '<p class="sx-p">' . Sections::e($site) . ' is built and run by Heights Consulting Group LLC, led by <a href="' . Sections::e(SeoMeta::AUTHOR_PATH) . '">' . Sections::e(SeoMeta::AUTHOR_NAME) . '</a>, CEO of Heights Consulting Group and author of Cybersecurity in the Age of AI. Questions about your account, billing or payouts go to <a href="/contact">our support team</a>.</p>';
echo Sections::close();

// Editorial: who writes the blog, how articles are researched and checked, how corrections work, the latest pieces. The byline on every article links here.
echo Sections::open('alt', 'Who writes the articles?', 'The ' . $site . ' team writes the blog: the people who build the product and answer its support tickets.', 'editorial');
echo '<p class="sx-p">' . Sections::e('Articles are drafted with AI tools, checked automatically for facts and links, and reviewed by the ' . $site . ' team before publishing.') . '</p>';   // the name appears on /about once, in Who We Are
echo Sections::cards(array(
    array('icon' => 'search', 'title' => 'How articles are researched', 'text' => 'Every article starts from the facts sheet the product runs on. Plans, fees, limits and payout terms come from the live configuration, so an article cannot state a price the pricing page does not. Outside facts come from primary sources only: official documentation, government guidance, platform terms and published studies, each linked where it is used.'),
    array('icon' => 'check', 'title' => 'How articles are fact-checked', 'text' => 'A draft goes live only after automated checks pass. Every cited link is fetched and must resolve. Every fee and plan price must match the current plans. Income promises and invented people are rejected. A nightly check reruns the rules and flags any article that drifts when the plans change.'),
    array('icon' => 'inbox', 'title' => 'How corrections are handled', 'text' => 'Send an error through the contact page with the article link and the sentence in question. The team reviews it, corrects the article and updates its modified date. An article that no longer holds is taken offline rather than left up.', 'link' => array('Report a Correction', '/contact')),
), 3);
if (!empty($recent)) {
    echo '<h3 class="sx-h3">Recent articles</h3><ul class="sx-checks sx-checks--2">';
    foreach ($recent as $r) {
        $when = !empty($r['published_at']) ? date('M j, Y', strtotime($r['published_at'] . ' UTC')) : '';
        echo '<li>' . Sections::icon('check', 16) . '<span><a href="/blog/' . Sections::e($r['slug']) . '">' . Sections::e($r['title']) . '</a>' . ($when !== '' ? ' <span class="sx-muted">' . Sections::e($when) . '</span>' : '') . '</span></li>';
    }
    echo '</ul><p class="sx-p"><a href="/blog">All articles</a></p>';
}
echo Sections::close();
