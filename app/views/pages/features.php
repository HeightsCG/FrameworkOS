<?php
/** Features page: hero with jump links, then one group per job a creator does (Sections::group). Every item is a shipped feature. */
$site = Main::site_name();
$groups = array(
    array('id' => 'page', 'jump' => 'Your page', 'jump_icon' => 'layers', 'title' => 'Your public page', 'lead' => 'One page at your handle that sells everything you make, in your brand colors.', 'image' => SiteImages::url('features_page'), 'items' => array(
        array('layers', 'Posts for everyone or for paying fans', 'Free posts are open to all. Subscriber and pay-per-view posts show blurred until a fan joins or unlocks.'),
        array('link', 'Tracked links', 'Add links to your page and see every click.'),
        array('palette', 'Your brand', 'Your colors, bio and links carry across your page.'),
        array('search', 'Found in the feed and search', 'Fans discover you in the home feed and in search across creators and content.'),
        array('bell', 'Free follows', 'Fans follow for free and hear about every new post.'),
        array('badge', 'Verified badge', 'Verified creators show a badge on their page.'),
    )),
    array('id' => 'earn', 'jump' => 'Ways to get paid', 'jump_icon' => 'wallet', 'title' => 'Ways to get paid', 'lead' => 'Memberships for your regulars, single sales for everyone else.', 'items' => array(
        array('users', 'Membership tiers', 'As many tiers as you want, each with its own price, billing interval, trial, perks and promo codes.'),
        array('lock', 'Pay-per-view posts', 'Put a price on any post. Fans unlock it in one tap from their credit wallet.'),
        array('package', 'Bundles', 'Group media from your library into a set. Buyers find it in their purchases.'),
        array('calendar', 'Services', 'Take bookings. Access details are shared after purchase.'),
        array('ticket', 'Events', 'Sell seats to live sessions. Details are shared after purchase.'),
        array('message', 'Paid messages', 'Attach media and a price to any message. It stays blurred until the fan pays.'),
    )),
    array('id' => 'publish', 'jump' => 'Publishing', 'jump_icon' => 'send', 'title' => 'Publish everywhere at once', 'lead' => 'Upload once and post to your page and your social accounts at the same time.', 'image' => SiteImages::url('features_studio'), 'items' => array(
        array('send', 'Nine social networks and Fanvue', 'X, Instagram, TikTok, Facebook, LinkedIn, Pinterest, YouTube, Threads and Bluesky, plus Fanvue cross-posting.'),
        array('sparkles', 'AI captions in your voice', 'Captions written in your brand voice, trimmed for each network.'),
        array('image', 'AI images', 'Generate on-brand images from a short prompt.'),
        array('clock', 'Drafts and scheduling', 'Keep a draft, schedule it, or publish now.'),
        array('repeat', 'Automations', 'Automations write and publish posts on the schedule you set.'),
        array('bot', 'AI influencers', 'Create AI personas and generate their photos and videos.'),
    )),
    array('id' => 'fans', 'jump' => 'Inbox and audience', 'jump_icon' => 'inbox', 'title' => 'Inbox and audience', 'lead' => 'Every fan conversation and every fan record in one place.', 'items' => array(
        array('inbox', 'Inbox', 'Every fan message in one place.'),
        array('sparkles', 'AI replies', 'AI drafts replies in your voice for your approval, or sends them.'),
        array('message', 'Welcome messages', 'Automatic messages for new fans, with media and a price if you want.'),
        array('megaphone', 'Broadcasts', 'Message one or more audience segments at once, now or on a schedule.'),
        array('list', 'Audience list', 'Followers, subscribers and buyers in one list, with tags and notes.'),
        array('ban', 'Blocking and reports', 'Block anyone from your page and your inbox, and report abuse.'),
    )),
    array('id' => 'insights', 'jump' => 'Analytics', 'jump_icon' => 'chart', 'title' => 'Analytics', 'lead' => 'See what earns, what spreads and who buys.', 'items' => array(
        array('chart', 'Dashboard', 'Revenue, content, audience and customer views in one dashboard.'),
        array('eye', 'Post performance', 'Views, shares and conversion for every post.'),
        array('send', 'Social metrics', 'Engagement from your social posts, pulled into the same view.'),
        array('link', 'Link clicks', 'Clicks on every link on your page.'),
    )),
    array('id' => 'payouts', 'jump' => 'Payouts and team', 'jump_icon' => 'bank', 'title' => 'Payouts, team and tools', 'lead' => 'Your money and your audience stay yours.', 'image' => SiteImages::url('features_payouts'), 'items' => array(
        array('bank', 'Payouts to your bank', 'Earnings collect as credits. Cash out to your own bank account.'),
        array('wallet', 'Fan credit wallet', 'Fans top up once and pay in one tap, with optional automatic top-ups.'),
        array('user-plus', 'Team seats', 'Invite collaborators to work on your account.'),
        array('plug', 'Claude connector', 'Run your account from Claude: posts, messages, analytics and more.'),
        array('download', 'Export everything', 'Export your subscribers, content and brand any time.'),
        array('shield', 'A fee that falls as you grow', 'A flat platform fee that drops on higher plans.'),
    )),
);
$jump = '<nav class="sx-jump" aria-label="Feature sections">';
foreach ($groups as $g) { $jump .= '<a href="#' . Sections::e($g['id']) . '">' . Sections::icon($g['jump_icon'], 20) . '<span>' . Sections::e($g['jump']) . '</span></a>'; }
$jump .= '</nav>';
echo Sections::panel_hero(array(
    'title' => 'Everything a creator sells, from one page.',
    'lead' => $site . ' gives you one public page for memberships, pay-per-view, bundles, services and events, a studio that publishes to your socials, and payouts to your bank.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'after_html' => $jump,
));

$n = 0;
foreach ($groups as $g) { echo Sections::group($g, ($n++ % 2 === 1) ? 'alt' : 'white'); }

echo Sections::faq($faq);
$cta_title = 'Create your page in a few minutes.';
