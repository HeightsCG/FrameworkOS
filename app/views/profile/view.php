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
$in_app     = !empty($viewer_logged_in);
?>
<?php if ($in_app): $this->view->site_header(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/css/profile.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/profile.css'); ?>">
<div class="pf pf--app">
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
        $seo_url    = $seo_base . '/@' . rawurlencode($handle);
        $seo_image  = $has_avatar ? (string) $profile['avatar_url'] : SeoMeta::default_image();
        $seo_desc   = $bio !== '' ? mb_substr($bio, 0, 160) : $display_name . ' (@' . $handle . ') on ' . $site_name . ': content, memberships, services, events, and links.';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/css/profile.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/profile.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/api.data.js'); ?>"></script>
</head>
<body class="pf">
<?php endif; ?>

    <!-- Signature: identity + primary action dock in on scroll -->
    <div class="pf-dock" id="pf_dock" aria-hidden="true">
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
                        <button type="button" class="pf-pc<?php echo $c['entitled'] ? '' : ' pf-pc--locked'; ?>" data-post-id="<?php echo (int) $c['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower((string) $c['caption']), ENT_QUOTES, 'UTF-8'); ?>">
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
                                    <span class="pf-bundle__price">$<?php echo (int) $bd['price_dollars']; ?></span>
                                </div>
                                <span class="pf-bundle__count"><i class="fa-solid fa-layer-group"></i> <?php echo (int) $bd['item_count']; ?> item<?php echo (int) $bd['item_count'] === 1 ? '' : 's'; ?></span>
                                <?php if (trim((string) $bd['description']) !== ''): ?><p class="pf-bundle__desc"><?php echo htmlspecialchars((string) $bd['description'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                            </div>
                            <?php if (!empty($bd['owned'])): ?>
                            <div class="pf-plan__cta pf-plan__member"><i class="fa-solid fa-circle-check"></i> Owned</div>
                            <?php elseif (!$viewer_logged_in): ?>
                            <button class="pf-btn pf-btn--subscribe pf-plan__cta" data-bundle-login><i class="fa-solid fa-lock"></i> Log in to Unlock</button>
                            <?php elseif ($viewer_credit_balance >= (int) $bd['price_credits']): ?>
                            <button class="pf-btn pf-btn--subscribe pf-plan__cta" data-bundle-unlock="<?php echo (int) $bd['id']; ?>"><i class="fa-solid fa-unlock"></i> Unlock &mdash; <?php echo (int) $bd['price_credits']; ?> credits &middot; $<?php echo (int) $bd['price_dollars']; ?></button>
                            <?php else: ?>
                            <a class="pf-btn pf-btn--subscribe pf-plan__cta" href="/account/settings"><i class="fa-solid fa-plus"></i> Add Credits to Unlock</a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </section>

                <?php if (!empty($service_cards)): ?>
                <?php $svm = array('zoom' => 'Zoom', 'teams' => 'Microsoft Teams', 'meet' => 'Google Meet', 'webex' => 'Webex', 'discord' => 'Discord', 'phone' => 'Phone', 'in_person' => 'In person', 'custom' => 'Custom'); ?>
                <section class="pf-panel" data-panel="services">
                    <div class="pf-events">
                        <?php foreach ($service_cards as $sc): ?>
                        <article class="pf-ev" data-sv-card="<?php echo (int) $sc['id']; ?>">
                            <div class="pf-ev__main">
                                <?php if (trim((string) $sc['category']) !== ''): ?><div class="pf-ev__when"><i class="fa-solid fa-briefcase"></i> <?php echo htmlspecialchars((string) $sc['category'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                <h3 class="pf-ev__title"><?php echo htmlspecialchars((string) $sc['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <?php if (trim((string) $sc['description']) !== ''): ?><p class="pf-ev__desc"><?php echo nl2br(htmlspecialchars((string) $sc['description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                                <div class="pf-ev__meta">
                                    <span class="pf-ev__tag pf-ev__tag--<?php echo ((int) $sc['price_credits'] > 0) ? 'paid' : ''; ?>"><?php echo ((int) $sc['price_credits'] > 0) ? '$' . htmlspecialchars((string) $sc['price_dollars'], ENT_QUOTES, 'UTF-8') : 'Free'; ?></span>
                                    <?php if ((int) $sc['duration_min'] > 0): ?><span class="pf-ev__where"><i class="fa-regular fa-clock"></i> <?php echo (int) $sc['duration_min']; ?> min</span><?php endif; ?>
                                    <span class="pf-ev__where"><i class="fa-solid fa-video"></i> <?php echo htmlspecialchars($svm[$sc['delivery_method']] ?? 'Custom', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ((int) $sc['capacity'] > 0): ?><span class="pf-ev__seats"><i class="fa-solid fa-user-group"></i> <?php echo max(0, (int) $sc['capacity'] - (int) $sc['purchases']); ?> spots left</span><?php endif; ?>
                                </div>
                                <?php if (trim((string) $sc['refund_policy']) !== ''): ?><p class="pf-ev__refund"><i class="fa-solid fa-rotate-left"></i> <?php echo htmlspecialchars((string) $sc['refund_policy'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                <?php if (!empty($sc['access'])): $ax = $sc['access']; ?>
                                <div class="pf-ev__access">
                                    <span class="pf-ev__access-h"><i class="fa-solid fa-circle-check"></i> Your booking details</span>
                                    <?php if (trim((string) $ax['scheduling_url']) !== ''): ?><span class="pf-ev__access-row"><i class="fa-regular fa-calendar-check"></i> <a href="<?php echo htmlspecialchars((string) $ax['scheduling_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer nofollow">Schedule your session</a></span><?php endif; ?>
                                    <?php if (trim((string) $ax['details']) !== ''): ?><span class="pf-ev__access-row pf-ev__access-instr"><?php echo nl2br(htmlspecialchars((string) $ax['details'], ENT_QUOTES, 'UTF-8')); ?></span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="pf-ev__cta">
                                <?php if ($sc['is_self']): ?>
                                <span class="pf-ev__status pf-ev__status--own"><i class="fa-solid fa-user-pen"></i> Your service</span>
                                <?php elseif (!empty($sc['purchased'])): ?>
                                <span class="pf-ev__status pf-ev__status--reg"><i class="fa-solid fa-circle-check"></i> Booked</span>
                                <?php elseif (!empty($sc['is_full'])): ?>
                                <span class="pf-ev__status pf-ev__status--full">Fully booked</span>
                                <?php else: ?>
                                <button type="button" class="pf-btn pf-btn--subscribe pf-ev__register" data-sv-purchase="<?php echo (int) $sc['id']; ?>">
                                    <i class="fa-solid fa-calendar-check"></i>
                                    <?php echo ((int) $sc['price_credits'] > 0) ? 'Book · $' . htmlspecialchars((string) $sc['price_dollars'], ENT_QUOTES, 'UTF-8') : 'Book'; ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($event_cards)): ?>
                <section class="pf-panel" data-panel="events">
                    <div class="pf-events">
                        <?php foreach ($event_cards as $ec):
                            $al = array('free' => 'Free', 'paid' => 'Paid', 'subscribers' => 'Subscribers only', 'tier' => 'Members only');
                        ?>
                        <article class="pf-ev" data-ev-card="<?php echo (int) $ec['id']; ?>">
                            <div class="pf-ev__main">
                                <div class="pf-ev__when"><i class="fa-regular fa-calendar"></i> <?php echo htmlspecialchars((string) $ec['when'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <h3 class="pf-ev__title"><?php echo htmlspecialchars((string) $ec['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <?php if (trim((string) $ec['description']) !== ''): ?><p class="pf-ev__desc"><?php echo nl2br(htmlspecialchars((string) $ec['description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                                <div class="pf-ev__meta">
                                    <span class="pf-ev__tag pf-ev__tag--<?php echo htmlspecialchars((string) $ec['access_type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($al[$ec['access_type']] ?? 'Free', ENT_QUOTES, 'UTF-8'); echo ($ec['access_type'] === 'paid' && (int) $ec['price_credits'] > 0) ? ' · $' . htmlspecialchars((string) $ec['price_dollars'], ENT_QUOTES, 'UTF-8') : ''; ?></span>
                                    <?php if ($ec['is_online']): ?><span class="pf-ev__where"><i class="fa-solid fa-video"></i> Online</span><?php elseif ($ec['is_inperson']): ?><span class="pf-ev__where"><i class="fa-solid fa-location-dot"></i> In person</span><?php endif; ?>
                                    <?php if ((int) $ec['capacity'] > 0): ?><span class="pf-ev__seats"><i class="fa-solid fa-user-group"></i> <?php echo max(0, (int) $ec['capacity'] - (int) $ec['attendees']); ?> seats left</span><?php endif; ?>
                                </div>
                                <?php if (!empty($ec['access'])): $ax = $ec['access']; ?>
                                <div class="pf-ev__access">
                                    <span class="pf-ev__access-h"><i class="fa-solid fa-circle-check"></i> Your access details</span>
                                    <?php if (trim((string) $ax['location']) !== ''): ?><span class="pf-ev__access-row"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars((string) $ax['location'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    <?php if (trim((string) $ax['url']) !== ''): ?><span class="pf-ev__access-row"><i class="fa-solid fa-link"></i> <a href="<?php echo htmlspecialchars((string) $ax['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo htmlspecialchars((string) $ax['url'], ENT_QUOTES, 'UTF-8'); ?></a></span><?php endif; ?>
                                    <?php if (trim((string) $ax['instructions']) !== ''): ?><span class="pf-ev__access-row pf-ev__access-instr"><?php echo nl2br(htmlspecialchars((string) $ax['instructions'], ENT_QUOTES, 'UTF-8')); ?></span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="pf-ev__cta">
                                <?php if ($ec['is_self']): ?>
                                <span class="pf-ev__status pf-ev__status--own"><i class="fa-solid fa-user-pen"></i> Your event</span>
                                <?php elseif (!empty($ec['registered'])): ?>
                                <span class="pf-ev__status pf-ev__status--reg"><i class="fa-solid fa-circle-check"></i> Registered</span>
                                <?php elseif (!empty($ec['is_full'])): ?>
                                <span class="pf-ev__status pf-ev__status--full">Sold out</span>
                                <?php else: ?>
                                <button type="button" class="pf-btn pf-btn--subscribe pf-ev__register" data-ev-register="<?php echo (int) $ec['id']; ?>">
                                    <i class="fa-solid fa-calendar-check"></i>
                                    <?php echo ($ec['access_type'] === 'paid' && (int) $ec['price_credits'] > 0) ? 'Register · $' . htmlspecialchars((string) $ec['price_dollars'], ENT_QUOTES, 'UTF-8') : 'Register'; ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
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
            </div>
        </div>

        <footer class="pf-foot">
            <a class="pf-foot__brand" href="<?php echo htmlspecialchars(Main::site_protocol() . $public_domain, ENT_QUOTES, 'UTF-8'); ?>">
                <span class="pf-foot__mark"></span>
                <span>Powered by <?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        </footer>
    </div>

    <div class="pf-lightbox" id="pf_lightbox" aria-hidden="true">
        <button type="button" class="pf-lightbox__close" id="pf_lightbox_close" aria-label="Close">&times;</button>
        <img class="pf-lightbox__img" id="pf_lightbox_img" src="" alt="">
    </div>

    <script>
    (function () {
        var CREATOR_ID = <?php echo (int) $user['user_id']; ?>;
        var HANDLE     = '<?php echo htmlspecialchars((string) $user['u_name'], ENT_QUOTES, 'UTF-8'); ?>';
        var IS_SELF    = <?php echo $is_self ? 'true' : 'false'; ?>;
        var LOGGED_IN  = <?php echo $viewer_logged_in ? 'true' : 'false'; ?>;
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
            if (!LOGGED_IN) { window.location = '/'; return; }
            var action = following ? 'unfollow_creator' : 'follow_creator';
            ApiDataSvc.apiCall('post', action, { creator_id: CREATOR_ID }, function (resp) {
                var o = JSON.parse(resp);
                if (o.need_login) { window.location = '/'; return; }
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
                if (!LOGGED_IN) { window.location = '/'; return; }
                b.disabled = true;
                var promoEl = document.getElementById('pf_promo_code');
                ApiDataSvc.apiCall('post', 'subscribe_plan', { plan_id: b.getAttribute('data-subscribe-plan'), code: (promoEl ? promoEl.value.trim() : '') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = '/'; return; }
                    if (o.success && o.url) { window.location = o.url; return; }
                    b.disabled = false;
                    pfToast(o.message || 'Could not start checkout.');
                });
            };
        });

        document.querySelectorAll('[data-join-free]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = '/'; return; }
                b.disabled = true;
                ApiDataSvc.apiCall('post', 'join_free_plan', { plan_id: b.getAttribute('data-join-free') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = '/'; return; }
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
            b.onclick = function () { window.location = '/'; };
        });
        document.querySelectorAll('[data-bundle-unlock]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = '/'; return; }
                var orig = b.innerHTML; b.disabled = true; b.textContent = 'Unlocking…';
                ApiDataSvc.apiCall('post', 'bundle_unlock', { bundle_id: b.getAttribute('data-bundle-unlock') }, function (resp) {
                    var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                    if (!o) { b.disabled = false; b.innerHTML = orig; pfToast('Could not unlock'); return; }
                    if (o.need_login) { window.location = '/'; return; }
                    if (o.need_credits) {
                        b.disabled = false; b.innerHTML = orig;
                        pfToast(o.message || 'Not enough credits.');
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
                if (!LOGGED_IN) { window.location = '/'; return; }
                var orig = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Registering…';
                ApiDataSvc.apiCall('post', 'event_register', { event_id: b.getAttribute('data-ev-register') }, function (resp) {
                    var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                    if (!o) { b.disabled = false; b.innerHTML = orig; pfToast('Could not register'); return; }
                    if (o.need_login) { window.location = '/'; return; }
                    if (o.need_subscription) { b.disabled = false; b.innerHTML = orig; pfToast(o.message || 'This event is for subscribers.'); goToPlans(); return; }
                    if (o.need_credits) {
                        b.disabled = false; b.innerHTML = orig;
                        pfToast(o.message || 'Not enough credits.');
                        setTimeout(function () { window.location = '/account/settings'; }, 1400);
                        return;
                    }
                    if (!o.success) { b.disabled = false; b.innerHTML = orig; pfToast(o.message || 'Could not register'); return; }
                    revealEventAccess(b, o.access || {});
                    pfToast('You\'re registered!');
                });
            };
        });
        function revealEventAccess(btn, ax) {
            var card = btn.closest('.pf-ev');
            if (!card) { return; }
            var cta = btn.closest('.pf-ev__cta');
            if (cta) { cta.innerHTML = '<span class="pf-ev__status pf-ev__status--reg"><i class="fa-solid fa-circle-check"></i> Registered</span>'; }
            var main = card.querySelector('.pf-ev__main');
            if (!main || main.querySelector('.pf-ev__access')) { return; }
            function e(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
            var rows = '<span class="pf-ev__access-h"><i class="fa-solid fa-circle-check"></i> Your access details</span>';
            if (ax.location) { rows += '<span class="pf-ev__access-row"><i class="fa-solid fa-location-dot"></i> ' + e(ax.location) + '</span>'; }
            if (ax.url) { rows += '<span class="pf-ev__access-row"><i class="fa-solid fa-link"></i> <a href="' + e(ax.url) + '" target="_blank" rel="noopener noreferrer nofollow">' + e(ax.url) + '</a></span>'; }
            if (ax.instructions) { rows += '<span class="pf-ev__access-row pf-ev__access-instr">' + e(ax.instructions).replace(/\n/g, '<br>') + '</span>'; }
            var wrap = document.createElement('div'); wrap.className = 'pf-ev__access'; wrap.innerHTML = rows;
            main.appendChild(wrap);
        }

        // Service purchase (one-time, credits) → reveals booking details on success.
        document.querySelectorAll('[data-sv-purchase]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = '/'; return; }
                var orig = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Booking…';
                ApiDataSvc.apiCall('post', 'service_purchase', { service_id: b.getAttribute('data-sv-purchase') }, function (resp) {
                    var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
                    if (!o) { b.disabled = false; b.innerHTML = orig; pfToast('Could not book'); return; }
                    if (o.need_login) { window.location = '/'; return; }
                    if (o.need_credits) {
                        b.disabled = false; b.innerHTML = orig;
                        pfToast(o.message || 'Not enough credits.');
                        setTimeout(function () { window.location = '/account/settings'; }, 1400);
                        return;
                    }
                    if (!o.success) { b.disabled = false; b.innerHTML = orig; pfToast(o.message || 'Could not book'); return; }
                    revealServiceAccess(b, o.access || {});
                    pfToast('Booked! Schedule your session next.');
                });
            };
        });
        function revealServiceAccess(btn, ax) {
            var card = btn.closest('.pf-ev');
            if (!card) { return; }
            var cta = btn.closest('.pf-ev__cta');
            if (cta) { cta.innerHTML = '<span class="pf-ev__status pf-ev__status--reg"><i class="fa-solid fa-circle-check"></i> Booked</span>'; }
            var main = card.querySelector('.pf-ev__main');
            if (!main || main.querySelector('.pf-ev__access')) { return; }
            function e(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
            var rows = '<span class="pf-ev__access-h"><i class="fa-solid fa-circle-check"></i> Your booking details</span>';
            if (ax.scheduling_url) { rows += '<span class="pf-ev__access-row"><i class="fa-regular fa-calendar-check"></i> <a href="' + e(ax.scheduling_url) + '" target="_blank" rel="noopener noreferrer nofollow">Schedule your session</a></span>'; }
            if (ax.details) { rows += '<span class="pf-ev__access-row pf-ev__access-instr">' + e(ax.details).replace(/\n/g, '<br>') + '</span>'; }
            var wrap = document.createElement('div'); wrap.className = 'pf-ev__access'; wrap.innerHTML = rows;
            main.appendChild(wrap);
        }

        // Content locked-state CTAs.
        document.querySelectorAll('[data-content-login]').forEach(function (b) {
            b.onclick = function () { window.location = '/'; };
        });
        document.querySelectorAll('[data-content-subscribe]').forEach(function (b) {
            b.onclick = goToPlans;
        });
        document.querySelectorAll('[data-unlock-content]').forEach(function (b) {
            b.onclick = function () {
                if (!LOGGED_IN) { window.location = '/'; return; }
                b.disabled = true;
                ApiDataSvc.apiCall('post', 'unlock_content', { content_id: b.getAttribute('data-unlock-content') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = '/'; return; }
                    if (o.need_credits) { b.disabled = false; pfToast('Not enough credits'); return; }
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
        function onScroll() { dock.classList.toggle('is-visible', hero.getBoundingClientRect().bottom < dockTop + 8); }
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
            function e(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
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
                if (act) { act.onclick = function () { if (!LOGGED_IN) { window.location = '/'; } else { closePlb(); goToPlans(); } }; }
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
                    document.getElementById('pfLbPpv').onclick = function () { window.location = '/'; };
                    return;
                }
                var price   = (typeof p.effective_price === 'number') ? p.effective_price : p.ppv_price_credits;
                var dollars = Math.round(price / 10);
                var bal     = '<div class="pf-plb__bal">Your balance: ' + VIEWER_CREDITS + ' credit' + (VIEWER_CREDITS === 1 ? '' : 's') + '</div>';
                if (VIEWER_CREDITS >= price) {
                    wrap.innerHTML = bal + '<button type="button" class="pf-btn pf-btn--follow" id="pfLbPpv">Unlock — ' + price + ' credits · $' + dollars + '</button>';
                    var b = document.getElementById('pfLbPpv');
                    b.onclick = function () { unlockPpv(p, b); };
                } else {
                    var need = price - VIEWER_CREDITS;
                    wrap.innerHTML = bal +
                        '<div class="pf-plb__short">You need ' + need + ' more credit' + (need === 1 ? '' : 's') + ' to unlock this.</div>' +
                        '<button type="button" class="pf-btn pf-btn--follow" id="pfLbAddCredits">Add Credits</button>';
                    document.getElementById('pfLbAddCredits').onclick = function () { window.location = '/account/settings'; };
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
                if (!LOGGED_IN) { window.location = '/'; return; }
                var orig = btn.textContent; btn.disabled = true; btn.textContent = 'Unlocking…';
                ApiDataSvc.apiCall('post', 'ppv_unlock', { post_id: p.id, code: (p.applied_code || '') }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = '/'; return; }
                    if (o.need_credits) {
                        btn.disabled = false; btn.textContent = orig;
                        pfToast(o.message || 'Not enough credits — add some to your wallet.');
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
                if (!LOGGED_IN) { window.location = '/'; return; }
                if (!plbPost) { return; }
                ApiDataSvc.apiCall('post', 'post_like', { id: plbPost.id }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = '/'; return; }
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
                    html += '<p class="pf-plb__cnote"><a href="/">Log in</a> to comment.</p>';
                } else if (!o.comments_enabled) {
                    html += '<p class="pf-plb__cnote">Comments are turned off for this post.</p>';
                }
                box.innerHTML = html;
                var form = document.getElementById('pfLbCform');
                if (form) { form.onsubmit = function (ev) { ev.preventDefault(); doComment(); }; }
            }
            function doComment() {
                var input = document.getElementById('pfLbCinput'); if (!input || !plbPost) { return; }
                var body = input.value.trim(); if (body === '') { return; }
                ApiDataSvc.apiCall('post', 'post_comment_add', { id: plbPost.id, body: body }, function (resp) {
                    var o = JSON.parse(resp);
                    if (o.need_login) { window.location = '/'; return; }
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
