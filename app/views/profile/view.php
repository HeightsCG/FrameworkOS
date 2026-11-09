<?php
Analytics::$creator_id = (int) ($user['user_id'] ?? 0);   // this page's traffic belongs to the creator (analytics_events.creator_id)
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
// webp copies (PublicThumbService::profile_image) when they exist, else the uploaded file.
$avatar_src = $has_avatar ? (trim((string) ($profile['avatar_webp_url'] ?? '')) !== '' ? (string) $profile['avatar_webp_url'] : (string) $profile['avatar_url']) : '';
$cover_webp = $has_cover ? trim((string) ($profile['cover_webp_url'] ?? '')) : '';
// Profiles saved before 2026-09-29 were stored HTML-encoded ("won&#039;t"): decode, then escape once below.
$bio        = trim(html_entity_decode((string) ($profile['bio'] ?? ''), ENT_QUOTES, 'UTF-8'));
$location   = trim(html_entity_decode((string) ($profile['location'] ?? ''), ENT_QUOTES, 'UTF-8'));
$site_name  = Main::site_name();
$page_title = $display_name . ' (@' . $handle . ') · ' . $site_name;
$initial    = strtoupper(mb_substr($display_name, 0, 1));
$followers  = number_format((int) $follower_count);
$follow_word = ((int) $follower_count === 1) ? 'follower' : 'followers';
$in_app     = !empty($viewer_logged_in);
$pf_base    = '/@' . rawurlencode((string) $handle);
$pf_home    = $pf_base;
$login_href = '/';
// Logged-out visitors get the site's nav and footer around the page. "Create Your Page Free" signs up with ?ref=.
$site_chrome = !$in_app && empty($viewer_logged_in);
$here_path   = Main::safe_path($_SERVER['REQUEST_URI'] ?? '/');
$join_href   = rtrim(Main::get_base_domain(), '/') . '/?auth=register&ref=' . rawurlencode((string) $user['u_name']);
$pub_links   = array(
    'base'     => '',
    'login'    => '/?auth=login&next=' . rawurlencode($here_path),
    'register' => '/?auth=register&ref=' . rawurlencode((string) $user['u_name']) . '&next=' . rawurlencode($here_path),
    'cta'      => 'outline',   // the creator's Follow is this page's one primary button
);
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
<?php /* icons on this logged-out page come from /css/pf-icons.css (no Font Awesome): map any new fa-* icon there. */ ?>
<?php include Main::app_path() . '/libs/Layout/heycatch.php'; ?>
<?php include Main::app_path() . '/libs/Layout/google_analytics.php'; ?>
    <script>if (window.ttq) { ttq.track('ViewContent', { content_id: '<?php echo (int) ($user['user_id'] ?? 0); ?>', content_type: 'product', content_name: 'Creator' }); }   // TikTok: a creator's page was viewed</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/logo/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/logo/apple-touch-icon.png">
    <?php echo CSRF::meta(); ?>
    <?php
        $seo_base   = Main::get_base_domain();
        $seo_url    = Main::get_base_domain() . '/@' . rawurlencode((string) $user['u_name']);
        $seo_image  = $has_avatar ? (string) $profile['avatar_url'] : SeoMeta::default_image();
        $seo_about  = $display_name . ' (@' . $handle . ') on ' . $site_name . ': content and links.';
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
        'robots' => !empty($user['is_demo']) ? 'noindex, nofollow' : (!empty($seo_thin) ? 'noindex, follow' : ''),
    )); ?>
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/pf-icons.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/pf-icons.css'); ?>">
<?php if ($site_chrome): ?>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/landing.css'); ?>">
    <link rel="stylesheet" href="/css/sx.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/sx.css'); ?>">
<?php endif; ?>
    <link rel="stylesheet" href="/css/profile.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/profile.css'); ?>">
</head>
<body class="pf">
<?php if ($site_chrome) { include Main::app_path() . '/libs/Layout/public_nav.php'; } /* signed-out: the site nav (Sign In / Get Started come back here) */ ?>
<?php endif; ?>

    <!-- Signature: identity + primary action dock in on scroll -->
    <div class="pf-dock" id="pf_dock" aria-hidden="true" inert>
        <div class="pf-dock__inner">
            <div class="pf-dock__id">
                <span class="pf-dock__avatar"<?php echo $has_avatar ? ' style="background-image:url(\'' . htmlspecialchars($avatar_src, ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>><?php echo $has_avatar ? '' : htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?><span class="pf-presence pf-presence--sm <?php echo $is_online ? 'is-online' : 'is-offline'; ?>"></span></span>
                <span class="pf-dock__name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="pf-dock__actions" id="pf_dock_actions"></div>
        </div>
    </div>

    <div class="pf-cover<?php echo $has_cover ? '' : ' pf-cover--empty'; ?>"><?php if ($cover_webp !== ''): ?><picture><source type="image/webp" srcset="<?php echo htmlspecialchars(PublicThumbService::cover_800($cover_webp), ENT_QUOTES, 'UTF-8'); ?> 800w, <?php echo htmlspecialchars($cover_webp, ENT_QUOTES, 'UTF-8'); ?> 1600w" sizes="100vw"><img class="pf-cover__img" src="<?php echo htmlspecialchars($profile['cover_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="" width="1600" height="400" fetchpriority="high"></picture><?php elseif ($has_cover): ?><img class="pf-cover__img" src="<?php echo htmlspecialchars($profile['cover_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="" width="1600" height="400" fetchpriority="high"><?php endif; ?></div>

    <div class="pf-container">
        <header class="pf-hero">
            <div class="pf-hero__top">
                <div class="pf-avatar"<?php echo $has_avatar ? ' style="background-image:url(\'' . htmlspecialchars($avatar_src, ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>>
                    <?php if (!$has_avatar): ?><span class="pf-avatar__initial"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    <span class="pf-presence <?php echo $is_online ? 'is-online' : 'is-offline'; ?>" title="<?php echo $is_online ? 'Online now' : 'Offline'; ?>"></span>
                </div>
                <div class="pf-hero__id">
                    <h1 class="pf-name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($user['verified'])): ?> <i class="fa-solid fa-circle-check pf-verified" title="Verified creator"></i><?php endif; ?><?php if (!empty($ai_creator)): ?> <span class="pf-ai" title="This account posts AI-generated content">AI</span><?php endif; ?></h1>
                    <div class="pf-meta">
                        <span class="pf-meta__handle">@<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="pf-meta__dot">·</span>
                        <span class="pf-status <?php echo $is_online ? 'pf-status--online' : 'pf-status--offline'; ?>"><span class="pf-status__dot"></span><?php echo $is_online ? 'Online' : 'Offline'; ?></span>
                        <?php if ($location !== ''): ?><span class="pf-meta__dot">·</span><span class="pf-meta__loc"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($ai_creator)): ?><p class="pf-ai-line"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> <?php echo htmlspecialchars(AiDisclosure::PROFILE_LINE, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                    <div class="pf-stats"><span class="pf-stat"><strong id="pf_follower_count"><?php echo $followers; ?></strong> <span id="pf_follower_word"><?php echo $follow_word; ?></span></span></div>
                </div>
                <div class="pf-actions" id="pf_actions"></div>
            </div>

        </header>
        <?php include __DIR__ . '/_featured.php'; ?>

        <div class="pf-grid">
            <div class="pf-main">
                <nav class="pf-tabs" role="tablist">
                    <button class="pf-tab is-active" data-panel="content" role="tab">Content</button>
                    <?php /* Every tab is always there (2026-10-13): one with nothing in it says so, it is never left out. */ ?>
                    <button class="pf-tab" data-panel="about" role="tab">About</button>
                    <button class="pf-tab" data-panel="links" role="tab">Links</button>
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
                            <input type="search" id="pfSearch" class="pf-search__input" placeholder="Search posts…" autocomplete="off" aria-label="Search posts" data-handle="<?php echo htmlspecialchars((string) $user['u_name'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <div class="pf-feed">
                        <?php foreach ($content_cards as $c) { require __DIR__ . '/_post_card.php'; } ?>
                    </div>
                    <?php if (!empty($posts_page['has_more'])): ?>
                    <div class="pf-more" id="pfMore">
                        <button type="button" class="pf-btn pf-more__btn" id="pfMoreBtn" data-handle="<?php echo htmlspecialchars((string) $user['u_name'], ENT_QUOTES, 'UTF-8'); ?>" data-offset="<?php echo (int) $posts_page['next_offset']; ?>">Load More</button>
                    </div>
                    <?php endif; ?>
                    <div class="pf-feed" id="pfResults" hidden></div>
                    <p class="pf-feed__none" id="pfNoResults" hidden>No posts match your search.</p>

                    <!-- full-post lightbox -->
                    <div class="pf-plb" id="pfLightbox" hidden>
                        <button type="button" class="pf-plb__close" id="pfLbClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                        <div class="pf-plb__inner" id="pfLbInner"></div>
                    </div>
                    <script>window.PROFILE_POSTS = <?php echo json_encode($content_cards, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;</script>
                    <?php endif; ?>
                </section>

                <section class="pf-panel" data-panel="about">
                    <div class="pf-info<?php echo ($bio !== '' && ($location !== '' || $member_since !== '')) ? ' pf-info--about' : ''; ?>">
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
                <?php else: ?>
                <section class="pf-panel" data-panel="links">
                    <div class="pf-empty">
                        <i class="fa-solid fa-link pf-empty__icon"></i>
                        <p class="pf-empty__title">No links yet</p>
                        <p class="pf-empty__text"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?> has not added any links.</p>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </div>

        <?php include __DIR__ . '/_similar.php'; ?>

        <?php if (empty($viewer_logged_in)): /* signed-in viewers are already inside the app */ ?>
        <footer class="pf-foot">
            <a class="pf-foot__brand" href="<?php echo htmlspecialchars($join_href . '&role=creator', ENT_QUOTES, 'UTF-8'); ?>" rel="nofollow">
                <span class="pf-foot__mark"></span>
                <span>Create Your Page Free</span>
            </a>
        </footer>
        <?php endif; ?>
    </div>
<?php if ($site_chrome) { include Main::app_path() . '/libs/Layout/public_footer.php'; } ?>

    <div class="pf-lightbox" id="pf_lightbox" aria-hidden="true">
        <button type="button" class="pf-lightbox__close" id="pf_lightbox_close" aria-label="Close">&times;</button>
        <img class="pf-lightbox__img" id="pf_lightbox_img" src="" alt="">
    </div>

    <?php if (!$in_app): ?>
    <!-- Deferred (they run in this order once the page is parsed): the script below only calls ApiDataSvc from click handlers. -->
    <script defer src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script defer src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/api.data.js'); ?>"></script>
    <script defer src="/js/csrf-retry.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/csrf-retry.js'); ?>"></script>
    <?php endif; ?>
    <script>
    (function () {
        // jquery and api.data.js load deferred: a click before they arrive does nothing now and is sent once, on load.
        var ApiDataSvc = { apiCall: function () {
            var args = arguments;
            if (!window.ApiDataSvc) { window.addEventListener('load', function () { if (window.ApiDataSvc) { window.ApiDataSvc.apiCall.apply(window.ApiDataSvc, args); } }, { once: true }); return; }
            return window.ApiDataSvc.apiCall.apply(window.ApiDataSvc, args);
        } };
        var CREATOR_ID = <?php echo (int) $user['user_id']; ?>;
        var HANDLE     = '<?php echo htmlspecialchars((string) $user['u_name'], ENT_QUOTES, 'UTF-8'); ?>';
        var IS_SELF    = <?php echo $is_self ? 'true' : 'false'; ?>;
        var LOGGED_IN  = <?php echo $viewer_logged_in ? 'true' : 'false'; ?>;
        var PF_LOGIN   = <?php echo json_encode($login_href, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
        // site nav phone menu (landing.js is not on this page)
        var nav_toggle = document.getElementById('ld_nav_toggle'), nav_links = document.getElementById('ld_nav_links');
        if (nav_toggle && nav_links) {
            nav_toggle.addEventListener('click', function () {
                var is_open = nav_links.classList.toggle('is-open');
                nav_toggle.setAttribute('aria-expanded', is_open ? 'true' : 'false');
            });
        }
        var following  = <?php echo $is_following ? 'true' : 'false'; ?>;
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

        function actionsHtml() {
            var followLabel = following ? 'Following' : 'Follow';
            var followCls   = 'pf-btn pf-btn--follow' + (following ? ' is-following' : '');
            return '<button class="' + followCls + '" data-follow>' +
                       '<i class="fa-solid ' + (following ? 'fa-check' : 'fa-plus') + '"></i> ' +
                       '<span data-follow-label>' + followLabel + '</span>' +
                   '</button>' +
                   (LOGGED_IN && !IS_SELF
                       ? '<button type="button" class="pf-btn pf-btn--quiet" data-block-user="' + CREATOR_ID + '" data-block-name="@' + HANDLE + '" data-block-redirect="/" title="Block @' + HANDLE + '" aria-label="Block @' + HANDLE + '"><i class="fa-solid fa-ban"></i></button>'
                       : '');
        }

        function renderActions() {
            document.getElementById('pf_actions').innerHTML = actionsHtml();
            document.getElementById('pf_dock_actions').innerHTML = actionsHtml();
            document.querySelectorAll('[data-follow]').forEach(function (b) { b.onclick = toggleFollow; });
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
        // Deep link to a tab (e.g. /@handle#links).
        var hashTab = (location.hash || '').replace('#', '');
        if (hashTab && document.querySelector('.pf-tab[data-panel="' + hashTab.replace(/[^a-z]/g, '') + '"]')) { selectTab(hashTab.replace(/[^a-z]/g, '')); }

        function pfToast(msg) {
            var t = document.createElement('div');
            t.className = 'pf-toast'; t.textContent = msg;
            document.body.appendChild(t);
            requestAnimationFrame(function () { t.classList.add('is-in'); });
            setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 2600);
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

        // Click any post image to view it larger.
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
                inner.innerHTML = h;
                lb.hidden = false; document.body.style.overflow = 'hidden';
                plbAutoplay();
                plbPost = p;
                renderEngage(p); recordView(p); loadComments(p);
            }
            function closePlb() { lb.hidden = true; inner.innerHTML = ''; document.body.style.overflow = ''; clearInterval(plbTimer); }
            function bindCards() {
                document.querySelectorAll('.pf-pc:not([data-bound])').forEach(function (c) {
                    c.setAttribute('data-bound', '1');
                    c.addEventListener('click', function () { openPost(parseInt(c.getAttribute('data-post-id'), 10)); });
                });
            }
            bindCards();
            window.pfBindCards = bindCards;                          // the search results grid binds its cards the same way
            window.pfAddPost = function (p) { byId[p.id] = p; };     // and registers them for the viewer
            // "Load More": the next page of cards, rendered and gated by the server (ProfilePosts), appended to the grid.
            var moreBtn = document.getElementById('pfMoreBtn');
            if (moreBtn) {
                moreBtn.addEventListener('click', function () {
                    if (!window.ApiDataSvc || moreBtn.disabled) { return; }
                    var label = moreBtn.textContent;
                    moreBtn.disabled = true; moreBtn.textContent = 'Loading…';
                    ApiDataSvc.apiCall('post', 'profile_posts', { handle: moreBtn.getAttribute('data-handle'), offset: moreBtn.getAttribute('data-offset') }, function (resp) {
                        var o = null; try { o = JSON.parse(resp); } catch (x) { o = null; }
                        if (!o || !o.success) { moreBtn.disabled = false; moreBtn.textContent = label; pfToast((o && o.message) || 'Could not load more posts. Please try again.'); return; }
                        var feed = document.querySelector('.pf-feed');
                        if (feed && o.html) { feed.insertAdjacentHTML('beforeend', o.html); }
                        (o.items || []).forEach(function (p) { byId[p.id] = p; });
                        bindCards();
                        if (o.has_more) { moreBtn.setAttribute('data-offset', o.next_offset); moreBtn.disabled = false; moreBtn.textContent = label; }
                        else { var wrap = document.getElementById('pfMore'); if (wrap) { wrap.parentNode.removeChild(wrap); } }
                    });
                });
            }
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

        // Content search: the server looks through every post's caption (not just the cards loaded so far); the results
        // show in their own grid, and clearing the box brings the page back as it was.
        var pfSearch = document.getElementById('pfSearch');
        if (pfSearch) {
            var pfFeed = document.querySelector('.pf-feed'), pfRes = document.getElementById('pfResults'), pfNone = document.getElementById('pfNoResults');
            var pfT = null, pfReq = 0;
            var pfShowMain = function (on) { if (pfFeed) { pfFeed.hidden = !on; } var mw = document.getElementById('pfMore'); if (mw) { mw.hidden = !on; } };
            pfSearch.addEventListener('input', function () {
                var q = this.value.trim(), handle = pfSearch.getAttribute('data-handle');
                clearTimeout(pfT);
                if (q === '') { pfReq++; if (pfRes) { pfRes.hidden = true; pfRes.innerHTML = ''; } if (pfNone) { pfNone.hidden = true; } pfShowMain(true); return; }
                pfT = setTimeout(function () {
                    if (!window.ApiDataSvc || !pfRes) { return; }
                    var req = ++pfReq;
                    ApiDataSvc.apiCall('post', 'profile_posts', { handle: handle, q: q }, function (resp) {
                        if (req !== pfReq) { return; }
                        var o = null; try { o = JSON.parse(resp); } catch (x) { o = null; }
                        if (!o || !o.success) { pfToast((o && o.message) || 'Could not search posts. Please try again.'); return; }
                        (o.items || []).forEach(function (p) { if (window.pfAddPost) { window.pfAddPost(p); } });
                        pfRes.innerHTML = o.html || '';
                        pfShowMain(false);
                        pfRes.hidden = !(o.items || []).length;
                        if (pfNone) { pfNone.hidden = (o.items || []).length > 0; }
                        if (window.pfBindCards) { window.pfBindCards(); }
                    });
                }, 300);
            });
        }

    })();
    </script>
<?php if ($in_app): ?>
</div>
<?php $this->view->site_footer(); else: ?>
</body>
</html>
<?php endif; ?>
