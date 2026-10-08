<?php
/** Home page body on the shared section system (libs/Classes/Sections.php). Rendered inside login_form.php. */
$site = Main::site_name();
$home_faq = array(
    array('q' => 'Who can see my content?', 'a' => 'You choose per post: Everyone, Subscribers, or Pay-per-view at a price you set.'),
    array('q' => 'How do fans pay?', 'a' => 'Memberships bill on your schedule; everything else uses credits.'),
    array('q' => 'How do I get paid?', 'a' => "Earnings collect as credits, net of your plan's fee. Cash out to your bank anytime."),
    array('q' => 'Can people follow me for free?', 'a' => 'Yes. Free follows, plus an optional free membership tier.'),
    array('q' => 'What do the plans cost?', 'a' => PagesController::plan_cost_answer()),
);
$handle = preg_replace('#^https?://#', '', Main::get_base_domain()) . '/@yourhandle';
/* plan strip under the hero: name, price and role per plan, straight from PlanTiers, each linking to /pricing */
echo Sections::tab_hero(array(
    'id' => 'home',
    'tag' => 'Keep It All Connected.',
    'lead' => $site . ' is a creator platform for monetizing your content: memberships, pay-per-view posts, and tracked links on one public page, with payouts to your bank.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'tabs' => array(
        array('word' => 'Create.', 'pane' => Sections::pane('Upload once, use everywhere.', 'Draft it, schedule it, publish now, or let an automation post for you.', Sections::pane_timeline(array(
            array('Draft', 'Work in progress, visible only to you.'), array('Scheduled', 'Queued for the moment you choose.'), array('Published', 'Live on your page, on your schedule or automatically.'))))),
        array('word' => 'Share.', 'pane' => Sections::pane('Your page lives at your handle.', 'One page carries everything you are. Every click tracked.', Sections::pane_links($handle, array(
            array('Latest video', 612), array('Book a session', 389), array('Podcast', 274), array('Shop', 145))))),
        array('word' => 'Earn.', 'pane' => Sections::pane('You decide who sees every post.', '', Sections::pane_options(array(
            array('Everyone', 'Anyone can see it.'), array('Subscribers', 'Members only.'), array('Pay-per-view', 'Unlock to view. You set the price.')), 0, 'Who can see this post'))),
    ),
));


echo Sections::open('white', 'Everything you sell, on one page', 'One public page at your handle. Fans follow for free, join a tier, or unlock a single post.');
echo Sections::index_list(array(
    array('icon' => 'layers', 'title' => 'Memberships', 'text' => 'Tiers with their own price, trial and perks. Target any post at one tier or several.'),
    array('icon' => 'lock', 'title' => 'Pay-per-view', 'text' => 'Put a price on your strongest posts. Fans unlock them in one tap from their credit wallet.'),
    array('icon' => 'package', 'title' => 'Bundles', 'text' => 'Group past media into a set and turn your back catalogue into a product.'),
    array('icon' => 'ticket', 'title' => 'Services and events', 'text' => 'Take bookings, sell seats to live sessions, and deliver it all from the same page.'),
));
echo Sections::close();

echo Sections::open('alt');
echo Sections::rows(array(
    array('title' => 'Publish everywhere at once', 'text' => 'Upload once in the studio. AI writes the caption in your brand voice, and the post goes to your page and your social accounts at the same time.',
          'points' => array('Nine social networks from one studio', 'Schedule ahead or run automations', 'Engagement from every platform in one view'), 'link' => array('See the studio', '/features')),
    array('title' => 'Get paid, keep your audience', 'text' => "Fans pay with credits and memberships bill on your schedule. Earnings collect as credits, net of your plan's fee, and you cash out to your bank anytime.",
          'points' => array('Platform fee of ' . PagesController::fee_short(), 'Export your audience and content any time', 'An inbox with AI replies in your voice')),
));
echo Sections::close();

echo Sections::open('white', 'Up and running in an afternoon');
echo Sections::cards(array(
    array('icon' => 'page', 'title' => '1. Create your page', 'text' => 'Pick your handle, add your brand, and set up a tier or two.'),
    array('icon' => 'send', 'title' => '2. Publish from the studio', 'text' => 'Post to your page and your socials together, with the paid version behind the lock.'),
    array('icon' => 'bank', 'title' => '3. Get paid', 'text' => 'Earnings arrive as credits in your balance. Cash out to your bank whenever you like.'),
), 3);
echo Sections::close();

/* Latest posts: newest free, fully moderated, non-adult image posts, as a mosaic (newest large). Hidden when there are none. */
$latest = array();
try { $latest = (new FeedModel())->public_latest(5); } catch (\Throwable $ex) { error_log('[home] latest posts: ' . $ex->getMessage()); }
$ago = function ($utc) {
    $d = max(0, time() - strtotime($utc . ' UTC'));
    if ($d < 3600) { return max(1, (int) floor($d / 60)) . 'm ago'; }
    if ($d < 86400) { return (int) floor($d / 3600) . 'h ago'; }
    return (int) floor($d / 86400) . 'd ago';
};
$tiles = '';
foreach ($latest as $i => $lp) {
    // the public, cacheable webp copy when it exists (the big tile gets the larger one); else a signed thumbnail.
    $big = ($tiles === '');
    $pub = PublicThumbService::url($lp + array('moderation_status' => 'approved', 'is_adult' => 0), $big ? 'feed' : 'grid');
    $thumb = $pub !== '' ? $pub : (!empty($lp['cover_thumb_key']) ? S3Service::presigned_get_url((string) $lp['cover_thumb_key'], 3600) : '');
    if ($thumb === '') { continue; }
    list($tw, $th) = $pub !== '' ? PublicThumbService::size($lp, $big ? 'feed' : 'grid') : array(0, 0);
    if ($tw === 0) { $tw = 800; $th = 800; }
    $who = '@' . $lp['u_name'];   // public pages show the handle only, never the display name
    $ini = strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $who), 0, 2));
    $cap = trim(html_entity_decode(strip_tags((string) $lp['caption']), ENT_QUOTES, 'UTF-8'));
    if (mb_strlen($cap) > 110) { $cap = rtrim(mb_substr($cap, 0, 108)) . '…'; }
    $tiles .= '<a class="lm__tile' . ($big ? ' lm__tile--big' : '') . '" href="/@' . Sections::e(rawurlencode((string) $lp['u_name'])) . '">'
        . '<img src="' . Sections::e($thumb) . '" alt="' . Sections::e(PublicThumbService::alt($lp['caption'], $who)) . '" loading="lazy" decoding="async" width="' . (int) $tw . '" height="' . (int) $th . '">'
        . ($big ? '<span class="lm__new"><i aria-hidden="true"></i>New</span>' : '')
        . '<span class="lm__meta"><span class="lm__av" aria-hidden="true">' . Sections::e($ini) . '</span><span class="lm__id"><b>' . Sections::e($who) . '</b><small>' . Sections::e($ago($lp['published_at'])) . '</small></span></span>'
        . ($big && $cap !== '' ? '<span class="lm__cap">' . Sections::e($cap) . '</span>' : '')
        . '</a>';
}
if ($tiles !== '') {
    echo Sections::open('white', 'Latest posts', 'Fresh from creators on ' . $site . '. Tap a post to visit their page.');
    echo '<p class="lm__live"><i aria-hidden="true"></i>Live from the community</p><div class="lm">' . $tiles . '</div>';
    echo Sections::close();
}

echo Sections::open('alt', 'Free for fans', 'Fans sign up free to follow creators, join memberships and unlock posts. Plans are only for creators.');
echo Sections::cards(array(
    array('icon' => 'bell', 'title' => 'Follow for free', 'text' => 'Follow any creator and hear about every new post.'),
    array('icon' => 'users', 'title' => 'Join a membership', 'text' => 'Subscribe to a tier and see everything it includes.'),
    array('icon' => 'lock', 'title' => 'Unlock single posts', 'text' => 'Pay for one post in one tap from your credit wallet.'),
    array('icon' => 'ticket', 'title' => 'Bundles, services and events', 'text' => 'Buy a bundle, book a service, or grab a seat at a live session.'),
    array('icon' => 'package', 'title' => 'Everything in one place', 'text' => 'Everything you buy is saved in your purchases.'),
    array('icon' => 'message', 'title' => 'Message creators', 'text' => 'Talk to the creators you follow from your inbox.'),
), 3);
echo '<div class="sx-acts"><a class="sx-btn sx-btn--secondary" href="/?auth=register" data-auth="register">Join Free</a></div>';
echo Sections::close();

echo Sections::faq($home_faq, 'white');
echo Sections::cta('Create your page today.', 'Memberships, pay-per-view, bundles, services and events, with payouts to your bank.');
