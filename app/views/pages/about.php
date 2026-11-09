<?php
/**
 * /about: what the platform is, who it is for, the AI influencer tools, what you pay for, who owns it.
 * Plan prices come from config (PagesController / PlanTiers), never typed here.
 */
$site = Main::site_name();
echo Sections::panel_hero(array('photo' => 'about', 'variant' => 'editorial', 
    'title'   => 'About ' . $site,
    'lead'    => $site . ' is an AI content studio for creators and brands: AI influencers, AI images and video, scheduling and cross-posting to social accounts, a link-in-bio page, inbox automation and analytics.',
    'buttons' => array(
        array('See Features', '/features', 'ghost'),
        array('Contact Us', '/contact', 'ghost'),
    ),
));

echo Sections::open('white', 'What it is', 'Everything your content needs, in one studio, under one handle.');
echo Sections::cards(array(
    array('icon' => 'page', 'title' => 'One public page', 'text' => 'Your link-in-bio page holds your posts and tracked links at your handle, or on your own domain on Studio. People follow you without leaving it.'),
    array('icon' => 'send', 'title' => 'A studio that publishes', 'text' => 'Write a post once and publish it to your page and your connected social accounts at the same time, with AI captions and engagement pulled back into analytics.'),
    array('icon' => 'inbox', 'title' => 'An inbox that answers', 'text' => 'Message your audience one to one or in broadcasts, and let AI draft replies in your voice so no message waits.'),
), 3);
echo Sections::close();

echo Sections::open('alt', 'Who it is for');
echo Sections::cards(array(
    array('icon' => 'star', 'title' => 'Independent creators', 'text' => 'Creators who want AI tools, scheduling and a page they control, with every post going to all of their social accounts at once.'),
    array('icon' => 'users', 'title' => 'Agencies', 'text' => 'Teams that run accounts for creators: invite collaborators with roles, and work on the owner\'s account without sharing a password.'),
    array('icon' => 'bot', 'title' => 'AI influencer operators', 'text' => 'People and brands who build and run AI characters, from the first image to a daily posting schedule and conversations with followers.'),
), 3);
echo Sections::close();

echo Sections::open('white', 'The AI influencer toolset');
echo Sections::rows(array(
    array('title' => 'Character generation and training', 'text' => 'Create a character from your photos or a description, then train it so every new image shows the same person.', 'link' => array('Explore AI Influencers', '/features/ai-influencer')),
    array('title' => 'Images and video', 'text' => 'Generate photos and short videos of your character in any scene, then publish them to your page and your social accounts, now or on a schedule.'),
    array('title' => 'DM agent', 'text' => 'An AI agent answers messages in your character\'s voice and sends welcome and trigger messages.', 'link' => array('Explore the DM Agent', '/features/dm-agent')),
));
echo Sections::close();

echo Sections::open('alt', 'What you pay for');
echo Sections::checks(array(
    'Your plan: ' . PagesController::plan_price_sentence(),
    'AI credits: Creator and Studio include them every month, and you can buy more at any time.',
    'Nothing else. Cancel a plan at any time and keep it until the end of the paid period.',
));
echo '<p class="sx-p"><a href="/pricing">See pricing</a></p>';
echo Sections::close();

// "Why we built this" (D3.3): Daniel's own words go in PagesController::WHY_WE_BUILT_THIS; the section stays hidden while either paragraph is empty.
$why = array_filter(array_map('trim', (array) PagesController::WHY_WE_BUILT_THIS));
if (count($why) === count(PagesController::WHY_WE_BUILT_THIS)) {
    echo Sections::open('alt', 'Why we built this');
    foreach ($why as $wp) { echo Sections::paragraphs($wp, 'sx-p'); }
    echo Sections::close();
}

echo Sections::open('white', 'Who we are');
echo '<p class="sx-p">' . Sections::e($site) . ' is built and run by Heights Consulting Group LLC, led by <a href="' . Sections::e(SeoMeta::AUTHOR_PATH) . '">' . Sections::e(SeoMeta::AUTHOR_NAME) . '</a>, CEO of Heights Consulting Group and author of Cybersecurity in the Age of AI. Questions about your account or billing go to <a href="/contact">our support team</a>.</p>';
echo Sections::close();

// Editorial: who writes the blog, how articles are researched and checked, how corrections work, the latest pieces. The byline on every article links here.
echo Sections::open('alt', 'Who writes the articles?', 'The ' . $site . ' team writes the blog: the people who build the product and answer its support tickets.', 'editorial');
echo '<p class="sx-p">' . Sections::e('Articles are drafted with AI tools, checked automatically for facts and links, and reviewed by the ' . $site . ' team before publishing.') . '</p>';   // the name appears on /about once, in Who We Are
echo Sections::cards(array(
    array('icon' => 'search', 'title' => 'How articles are researched', 'text' => 'Every article starts from the facts sheet the product runs on. Plans, prices and limits come from the live configuration, so an article cannot state a price the pricing page does not. Outside facts come from primary sources only: official documentation, government guidance, platform terms and published studies, each linked where it is used.'),
    array('icon' => 'check', 'title' => 'How articles are fact-checked', 'text' => 'A draft goes live only after automated checks pass. Every cited link is fetched and must resolve. Every plan price and limit must match the current plans. Income promises and invented people are rejected. A nightly check reruns the rules and flags any article that drifts when the plans change.'),
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
