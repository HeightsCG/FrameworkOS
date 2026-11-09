<?php
/**
 * Features page (redesigned 2026-10-12, repositioned 2026-11-09): the creator's working loop told in order, idea to analytics, with a
 * table of what the AI tools make, prose for each part of the product, what you pay for, and a list of the detailed feature pages.
 * Every fact comes from config (PagesController, PlanTiers, IntegrationPages); nothing invented.
 */
$site = Main::site_name(); $e = function ($s) { return Sections::e($s); };
$n_platforms = count(IntegrationPages::PAGES);

/* The six jump links under the hero (pointing at the sections below). */
$jumps = array(array('loop', 'How It Works', 'layers'), array('create', 'AI Studio', 'sparkles'), array('publish', 'Publishing', 'send'), array('inbox', 'Inbox', 'inbox'), array('money', 'Analytics', 'chart'), array('plans', 'Plans', 'tag'));
$jump = '<nav class="sx-jump" aria-label="Feature sections">';
foreach ($jumps as $j) { $jump .= '<a href="#' . $e($j[0]) . '">' . Sections::icon($j[2], 20) . '<span>' . $e($j[1]) . '</span></a>'; }
$jump .= '</nav>';
// 2026-10-15: the approved home hero (Sections::premium_hero), same H1 sentence split into a white and an orange line, its own portrait.
echo Sections::premium_hero(array(
    'title'     => 'Everything your content needs,',
    'highlight' => 'In one AI studio.',
    'lead'      => $site . ' is an AI content studio for creators and brands: AI influencers, AI images and video, scheduling and cross-posting, a link-in-bio page, inbox automation and analytics.',
    'points'    => array(array('sparkles', 'AI Influencers, Images & Video'), array('send', 'Publishing to ' . $n_platforms . ' Platforms'), array('chart', 'Inbox, Automations & Analytics')),
    'buttons'   => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'photo'     => 'featport',
));
echo '<div class="pm-jumpbar"><div class="ld-wrap">' . $jump . '</div></div>';   // the six jump links as a slim bar under the hero, not boxes over the photo

/* The spine of the page: one post, followed from the idea to the numbers, as the product's own steps. */
echo Sections::open('white', 'How does an idea become a post everywhere?', 'The same four steps for every creator and brand, whatever you make.', 'loop');
echo '<ol class="fx-steps">'
    . '<li><h3>Create with AI</h3><p>Generate photos and short videos of your AI influencer or your brand, or upload your own.</p></li>'
    . '<li><h3>Write once</h3><p>AI drafts the caption in your voice and trims it for each network.</p></li>'
    . '<li><h3>Publish everywhere</h3><p>The same post goes to your page and to the ' . $e((string) $n_platforms) . ' platforms you connect, now, on a schedule, or by an automation.</p></li>'
    . '<li class="is-last"><h3>Learn what works</h3><p>Views, engagement and link clicks from your page and every network come back into one dashboard.</p></li>'
    . '</ol>';
echo Sections::close();

/* What the AI tools make, as a table a reader uses: what you start from, what you get, and what it uses. */
echo Sections::open('alt', 'What can you make with the AI tools?', 'Every generation spends AI credits, which Creator and Studio include every month.', 'create');
echo Sections::table(array('What you make', 'What you start from', 'What you get'), array(
    array($e('AI influencer'), $e('A short description, or a set of your own photos.'), $e('A character with the same face in every photo and video.')),
    array($e('AI images'), $e('A short prompt, with your character or your brand.'), $e('New photos in any outfit, place or style.')),
    array($e('AI video'), $e('Any still image, or a script for a talking video.'), $e('Short clips, and talking videos in a voice you design.')),
    array($e('AI captions'), $e('Your post and your brand voice.'), $e('A caption sized for each network you publish to.')),
    array($e('Inbox replies'), $e('A message from your audience.'), $e('A drafted reply in your voice, waiting for your approval.')),
));
echo '<p class="sx-note">Need more than your plan includes? Buy an AI credit pack at any time on Creator or Studio.</p>';
echo Sections::close();

/* Publishing, inbox, analytics: the home's split sections (2026-10-15). The question is the H2 beside the schematic (Sections::ui,
   invented values, never a screenshot); the answer keeps its H3 and full text. Backgrounds alternate white, alt, white. */
$split = function ($bg, $id, $h2, $lead, $h3, $text, $link, $ui, $rev) use ($e) {
    $copy = '<div class="sx-split__copy"><h2 class="sx-h2">' . $e(Sections::tc($h2)) . '</h2>' . ($lead !== '' ? '<p class="sx-lead">' . $e($lead) . '</p>' : '')
        . '<h3 class="sx-h3 fx-split__h3">' . $e(Sections::tc($h3)) . '</h3>' . Sections::paragraphs($text, 'sx-p') . '<a class="sx-more" href="' . $e($link[1]) . '">' . $e($link[0]) . '</a></div>';
    return Sections::open($bg, '', '', $id) . '<div class="sx-split' . ($rev ? ' sx-split--rev' : '') . '">' . ($rev ? Sections::ui($ui) . $copy : $copy . Sections::ui($ui)) . '</div>' . Sections::close();
};
echo $split('white', 'publish', 'How does publishing work?', '', 'One upload, every network',
    'Upload a photo or a video once and the studio posts it to your page and to X, Instagram, TikTok, Facebook, LinkedIn, Pinterest, YouTube, Threads and Bluesky. AI writes the caption in your brand voice and trims it to each network\'s length. Keep a draft, schedule it for a time, or let an automation publish on the rhythm you set. Engagement from every network comes back into one analytics view.',
    array('See publishing', '/features/publishing'), 'targets', false);
echo $split('alt', 'inbox', 'What happens in the inbox?', 'Every conversation in one place, answered in your voice.', 'One inbox, replies in your voice',
    'Every message from your audience lands in one inbox. The DM agent drafts a reply in your voice and waits for your approval, or sends on its own once you let it. A welcome message can greet every new follower, and a broadcast reaches one tag or all of your followers at once. Behind the inbox sits your audience list, with tags and notes on every follower.',
    array('See the DM agent', '/features/dm-agent'), 'inbox', true);
echo $split('white', 'money', 'What do you see once posts go out?', 'Every number in one view.', 'One dashboard for content and audience',
    'The dashboard shows content and audience for the last 7, 30 or 90 days. Every post carries its views and shares, every link on your page its clicks, and your social posts bring their engagement into the same view. Team seats let collaborators work on your account with you, and the Claude connector runs posts, messages and analytics from a chat.',
    array('See analytics in the dashboard', '/features/analytics'), 'analytics', false);

/* What you pay for, in prose, as the jump target. */
echo Sections::prose_id('alt', 'What do you pay for?', 'plans', array(
    'Two things only: your plan and any extra AI credits you choose to buy. ' . PagesController::plan_price_sentence() . ' ' . PagesController::addon_sentence(),
    'Creator and Studio include AI credits every month, and AI images, video and AI influencers spend them. Free is the account everyone starts with and builds drafts; publishing, scheduling and the AI tools start on a paid plan. Your posts, media and messages export whenever you want them.',
), 'Your plan, and AI credits if you need more.');

/* The detailed pages, as a plain list a reader scans. */
$guides = array();
foreach (PagesController::feature_pages() as $f_slug => $f) { $guides[] = array('/features/' . $f_slug, (string) ($f['nav_title'] ?? $f['title']), (string) $f['description']); }
foreach (PagesController::root_feature_pages() as $f_slug => $f) { $guides[] = array('/' . $f_slug, (string) ($f['nav_title'] ?? $f['title']), (string) $f['description']); }
echo Sections::open('white', 'Where is each part explained in full?', 'One page per part of ' . $site . ', each with its own limits and questions.', 'guides');
echo '<dl class="fx-list">';
foreach ($guides as $g) { echo '<div class="fx-list__row"><dt><a href="' . $e($g[0]) . '">' . $e(Sections::tc($g[1])) . '</a></dt><dd>' . $e(Sections::cap($g[2], 2)[0]) . '</dd></div>'; }
echo '</dl>';
echo Sections::close();

echo Sections::faq($faq);
$cta_title = 'Create your page in a few minutes.';
$cta_photo = 'features';   // the closing band's photo, like the home's final band
