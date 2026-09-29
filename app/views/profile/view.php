<?php
/**
 * Public creator storefront (self-contained page). Locals from
 * ProfileController::viewAction(): $user, $profile, $links, $display_name,
 * $handle, $public_domain, $is_self, $viewer_logged_in, $is_following,
 * $follower_count, $member_since.
 * Signed-in viewers get the page inside the app shell (sidebar + top bar, via View::site_header/footer)
 * so they can keep navigating; logged-out visitors get the standalone public page with full SEO head.
 */
$has_cover  = trim((string) ($profile['cover_url'] ?? ''))  !== '';
$has_avatar = trim((string) ($profile['avatar_url'] ?? '')) !== '';
$bio        = trim((string) ($profile['bio'] ?? ''));
$location   = trim((string) ($profile['location'] ?? ''));
$site_name  = Main::site_name();
$page_title = $display_name . ' (@' . $handle . ') · ' . $site_name;
$initial    = strtoupper(mb_substr($display_name, 0, 1));
$followers  = number_format((int) $follower_count);
$follow_word = ((int) $follower_count === 1) ? 'follower' : 'followers';
// On the creator's own domain (lexivaughn.com) the page is always the standalone one, its links drop the
// /@handle prefix, and Sign In goes through the platform (CustomDomains::login_url) and comes back here.
$on_own_domain = CustomDomains::is_home_of($handle);
$in_app     = !empty($viewer_logged_in) && !$on_own_domain;
$pf_base    = CustomDomains::profile_path($handle);
$pf_home    = ($pf_base === '') ? '/' : $pf_base;
$login_href = $on_own_domain ? CustomDomains::login_url(CustomDomains::safe_path($_SERVER['REQUEST_URI'] ?? '/')) : '/';
?>
<?php if ($in_app): $this->view->site_header(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/css/profile.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/profile.css'); ?>">
<div class="pf pf--app<?php echo (!empty($focus_event) || !empty($focus_service)) ? ' pf--event' : ''; ?>">
<?php else: ?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include Main::app_path() . '/libs/Layout/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/images/android-chrome-192x192.png">
    <?php echo CSRF::meta(); ?>
    <?php
        $seo_base   = Main::get_base_domain();
        $seo_url    = CustomDomains::canonical_profile_url($user);
        $seo_image  = $has_avatar ? (string) $profile['avatar_url'] : SeoMeta::default_image();
        $seo_about  = $display_name . ' (@' . $handle . ') on ' . $site_name . ': content, memberships, services, events, and links.';
        // A bio too short to describe the page gets the standard line after it.
        $seo_desc   = $bio === '' ? $seo_about : (mb_strlen($bio) < 70 ? mb_substr(rtrim($bio, '. ') . '. ' . $seo_about, 0, 160) : mb_substr($bio, 0, 160));
        $seo_same   = array();
        foreach ($links as $l) {
            if (preg_match('#^https?://#i', (string) $l['url'])) { $seo_same[] = (string) $l['url']; }
        }
        $seo_person = array(
            '@type'         => 'Person',
            '@id'           => $seo_url . '#person',
            'name'          => $display_name,
            'alternateName' => '@' . $handle,
            'url'           => $seo_url,
            'image'         => $seo_image,
            'interactionStatistic' => array(
                '@type'                => 'InteractionCounter',
                'interactionType'      => 'https://schema.org/FollowAction',
                'userInteractionCount' => (int) $follower_count,
            ),
        );
        if ($bio !== '')        { $seo_person['description'] = $bio; }
        if ($location !== '')   { $seo_person['homeLocation'] = array('@type' => 'Place', 'name' => $location); }
        if ($seo_same !== array()) { $seo_person['sameAs'] = $seo_same; }
        $seo_ld = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'ProfilePage',
            '@id'        => $seo_url,
            'url'        => $seo_url,
            'name'       => $page_title,
            'mainEntity' => $seo_person,
            'isPartOf'   => array('@type' => 'WebSite', 'name' => $site_name, 'url' => $seo_base . '/'),
        );
        // Google wants a full ISO 8601 datetime with offset; skip the field when the value can't be parsed.
        $created_ts = !empty($user['creator_since']) ? strtotime((string) $user['creator_since'] . ' UTC') : false;
        if ($created_ts !== false) { $seo_ld['dateCreated'] = gmdate('c', $created_ts); }
    ?>
    <?php echo SeoMeta::head(array(
        'title' => $page_title, 'og_title' => $display_name, 'description' => $seo_desc, 'url' => $seo_url, 'type' => 'profile', 'image' => $seo_image,
        'twitter_card' => $has_avatar ? 'summary' : 'summary_large_image',
        'extra' => array('<meta property="profile:username" content="' . htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') . '">'),
        'jsonld' => $seo_ld,
    )); ?>
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
    <link rel="stylesheet" href="/css/profile.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/profile.css'); ?>">
</head>
<body class="pf<?php echo (!empty($focus_event) || !empty($focus_service)) ? ' pf--event' : ''; ?>">
<?php if (!empty($focus_event) && !$on_own_domain) { include Main::app_path() . '/libs/Layout/guest_bar.php'; } /* signed-out event page: brand + Log In / Register */ ?>
<?php endif; ?>

    <!-- Signature: identity + primary action dock in on scroll -->
    <div class="pf-dock" id="pf_dock" aria-hidden="true" inert>
        <div class="pf-dock__inner">
            <div class="pf-dock__id">
                <span class="pf-dock__avatar"<?php echo $has_avatar ? ' style="background-image:url(\'' . htmlspecialchars($profile['avatar_url'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>><?php echo $has_avatar ? '' : htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?><span class="pf-presence pf-presence--sm <?php echo $is_online ? 'is-online' : 'is-offline'; ?>"></span></span>
                <span class="pf-dock__name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="pf-dock__actions" id="pf_dock_actions"></div>
        </div>
    </div>

    <div class="pf-cover<?php echo $has_cover ? '' : ' pf-cover--empty'; ?>"<?php echo $has_cover ? ' style="background-image:url(\'' . htmlspecialchars($profile['cover_url'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>></div>

    <div class="pf-container">
        <header class="pf-hero">
            <div class="pf-hero__top">
                <div class="pf-avatar"<?php echo $has_avatar ? ' style="background-image:url(\'' . htmlspecialchars($profile['avatar_url'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>>
                    <?php if (!$has_avatar): ?><span class="pf-avatar__initial"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    <span class="pf-presence <?php echo $is_online ? 'is-online' : 'is-offline'; ?>" title="<?php echo $is_online ? 'Online now' : 'Offline'; ?>"></span>
                </div>
                <div class="pf-hero__id">
                    <h1 class="pf-name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($user['verified'])): ?> <i class="fa-solid fa-circle-check pf-verified" title="Verified creator"></i><?php endif; ?></h1>
                    <div class="pf-meta">
                        <span class="pf-meta__handle">@<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="pf-meta__dot">·</span>
                        <span class="pf-status <?php echo $is_online ? 'pf-status--online' : 'pf-status--offline'; ?>"><span class="pf-status__dot"></span><?php echo $is_online ? 'Online' : 'Offline'; ?></span>
                        <?php if ($location !== ''): ?><span class="pf-meta__dot">·</span><span class="pf-meta__loc"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    </div>
                    <div class="pf-stats"><span class="pf-stat"><strong id="pf_follower_count"><?php echo $followers; ?></strong> <span id="pf_follower_word"><?php echo $follow_word; ?></span></span></div>
                </div>
                <div class="pf-actions" id="pf_actions"></div>
            </div>

        </header>

        <div class="pf-grid">
            <div class="pf-main">
<?php $render_ev_card = function (array $ec) use ($user, $display_name, $pf_base) {
    // One row per event in the Events tab; the whole row opens the event page, where people register.
    $h_   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $paid = ($ec['access_type'] !== 'free' && (int) $ec['price_credits'] > 0);
    $req  = $ec['access_type'] === 'tier' ? ($ec['tier_name'] !== '' ? $ec['tier_name'] . ' subscribers' : 'Subscribers') : ($ec['access_type'] === 'subscribers' ? 'Subscribers' : '');
    $left = (int) $ec['capacity'] > 0 ? max(0, (int) $ec['capacity'] - (int) $ec['attendees']) : null;
    $flag = !empty($ec['is_canceled']) ? array('Canceled', 'off') : (!empty($ec['is_past']) ? array('Ended', 'off') : (!empty($ec['registered']) ? array('Registered', 'ok') : (!empty($ec['is_full']) ? array('Sold out', 'off') : null)));
    $where = $ec['is_inperson'] ? ($ec['place']['short'] !== '' ? $ec['place']['short'] : 'In person') : 'Online';
?>
                        <a class="pel" href="<?php echo $h_($pf_base); ?>/events/<?php echo (int) $ec['id']; ?>" data-ev-card="<?php echo (int) $ec['id']; ?>">
                            <span class="pel__date" aria-hidden="true"><span class="pel__m"><?php echo $h_($ec['tile_month']); ?></span><span class="pel__d"><?php echo $h_($ec['tile_day']); ?></span></span>
                            <span class="pel__body">
                                <span class="pel__title"><?php echo $h_($ec['title']); ?><?php if ($flag): ?> <span class="pel__flag pel__flag--<?php echo $flag[1]; ?>"><?php echo $flag[0]; ?></span><?php endif; ?></span>
                                <span class="pel__meta">
                                    <span><?php echo $h_($ec['time_line']); ?></span>
                                    <span class="pel__sep" aria-hidden="true">·</span>
                                    <span class="pel__where"><i class="fa-solid <?php echo $ec['is_inperson'] ? 'fa-location-dot' : 'fa-video'; ?>" aria-hidden="true"></i> <?php echo $h_($where); ?></span>
                                    <?php if ($left !== null && !$flag): ?><span class="pel__sep" aria-hidden="true">·</span><span><?php echo $left; ?> <?php echo $left === 1 ? 'spot' : 'spots'; ?> left</span><?php endif; ?>
                                </span>
                                <?php if (trim((string) $ec['description']) !== ''): ?><span class="pel__desc"><?php echo $h_($ec['description']); ?></span><?php endif; ?>
                            </span>
                            <span class="pel__price">
                                <span class="pel__amount"><?php echo $paid ? '$' . $h_($ec['price_dollars']) : 'Free'; ?></span>
                                <?php if ($req !== ''): ?><span class="pel__req"><?php echo $h_($req); ?></span><?php endif; ?>
                            </span>
                            <i class="fa-solid fa-chevron-right pel__go" aria-hidden="true"></i>
                        </a>
<?php }; ?>
                <?php if (!empty($focus_event)): $fe = $focus_event;
                    $h_       = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
                    $fe_url   = $pf_home;
                    $fe_paid  = ($fe['access_type'] !== 'free' && (int) $fe['price_credits'] > 0);
                    $fe_left  = (int) $fe['capacity'] > 0 ? max(0, (int) $fe['capacity'] - (int) $fe['attendees']) : null;
                    $fe_members = in_array($fe['access_type'], array('subscribers', 'tier'), true);
                    $fe_req   = $fe['access_type'] === 'tier' ? ($fe['tier_name'] !== '' ? 'For ' . $fe['tier_name'] . ' subscribers' : 'For subscribers on one plan') : ($fe_members ? 'For ' . $display_name . '’s subscribers' : 'Open to everyone');
                    // One state drives the card: what this viewer can do right now.
                    $fe_state = !empty($fe['is_canceled']) ? 'canceled' : (!empty($fe['is_past']) ? 'ended'
                              : (!empty($fe['registered']) ? 'registered'
                              : (!empty($fe['is_full']) ? 'full' : (!$viewer_logged_in ? 'signin' : (empty($fe['eligible']) && empty($fe['is_self']) ? 'ineligible' : 'open')))));   // the host sees what an eligible fan sees
                    $ax = $fe['access'] ?? null;   // venue / link / instructions: only for registered attendees and the host
                    $can_see = is_array($ax);
                ?>
                <?php
                    $pl = $fe['place'];
                    $maps_q = trim(implode(', ', array_filter(array($pl['venue'], $pl['street'], $pl['city_line']), 'strlen')));
                    if ($maps_q === '') { $maps_q = $pl['line']; }
                ?>
                <article class="pe pf-ev" data-ev-card="<?php echo (int) $fe['id']; ?>">
                    <div class="pe-bar">
                        <a class="pe-back" href="<?php echo $h_($fe_url); ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?php echo $h_($display_name); ?></a>
                        <?php if (!empty($fe['is_self'])): ?><a class="pe-manage" href="/events/manage/<?php echo (int) $fe['id']; ?>"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Manage Event</a><?php endif; ?>
                    </div>


                    <div class="pe-grid">
                        <div class="pe-main">
                            <header class="pe-hero">
                                <div class="pe-hero__date" aria-hidden="true">
                                    <span class="pe-hero__m"><?php echo $h_($fe['tile_month']); ?></span>
                                    <span class="pe-hero__d"><?php echo $h_($fe['tile_day']); ?></span>
                                    <span class="pe-hero__w"><?php echo $h_($fe['tile_wday']); ?></span>
                                </div>
                                <div class="pe-hero__text">
                                    <h1 class="pe-title<?php echo mb_strlen((string) $fe['title']) > 60 ? ' pe-title--long' : ''; ?>"><?php echo $h_($fe['title']); ?></h1>
                                    <div class="pe-meta">
                                    <a class="pe-host" href="<?php echo $h_($fe_url); ?>">
                                        <span class="pe-host__av"<?php echo $has_avatar ? ' style="background-image:url(\'' . $h_($profile['avatar_url']) . '\')"' : ''; ?>><?php echo $has_avatar ? '' : $h_($initial); ?></span>
                                        <span class="pe-host__text"><span class="pe-host__by">Hosted by</span><span class="pe-host__name"><?php echo $h_($display_name); ?><?php if (!empty($user['verified'])): ?> <i class="fa-solid fa-circle-check pf-verified" title="Verified creator"></i><?php endif; ?></span></span>
                                    </a>
                                    </div>
                                </div>
                            </header>
                            <div class="pe-tiles">
                                <section class="pe-tile">
                                    <h2 class="pe-tile__h"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Date and Time</h2>
                                    <p class="pe-tile__main"><?php echo $h_($fe['date_long']); ?></p>
                                    <p class="pe-tile__sub"><?php echo $h_($fe['time_range']); ?></p>
                                </section>
                                <section class="pe-tile">
                                    <h2 class="pe-tile__h"><i class="fa-solid <?php echo $fe['is_inperson'] ? 'fa-location-dot' : 'fa-video'; ?>" aria-hidden="true"></i> <?php echo $fe['is_inperson'] ? 'In-Person Event' : 'Online Event'; ?></h2>
                                    <?php if ($fe['is_inperson']): ?>
                                        <?php if ($pl['venue'] !== ''): ?><p class="pe-tile__main"><?php echo $h_($pl['venue']); ?></p><?php endif; ?>
                                        <?php if ($pl['street'] !== ''): ?><p class="<?php echo $pl['venue'] === '' ? 'pe-tile__main' : 'pe-tile__sub'; ?>"><?php echo $h_($pl['street']); ?></p><?php endif; ?>
                                        <?php if ($pl['city_line'] !== ''): ?><p class="pe-tile__sub"><?php echo $h_($pl['city_line']); ?></p><?php endif; ?>
                                        <?php if ($maps_q === ''): ?><p class="pe-tile__main">In person</p><?php else: ?>
                                        <a class="pe-tile__link" href="https://www.google.com/maps/search/?api=1&amp;query=<?php echo $h_(rawurlencode($maps_q)); ?>" target="_blank" rel="noopener noreferrer">Open in Maps <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (!empty($fe['is_video'])): ?>
                                        <p class="pe-tile__main">CLS Video</p>
                                        <p class="pe-tile__sub"><?php echo !empty($fe['open_call']) ? 'Anyone can join the call on this page. No registration needed.' : ($fe_state === 'registered' ? 'Join the call on this page.' : 'Join the call on this page after you register.'); ?></p>
                                        <?php else: ?>
                                        <p class="pe-tile__main">Online</p>
                                        <?php if ($fe_state === 'registered'): ?><p class="pe-tile__sub">Your meeting link was sent to your email.</p>
                                        <?php elseif (in_array($fe_state, array('open', 'signin', 'ineligible'), true)): ?><p class="pe-tile__sub">The meeting link is emailed when you register.</p><?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </section>
                            </div>
                            <?php if (trim((string) $fe['description']) !== ''): ?>
                            <section class="pe-about">
                                <h2 class="pe-about__h">About This Event</h2>
                                <p class="pe-desc"><?php echo nl2br($h_($fe['description'])); ?></p>
                            </section>
                            <?php endif; ?>
                            <?php if ($can_see && trim((string) $ax['instructions']) !== ''): ?>
                            <section class="pe-about pe-about--instr">
                                <h2 class="pe-about__h">Instructions for Attendees</h2>
                                <p class="pe-desc"><?php echo nl2br($h_($ax['instructions'])); ?></p>
                            </section>
                            <?php endif; ?>
                        </div>

                        <aside class="pe-ticket" aria-label="Registration">
                            <div class="pe-ticket__top">
                                <p class="pe-ticket__label">Admission</p>
                                <p class="pe-ticket__price"><?php echo $fe_paid ? '$' . $h_($fe['price_dollars']) . ' <span>per person</span>' : 'Free'; ?></p>
                                <p class="pe-ticket__row"><i class="fa-solid <?php echo $fe_members ? 'fa-user-group' : 'fa-globe'; ?>" aria-hidden="true"></i> <?php echo $h_($fe_req); ?></p>
                                <?php if ($fe_left !== null && in_array($fe_state, array('open', 'signin', 'ineligible'), true)): ?>
                                <p class="pe-ticket__row"><i class="fa-solid fa-ticket" aria-hidden="true"></i> <?php echo $fe_left; ?> of <?php echo (int) $fe['capacity']; ?> <?php echo (int) $fe['capacity'] === 1 ? 'spot' : 'spots'; ?> left</p>
                                <?php endif; ?>
                            </div>
                            <div class="pe-ticket__tear" aria-hidden="true"></div>
                            <div class="pf-ev__cta pe-ticket__bottom">
                                <?php
                                // CLS Video: a free event open to everyone can be joined by anyone while the call is open, registered or
                                // not (no account needed). Paid / members events: ticket holders only. Registering works as before.
                                $fe_video_now = !empty($fe['is_video']) && ($fe['video_phase'] ?? '') === 'open';
                                $fe_can_join  = $fe_video_now && (!empty($fe['open_call']) || !empty($fe['registered']));
                                $fe_pw_note   = !empty($fe['has_password']) ? '<p class="pe-card__sub"><i class="fa-solid fa-lock" aria-hidden="true"></i> You&rsquo;ll need the call password from the host.</p>' : '';
                                ?>
                                <?php if ($fe_state === 'canceled'): ?>
                                <p class="pe-status">This event was canceled.</p>
                                <?php elseif ($fe_can_join): ?>
                                <a class="pe-btn" href="<?php echo $h_($fe['video_url']); ?>"><i class="fa-solid fa-video" aria-hidden="true"></i> Join Video</a>
                                <?php echo $fe_pw_note; ?>
                                <?php if ($fe_state === 'registered'): ?>
                                <p class="pe-status pe-status--ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> You&rsquo;re registered</p>
                                <?php elseif ($fe_state === 'open'): ?>
                                <button type="button" class="pe-btn pe-btn--secondary" data-ev-register="<?php echo (int) $fe['id']; ?>">Register for Event</button>
                                <?php endif; ?>
                                <?php if (!empty($fe['open_call']) && $fe_state !== 'registered'): ?><p class="pe-card__sub">Anyone can join. Registering is optional.</p><?php endif; ?>
                                <?php elseif ($fe_state === 'ended'): ?>
                                <p class="pe-status">This event has ended.</p>
                                <?php elseif ($fe_state === 'registered'): ?>
                                <p class="pe-status pe-status--ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> You&rsquo;re registered</p>
                                <?php if (!empty($fe['is_video'])): ?><p class="pe-card__sub">The call opens here at <?php echo $h_($fe['video_opens']); ?>.</p><?php echo $fe_pw_note; ?><?php endif; ?>
                                <button type="button" class="pe-cancel" data-ev-cancel="<?php echo (int) $fe['id']; ?>" data-paid="<?php echo (int) ($fe['my_paid'] ?? 0); ?>">Cancel Registration</button>
                                <?php elseif ($fe_state === 'full'): ?>
                                <p class="pe-status">This event is full.</p>
                                <?php if (!empty($fe['open_call'])): ?><p class="pe-card__sub">The call is still open to everyone. It opens here at <?php echo $h_($fe['video_opens']); ?>.</p><?php endif; ?>
                                <?php elseif ($fe_state === 'signin'): ?>
                                <a class="pe-btn" href="<?php echo htmlspecialchars($login_href, ENT_QUOTES, 'UTF-8'); ?>">Sign In to Register</a>
                                <?php if (!empty($fe['open_call'])): ?><p class="pe-card__sub">Anyone can join the call here at <?php echo $h_($fe['video_opens']); ?>, registered or not.</p><?php echo $fe_pw_note; ?><?php endif; ?>
                                <?php elseif ($fe_state === 'ineligible'): ?>
                                <a class="pe-btn" href="<?php echo $h_($fe_url); ?>#plans">View Membership Plans</a>
                                <p class="pe-card__sub"><?php echo $fe['access_type'] === 'tier' && $fe['tier_name'] !== '' ? 'Subscribe to ' . $h_($fe['tier_name']) . ' to register.' : 'Subscribe to ' . $h_($display_name) . ' to register.'; ?></p>
                                <?php else: ?>
                                <button type="button" class="pe-btn" data-ev-register="<?php echo (int) $fe['id']; ?>">Register for Event</button>
                                <?php if (!empty($fe['open_call'])): ?><p class="pe-card__sub">Anyone can join the call here at <?php echo $h_($fe['video_opens']); ?>, registered or not.</p><?php echo $fe_pw_note; ?><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </aside>
                    </div>
                </article>
                <?php elseif (!empty($focus_service)): $fs = $focus_service;
                    $h_     = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
                    $dx     = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
                    $fs_url = $pf_home;
                    $fs_paid = (int) $fs['price_credits'] > 0;
                    $fs_left = (int) $fs['capacity'] > 0 ? max(0, (int) $fs['capacity'] - (int) $fs['purchases']) : null;
                    $fs_method = ServicesModel::method_label($fs['delivery_method']);
                    $fs_icon = $fs['delivery_method'] === 'in_person' ? 'fa-location-dot' : ($fs['delivery_method'] === 'phone' ? 'fa-phone' : 'fa-video');
                    $fs_state = !empty($fs['purchased']) ? 'booked' : (!empty($fs['is_full']) ? 'full' : (!$viewer_logged_in ? 'signin' : 'open'));   // the creator sees what a fan sees
                    $fs_title = $dx($fs['name']);
                ?>
                <article class="pe pf-ev" data-sv-card="<?php echo (int) $fs['id']; ?>">
                    <div class="pe-bar">
                        <a class="pe-back" href="<?php echo $h_($fs_url); ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?php echo $h_($display_name); ?></a>
                        <?php if (!empty($fs['is_self'])): ?><a class="pe-manage" href="/services/manage/<?php echo (int) $fs['id']; ?>"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Manage Service</a><?php endif; ?>
                    </div>
                    <div class="pe-grid">
                        <div class="pe-main">
                            <header class="pe-hero">
                                <div class="pe-hero__date" aria-hidden="true">
                                    <span class="pe-hero__m">Session</span>
                                    <?php if ((int) $fs['duration_min'] > 0): ?>
                                    <span class="pe-hero__d"><?php echo (int) $fs['duration_min']; ?></span>
                                    <span class="pe-hero__w">min</span>
                                    <?php else: ?>
                                    <span class="pe-hero__d pe-hero__d--ic"><i class="fa-solid <?php echo $fs_icon; ?>"></i></span>
                                    <span class="pe-hero__w"><?php echo $h_($fs_method); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="pe-hero__text">
                                    <h1 class="pe-title<?php echo mb_strlen($fs_title) > 60 ? ' pe-title--long' : ''; ?>"><?php echo $h_($fs_title); ?></h1>
                                    <div class="pe-meta">
                                    <a class="pe-host" href="<?php echo $h_($fs_url); ?>">
                                        <span class="pe-host__av"<?php echo $has_avatar ? ' style="background-image:url(\'' . $h_($profile['avatar_url']) . '\')"' : ''; ?>><?php echo $has_avatar ? '' : $h_($initial); ?></span>
                                        <span class="pe-host__text"><span class="pe-host__by">Offered by</span><span class="pe-host__name"><?php echo $h_($display_name); ?><?php if (!empty($user['verified'])): ?> <i class="fa-solid fa-circle-check pf-verified" title="Verified creator"></i><?php endif; ?></span></span>
                                    </a>
                                    </div>
                                </div>
                            </header>
                            <div class="pe-tiles">
                                <section class="pe-tile">
                                    <h2 class="pe-tile__h"><i class="fa-solid <?php echo $fs_icon; ?>" aria-hidden="true"></i> Session</h2>
                                    <p class="pe-tile__main"><?php echo (int) $fs['duration_min'] > 0 ? (int) $fs['duration_min'] . ' minutes' : 'Flexible length'; ?> · <?php echo $h_($fs_method); ?></p>
                                    <?php if (trim($dx($fs['category'])) !== ''): ?><p class="pe-tile__sub"><?php echo $h_($dx($fs['category'])); ?></p><?php endif; ?>
                                </section>
                                <?php if (trim($dx($fs['refund_policy'])) !== ''): ?>
                                <section class="pe-tile">
                                    <h2 class="pe-tile__h"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Refund Policy</h2>
                                    <p class="pe-tile__sub"><?php echo $h_($dx($fs['refund_policy'])); ?></p>
                                </section>
                                <?php endif; ?>
                            </div>
                            <?php if (trim($dx($fs['description'])) !== ''): ?>
                            <section class="pe-about">
                                <h2 class="pe-about__h">About This Service</h2>
                                <p class="pe-desc"><?php echo nl2br($h_($dx($fs['description']))); ?></p>
                            </section>
                            <?php endif; ?>
                            <?php if (!empty($fs['access']) && trim($dx($fs['access']['details'])) !== ''): ?>
                            <section class="pe-about pe-about--instr">
                                <h2 class="pe-about__h">Your Booking Details</h2>
                                <p class="pe-desc"><?php echo nl2br($h_($dx($fs['access']['details']))); ?></p>
                            </section>
                            <?php endif; ?>
                        </div>

                        <aside class="pe-ticket" aria-label="Booking">
                            <div class="pe-ticket__top">
                                <p class="pe-ticket__label">Booking</p>
                                <p class="pe-ticket__price"><?php echo $fs_paid ? '$' . $h_($fs['price_dollars']) . ' <span>per booking</span>' : 'Free'; ?></p>
                                <p class="pe-ticket__row"><i class="fa-solid <?php echo $fs_icon; ?>" aria-hidden="true"></i> <?php echo (int) $fs['duration_min'] > 0 ? (int) $fs['duration_min'] . ' min · ' : ''; ?><?php echo $h_($fs_method); ?></p>
                                <?php if ($fs_left !== null && in_array($fs_state, array('open', 'signin'), true)): ?>
                                <p class="pe-ticket__row"><i class="fa-solid fa-ticket" aria-hidden="true"></i> <?php echo $fs_left; ?> of <?php echo (int) $fs['capacity']; ?> <?php echo (int) $fs['capacity'] === 1 ? 'spot' : 'spots'; ?> left</p>
                                <?php endif; ?>
                            </div>
                            <div class="pe-ticket__tear" aria-hidden="true"></div>
                            <div class="pf-ev__cta pe-ticket__bottom">
                                <?php if ($fs_state === 'booked'): ?>
                                <?php if (!empty($fs['video_url'])): ?>
                                <a class="pe-btn" href="<?php echo $h_($fs['video_url']); ?>"><i class="fa-solid fa-video" aria-hidden="true"></i> Join Call</a>
                                <?php endif; ?>
                                <p class="pe-status pe-status--ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> You&rsquo;re booked</p>
                                <p class="pe-card__sub">Your booking details are on this page.</p>
                                <?php elseif ($fs_state === 'full'): ?>
                                <p class="pe-status">This service is fully booked.</p>
                                <?php elseif ($fs_state === 'signin'): ?>
                                <a class="pe-btn" href="<?php echo htmlspecialchars($login_href, ENT_QUOTES, 'UTF-8'); ?>">Sign In to Book</a>
                                <?php else: ?>
                                <button type="button" class="pe-btn" data-sv-purchase="<?php echo (int) $fs['id']; ?>">Book Now</button>
                                <?php endif; ?>
                            </div>
                        </aside>
                    </div>
                </article>
                <?php else: ?>
                <nav class="pf-tabs" role="tablist">
                    <button class="pf-tab is-active" data-panel="content" role="tab">Content</button>
                    <button class="pf-tab" data-panel="plans" role="tab">Membership</button>
                    <?php if (!empty($service_cards)): ?><button class="pf-tab" data-panel="services" role="tab">Services</button><?php endif; ?>
                    <?php if (!empty($event_cards)): ?><button class="pf-tab" data-panel="events" role="tab">Events</button><?php endif; ?>
                    <button class="pf-tab" data-panel="about" role="tab">About</button>
                    <?php if (!empty($links)): ?><button class="pf-tab" data-panel="links" role="tab">Links</button><?php endif; ?>
                </nav>

                <section class="pf-panel is-active" data-panel="content">
                    <?php if (empty($content_cards)): ?>
                    <div class="pf-empty">
                        <i class="fa-regular fa-images pf-empty__icon"></i>
                        <p class="pf-empty__title">No posts yet</p>
                        <p class="pf-empty__text">When <?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?> shares something, it'll show up here. Follow to get notified.</p>
                    </div>
                    <?php else: ?>
                    <div class="pf-toolbar">
                        <div class="pf-search">
                            <i class="fa-solid fa-magnifying-glass pf-search__icon"></i>
                            <input type="search" id="pfSearch" class="pf-search__input" placeholder="Search posts…" autocomplete="off" aria-label="Search posts">
                        </div>
                    </div>
                    <div class="pf-feed">
                        <?php foreach ($content_cards as $c): $cov = htmlspecialchars((string) $c['cover'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php $pc_cap = trim((string) $c['caption']); $pc_label = ($c['entitled'] ? 'Open post' : 'Locked post') . ($pc_cap !== '' ? ': ' . mb_substr($pc_cap, 0, 80) : ''); ?>
                        <button type="button" class="pf-pc<?php echo $c['entitled'] ? '' : ' pf-pc--locked'; ?>" aria-label="<?php echo htmlspecialchars($pc_label, ENT_QUOTES, 'UTF-8'); ?>" data-post-id="<?php echo (int) $c['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower((string) $c['caption']), ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="pf-pc__thumb"<?php echo $cov !== '' ? ' style="background-image:url(\'' . $cov . '\')"' : ''; ?>>
                                <?php if (!$c['entitled']): ?><span class="pf-pc__lockbadge"><i class="fa-solid fa-lock"></i></span><?php endif; ?>
                                <?php if ($c['entitled'] && $c['has_video']): ?><span class="pf-pc__play"><i class="fa-solid fa-play"></i></span><?php endif; ?>
                                <?php if ((int) $c['media_count'] > 1): ?><span class="pf-pc__count"><i class="fa-solid fa-layer-group"></i> <?php echo (int) $c['media_count']; ?></span><?php endif; ?>
                            </span>
                            <?php if (trim((string) $c['excerpt']) !== ''): ?><span class="pf-pc__cap"><?php echo htmlspecialchars((string) $c['excerpt'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="pf-feed__none" id="pfNoResults" hidden>No posts match your search.</p>

                    <!-- full-post lightbox -->
                    <div class="pf-plb" id="pfLightbox" hidden>
                        <button type="button" class="pf-plb__close" id="pfLbClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                        <div class="pf-plb__inner" id="pfLbInner"></div>
                    </div>
                    <script>window.PROFILE_POSTS = <?php echo json_encode($content_cards, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;</script>
                    <?php endif; ?>
                </section>

                <section class="pf-panel" data-panel="plans">
                    <?php if (empty($plans) && empty($bundle_cards)): ?>
                    <div class="pf-empty">
                        <i class="fa-regular fa-star pf-empty__icon"></i>
                        <p class="pf-empty__title">No membership plans yet</p>
                        <p class="pf-empty__text"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?> hasn't set up membership tiers. Check back soon.</p>
                    </div>
                    <?php else: ?>
                    <?php if ($viewer_logged_in && !empty($plans)): ?>
                    <div class="pf-promo">
                        <input type="text" id="pf_promo_code" class="pf-promo__input" placeholder="Have a discount code? Enter it here" maxlength="40" autocomplete="off">
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($plans)): ?>
                    <div class="pf-plans">
                        <?php foreach ($plans as $plan): ?>
                        <?php
                            $is_free = ((int) $plan['price_cents'] === 0);
                            $is_subscribed = in_array((int) $plan['id'], $subscribed_plan_ids, true);
                            $unit = ($plan['billing_interval'] === 'year') ? 'year' : (($plan['billing_interval'] === 'week') ? 'week' : 'month');
                            $perk_lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $plan['perks'])));
                        ?>
                        <div class="pf-plan<?php echo $is_free ? ' pf-plan--free' : ''; ?>">
                            <div class="pf-plan__body">
                                <div class="pf-plan__head">
                                    <span class="pf-plan__name"><?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="pf-plan__price"><?php if ($is_free): ?>Free<?php else: ?>$<?php echo number_format($plan['price_cents'] / 100, 2); ?><span class="pf-plan__unit">/<?php echo $unit; ?></span><?php endif; ?></span>
                                </div>
                                <?php
                                    $tunit2 = in_array(($plan['trial_unit'] ?? 'day'), array('day', 'week', 'month'), true) ? $plan['trial_unit'] : 'day';
                                    $has_trial = !$is_free && !empty($plan['trial_enabled']) && (int) ($plan['trial_value'] ?? 0) > 0;
                                    $trial_label = $has_trial ? ((int) $plan['trial_value'] . '-' . $tunit2) : '';
                                ?>
                                <?php if ($has_trial): ?><span class="pf-plan__trial"><i class="fa-solid fa-gift"></i> <?php echo $trial_label; ?> free trial</span><?php endif; ?>
                                <?php if (trim((string) $plan['description']) !== ''): ?><p class="pf-plan__desc"><?php echo htmlspecialchars((string) $plan['description'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                <?php if (!empty($perk_lines)): ?>
                                <ul class="pf-plan__perks">
                                    <?php foreach ($perk_lines as $perk): ?><li><i class="fa-solid fa-check"></i> <span><?php echo htmlspecialchars($perk, ENT_QUOTES, 'UTF-8'); ?></span></li><?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_subscribed): ?>
                            <div class="pf-plan__cta pf-plan__member"><i class="fa-solid fa-circle-check"></i> <?php echo $is_free ? 'Joined' : 'Member'; ?></div>
                            <?php elseif ($is_free): ?>
                            <button class="pf-btn pf-btn--follow pf-plan__cta" data-join-free="<?php echo (int) $plan['id']; ?>"><i class="fa-solid fa-plus"></i> Join for Free</button>
                            <?php else: ?>
                            <button class="pf-btn pf-btn--subscribe pf-plan__cta" data-subscribe-plan="<?php echo (int) $plan['id']; ?>"><i class="fa-solid fa-star"></i> <?php echo $has_trial ? ('Start ' . $trial_label . ' free trial') : 'Subscribe'; ?></button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($bundle_cards)): ?>
                    <h3 class="pf-bundles__title">Content bundles</h3>
                    <p class="pf-bundles__sub">Buy a group of content together at one price.</p>
                    <div class="pf-bundles">
                        <?php foreach ($bundle_cards as $bd): ?>
                        <div class="pf-bundle">
                            <div class="pf-bundle__body">
                                <div class="pf-bundle__head">
                                    <span class="pf-bundle__name"><?php echo htmlspecialchars((string) $bd['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="pf-bundle__price">$<?php echo htmlspecialchars((string) $bd['price_dollars'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <span class="pf-bundle__count"><i class="fa-solid fa-layer-group"></i> <?php echo (int) $bd['item_count']; ?> item<?php echo (int) $bd['item_count'] === 1 ? '' : 's'; ?></span>
                                <?php if (trim((string) $bd['description']) !== ''): ?><p class="pf-bundle__desc"><?php echo htmlspecialchars((string) $bd['description'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                            </div>
                            <?php if (!empty($bd['owned'])): ?>
                            <div class="pf-plan__cta pf-plan__member"><i class="fa-solid fa-circle-check"></i> Owned</div>
                            <?php elseif (!$viewer_logged_in): ?>
                            <button class="pf-btn pf-btn--subscribe pf-plan__cta" data-bundle-login><i class="fa-solid fa-lock"></i> Log in to Unlock</button>
                            <?php elseif ($viewer_credit_balance >= (int) $bd['price_credits']): ?>
                            <button class="pf-btn pf-btn--subscribe pf-plan__cta" data-bundle-unlock="<?php echo (int) $bd['id']; ?>"><i class="fa-solid fa-unlock"></i> Unlock for $<?php echo htmlspecialchars((string) $bd['price_dollars'], ENT_QUOTES, 'UTF-8'); ?></button>
                            <?php else: ?>
                            <a class="pf-btn pf-btn--subscribe pf-plan__cta" href="/account/settings?section=wallet"><i class="fa-solid fa-plus"></i> Add Funds to Unlock</a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </section>

                <?php if (!empty($service_cards)): ?>
                <?php $svm = ServicesModel::method_labels() + array('cls_video' => 'CLS Video'); ?>
                <section class="pf-panel" data-panel="services">
                    <div class="pel-list">
                        <?php foreach ($service_cards as $sc):   // one row per service; the row opens the service page, where people book
                            $sc_h = function ($x) { return htmlspecialchars(html_entity_decode((string) $x, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); };
                            $sc_icon = $sc['delivery_method'] === 'in_person' ? 'fa-location-dot' : ($sc['delivery_method'] === 'phone' ? 'fa-phone' : 'fa-video');
                            $sc_left = (int) $sc['capacity'] > 0 ? max(0, (int) $sc['capacity'] - (int) $sc['purchases']) : null;
                            $sc_flag = !empty($sc['purchased']) ? array('Booked', 'ok') : (!empty($sc['is_full']) ? array('Fully booked', 'off') : null);
                        ?>
                        <a class="pel" href="<?php echo htmlspecialchars($pf_base, ENT_QUOTES, 'UTF-8'); ?>/services/<?php echo (int) $sc['id']; ?>" data-sv-card="<?php echo (int) $sc['id']; ?>">
                            <span class="pel__date" aria-hidden="true"><?php if ((int) $sc['duration_min'] > 0): ?><span class="pel__m">Min</span><span class="pel__d"><?php echo (int) $sc['duration_min']; ?></span><?php else: ?><span class="pel__d pel__d--ic"><i class="fa-solid <?php echo $sc_icon; ?>"></i></span><?php endif; ?></span>
                            <span class="pel__body">
                                <span class="pel__title"><?php echo $sc_h($sc['name']); ?><?php if ($sc_flag): ?> <span class="pel__flag pel__flag--<?php echo $sc_flag[1]; ?>"><?php echo $sc_flag[0]; ?></span><?php endif; ?></span>
                                <span class="pel__meta">
                                    <span class="pel__where"><i class="fa-solid <?php echo $sc_icon; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($svm[$sc['delivery_method']] ?? 'Other', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if (trim((string) $sc['category']) !== ''): ?><span class="pel__sep" aria-hidden="true">·</span><span><?php echo $sc_h($sc['category']); ?></span><?php endif; ?>
                                    <?php if ($sc_left !== null && !$sc_flag): ?><span class="pel__sep" aria-hidden="true">·</span><span><?php echo $sc_left; ?> <?php echo $sc_left === 1 ? 'spot' : 'spots'; ?> left</span><?php endif; ?>
                                </span>
                                <?php if (trim((string) $sc['description']) !== ''): ?><span class="pel__desc"><?php echo $sc_h($sc['description']); ?></span><?php endif; ?>
                            </span>
                            <span class="pel__price"><span class="pel__amount"><?php echo (int) $sc['price_credits'] > 0 ? '$' . htmlspecialchars((string) $sc['price_dollars'], ENT_QUOTES, 'UTF-8') : 'Free'; ?></span><span class="pel__req">per booking</span></span>
                            <i class="fa-solid fa-chevron-right pel__go" aria-hidden="true"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($event_cards)): ?>
                <section class="pf-panel" data-panel="events">
                    <div class="pel-list">
                        <?php foreach ($event_cards as $ec) { $render_ev_card($ec); } ?>
                    </div>
                </section>
                <?php endif; ?>

                <section class="pf-panel" data-panel="about">
                    <div class="pf-info">
                        <?php if ($bio !== ''): ?><p class="pf-info__bio"><?php echo nl2br(htmlspecialchars($bio, ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                        <dl class="pf-facts">
                            <?php if ($location !== ''): ?><div class="pf-fact"><dt><i class="fa-solid fa-location-dot"></i></dt><dd><?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                            <?php if ($member_since !== ''): ?><div class="pf-fact"><dt><i class="fa-regular fa-calendar"></i></dt><dd>Creator since <?php echo htmlspecialchars($member_since, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                        </dl>
                        <?php if ($bio === '' && $location === '' && $member_since === ''): ?><p class="pf-info__empty">Nothing here yet.</p><?php endif; ?>
                    </div>
                </section>

                <?php if (!empty($links)): ?>
                <section class="pf-panel" data-panel="links">
                    <div class="pf-info">
                        <div class="pf-links">
                            <?php foreach ($links as $link): ?>
                            <a class="pf-link" href="/go/<?php echo (int) $link['id']; ?>" target="_blank" rel="noopener noreferrer nofollow">
                                <span class="pf-link__title"><?php echo htmlspecialchars($link['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <i class="fa-solid fa-arrow-up-right-from-square pf-link__icon"></i>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($viewer_logged_in)): /* signed-in viewers are already inside the app */ ?>
        <footer class="pf-foot">
            <a class="pf-foot__brand" href="<?php echo htmlspecialchars(SeoMeta::base() . '/', ENT_QUOTES, 'UTF-8'); ?>">
                <span class="pf-foot__mark"></span>
                <span>Powered by <?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        </footer>
        <?php endif; ?>
    </div>

    <div class="pf-lightbox" id="pf_lightbox" aria-hidden="true">
        <button type="button" class="pf-lightbox__close" id="pf_lightbox_close" aria-label="Close">&times;</button>
        <img class="pf-lightbox__img" id="pf_lightbox_img" src="" alt="">
    </div>

    <?php if (!$in_app): ?>
    <!-- Loaded here, not in <head>, so they don't hold up the first paint; still before the script that uses them. -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/api.data.js'); ?>"></script>
    <?php endif; ?>
    <script>
    (function () {
        var CREATOR_ID = <?php echo (int) $user['user_id']; ?>;
        var HANDLE     = '<?php echo htmlspecialchars((string) $user['u_name'], ENT_QUOTES, 'UTF-8'); ?>';
        var IS_SELF    = <?php echo $is_self ? 'true' : 'false'; ?>;
        var LOGGED_IN  = <?php echo $viewer_logged_in ? 'true' : 'false'; ?>;
        function money(credits) { return '$' + ((parseInt(credits, 10) || 0) / 10).toFixed(2); }   // $1 = 10 credits
        var PF_LOGIN   = <?php echo json_encode($login_href, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
        var VIEWER_CREDITS = <?php echo (int) $viewer_credit_balance; ?>;
        var following  = <?php echo $is_following ? 'true' : 'false'; ?>;
        var SUB_NOTICE = '<?php echo $sub_notice; ?>';
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

        function actionsHtml() {
            var followLabel = following ? 'Following' : 'Follow';
            var followCls   = 'pf-btn pf-btn--follow' + (following ? ' is-following' : '');
            return '<button class="' + followCls + '" data-follow>' +
                       '<i class="fa-solid ' + (following ? 'fa-check' : 'fa-plus') + '"></i> ' +
                       '<span data-follow-label>' + followLabel + '</span>' +
                   '</button>' +
                   '<button class="pf-btn pf-btn--subscribe" data-subscribe><i class="fa-solid fa-star"></i> Subscribe</button>' +
                   (LOGGED_IN && !IS_SELF
                       ? '<button type="button" class="pf-btn pf-btn--quiet" data-block-user="' + CREATOR_ID + '" data-block-name="@' + HANDLE + '" data-block-redirect="/" title="Block @' + HANDLE + '" aria-label="Block @' + HANDLE + '"><i class="fa-solid fa-ban"></i></button>'
                       : '');
        }

        function renderActions() {
            document.getElementById('pf_actions').innerHTML = actionsHtml();
            document.getElementById('pf_dock_actions').innerHTML = actionsHtml();
            document.querySelectorAll('[data-follow]').forEach(function (b) { b.onclick = toggleFollow; });
            document.querySelectorAll('[data-subscribe]').forEach(function (b) { b.onclick = goToPlans; });
        }

        function goToPlans() {
            selectTab('plans');
            document.querySelector('.pf-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function toggleFollow() {
            if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
            var action = following ? 'unfollow_creator' : 'follow_creator';
            ApiDataSvc.apiCall('post', action, { creator_id: CREATOR_ID }, function (resp) {
                var o = JSON.parse(resp);
                if (o.need_login) { window.location = PF_LOGIN; return; }
                if (!o.success) { return; }
                following = !!o.following;
                var n = o.follower_count;
                document.getElementById('pf_follower_count').textContent = n.toLocaleString();
                document.getElementById('pf_follower_word').textContent = (n === 1 ? 'follower' : 'followers');
                renderActions();
            });
        }

        function selectTab(panel) {
            document.querySelectorAll('.pf-tab').forEach(function (t) { t.classList.toggle('is-active', t.dataset.panel === panel); });
            document.querySelectorAll('.pf-panel').forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === panel); });
        }
        document.querySelectorAll('.pf-tab').forEach(function (t) { t.onclick = function () { selectTab(t.dataset.panel); }; });
        // Deep link to a tab (e.g. /@handle#events from a creator's "Copy Event Link").
        var hashTab = (location.hash || '').replace('#', '');
        if (hashTab && document.querySelector('.pf-tab[data-panel="' + hashTab.replace(/[^a-z]/g, '') + '"]')) { selectTab(hashTab.replace(/[^a-z]/g, '')); }

        // Plan subscribe (checkout flow lands next; interim: prompt login / notice).
        function pfToast(msg) {
            var t = document.createElement('div');
            t.className = 'pf-toast'; t.textContent = msg;
            document.body.appendChild(t);
            requestAnimationFrame(function () { t.classList.add('is-in'); });
            setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 2600);
        }
        document.querySelectorAll('[data-subscribe-plan]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                b.disabled = true;
                var promoEl = document.getElementById('pf_promo_code');
                ApiDataSvc.apiCall('post', 'subscribe_plan', { plan_id: b.getAttribute('data-subscribe-plan'), code: (promoEl ? promoEl.value.trim() : '') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (o.success && o.url) { window.location = o.url; return; }
                    b.disabled = false;
                    pfToast(o.message || 'Could not start checkout.');
                });
            };
        });

        document.querySelectorAll('[data-join-free]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                b.disabled = true;
                ApiDataSvc.apiCall('post', 'join_free_plan', { plan_id: b.getAttribute('data-join-free') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (!o.success) { b.disabled = false; pfToast(o.message); return; }
                    var member = document.createElement('div');
                    member.className = 'pf-plan__cta pf-plan__member';
                    member.innerHTML = '<i class="fa-solid fa-circle-check"></i> Joined';
                    b.replaceWith(member);
                    pfToast(o.message);
                });
            };
        });

        // Content bundle unlock.
        document.querySelectorAll('[data-bundle-login]').forEach(function (b) {
            b.onclick = function () { window.location = PF_LOGIN; };
        });
        document.querySelectorAll('[data-bundle-unlock]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                var orig = b.innerHTML; b.disabled = true; b.textContent = 'Unlocking…';
                ApiDataSvc.apiCall('post', 'bundle_unlock', { bundle_id: b.getAttribute('data-bundle-unlock') }, function (resp) {
                    var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                    if (!o) { b.disabled = false; b.innerHTML = orig; pfToast('Could not unlock'); return; }
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (o.need_credits) {
                        b.disabled = false; b.innerHTML = orig;
                        pfToast(o.message || 'Not enough funds in your wallet.');
                        setTimeout(function () { window.location = '/account/settings'; }, 1400);
                        return;
                    }
                    if (!o.success) { b.disabled = false; b.innerHTML = orig; pfToast(o.message || 'Could not unlock'); return; }
                    pfToast(o.message || 'Bundle unlocked!');
                    setTimeout(function () { window.location.reload(); }, 900);   // reveal the now-unlocked posts
                });
            };
        });

        // Event registration (free / paid-with-credits / subscribers / tier).
        document.querySelectorAll('[data-ev-register]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                var orig = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Registering…';
                ApiDataSvc.apiCall('post', 'event_register', { event_id: b.getAttribute('data-ev-register') }, function (resp) {
                    var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                    if (!o) { b.disabled = false; b.innerHTML = orig; pfToast('Could not register'); return; }
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (o.need_subscription) {
                        b.disabled = false; b.innerHTML = orig; pfToast(o.message || 'This event is for subscribers.');
                        if (document.querySelector('.pf-tabs')) { goToPlans(); } else { window.location = <?php echo json_encode($pf_home, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?> + '#plans'; }
                        return;
                    }
                    if (o.need_credits) {
                        b.disabled = false; b.innerHTML = orig;
                        pfToast(o.message || 'Not enough funds in your wallet.');
                        setTimeout(function () { window.location = '/account/settings'; }, 1400);
                        return;
                    }
                    if (!o.success) { b.disabled = false; b.innerHTML = orig; pfToast(o.message || 'Could not register'); return; }
                    pfToast('You\'re registered!');
                    if (b.closest('.pe')) { setTimeout(function () { window.location.reload(); }, 700); return; }   // the event page re-renders with the attendee details
                    revealEventAccess(b, o.access || {});
                });
            };
        });
        // Cancel my registration (event page). Before the start a paid ticket is refunded to the wallet.
        document.querySelectorAll('[data-ev-cancel]').forEach(function (b) {
            b.onclick = function () {
                var paid = parseInt(b.getAttribute('data-paid'), 10) || 0;
                var text = paid > 0 ? '$' + (paid / 10).toFixed(2) + ' goes back to your wallet and your spot is released.' : 'Your spot is released for someone else.';
                var go = function () {
                    b.disabled = true; b.textContent = 'Canceling…';
                    ApiDataSvc.apiCall('post', 'event_cancel', { event_id: b.getAttribute('data-ev-cancel') }, function (resp) {
                        var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                        if (!o || !o.success) { b.disabled = false; b.textContent = 'Cancel Registration'; pfToast((o && o.message) || 'Could not cancel'); return; }
                        pfToast(o.refunded > 0 ? 'Registration canceled. $' + (o.refunded / 10).toFixed(2) + ' refunded.' : 'Registration canceled.');
                        setTimeout(function () { window.location.reload(); }, 900);
                    });
                };
                if (!window.Swal) { if (window.confirm('Cancel your registration? ' + text)) { go(); } return; }
                Swal.fire({ title: 'Cancel your registration?', text: text, showCancelButton: true, reverseButtons: true, focusCancel: true,
                    confirmButtonText: 'Cancel Registration', cancelButtonText: 'Keep My Spot', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' })
                    .then(function (r) { if (r.isConfirmed) { go(); } });
            };
        });

        function revealEventAccess(btn, ax) {
            var card = btn.closest('.pf-ev');
            if (!card) { return; }
            var cta = btn.closest('.pf-ev__cta');
            if (cta) { cta.innerHTML = '<span class="pf-ev__status pf-ev__status--reg"><i class="fa-solid fa-circle-check"></i> Registered</span>'; }
            var main = card.querySelector('.pf-ev__main');
            if (!main || main.querySelector('.pf-ev__access')) { return; }
            function e(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
            var rows = '<span class="pf-ev__access-h"><i class="fa-solid fa-circle-check"></i> You&rsquo;re registered</span>';
            if (ax.instructions) { rows += '<span class="pf-ev__access-row pf-ev__access-instr">' + e(ax.instructions).replace(/\n/g, '<br>') + '</span>'; }
            var wrap = document.createElement('div'); wrap.className = 'pf-ev__access'; wrap.innerHTML = rows;
            main.appendChild(wrap);
        }

        // Service purchase (one-time, credits) → reveals booking details on success.
        document.querySelectorAll('[data-sv-purchase]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                var orig = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Booking…';
                ApiDataSvc.apiCall('post', 'service_purchase', { service_id: b.getAttribute('data-sv-purchase') }, function (resp) {
                    var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                    if (!o) { b.disabled = false; b.innerHTML = orig; pfToast('Could not book'); return; }
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (o.need_credits) {
                        b.disabled = false; b.innerHTML = orig;
                        pfToast(o.message || 'Not enough funds in your wallet.');
                        setTimeout(function () { window.location = '/account/settings'; }, 1400);
                        return;
                    }
                    if (!o.success) { b.disabled = false; b.innerHTML = orig; pfToast(o.message || 'Could not book'); return; }
                    pfToast('You\'re booked!');
                    setTimeout(function () { window.location.reload(); }, 700);   // the service page re-renders with the booking details
                });
            };
        });

        // Content locked-state CTAs.
        document.querySelectorAll('[data-content-login]').forEach(function (b) {
            b.onclick = function () { window.location = PF_LOGIN; };
        });
        document.querySelectorAll('[data-content-subscribe]').forEach(function (b) {
            b.onclick = goToPlans;
        });
        document.querySelectorAll('[data-unlock-content]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                b.disabled = true;
                ApiDataSvc.apiCall('post', 'unlock_content', { content_id: b.getAttribute('data-unlock-content') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (o.need_credits) { b.disabled = false; pfToast('Not enough funds in your wallet.'); return; }
                    if (!o.success) { b.disabled = false; pfToast(o.message || 'Could not unlock'); return; }
                    revealPost(b, o);
                    pfToast('Unlocked');
                });
            };
        });
        function revealPost(btn, o) {
            var article = btn.closest('.pf-post');
            if (!article) { return; }
            var title = (article.querySelector('.pf-post__title') || {}).textContent || '';
            article.innerHTML = '';
            (o.assets || []).forEach(function (a) {
                var el;
                if (a.type === 'image') { el = document.createElement('img'); el.className = 'pf-post__img'; el.src = a.url; }
                else if (a.type === 'video') { el = document.createElement('video'); el.className = 'pf-post__video'; el.src = a.url; el.controls = true; el.preload = 'metadata'; }
                else if (a.type === 'audio') { el = document.createElement('div'); el.className = 'pf-post__audiowrap'; var au = document.createElement('audio'); au.className = 'pf-post__audio'; au.src = a.url; au.controls = true; au.preload = 'none'; el.appendChild(au); }
                else { el = document.createElement('a'); el.className = 'pf-post__doc'; el.href = a.url; el.target = '_blank'; el.rel = 'noopener'; el.innerHTML = '<i class="fa-solid fa-file-pdf"></i> Open document'; }
                article.appendChild(el);
            });
            var wrap = document.createElement('div'); wrap.className = 'pf-post__body';
            if (title) { var h = document.createElement('h3'); h.className = 'pf-post__title'; h.textContent = title; wrap.appendChild(h); }
            if (o.description) { var d = document.createElement('p'); d.className = 'pf-post__desc'; d.textContent = o.description; wrap.appendChild(d); }
            if (o.body) { var p = document.createElement('p'); p.className = 'pf-post__text'; p.textContent = o.body; wrap.appendChild(p); }
            article.appendChild(wrap);
        }

        // Sticky dock reveals once the hero name scrolls out of view.
        var hero = document.querySelector('.pf-name');
        var dock = document.getElementById('pf_dock');
        var dockTop = parseFloat(getComputedStyle(dock).top) || 0;   // below the app top bar when signed in
        function onScroll() {
            var on = hero.getBoundingClientRect().bottom < dockTop + 8;
            dock.classList.toggle('is-visible', on);
            dock.inert = !on; dock.setAttribute('aria-hidden', on ? 'false' : 'true');   // hidden dock can't be tabbed into
        }
        window.addEventListener('scroll', onScroll, { passive: true });

        // Click any unlocked post image to view it larger.
        var lb = document.getElementById('pf_lightbox');
        var lbImg = document.getElementById('pf_lightbox_img');
        function closeLightbox() { lb.classList.remove('is-open'); lbImg.src = ''; document.body.style.overflow = ''; }
        document.addEventListener('click', function (e) {
            var img = e.target.closest && e.target.closest('.pf-post__img');
            if (img && img.src) { lbImg.src = img.src; lb.classList.add('is-open'); document.body.style.overflow = 'hidden'; }
        });
        lb.addEventListener('click', function (e) { if (e.target !== lbImg) { closeLightbox(); } });
        document.getElementById('pf_lightbox_close').addEventListener('click', closeLightbox);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeLightbox(); } });

        renderActions();
        onScroll();

        // Content grid → full-post lightbox
        (function () {
            var byId = {}; (window.PROFILE_POSTS || []).forEach(function (p) { byId[p.id] = p; });
            var lb = document.getElementById('pfLightbox'), inner = document.getElementById('pfLbInner');
            if (!lb) { return; }   // no posts on this profile, so no post viewer to wire up
            function e(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
            function openPost(id) {
                var p = byId[id]; if (!p) { return; }
                var h = '';
                if (p.entitled) {
                    var assets = p.assets || [];
                    h += '<div class="pf-plb__media' + (assets.length > 1 ? ' pf-plb__media--carousel' : '') + '">';
                    assets.forEach(function (a, i) {
                        var slide = (a.type === 'video')
                            ? '<video src="' + e(a.url) + '"' + (a.poster ? ' poster="' + e(a.poster) + '"' : '') + ' controls preload="metadata" controlsList="nodownload"></video>'
                            : '<img src="' + e(a.url) + '" alt="" oncontextmenu="return false">';
                        h += '<div class="pf-plb__slide' + (i === 0 ? ' is-on' : '') + '">' + slide + '</div>';
                    });
                    if (assets.length > 1) {
                        h += '<button type="button" class="pf-plb__nav pf-plb__nav--prev" data-plb="prev" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></button>' +
                             '<button type="button" class="pf-plb__nav pf-plb__nav--next" data-plb="next" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></button>' +
                             '<div class="pf-plb__dots">' + assets.map(function (a, i) { return '<span class="pf-plb__dot' + (i === 0 ? ' is-on' : '') + '"></span>'; }).join('') + '</div>';
                    }
                    h += '</div>';
                    h += '<div class="pf-plb__body">';
                    if (p.caption) { h += '<p>' + e(p.caption).replace(/\n/g, '<br>') + '</p>'; }
                    if (p.published_at) { h += '<span class="pf-plb__date">' + e(p.published_at) + '</span>'; }
                    h += '<div class="pf-plb__engage" id="pfLbEngage"></div>';
                    h += '<div class="pf-plb__comments" id="pfLbComments"></div>';
                    h += '</div>';
                } else if (p.audience === 'ppv') {
                    h += '<div class="pf-plb__locked"' + (p.locked_url ? ' style="background-image:url(\'' + e(p.locked_url) + '\')"' : '') + '><div class="pf-plb__lockmeta"><i class="fa-solid fa-lock"></i><span>Pay-per-view post</span>' +
                        '<div class="pf-plb__ppv" id="pfLbPpvAction"></div>' +
                        (LOGGED_IN ? '<div class="pf-plb__promo"><input type="text" id="pfLbPromo" class="pf-plb__promo-input" placeholder="Discount code" maxlength="40" autocomplete="off"><button type="button" class="pf-plb__promo-apply" id="pfLbPromoApply">Apply</button></div><div class="pf-plb__promo-msg" id="pfLbPromoMsg"></div>' : '') +
                        '</div></div>';
                    if (p.caption) { h += '<div class="pf-plb__body"><p>' + e(p.caption).replace(/\n/g, '<br>') + '</p></div>'; }
                } else {
                    h += '<div class="pf-plb__locked"' + (p.locked_url ? ' style="background-image:url(\'' + e(p.locked_url) + '\')"' : '') + '><div class="pf-plb__lockmeta"><i class="fa-solid fa-lock"></i><span>Subscribers-only post</span>' +
                        '<button type="button" class="pf-btn pf-btn--follow" id="pfLbAct">' + (LOGGED_IN ? 'Subscribe to unlock' : 'Log in to view') + '</button></div></div>';
                    if (p.caption) { h += '<div class="pf-plb__body"><p>' + e(p.caption).replace(/\n/g, '<br>') + '</p></div>'; }
                }
                inner.innerHTML = h;
                lb.hidden = false; document.body.style.overflow = 'hidden';
                var act = document.getElementById('pfLbAct');
                if (act) { act.onclick = function () { if (!LOGGED_IN) { window.location = PF_LOGIN; } else { closePlb(); goToPlans(); } }; }
                if (p.audience === 'ppv') { renderPpvAction(p); }
                var promoApply = document.getElementById('pfLbPromoApply');
                if (promoApply) { promoApply.onclick = function () { applyPpvPromo(p); }; }
                plbAutoplay();
                plbPost = p;
                if (p.entitled) { renderEngage(p); recordView(p); loadComments(p); }
            }
            // Show the viewer's balance and either an Unlock button (can afford) or an
            // Add-credits button (short) — recomputed whenever the effective price changes.
            function renderPpvAction(p) {
                var wrap = document.getElementById('pfLbPpvAction');
                if (!wrap) { return; }
                if (!LOGGED_IN) {
                    wrap.innerHTML = '<button type="button" class="pf-btn pf-btn--follow" id="pfLbPpv">Log in to Unlock</button>';
                    document.getElementById('pfLbPpv').onclick = function () { window.location = PF_LOGIN; };
                    return;
                }
                // Fans see dollars: the wallet holds credits ($1 = 10), shown as money.
                var price   = (typeof p.effective_price === 'number') ? p.effective_price : p.ppv_price_credits;
                var bal     = '<div class="pf-plb__bal">Your balance: ' + money(VIEWER_CREDITS) + '</div>';
                if (VIEWER_CREDITS >= price) {
                    wrap.innerHTML = bal + '<button type="button" class="pf-btn pf-btn--follow" id="pfLbPpv">Unlock for ' + money(price) + '</button>';
                    var b = document.getElementById('pfLbPpv');
                    b.onclick = function () { unlockPpv(p, b); };
                } else {
                    wrap.innerHTML = bal +
                        '<div class="pf-plb__short">You need ' + money(price - VIEWER_CREDITS) + ' more to unlock this.</div>' +
                        '<button type="button" class="pf-btn pf-btn--follow" id="pfLbAddCredits">Add Funds</button>';
                    document.getElementById('pfLbAddCredits').onclick = function () { window.location = '/account/settings?section=wallet'; };
                }
            }
            function applyPpvPromo(p) {
                var input = document.getElementById('pfLbPromo');
                var msg   = document.getElementById('pfLbPromoMsg');
                var code  = input ? input.value.trim().toUpperCase() : '';
                if (code === '') { return; }
                ApiDataSvc.apiCall('post', 'promo_preview', { post_id: p.id, code: code }, function (resp) {
                    var o = null; try { o = JSON.parse(resp); } catch (err) { o = null; }
                    if (!o || !o.success) {
                        p.applied_code = null; p.effective_price = p.ppv_price_credits;
                        if (msg) { msg.textContent = (o && o.message) ? o.message : "That code isn't valid."; msg.className = 'pf-plb__promo-msg is-err'; }
                        renderPpvAction(p);
                        return;
                    }
                    p.applied_code = code; p.effective_price = o.new_price;
                    if (msg) { msg.textContent = o.percent_off + '% off applied'; msg.className = 'pf-plb__promo-msg is-ok'; }
                    renderPpvAction(p);   // may flip Add-credits → Unlock if the discount brings it within budget
                });
            }
            function unlockPpv(p, btn) {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                var orig = btn.textContent; btn.disabled = true; btn.textContent = 'Unlocking…';
                ApiDataSvc.apiCall('post', 'ppv_unlock', { post_id: p.id, code: (p.applied_code || '') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (o.need_credits) {
                        btn.disabled = false; btn.textContent = orig;
                        pfToast(o.message || 'Not enough funds in your wallet.');
                        setTimeout(function () { window.location = '/account/settings'; }, 1400);
                        return;
                    }
                    if (!o.success) { btn.disabled = false; btn.textContent = orig; pfToast(o.message || 'Could not unlock'); return; }
                    p.entitled = true; p.assets = o.assets || []; p.unlocked = true; byId[p.id] = p;
                    var card = document.querySelector('.pf-pc[data-post-id="' + p.id + '"]');
                    if (card) { card.classList.remove('pf-pc--locked'); }
                    pfToast('Unlocked!');
                    openPost(p.id);
                });
            }
            function closePlb() { lb.hidden = true; inner.innerHTML = ''; document.body.style.overflow = ''; clearInterval(plbTimer); }
            document.querySelectorAll('.pf-pc').forEach(function (c) { c.addEventListener('click', function () { openPost(parseInt(c.getAttribute('data-post-id'), 10)); }); });
            document.getElementById('pfLbClose').addEventListener('click', closePlb);
            lb.addEventListener('click', function (ev) { if (ev.target === lb) { closePlb(); } });
            document.addEventListener('keydown', function (ev) {
                if (lb.hidden) { return; }
                if (ev.key === 'Escape') { closePlb(); }
                else if (ev.key === 'ArrowLeft') { plbGo(-1); plbAutoplay(); }
                else if (ev.key === 'ArrowRight') { plbGo(1); plbAutoplay(); }
            });
            inner.addEventListener('click', function (ev) {
                var like = ev.target.closest('[data-plb-like]');
                if (like) { doLike(); return; }
                var del = ev.target.closest('[data-plb-cdel]');
                if (del) { doDeleteComment(del.getAttribute('data-plb-cdel')); return; }
                var nav = ev.target.closest('.pf-plb__nav');
                if (nav) { plbGo(nav.getAttribute('data-plb') === 'next' ? 1 : -1); plbAutoplay(); }
            });
            function plbGo(dir) {
                var slides = inner.querySelectorAll('.pf-plb__slide'); var n = slides.length; if (n < 2) { return; }
                var cur = 0; slides.forEach(function (s, i) { if (s.classList.contains('is-on')) { cur = i; } });
                var next = (cur + dir + n) % n;
                slides.forEach(function (s, i) { var on = i === next; s.classList.toggle('is-on', on); if (!on) { var v = s.querySelector('video'); if (v) { v.pause(); } } });
                var dots = inner.querySelectorAll('.pf-plb__dot'); dots.forEach(function (d, i) { d.classList.toggle('is-on', i === next); });
            }
            var plbTimer = null;
            function plbAutoplay() {
                clearInterval(plbTimer);
                if (inner.querySelectorAll('.pf-plb__slide').length > 1) {
                    plbTimer = setInterval(function () {
                        if (lb.hidden) { clearInterval(plbTimer); return; }
                        var v = inner.querySelector('.pf-plb__slide.is-on video');
                        if (v && !v.paused) { return; } // don't interrupt a playing video
                        plbGo(1);
                    }, 4500);
                }
            }

            // ---- engagement: likes, views, comments ----
            var plbPost = null;
            function renderEngage(p) {
                var bar = document.getElementById('pfLbEngage'); if (!bar) { return; }
                bar.innerHTML =
                    '<button type="button" class="pf-plb__like' + (p.liked ? ' is-liked' : '') + '" data-plb-like>' +
                        '<i class="fa-' + (p.liked ? 'solid' : 'regular') + ' fa-heart"></i> <span>' + (p.likes || 0) + '</span></button>' +
                    '<span class="pf-plb__estat"><i class="fa-regular fa-comment"></i> ' + (p.comments || 0) + '</span>' +
                    '<span class="pf-plb__estat pf-plb__estat--views"><i class="fa-regular fa-eye"></i> ' + (p.views || 0) + '</span>';
            }
            function doLike() {
                if (!LOGGED_IN) { window.location = PF_LOGIN; return; }
                if (!plbPost) { return; }
                ApiDataSvc.apiCall('post', 'post_like', { id: plbPost.id }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (!o.success) { pfToast(o.message || 'Could not like'); return; }
                    plbPost.liked = o.liked; plbPost.likes = o.likes; renderEngage(plbPost);
                });
            }
            function recordView(p) {
                ApiDataSvc.apiCall('post', 'post_view', { id: p.id }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o && o.success && typeof o.views === 'number') {
                        p.views = o.views;
                        var v = document.querySelector('#pfLbEngage .pf-plb__estat--views');
                        if (v) { v.innerHTML = '<i class="fa-regular fa-eye"></i> ' + o.views; }
                    }
                });
            }
            function loadComments(p) {
                var box = document.getElementById('pfLbComments'); if (!box) { return; }
                box.innerHTML = '<div class="pf-plb__cload">Loading comments…</div>';
                ApiDataSvc.apiCall('post', 'post_comments', { id: p.id }, function (resp) {
                    var o = JSON.parse(resp);
                    if (!o || !o.success) { box.innerHTML = ''; return; }
                    renderComments(o);
                });
            }
            function renderComments(o) {
                var box = document.getElementById('pfLbComments'); if (!box) { return; }
                var html = '<div class="pf-plb__clist">';
                if (!o.comments.length) { html += '<p class="pf-plb__cempty">No comments yet' + (o.can_comment ? ', be the first.' : '.') + '</p>'; }
                o.comments.forEach(function (c) {
                    html += '<div class="pf-plb__c">' +
                        '<span class="pf-plb__cav">' + e(c.initial) + '</span>' +
                        '<div class="pf-plb__cmain"><div class="pf-plb__chead"><span class="pf-plb__cname">' + e(c.name) + '</span><span class="pf-plb__cwhen">' + e(c.when) + '</span>' +
                        (c.can_delete ? '<button type="button" class="pf-plb__cdel" data-plb-cdel="' + c.id + '" aria-label="Delete comment"><i class="fa-solid fa-xmark"></i></button>' : '') +
                        '</div><p class="pf-plb__cbody">' + e(c.body).replace(/\n/g, '<br>') + '</p></div></div>';
                });
                html += '</div>';
                if (o.can_comment) {
                    html += '<form class="pf-plb__cform" id="pfLbCform"><input type="text" class="pf-plb__cinput" id="pfLbCinput" placeholder="Add a comment…" maxlength="2000" autocomplete="off"><button type="submit" class="pf-btn pf-btn--follow pf-plb__csend">Post</button></form>';
                } else if (!LOGGED_IN && o.comments_enabled) {
                    html += '<p class="pf-plb__cnote"><a href="' + PF_LOGIN + '">Log in</a> to comment.</p>';
                } else if (!o.comments_enabled) {
                    html += '<p class="pf-plb__cnote">Comments are turned off for this post.</p>';
                }
                box.innerHTML = html;
                var form = document.getElementById('pfLbCform');
                if (form) { form.onsubmit = function (ev) { ev.preventDefault(); doComment(); }; }
            }
            function doComment() {
                var input = document.getElementById('pfLbCinput'); if (!input || !plbPost) { return; }
                var body = input.value.trim(); if (body === '' || input.dataset.busy === '1') { return; }   // a double click posts once
                input.dataset.busy = '1'; var release = setTimeout(function () { input.dataset.busy = ''; }, 15000);
                ApiDataSvc.apiCall('post', 'post_comment_add', { id: plbPost.id, body: body }, function (resp) {
                    input.dataset.busy = ''; clearTimeout(release);
                    var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                    if (!o) { pfToast('Could not post'); return; }
                    if (o.need_login) { window.location = PF_LOGIN; return; }
                    if (!o.success) { pfToast(o.message || 'Could not post'); return; }
                    input.value = ''; plbPost.comments = o.count; renderEngage(plbPost); loadComments(plbPost);
                });
            }
            function doDeleteComment(id) {
                ApiDataSvc.apiCall('post', 'post_comment_delete', { comment_id: id }, function (resp) {
                    var o = JSON.parse(resp);
                    if (!o.success) { pfToast(o.message || 'Could not delete'); return; }
                    if (plbPost) { plbPost.comments = o.count; renderEngage(plbPost); loadComments(plbPost); }
                });
            }
        })();

        // Content search — filter the grid by caption.
        var pfSearch = document.getElementById('pfSearch');
        if (pfSearch) {
            pfSearch.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase();
                var shown = 0;
                document.querySelectorAll('.pf-feed .pf-pc').forEach(function (c) {
                    var hit = (q === '') || (c.getAttribute('data-search') || '').indexOf(q) >= 0;
                    c.hidden = !hit;
                    if (hit) { shown++; }
                });
                var none = document.getElementById('pfNoResults');
                if (none) { none.hidden = (q === '' || shown > 0); }
            });
        }

        // Returned from Stripe Checkout — show the outcome, land on Membership, tidy the URL.
        if (SUB_NOTICE === 'success') {
            selectTab('plans');
            pfToast("You're now a member!");
        } else if (SUB_NOTICE === 'cancel') {
            pfToast('Checkout canceled — you have not been charged.');
        }
        if (SUB_NOTICE && window.history.replaceState) {
            window.history.replaceState({}, '', window.location.pathname);
        }
    })();
    </script>
<?php if ($in_app): ?>
</div>
<?php $this->view->site_footer(); else: ?>
</body>
</html>
<?php endif; ?>
