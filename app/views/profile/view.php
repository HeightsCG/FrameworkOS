<?php
/**
 * Public creator storefront (self-contained page). Locals from
 * ProfileController::viewAction(): $user, $profile, $links, $display_name,
 * $tags, $handle, $public_domain, $is_self, $viewer_logged_in, $is_following,
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
    <link rel="stylesheet" href="/css/profile.css">
</head>
<body class="pf">

    <!-- Signature: identity + primary action dock in on scroll -->
    <div class="pf-dock" id="pf_dock" aria-hidden="true">
        <div class="pf-dock__inner">
            <div class="pf-dock__id">
                <span class="pf-dock__avatar"<?php echo $has_avatar ? ' style="background-image:url(\'' . htmlspecialchars($profile['avatar_url'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>><?php echo $has_avatar ? '' : htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></span>
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
                </div>
                <div class="pf-hero__id">
                    <h1 class="pf-name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="pf-meta">
                        <span class="pf-meta__handle">@<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($location !== ''): ?><span class="pf-meta__dot">·</span><span class="pf-meta__loc"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    </div>
                    <div class="pf-stats"><span class="pf-stat"><strong id="pf_follower_count"><?php echo $followers; ?></strong> <span id="pf_follower_word"><?php echo $follow_word; ?></span></span></div>
                </div>
                <div class="pf-actions" id="pf_actions"></div>
            </div>

            <?php if ($bio !== ''): ?><p class="pf-bio"><?php echo nl2br(htmlspecialchars($bio, ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
            <?php if (!empty($tags)): ?>
            <div class="pf-tags">
                <?php foreach ($tags as $tag): ?><span class="pf-tag"><?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>
        </header>

        <div class="pf-grid">
            <div class="pf-main">
                <nav class="pf-tabs" role="tablist">
                    <button class="pf-tab is-active" data-panel="content" role="tab">Content</button>
                    <button class="pf-tab" data-panel="plans" role="tab">Membership</button>
                </nav>

                <section class="pf-panel is-active" data-panel="content">
                    <?php if (empty($content_cards)): ?>
                    <div class="pf-empty">
                        <i class="fa-regular fa-images pf-empty__icon"></i>
                        <p class="pf-empty__title">No posts yet</p>
                        <p class="pf-empty__text">When <?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?> shares something, it'll show up here. Follow to get notified.</p>
                    </div>
                    <?php else: ?>
                    <div class="pf-posts">
                        <?php foreach ($content_cards as $c): ?>
                        <article class="pf-post" data-content-id="<?php echo (int) $c['id']; ?>">
                            <?php if ($c['entitled']): ?>
                            <?php foreach ($c['assets'] as $a): $au = htmlspecialchars($a['url'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($a['type'] === 'image'): ?>
                                <img class="pf-post__img" src="<?php echo $au; ?>" alt="" loading="lazy">
                                <?php elseif ($a['type'] === 'video'): ?>
                                <video class="pf-post__video" src="<?php echo $au; ?>" controls preload="metadata"></video>
                                <?php elseif ($a['type'] === 'audio'): ?>
                                <div class="pf-post__audiowrap"><audio class="pf-post__audio" src="<?php echo $au; ?>" controls preload="none"></audio></div>
                                <?php else: ?>
                                <a class="pf-post__doc" href="<?php echo $au; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> Open document</a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div class="pf-post__body">
                                <h3 class="pf-post__title"><?php echo htmlspecialchars((string) $c['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <?php if (trim((string) $c['description']) !== ''): ?><p class="pf-post__desc"><?php echo htmlspecialchars((string) $c['description'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                <?php if (trim((string) $c['body']) !== ''): ?><p class="pf-post__text"><?php echo nl2br(htmlspecialchars((string) $c['body'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                                <?php if (!empty($c['tags'])): ?><div class="pf-post__tags"><?php foreach ($c['tags'] as $t): ?><span class="pf-post__tag"><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></span><?php endforeach; ?></div><?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="pf-post__locked"<?php echo $c['preview_url'] !== '' ? ' style="background-image:url(\'' . htmlspecialchars($c['preview_url'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>>
                                <div class="pf-post__lockmeta">
                                    <i class="fa-solid fa-lock pf-post__lockicon"></i>
                                    <span class="pf-post__title pf-post__title--onlock"><?php echo htmlspecialchars((string) $c['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if (trim((string) $c['description']) !== ''): ?><span class="pf-post__desc pf-post__desc--onlock"><?php echo htmlspecialchars((string) $c['description'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    <?php if (!$viewer_logged_in): ?>
                                    <button type="button" class="pf-btn pf-btn--follow pf-post__cta" data-content-login>Log in to view</button>
                                    <?php elseif ($c['access'] === 'subscribers'): ?>
                                    <button type="button" class="pf-btn pf-btn--follow pf-post__cta" data-content-subscribe>Subscribe to <?php echo htmlspecialchars((string) $c['required_name'], ENT_QUOTES, 'UTF-8'); ?></button>
                                    <?php else: ?>
                                    <button type="button" class="pf-btn pf-btn--subscribe pf-post__cta" data-unlock-content="<?php echo (int) $c['id']; ?>"><i class="fa-solid fa-lock-open"></i> Unlock for <?php echo number_format((int) $c['price_credits']); ?> credits</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </article>
                        <?php endforeach; ?>
                    </div>
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
            </div>

            <aside class="pf-aside">
                <div class="pf-card">
                    <h2 class="pf-card__title">About</h2>
                    <?php if ($bio !== ''): ?><p class="pf-card__bio"><?php echo nl2br(htmlspecialchars($bio, ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                    <dl class="pf-facts">
                        <?php if ($location !== ''): ?><div class="pf-fact"><dt><i class="fa-solid fa-location-dot"></i></dt><dd><?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                        <?php if ($member_since !== ''): ?><div class="pf-fact"><dt><i class="fa-regular fa-calendar"></i></dt><dd>Creator since <?php echo htmlspecialchars($member_since, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                    </dl>
                </div>

                <?php if (!empty($links)): ?>
                <div class="pf-card">
                    <h2 class="pf-card__title">Links</h2>
                    <div class="pf-links">
                        <?php foreach ($links as $link): ?>
                        <a class="pf-link" href="<?php echo htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer nofollow">
                            <span class="pf-link__title"><?php echo htmlspecialchars($link['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <i class="fa-solid fa-arrow-up-right-from-square pf-link__icon"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </aside>
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
            if (o.tags && o.tags.length) { var tw = document.createElement('div'); tw.className = 'pf-post__tags'; o.tags.forEach(function (t) { var s = document.createElement('span'); s.className = 'pf-post__tag'; s.textContent = t; tw.appendChild(s); }); wrap.appendChild(tw); }
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
