<?php
/** Home page body on the shared section system (libs/Classes/Sections.php). Rendered inside login_form.php. Styles: public/css/home.css (hm-*). */
$site = Main::site_name();
$home_faq = PagesController::home_faq();   // shared with the FAQPage markup in login_form.php
$e = function ($s) { return Sections::e($s); };
$trial = Sections::trial_link();   // "Start Your 14-Day Free Trial" + the founding claim link while spots remain, else "Start Free"
$trial_open = false; try { $trial_open = Founding::remaining_cached() > 0; } catch (\Throwable $ex) { error_log('[home] founding: ' . $ex->getMessage()); }
/* One photo as a <picture>: the WebP renditions in public/images/home/<name>-<w>.webp (generated 2026-10-15 with fal, Most Realistic recipe; illustrative, never presented as a customer). */
$pic = function ($name, array $widths, $w, $h, $alt, $sizes, $class = '', $eager = false) use ($e) {
    $set = array(); foreach ($widths as $x) { $set[] = '/images/home/' . $name . '-' . $x . '.webp ' . $x . 'w'; }
    return '<img' . ($class !== '' ? ' class="' . $class . '"' : '') . ' src="/images/home/' . $name . '-' . end($widths) . '.webp" srcset="' . implode(', ', $set) . '" sizes="' . $e($sizes) . '"'
        . ' width="' . (int) $w . '" height="' . (int) $h . '" alt="' . $e($alt) . '"' . ($eager ? ' fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"') . '>';
};
$btn = function (array $t, $class) use ($e) {   // a trial_link() tuple as a button, with the arrow drawn as a Lucide icon
    return '<a class="sx-btn ' . $class . ' hm-btn" href="' . $e($t[1]) . '" rel="nofollow"' . ($t[2] !== '' ? ' data-auth="' . $e($t[2]) . '"' : '') . '>' . $e($t[0]) . Sections::icon('arrow-right', 18) . '</a>';
};

// Hero (2026-10-15 redesign, from Daniel's ad; no logo here, the nav already carries it): H1, supporting line, three benefits, the trial CTA; the portrait sits right and fades into the black.
?>
<section class="hm-hero" aria-labelledby="hm_title">
    <div class="hm-hero__media"><?php echo $pic('hero', array(640, 960, 1152), 1152, 1440, 'A content creator sitting on the edge of her bed in warm window light, looking into the camera', '(max-width: 760px) 100vw, 55vw', 'hm-hero__img', true); ?></div>
    <div class="ld-wrap hm-hero__in">
        <div class="hm-hero__copy">
            <h1 class="hm-hero__title" id="hm_title"><span class="hm-hero__l1">Create Stunning Content</span> <span class="hm-hero__l2">Publish Everywhere.</span></h1>
            <p class="hm-hero__sub">Create AI-powered photos and videos, publish across <?php echo count(IntegrationPages::PAGES); ?> platforms, automate your content, and monetize your audience&mdash;all from one studio.</p>
            <ul class="hm-hero__pts">
                <li><?php echo Sections::icon('image', 22); ?>AI Images &amp; Video</li>
                <li><?php echo Sections::icon('send', 22); ?>Multi-Platform Publishing</li>
                <li><?php echo Sections::icon('zap', 22); ?>Automation &amp; Monetization</li>
            </ul>
            <div class="hm-hero__acts">
                <?php echo $btn($trial, 'sx-btn--primary hm-btn--lg'); ?>
                <a class="sx-btn sx-btn--secondary hm-btn--lg" href="/features">Explore the Platform</a>
            </div>
        </div>
    </div>
</section>
<?php

// 1. Four pillars. The lead keeps the "What is" definition (SEO audit 2026-10-12) with its links to the AI influencer and team pages.
echo Sections::open('white', 'Everything You Need. One Studio.', '', 'studio');
echo '<p class="hm-def">' . $e($site) . ' is a creator platform for monetizing your content: one public page at your handle that sells memberships, pay-per-view posts, bundles, services and event tickets, with payouts to your bank. It includes an <a href="/features/ai-influencer">AI influencer generator</a> and <a href="/ai-ofm-tools">team seats for people who run creator accounts</a>. See where it sits in <a href="/best-creator-monetization-platforms">the best creator monetization platforms</a>.</p>';
$pillars = array(
    array('create-c', 'Create', 'AI photos and video of one consistent character, or of your own brand.', '/features/ai-influencer', 'A woman in a camel coat looking back over her shoulder on a city street at dusk'),
    array('publish', 'Publish', 'Write a post once and send it to your page and ten platforms, now or on a schedule.', '/integrations', 'A creator filming herself with a phone on a small tripod at home'),
    array('automate', 'Automate', 'Automations create, caption and post for you every day or on the days you pick.', '/features/publishing', 'A creator working at a laptop under a desk lamp in the evening'),
    array('monetize', 'Monetize', 'Sell memberships, single posts, bundles, services and events from one page.', '/features/memberships', 'A creator speaking to a camera in a home studio with a softbox light'),
);
echo '<ul class="hm-pillars">';
foreach ($pillars as $pl) {
    echo '<li class="hm-pillar"><a class="hm-pillar__a" href="' . $e($pl[3]) . '"><span class="hm-pillar__img">' . $pic($pl[0], array(480, 896), 896, 1120, $pl[4], '(max-width: 560px) 100vw, (max-width: 1100px) 50vw, 300px') . '</span>'
        . '<h3 class="hm-pillar__t">' . $e($pl[1]) . Sections::icon('arrow-right', 18) . '</h3></a><p class="hm-pillar__x">' . $e($pl[2]) . '</p></li>';
}
echo '</ul>';
echo Sections::close();

// 2. Create: the same generated character in three settings shows what "consistent" means. Every point is a real feature (FeaturePages ai-influencer, lora-character-training, consistent-ai-model-face; voice in InfluencerVoiceActions).
echo Sections::open('alt', '', '', 'create');
echo '<div class="hm-split">';
echo '<div class="hm-split__copy"><h2 class="sx-h2">' . $e(Sections::tc('Create Without Limits')) . '</h2>'
    . '<p class="sx-lead">Design an AI influencer once and keep the same face in every photo and video, whatever the outfit, place or style.</p>'
    . '<ul class="hm-list">'
    . '<li>' . Sections::icon('users', 20) . '<span><b>AI influencer creation.</b> Start from a description or from 10 to 50 photos.</span></li>'
    . '<li>' . Sections::icon('face', 20) . '<span><b>A consistent character.</b> <a href="/lora-character-training">Train the face</a> once and it stays the same.</span></li>'
    . '<li>' . Sections::icon('image', 20) . '<span><b>AI images.</b> New photos of your character from a short prompt.</span></li>'
    . '<li>' . Sections::icon('video', 20) . '<span><b>AI video.</b> Turn any still into a clip, or a talking video in a voice you design.</span></li>'
    . '<li>' . Sections::icon('wand', 20) . '<span><b>Creative flexibility.</b> Change the look, the scene and the style without losing the face.</span></li>'
    . '</ul><p class="hm-more"><a href="/consistent-ai-model-face">How the consistent face works</a></p></div>';
echo '<figure class="hm-trio">'
    . $pic('create-a', array(480, 896), 896, 1152, 'The same AI-generated woman at a cafe terrace with a coffee', '(max-width: 760px) 50vw, 240px', 'hm-trio__a')
    . $pic('create-b', array(480, 896), 896, 1152, 'The same AI-generated woman in a gym', '(max-width: 760px) 50vw, 240px', 'hm-trio__b')
    . $pic('hero', array(640, 960), 1152, 1440, 'The same AI-generated woman at home in a black cardigan', '(max-width: 760px) 50vw, 240px', 'hm-trio__c')
    . '<figcaption>One character, three scenes. Illustrative images made with AI.</figcaption></figure>';
echo '</div>';
echo Sections::close();

// 3. Ten platforms, straight from IntegrationPages::PAGES so the list can never drift from the integrations the app has.
echo Sections::open('white', 'One Post. Ten Platforms.', 'Write it once. Your page and every account you tick get it together, with caption limits and AI labels handled.', 'platforms');
echo '<ul class="hm-nets">';
foreach (IntegrationPages::PAGES as $slug => $ip) {
    $mark = IntegrationPages::logo($slug, 28);
    echo '<li><a class="hm-net" href="/integrations/' . $e($slug) . '">' . ($mark !== '' ? '<span class="hm-net__mark">' . $mark . '</span>' : '<span class="hm-net__mark hm-net__mark--text" aria-hidden="true">' . $e(mb_substr($ip['name'], 0, 1)) . '</span>') . '<span>' . $e($ip['name']) . '</span></a></li>';
}
echo '</ul><p class="hm-more"><a href="/integrations">See every integration</a></p>';
echo Sections::close();

// 4. Automation: a schematic of one rule (invented values, labelled), next to what the scheduler really does (cron/scheduler.php, AutoPostService).
echo Sections::open('alt', '', '', 'automate');
echo '<div class="hm-split">';
echo '<div class="hm-split__copy"><h2 class="sx-h2">' . $e(Sections::tc('Put Your Content on Autopilot')) . '</h2>'
    . '<p class="sx-lead">Plan a week of posts in one sitting, or set an automation and let it keep your page and socials fresh.</p>'
    . '<ul class="hm-list">'
    . '<li>' . Sections::icon('calendar', 20) . '<span><b>Scheduled publishing.</b> Pick a time and the post goes out on its own.</span></li>'
    . '<li>' . Sections::icon('repeat', 20) . '<span><b>Publishing automations.</b> Daily, or on the weekdays you choose, at your local time.</span></li>'
    . '<li>' . Sections::icon('sparkles', 20) . '<span><b>Less repetitive work.</b> Each run makes the image or video and writes the caption.</span></li>'
    . '<li>' . Sections::icon('clock', 20) . '<span><b>Consistent delivery.</b> Your audience sees something new on schedule.</span></li>'
    . '</ul><p class="hm-more"><a href="/features/publishing">More on publishing</a></p></div>';
echo '<figure class="hm-ui" aria-label="Illustrative example of an automation">'
    . '<div class="hm-ui__bar"><b>Morning Post</b><span class="hm-ui__on">Active</span></div>'
    . '<dl class="hm-ui__rows">'
    . '<div><dt>Runs</dt><dd>Every day at 9:00</dd></div>'
    . '<div><dt>Source</dt><dd>AI influencer</dd></div>'
    . '<div><dt>Format</dt><dd>Photo</dd></div>'
    . '<div><dt>Caption</dt><dd>Written by AI in your brand voice</dd></div>'
    . '<div><dt>Publish to</dt><dd class="hm-ui__chips"><span>Your page</span><span>Instagram</span><span>TikTok</span><span>X</span></dd></div>'
    . '<div><dt>Next run</dt><dd>Tomorrow, 9:00</dd></div>'
    . '</dl><figcaption>Illustrative example</figcaption></figure>';
echo '</div>';
echo Sections::close();

// 5. Monetize: the six ways to sell that exist as creator features. No earnings, no fee figures here.
echo Sections::open('white', 'Turn Your Audience Into Income', 'Fans follow for free, then choose how to support you. You set every price, and payouts go to your bank.', 'monetize');
echo Sections::index_list(array(
    array('icon' => 'layers', 'title' => 'Subscriptions', 'text' => 'Membership tiers with their own price, trial and perks.'),
    array('icon' => 'lock', 'title' => 'Pay-per-view', 'text' => 'Put a price on single posts that fans unlock in one tap.'),
    array('icon' => 'package', 'title' => 'Bundles', 'text' => 'Sell a set of past photos and videos as one product.'),
    array('icon' => 'calendar', 'title' => 'Services', 'text' => 'Take bookings for 1:1 sessions and custom work.'),
    array('icon' => 'ticket', 'title' => 'Events', 'text' => 'Sell seats to live sessions and video calls.'),
    array('icon' => 'message', 'title' => 'Direct to fans', 'text' => 'Message fans and send paid posts straight to their inbox.'),
));
echo '<p class="hm-more"><a href="/monetize-your-content">How creators sell on ' . $e($site) . '</a></p>';
echo Sections::close();

// 6. Growth: a schematic of the dashboard (invented figures, labelled; revenue drawn without a number) and what /dashboard really reports.
$bars = array(38, 52, 45, 61, 57, 72, 66, 80, 74, 90, 84, 96);
$bars_html = ''; foreach ($bars as $b) { $bars_html .= '<i style="height:' . (int) $b . '%"></i>'; }
echo Sections::open('alt', '', '', 'growth');
echo '<div class="hm-split hm-split--rev">';
echo '<figure class="hm-ui hm-ui--stats" aria-label="Illustrative example of the analytics dashboard">'
    . '<div class="hm-ui__bar"><b>Analytics</b><span class="hm-ui__seg"><span>7d</span><span class="is-on">30d</span><span>90d</span></span></div>'
    . '<div class="hm-ui__kpis"><div><small>Views</small><b>12,480</b></div><div><small>Engagement</small><b>6.2%</b></div><div><small>Link clicks</small><b>1,936</b></div><div><small>New followers</small><b>418</b></div></div>'
    . '<div class="hm-ui__chart"><small>Revenue</small><div class="hm-ui__bars">' . $bars_html . '</div></div>'
    . '<figcaption>Illustrative figures</figcaption></figure>';
echo '<div class="hm-split__copy"><h2 class="sx-h2">' . $e(Sections::tc('Understand Your Growth')) . '</h2>'
    . '<p class="sx-lead">One dashboard for your page and every platform you post to, over 7, 30 or 90 days.</p>'
    . '<ul class="hm-list">'
    . '<li>' . Sections::icon('chart', 20) . '<span><b>Engagement.</b> Likes, comments and views from your page and your social accounts.</span></li>'
    . '<li>' . Sections::icon('users', 20) . '<span><b>Audience activity.</b> Follower growth, where visitors come from and when they are active.</span></li>'
    . '<li>' . Sections::icon('eye', 20) . '<span><b>Content performance.</b> Every post with its views, engagement and sales.</span></li>'
    . '<li>' . Sections::icon('link', 20) . '<span><b>Clicks.</b> Link clicks, plus tracking links that show which links bring signups and sales.</span></li>'
    . '<li>' . Sections::icon('wallet', 20) . '<span><b>Revenue reporting.</b> Sales by offering, with an export of recent sales.</span></li>'
    . '</ul></div>';
echo '</div>';
echo Sections::close();

// 7. Plans: the offered tiers from PlanTiers via pricing_rows(). Price and fee each on their own line, never added together; the trial note only while the founding offer runs.
echo Sections::open('white', 'Start Free. Upgrade When You Sell.', 'Every account starts on Free. Pick a plan when you are ready to sell and publish.', 'plans');
echo '<ul class="hm-plans">';
foreach (PagesController::pricing_rows() as $r) {
    $t = $r['tier']; $paid = (int) $t['price'] > 0;
    echo '<li class="hm-plan' . (!empty($t['recommended']) ? ' is-rec' : '') . '"><h3 class="hm-plan__n">' . $e($t['name']) . '</h3><p class="hm-plan__tag">' . $e($t['tagline']) . '</p>'
        . '<p class="hm-plan__p"><b>$' . (int) $t['price'] . '</b><span>/month</span></p>'
        . '<p class="hm-plan__f">' . ($paid ? $e('Platform fee: ' . (int) $t['limits']['fee_percent'] . '% of sales') : 'Build your page and drafts') . '</p></li>';
}
echo '</ul>';
if ($trial_open) { echo '<p class="hm-trial">' . Sections::icon('star', 18) . '<span>Founding creators get their first ' . (int) Founding::TRIAL_DAYS . ' days free on ' . $e(Founding::plan_names()) . '.</span></p>'; }
echo '<div class="sx-acts"><a class="sx-btn sx-btn--secondary" href="/pricing">Compare Plans</a></div>';
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
// 8. Final band: dark photo, the closing line and the trial CTA as the one orange button.
?>
<section class="hm-final" aria-labelledby="hm_final">
    <div class="hm-final__media"><?php echo $pic('final', array(960, 1440), 1440, 816, 'A creator at her desk at night reviewing photos on a large monitor', '100vw'); ?></div>
    <div class="ld-wrap hm-final__in">
        <h2 class="hm-final__t" id="hm_final"><span>Your Content.</span> <span>Your Audience.</span> <span>Your Business.</span></h2>
        <div class="hm-hero__acts"><?php echo $btn($trial, 'sx-btn--primary hm-btn--lg'); ?></div>
    </div>
</section>

