<?php
/**
 * Public creator storefront (self-contained page). Locals from
 * ProfileController::viewAction(): $user, $profile, $links, $display_name,
 * $handle, $public_domain, $is_self, $viewer_logged_in, $is_following,
 * $follower_count, $member_since.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo CSRF::meta(); ?>
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if ($bio !== ''): ?><meta name="description" content="<?php echo htmlspecialchars(mb_substr($bio, 0, 160), ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <meta property="og:title" content="<?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($bio !== ''): ?><meta property="og:description" content="<?php echo htmlspecialchars(mb_substr($bio, 0, 160), ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <?php if ($has_avatar): ?><meta property="og:image" content="<?php echo htmlspecialchars($profile['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/css/profile.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/profile.css'); ?>">
</head>
<body class="pf">

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
                    <h1 class="pf-name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></h1>
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
                    <?php if (empty($plans)): ?>
                    <div class="pf-empty">
                        <i class="fa-regular fa-star pf-empty__icon"></i>
                        <p class="pf-empty__title">No membership plans yet</p>
                        <p class="pf-empty__text"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?> hasn't set up membership tiers. Check back soon.</p>
                    </div>
                    <?php else: ?>
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
                            <button class="pf-btn pf-btn--follow pf-plan__cta" data-join-free="<?php echo (int) $plan['id']; ?>"><i class="fa-solid fa-plus"></i> Join for free</button>
                            <?php else: ?>
                            <button class="pf-btn pf-btn--subscribe pf-plan__cta" data-subscribe-plan="<?php echo (int) $plan['id']; ?>"><i class="fa-solid fa-star"></i> Subscribe</button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>

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
                            <a class="pf-link" href="<?php echo htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer nofollow">
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
            <a class="pf-foot__brand" href="<?php echo htmlspecialchars(Main::site_protocol() . '://' . $public_domain, ENT_QUOTES, 'UTF-8'); ?>">
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
        var IS_SELF    = <?php echo $is_self ? 'true' : 'false'; ?>;
        var LOGGED_IN  = <?php echo $viewer_logged_in ? 'true' : 'false'; ?>;
        var following  = <?php echo $is_following ? 'true' : 'false'; ?>;
        var SUB_NOTICE = '<?php echo $sub_notice; ?>';
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

        function pfPost(action, params) {
            var body = new URLSearchParams();
            Object.keys(params || {}).forEach(function (k) { body.set(k, params[k]); });
            body.set('csrf_token', csrf);
            return fetch('/api/' + action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); });
        }

        // Self-view has no follow/subscribe actions.
        function actionsHtml() {
            if (IS_SELF) { return ''; }
            var followLabel = following ? 'Following' : 'Follow';
            var followCls   = 'pf-btn pf-btn--follow' + (following ? ' is-following' : '');
            return '<button class="' + followCls + '" data-follow>' +
                       '<i class="fa-solid ' + (following ? 'fa-check' : 'fa-plus') + '"></i> ' +
                       '<span data-follow-label>' + followLabel + '</span>' +
                   '</button>' +
                   '<button class="pf-btn pf-btn--subscribe" data-subscribe><i class="fa-solid fa-star"></i> Subscribe</button>';
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
            var body = new URLSearchParams();
            body.set('creator_id', CREATOR_ID);
            body.set('csrf_token', csrf);
            fetch('/api/' + action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (o) {
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
                var body = new URLSearchParams();
                body.set('plan_id', b.getAttribute('data-subscribe-plan'));
                body.set('csrf_token', csrf);
                b.disabled = true;
                fetch('/api/subscribe_plan', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (o) {
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
                var body = new URLSearchParams();
                body.set('plan_id', b.getAttribute('data-join-free'));
                body.set('csrf_token', csrf);
                b.disabled = true;
                fetch('/api/join_free_plan', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (o) {
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
                var body = new URLSearchParams();
                body.set('content_id', b.getAttribute('data-unlock-content'));
                body.set('csrf_token', csrf);
                b.disabled = true;
                fetch('/api/unlock_content', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (o) {
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
        function onScroll() { dock.classList.toggle('is-visible', hero.getBoundingClientRect().bottom < 8); }
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
                        '<button type="button" class="pf-btn pf-btn--follow" id="pfLbPpv">' + (LOGGED_IN ? ('Unlock — ' + p.ppv_price_credits + ' credits · $' + p.ppv_price_dollars) : 'Log in to unlock') + '</button></div></div>';
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
                var ppvBtn = document.getElementById('pfLbPpv');
                if (ppvBtn) { ppvBtn.onclick = function () { unlockPpv(p, ppvBtn); }; }
                plbAutoplay();
                plbPost = p;
                if (p.entitled) { renderEngage(p); recordView(p); loadComments(p); }
            }
            function unlockPpv(p, btn) {
                if (!LOGGED_IN) { window.location = '/'; return; }
                var orig = btn.textContent; btn.disabled = true; btn.textContent = 'Unlocking…';
                var body = new URLSearchParams();
                body.set('post_id', p.id); body.set('csrf_token', csrf);
                fetch('/api/ppv_unlock', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (o) {
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
                }).catch(function () { btn.disabled = false; btn.textContent = orig; pfToast('Could not unlock'); });
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
                pfPost('post_like', { id: plbPost.id }).then(function (o) {
                    if (o.need_login) { window.location = '/'; return; }
                    if (!o.success) { pfToast(o.message || 'Could not like'); return; }
                    plbPost.liked = o.liked; plbPost.likes = o.likes; renderEngage(plbPost);
                });
            }
            function recordView(p) {
                pfPost('post_view', { id: p.id }).then(function (o) {
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
                fetch('/api/post_comments?id=' + p.id).then(function (r) { return r.json(); }).then(function (o) {
                    if (!o || !o.success) { box.innerHTML = ''; return; }
                    renderComments(o);
                });
            }
            function renderComments(o) {
                var box = document.getElementById('pfLbComments'); if (!box) { return; }
                var html = '<div class="pf-plb__clist">';
                if (!o.comments.length) { html += '<p class="pf-plb__cempty">No comments yet' + (o.can_comment ? ' — be the first.' : '.') + '</p>'; }
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
                pfPost('post_comment_add', { id: plbPost.id, body: body }).then(function (o) {
                    if (o.need_login) { window.location = '/'; return; }
                    if (!o.success) { pfToast(o.message || 'Could not post'); return; }
                    input.value = ''; plbPost.comments = o.count; renderEngage(plbPost); loadComments(plbPost);
                });
            }
            function doDeleteComment(id) {
                pfPost('post_comment_delete', { comment_id: id }).then(function (o) {
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
</body>
</html>
