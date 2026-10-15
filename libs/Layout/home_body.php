<?php
/** Home page body on the shared section system (libs/Classes/Sections.php). Rendered inside login_form.php. */
$site = Main::site_name();
$home_faq = PagesController::home_faq();   // shared with the FAQPage markup in login_form.php
$handle = preg_replace('#^https?://#', '', Main::get_base_domain()) . '/@yourhandle';
/* plan strip under the hero: name, price and role per plan, straight from PlanTiers, each linking to /pricing */
// Hero (2026-10-13): four things on the left, in this order: the CREATE. SHARE. EARN. wordmark as a small one-line eyebrow, the H1,
// the fee line from config (what each selling plan keeps, against OnlyFans' sourced rate), and the two buttons. The definition
// paragraph lives in the "What is" block below; the founding offer is in the announcement bar.
echo Sections::tab_hero(array(
    'id' => 'home',
    'words_eyebrow' => true,
    'start' => 2,   // the card opens on Earn ("You decide who sees every post.") and then turns through the other two
    'title' => 'One page to sell your content, and a studio that posts it everywhere.',
    'sub' => PagesController::fee_wedge_sentence(),
    'buttons' => array(array('Start Free', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'tabs' => array(
        array('word' => 'Create.', 'pane' => Sections::pane('Upload once, use everywhere.', 'Draft it, schedule it, publish now, or let an automation post for you.', Sections::pane_timeline(array(
            array('Draft', 'Work in progress, visible only to you.'), array('Scheduled', 'Queued for the moment you choose.'), array('Published', 'Live on your page, on your schedule or automatically.'))))),
        array('word' => 'Share.', 'pane' => Sections::pane('Your page lives at your handle.', 'One page carries everything you are. Every click tracked.', Sections::pane_links($handle, array(
            array('Latest video', 612), array('Book a session', 389), array('Podcast', 274), array('Shop', 145))))),
        array('word' => 'Earn.', 'pane' => Sections::pane('You decide who sees every post.', '', Sections::pane_options(array(
            array('Everyone', 'Anyone can see it.'), array('Subscribers', 'Members only.'), array('Pay-per-view', 'Unlock to view. You set the price.')), 0, 'Who can see this post'))),
    ),
));


// What the product is, in sentences that stand alone (SEO audit 2026-10-12: the home page had no definition and never mentioned AI influencers or teams outside the FAQ).
echo Sections::prose('alt', 'What is ' . $site . '?', array(
    $site . ' is a creator platform for monetizing your content: memberships, pay-per-view posts, and tracked links on one public page, with payouts to your bank.',
    $site . ' is a creator monetization platform: one public page at your handle that sells memberships, pay-per-view posts, bundles, services and event tickets. A studio publishes each post to ten platforms. The platform fee is ' . PagesController::fee_short() . ', and plan prices are a separate monthly line on the pricing page.',
    'It also includes an [AI influencer generator](/features/ai-influencer) that creates photos and video of one consistent character, and [team seats for people who run creator accounts](/ai-ofm-tools). To see where it sits among the options, read [best creator monetization platforms](/best-creator-monetization-platforms).',
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
          'points' => array('Ten platforms from one studio', 'Schedule ahead or run automations', 'Engagement from every platform in one view'), 'link' => array('See the studio', '/features')),
    array('title' => 'Get paid, keep your audience', 'text' => "Fans pay with credits and memberships bill on your schedule. Earnings collect as credits, net of your plan's platform fee, and you cash out to your bank anytime.",
          'points' => array('Platform fee of ' . PagesController::fee_short(), 'Export your audience and content any time', 'An inbox with AI replies in your voice'),
          // the after-state (heycatch D1.5, D2.3) as a schematic drawing of the Analytics dashboard with invented figures, never a screenshot
          'image' => Sections::ui_sketch_url('analytics'), 'image_w' => 960, 'image_h' => 600, 'caption' => 'The Analytics dashboard, drawn as a schematic.',
          'image_alt' => 'Schematic of the Analytics dashboard: six cards for revenue, subscribers, followers, views, posts and engagement, a 7, 30 or 90 day switch, and a daily revenue bar chart beneath'),
));
echo Sections::close();

echo Sections::open('white', 'Up and running in an afternoon');
echo Sections::cards(array(
    array('icon' => 'page', 'title' => '1. Create your page', 'text' => 'Pick your handle, add your brand, and set up a tier or two.'),
    array('icon' => 'send', 'title' => '2. Publish from the studio', 'text' => 'Post to your page and your socials together, with the paid version behind the lock.'),
    array('icon' => 'bank', 'title' => '3. Get paid', 'text' => 'Earnings arrive as credits in your balance. Cash out to your bank whenever you like.'),
), 3);
echo Sections::close();

/* Testimonials (heycatch D3.2): real people's words from /admin/testimonials, shown only once Proof::TESTIMONIALS_MIN are approved. */
$quotes = array();
try { $tm = new TestimonialsModel(); if ($tm->approved_count() >= Proof::TESTIMONIALS_MIN) { $quotes = $tm->approved(3); } } catch (\Throwable $ex) { error_log('[home] testimonials: ' . $ex->getMessage()); }
if (count($quotes) >= Proof::TESTIMONIALS_MIN) {
    echo Sections::open('alt', 'What creators say');
    echo '<ul class="ts-row">';
    foreach ($quotes as $q) {
        $who = trim((string) $q['first_name']) . ((string) $q['niche'] !== '' ? ', ' . (string) $q['niche'] : '');
        echo '<li class="ts-card"><blockquote class="ts-card__q">' . Sections::paragraphs((string) $q['quote']) . '</blockquote><p class="ts-card__who">'
            . ((string) ($q['photo_url'] ?? '') !== '' ? '<img class="ts-card__av" src="' . Sections::e($q['photo_url']) . '" alt="' . Sections::e((string) $q['first_name']) . '" width="40" height="40" loading="lazy">' : '')
            . '<span>' . Sections::e($who) . '</span></p></li>';
    }
    echo '</ul>';
    echo Sections::close();
}

/* Latest posts: newest free, fully moderated, non-adult image posts, as a mosaic (newest large). Hidden when there are none. */
$latest = array();
try { $latest = (new FeedModel())->public_latest_distinct(5); } catch (\Throwable $ex) { error_log('[home] latest posts: ' . $ex->getMessage()); }
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
    // Both public renditions (480 and 960 wide) as a srcset, so phones fetch the small one; sizes follow the .lm grid
    // (4 columns inside the 1320px wrap, 2 columns under 1000px, the big tile spanning two). Signed fallbacks have no variants.
    $srcset = '';
    if ($pub !== '') {
        $cands = array();
        $small = PublicThumbService::url($lp + array('moderation_status' => 'approved', 'is_adult' => 0), 'grid');
        $large = PublicThumbService::url($lp + array('moderation_status' => 'approved', 'is_adult' => 0), 'feed');
        if ($small !== '') { $cands[] = Sections::e($small) . ' ' . PublicThumbService::GRID . 'w'; }
        if ($large !== '' && $large !== $small) { $cands[] = Sections::e($large) . ' ' . PublicThumbService::FEED . 'w'; }
        if (count($cands) > 1) {
            $srcset = ' srcset="' . implode(', ', $cands) . '" sizes="' . ($big ? '(max-width: 1000px) calc(100vw - 40px), 596px' : '(max-width: 1000px) calc(50vw - 28px), 290px') . '"';
        }
    }
    $who = '@' . $lp['u_name'];   // public pages show the handle only, never the display name
    $ini = strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $who), 0, 2));
    $cap = trim(html_entity_decode(strip_tags((string) $lp['caption']), ENT_QUOTES, 'UTF-8'));
    if (mb_strlen($cap) > 110) { $cap = rtrim(mb_substr($cap, 0, 108)) . '…'; }
    // The link holds only the image and the handle; the badge and caption sit beside it so the anchor text stays short (Seobility "anchor text too long", 2026-10-15)
    $tiles .= '<li class="lm__tile' . ($big ? ' lm__tile--big' : '') . '"><a class="lm__link" href="/@' . Sections::e(rawurlencode((string) $lp['u_name'])) . '">'
        . '<img src="' . Sections::e($thumb) . '"' . $srcset . ' alt="' . Sections::e(PublicThumbService::alt($lp['caption'], $who)) . '" loading="lazy" decoding="async" width="' . (int) $tw . '" height="' . (int) $th . '">'
        . '<p class="lm__meta"><span class="lm__av" aria-hidden="true">' . Sections::e($ini) . '</span><span class="lm__id"><b>' . Sections::e($who) . '</b><small>' . Sections::e($ago($lp['published_at'])) . '</small></span></p>'
        . '</a>'
        . ($big ? '<span class="lm__new"><i aria-hidden="true"></i>New</span>' : '')
        . ($big && $cap !== '' ? '<p class="lm__cap">' . Sections::e($cap) . '</p>' : '')   // caption and meta are paragraphs, each tile a list item: an extractor never merges the tiles into one run of text (2026-10-12)
        . '</li>';
}
if ($tiles !== '') {
    echo Sections::open('white', 'Latest posts', 'Fresh from creators on ' . $site . '. Tap a post to visit their page.');
    echo '<p class="lm__live"><i aria-hidden="true"></i>Live from the community</p><ul class="lm">' . $tiles . '</ul>';
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
echo '<div class="sx-acts"><a class="sx-btn sx-btn--secondary" href="/?auth=register" rel="nofollow" data-auth="register">Join Free</a></div>';
echo Sections::close();

/* Founding creators (/founding): up to six, listed like the directory, only when there is at least one. Avatar, name and handle; never a screenshot. */
$founders = array();
try { $founders = (new CreatorProfileModel())->founding_showcase(gmdate('Y-m-d'), 6); } catch (\Throwable $ex) { error_log('[home] founding row: ' . $ex->getMessage()); }
if (!empty($founders)) {
    echo Sections::open('white', 'Founding Creators', 'The first creators on ' . $site . '.');
    echo '<ul class="fc-row">';
    foreach ($founders as $fc) {
        $fc_name = trim((string) $fc['display_name']) !== '' ? (string) $fc['display_name'] : '@' . $fc['u_name'];
        $fc_img  = trim((string) ($fc['avatar_webp_url'] ?? '')) !== '' ? (string) $fc['avatar_webp_url'] : (string) $fc['avatar_url'];
        echo '<li><a class="fc-row__a" href="' . Sections::e(Main::get_base_domain() . '/@' . rawurlencode((string) $fc['u_name'])) . '">'
            . '<img class="fc-row__av" src="' . Sections::e($fc_img) . '" alt="' . Sections::e($fc_name) . '" width="56" height="56" loading="lazy" decoding="async">'
            . '<span class="fc-row__id"><b>' . Sections::e($fc_name) . '</b><small>@' . Sections::e($fc['u_name']) . '</small></span></a></li>';
    }
    echo '</ul>';
    echo Sections::close();
}

/* Trust area (heycatch D3.5, D3.1): the third-party listings that exist, and the creator count with an avatar grid once Proof::CREATOR_COUNT_MIN creators have public pages. */
$proof = Proof::links('sx-proof'); $grid = '';
try {
    $cpm = new CreatorProfileModel(); $n_creators = $cpm->directory_total('', '');
    if ($n_creators >= Proof::CREATOR_COUNT_MIN) {
        $avs = '';
        foreach ($cpm->directory('', '', 'active', 12, 0) as $c) {
            $src = trim((string) ($c['avatar_webp_url'] ?? '')) !== '' ? (string) $c['avatar_webp_url'] : (string) $c['avatar_url'];
            $avs .= '<li><img src="' . Sections::e($src) . '" alt="' . Sections::e((trim((string) $c['display_name']) !== '' ? (string) $c['display_name'] : '@' . $c['u_name'])) . '" width="44" height="44" loading="lazy"></li>';
        }
        $grid = '<div class="cc"><ul class="cc__avs">' . $avs . '</ul><p class="cc__n"><a href="/creators">' . number_format($n_creators) . ' creators with a public page</a></p></div>';
    }
} catch (\Throwable $ex) { error_log('[home] creator count: ' . $ex->getMessage()); }
if ($grid !== '') {   // a lone listing link made a near-empty band (design review 2026-10-12); the footer carries the Product Hunt link until the creator grid exists
    echo Sections::open('alt', 'Where to find us');
    echo $grid . $proof;
    echo Sections::close();
}

echo Sections::faq($home_faq, 'white');
echo Sections::cta('Create your page today.', 'Memberships, pay-per-view, bundles, services and events, with payouts to your bank.');
