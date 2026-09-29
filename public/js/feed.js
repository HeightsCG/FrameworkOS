/* =====================================================================
   Home discovery feed. The view ships as an empty shell; every card is
   fetched from /api/feed and rendered here. Cards are NOT links — clicking
   one (or its like/comment buttons) opens the full-post lightbox, which runs
   like / comment / view / unlock against the API. Mirrors the profile page's
   post lightbox so the interaction feels identical across the app.

   All requests go through the shared data API, ApiDataSvc.apiCall('post', …);
   values travel in POST and are read from $this->post server-side. The response
   is a string, parsed with JSON.parse.
   ===================================================================== */
(function () {
    'use strict';

    var LOGGED_IN = true;          // filled from the feed response
    var VIEWER_CREDITS = 0;        // viewer's credit balance, from the feed response
    var feed_offset = 0;
    var loading_feed = false;
    var has_more = false;
    var newest_id = 0;             // id of the top-most card — watermark for "new posts"
    var poll_timer = null;
    var card_by_id = {};           // id -> card data (author, cover, counts…)

    var POLL_MS = 45000;           // how often we ask the server for newer content

    var $grid     = $('#feed_grid');
    var $loading  = $('#feed_loading');
    var $error    = $('#feed_error');
    var $empty    = $('#feed_empty');
    var $inf_load = $('#feed_inf_load');
    var $end      = $('#feed_end');
    var $pill     = $('#feed_new_pill');
    var $pill_txt = $('#feed_new_pill_text');

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function fmt(n) { n = +n || 0; return n >= 1000 ? (Math.round(n / 100) / 10) + 'k' : String(n); }
    function money(credits) { return '$' + ((parseInt(credits, 10) || 0) / 10).toFixed(2); }   // fans see dollars ($1 = 10 credits)
    function toast(msg) { if (window.toastr) { toastr.info(msg); } }

    // ------------------------------------------------------------------ feed
    function load_feed(append) {
        if (loading_feed) { return; }
        loading_feed = true;
        $error.prop('hidden', true);
        if (!append) {
            $loading.prop('hidden', false); $grid.prop('hidden', true).empty();
            $empty.prop('hidden', true); $end.prop('hidden', true); feed_offset = 0; has_more = false;
        } else { $inf_load.prop('hidden', false); }

        ApiDataSvc.apiCall('post', 'feed', { offset: feed_offset }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            loading_feed = false;
            $loading.prop('hidden', true); $inf_load.prop('hidden', true);
            if (!o || !o.success) { show_error(append); return; }
            LOGGED_IN = !!o.viewer_logged;
            VIEWER_CREDITS = o.viewer_credits || 0;
            var items = o.items || [];
            if (!append) {
                if (!items.length) { $empty.prop('hidden', false); return; }
                hide_pill();
            }
            $grid.prop('hidden', false);
            items.forEach(function (c) {
                if (c.id > newest_id) { newest_id = c.id; }   // watermark = highest id loaded (feed sorts by published_at, so the top card isn't always the max id)
                card_by_id[c.id] = c;
                $grid.append(render_card(c));
            });
            feed_offset = o.next_offset || (feed_offset + items.length);
            has_more = !!o.has_more;
            $end.prop('hidden', has_more || !$grid.children().length);
            if (!append) { start_polling(); }
            // If the sentinel is still on-screen (short page / tall viewport), keep filling.
            if (has_more) { requestAnimationFrame(check_sentinel); }
        });
    }
    function show_error(append) {
        if (append) { toast('Could not load more posts.'); }
        else { $error.prop('hidden', false); }
    }

    // ---- infinite scroll: auto-load as the sentinel nears the viewport ----
    var sentinel = document.getElementById('feed_sentinel');
    function check_sentinel() {
        if (loading_feed || !has_more) { return; }
        var r = sentinel.getBoundingClientRect();
        if (r.top <= (window.innerHeight || document.documentElement.clientHeight) + 400) { load_feed(true); }
    }
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) { check_sentinel(); }
        }, { rootMargin: '400px 0px' }).observe(sentinel);
    } else {
        $(window).on('scroll', check_sentinel);
    }

    // ---- new-content alert: poll for posts published since our watermark ----
    function start_polling() {
        if (poll_timer) { return; }
        poll_timer = setInterval(check_new, POLL_MS);
    }
    function check_new() {
        if (document.hidden || !newest_id) { return; }
        ApiDataSvc.apiCall('post', 'feed_new', { since_id: newest_id }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (o && o.success && o.count > 0) { show_pill(o.count); }
        });
    }
    function show_pill(n) {
        var label = n === 1 ? '1 new post' : ((n >= 50 ? '50+' : n) + ' new posts');
        $pill_txt.text(label);
        $pill.prop('hidden', false);
        requestAnimationFrame(function () { $pill.addClass('is-in'); });
    }
    function hide_pill() { $pill.removeClass('is-in').prop('hidden', true); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { check_new(); } });

    function render_card(c) {
        var locked = !c.entitled;
        var media = c.cover
            ? '<img class="feed-card__img' + (locked ? ' feed-card__img--locked' : '') + '" src="' + esc(c.cover) + '" alt="" loading="lazy" onerror="this.remove()">'
            : '<div class="feed-card__ph"><i class="fa-regular fa-image"></i></div>';
        var glyph = c.is_video ? '<span class="feed-card__glyph"><i class="fa-solid fa-play"></i></span>'
            : ((c.media_count || 0) > 1 ? '<span class="feed-card__glyph"><i class="fa-solid fa-layer-group"></i></span>' : '');
        var lock = '';
        if (locked) {
            var tag = c.audience === 'ppv' ? ('Unlock &middot; ' + money(c.ppv_price_credits)) : 'Subscribers only';
            lock = '<div class="feed-card__lock"><span class="feed-card__lock-icon"><i class="fa-solid fa-lock"></i></span>' +
                '<span class="feed-card__lock-tag">' + tag + '</span></div>';
        }
        var initial = esc(String(c.author || '?').replace(/^@/, '').charAt(0).toUpperCase());
        // If the avatar file can't load (deleted or missing on S3), swap in the initial instead of a broken image.
        var avatar = c.avatar
            ? '<img class="feed-card__avatar" src="' + esc(c.avatar) + '" alt="" data-initial="' + initial + '" onerror="var s=document.createElement(\'span\');s.className=\'feed-card__avatar\';s.textContent=this.getAttribute(\'data-initial\');this.replaceWith(s);">'
            : '<span class="feed-card__avatar">' + initial + '</span>';
        var cap = (c.caption && c.caption.trim() !== '')
            ? '<p class="feed-card__caption">' + esc(c.caption) + '</p>'
            : '<p class="feed-card__caption feed-card__caption--empty">Untitled</p>';

        var $el = $(
            '<article class="feed-card" data-id="' + c.id + '" tabindex="0" role="button" aria-label="Open post">' +
                '<div class="feed-card__media">' + media + glyph + lock + '</div>' +
                '<div class="feed-card__body">' +
                    '<div class="feed-card__author">' + avatar + '<span class="feed-card__name">' + esc(c.author) + '</span></div>' +
                    cap +
                    '<div class="feed-card__meta">' +
                        '<button type="button" class="feed-card__act' + (c.liked ? ' is-liked' : '') + '" data-act="like" aria-label="Like">' +
                            '<i class="fa-' + (c.liked ? 'solid' : 'regular') + ' fa-heart"></i><span>' + fmt(c.likes) + '</span></button>' +
                        '<button type="button" class="feed-card__act" data-act="comment" aria-label="Comments">' +
                            '<i class="fa-regular fa-comment"></i><span>' + fmt(c.comments) + '</span></button>' +
                        '<span class="feed-card__act feed-card__act--views"><i class="fa-regular fa-eye"></i><span>' + fmt(c.views) + '</span></span>' +
                    '</div>' +
                '</div>' +
            '</article>');
        return $el;
    }

    // card interactions: like inline, everything else opens the post
    $grid.on('click', '.feed-card', function (e) {
        var id = +$(this).data('id');
        var like_btn = $(e.target).closest('[data-act="like"]');
        if (like_btn.length) { e.stopPropagation(); card_like(id, like_btn); return; }
        open_post(id);
    });
    $grid.on('keydown', '.feed-card', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open_post(+$(this).data('id')); }
    });

    // Repaint the like button by REPLACING its markup — swapping the class on an
    // existing <i> doesn't change the glyph once Font Awesome's kit has turned it
    // into an <svg>, so we re-insert a fresh <i> and let FA reprocess it.
    function paint_like($btn, liked, likes) {
        $btn.toggleClass('is-liked', !!liked)
            .html('<i class="fa-' + (liked ? 'solid' : 'regular') + ' fa-heart"></i><span>' + fmt(likes) + '</span>');
    }

    function card_like(id, $btn) {
        if (!LOGGED_IN) { window.location = '/'; return; }
        ApiDataSvc.apiCall('post', 'post_like', { id: id }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o) { toast('Could not like'); return; }
            if (o.need_login) { window.location = '/'; return; }
            if (!o.success) { toast(o.message || 'Could not like'); return; }
            var c = card_by_id[id]; if (c) { c.liked = o.liked; c.likes = o.likes; }
            paint_like($btn, o.liked, o.likes);
            if (plb_post && plb_post.id === id) { plb_post.liked = o.liked; plb_post.likes = o.likes; render_engage(plb_post); }
        });
    }

    function sync_card_counts(id) {
        var c = card_by_id[id]; if (!c) { return; }
        var $card = $grid.find('.feed-card[data-id="' + id + '"]');
        paint_like($card.find('[data-act="like"]'), c.liked, c.likes);
        $card.find('[data-act="comment"] span').text(fmt(c.comments));
        $card.find('.feed-card__act--views span').text(fmt(c.views));
    }

    // --------------------------------------------------------------- lightbox
    var lb = document.getElementById('feed_lightbox');
    var inner = document.getElementById('feed_lb_inner');
    var plb_post = null;
    var plb_timer = null;

    function open_post(id) {
        var card = card_by_id[id]; if (!card) { return; }
        inner.innerHTML = '<div class="feed-plb__loading"><span class="feed__spinner"></span></div>';
        lb.hidden = false; document.body.style.overflow = 'hidden';
        ApiDataSvc.apiCall('post', 'post_detail', { id: id }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o || !o.success) { close_lb(); toast(o && o.message ? o.message : 'Post not available'); return; }
            var p = $.extend({}, card, o.post);   // detail wins; card carries author/profile
            card_by_id[id] = $.extend(card, { liked: p.liked, likes: p.likes, comments: p.comments, views: p.views });
            render_post(p);
        });
    }

    function render_post(p) {
        plb_post = p;
        var h = '<div class="feed-plb__head">';
        var av = p.avatar ? '<img class="feed-plb__avatar" src="' + esc(p.avatar) + '" alt="">'
            : '<span class="feed-plb__avatar">' + esc(String(p.author || '?').replace(/^@/, '').charAt(0).toUpperCase()) + '</span>';
        h += av + '<a class="feed-plb__author" href="' + esc(p.profile_url) + '">' + esc(p.author) + '</a>' +
            '<button type="button" class="feed-plb__report" data-report-type="post" data-report-id="' + (p.id || 0) + '" title="Report this post" aria-label="Report this post"><i class="fa-solid fa-flag"></i></button></div>';

        if (p.entitled) {
            var assets = p.assets || [];
            h += '<div class="feed-plb__media' + (assets.length > 1 ? ' feed-plb__media--carousel' : '') + '">';
            assets.forEach(function (a, i) {
                var slide = (a.type === 'video')
                    ? '<video src="' + esc(a.url) + '"' + (a.poster ? ' poster="' + esc(a.poster) + '"' : '') + ' controls preload="metadata" controlsList="nodownload"></video>'
                    : '<img src="' + esc(a.url) + '" alt="" oncontextmenu="return false">';
                h += '<div class="feed-plb__slide' + (i === 0 ? ' is-on' : '') + '">' + slide + '</div>';
            });
            if (assets.length > 1) {
                h += '<button type="button" class="feed-plb__nav feed-plb__nav--prev" data-plb="prev" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></button>' +
                     '<button type="button" class="feed-plb__nav feed-plb__nav--next" data-plb="next" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></button>' +
                     '<div class="feed-plb__dots">' + assets.map(function (a, i) { return '<span class="feed-plb__dot' + (i === 0 ? ' is-on' : '') + '"></span>'; }).join('') + '</div>';
            }
            if (!assets.length) { h += '<div class="feed-plb__nomedia"><i class="fa-regular fa-image"></i></div>'; }
            h += '</div>';
            h += '<div class="feed-plb__body">';
            if (p.caption) { h += '<p>' + esc(p.caption).replace(/\n/g, '<br>') + '</p>'; }
            if (p.published_at) { h += '<span class="feed-plb__date">' + esc(p.published_at) + '</span>'; }
            h += '<div class="feed-plb__engage" id="feed_lb_engage"></div>';
            h += '<div class="feed-plb__comments" id="feed_lb_comments"></div>';
            h += '</div>';
        } else if (p.audience === 'ppv') {
            h += locked_block(p, 'Pay-per-view post',
                (LOGGED_IN ? ('Unlock for ' + money(p.ppv_price_credits)) : 'Log in to Unlock'), 'ppv');
            if (p.caption) { h += '<div class="feed-plb__body"><p>' + esc(p.caption).replace(/\n/g, '<br>') + '</p></div>'; }
        } else {
            h += locked_block(p, 'Subscribers-only post',
                (LOGGED_IN ? 'Subscribe to unlock' : 'Log in to view'), 'sub');
            if (p.caption) { h += '<div class="feed-plb__body"><p>' + esc(p.caption).replace(/\n/g, '<br>') + '</p></div>'; }
        }
        inner.innerHTML = h;

        if (p.audience === 'ppv') { render_ppv_action(p); }
        var promo_apply = document.getElementById('feed_lb_promo_apply');
        if (promo_apply) { promo_apply.onclick = function () { apply_ppv_promo(p); }; }
        var sub_btn = document.getElementById('feed_lb_sub');
        if (sub_btn) { sub_btn.onclick = function () { window.location = p.profile_url; }; }

        plb_autoplay();
        if (p.entitled) { render_engage(p); record_view(p); load_comments(p); }
    }

    function locked_block(p, label, btn_text, kind) {
        var bg = p.locked_url ? ' style="background-image:url(\'' + esc(p.locked_url) + '\')"' : '';
        if (kind === 'ppv') {
            var promo = LOGGED_IN
                ? '<div class="feed-plb__promo"><input type="text" id="feed_lb_promo" class="feed-plb__promo-input" placeholder="Discount code" maxlength="40" autocomplete="off">' +
                  '<button type="button" class="feed-plb__promo-apply" id="feed_lb_promo_apply">Apply</button></div>' +
                  '<div class="feed-plb__promo-msg" id="feed_lb_promo_msg"></div>'
                : '';
            return '<div class="feed-plb__locked"' + bg + '><div class="feed-plb__lockmeta"><i class="fa-solid fa-lock"></i><span>' + label + '</span>' +
                '<div class="feed-plb__ppv" id="feed_lb_ppv_action"></div>' + promo + '</div></div>';
        }
        return '<div class="feed-plb__locked"' + bg + '><div class="feed-plb__lockmeta"><i class="fa-solid fa-lock"></i><span>' + label + '</span>' +
            '<button type="button" class="feed-plb__unlock" id="feed_lb_sub">' + btn_text + '</button></div></div>';
    }

    // Show the viewer's balance and either an Unlock button (can afford) or an
    // Add-credits button (short) — recomputed whenever the effective price changes.
    function render_ppv_action(p) {
        var wrap = document.getElementById('feed_lb_ppv_action');
        if (!wrap) { return; }
        if (!LOGGED_IN) {
            wrap.innerHTML = '<button type="button" class="feed-plb__unlock" id="feed_lb_ppv">Log in to Unlock</button>';
            document.getElementById('feed_lb_ppv').onclick = function () { window.location = '/'; };
            return;
        }
        var price   = (typeof p.effective_price === 'number') ? p.effective_price : p.ppv_price_credits;
        var bal     = '<div class="feed-plb__bal">Your balance: ' + money(VIEWER_CREDITS) + '</div>';
        if (VIEWER_CREDITS >= price) {
            wrap.innerHTML = bal + '<button type="button" class="feed-plb__unlock" id="feed_lb_ppv">Unlock for ' + money(price) + '</button>';
            var b = document.getElementById('feed_lb_ppv');
            b.onclick = function () { unlock_ppv(p, b); };
        } else {
            wrap.innerHTML = bal +
                '<div class="feed-plb__short">You need ' + money(price - VIEWER_CREDITS) + ' more to unlock this.</div>' +
                '<button type="button" class="feed-plb__unlock" id="feed_lb_add_credits">Add Funds</button>';
            document.getElementById('feed_lb_add_credits').onclick = function () { window.location = '/account/settings?section=wallet'; };
        }
    }

    // Validate a discount code against this PPV post and reflect the new price.
    function apply_ppv_promo(p) {
        var input = document.getElementById('feed_lb_promo');
        var msg   = document.getElementById('feed_lb_promo_msg');
        var code  = input ? input.value.trim().toUpperCase() : '';
        if (code === '') { return; }
        ApiDataSvc.apiCall('post', 'promo_preview', { post_id: p.id, code: code }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o || !o.success) {
                p.applied_code = null; p.effective_price = p.ppv_price_credits;
                if (msg) { msg.textContent = (o && o.message) ? o.message : "That code isn't valid."; msg.className = 'feed-plb__promo-msg is-err'; }
                render_ppv_action(p);
                return;
            }
            p.applied_code = code; p.effective_price = o.new_price;
            if (msg) { msg.textContent = (o.label || (o.percent_off + '% off')) + ' applied'; msg.className = 'feed-plb__promo-msg is-ok'; }
            render_ppv_action(p);   // may flip Add-credits → Unlock if the discount brings it within budget
        });
    }

    function unlock_ppv(p, btn) {
        if (!LOGGED_IN) { window.location = '/'; return; }
        var orig = btn.textContent; btn.disabled = true; btn.textContent = 'Unlocking…';
        ApiDataSvc.apiCall('post', 'ppv_unlock', { post_id: p.id, code: (p.applied_code || '') }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o) { btn.disabled = false; btn.textContent = orig; toast('Could not unlock'); return; }
            if (o.need_login) { window.location = '/'; return; }
            if (o.need_credits) {
                btn.disabled = false; btn.textContent = orig;
                toast(o.message || 'Not enough funds in your wallet.');
                setTimeout(function () { window.location = '/account/settings?section=wallet'; }, 1400);
                return;
            }
            if (!o.success) { btn.disabled = false; btn.textContent = orig; toast(o.message || 'Could not unlock'); return; }
            p.entitled = true; p.assets = o.assets || []; p.unlocked = true;
            var c = card_by_id[p.id]; if (c) { c.entitled = true; }
            var $card = $grid.find('.feed-card[data-id="' + p.id + '"]');
            $card.find('.feed-card__img').removeClass('feed-card__img--locked');
            $card.find('.feed-card__lock').remove();
            toast('Unlocked!');
            render_post(p);
        });
    }

    function close_lb() { lb.hidden = true; inner.innerHTML = ''; document.body.style.overflow = ''; clearInterval(plb_timer); plb_post = null; }

    document.getElementById('feed_lb_close').addEventListener('click', close_lb);
    lb.addEventListener('click', function (ev) { if (ev.target === lb) { close_lb(); } });
    document.addEventListener('keydown', function (ev) {
        if (lb.hidden) { return; }
        if (ev.key === 'Escape') { close_lb(); }
        else if (ev.key === 'ArrowLeft') { plb_go(-1); plb_autoplay(); }
        else if (ev.key === 'ArrowRight') { plb_go(1); plb_autoplay(); }
    });
    inner.addEventListener('click', function (ev) {
        var like = ev.target.closest('[data-plb-like]');
        if (like) { do_like(); return; }
        var del = ev.target.closest('[data-plb-cdel]');
        if (del) { do_delete_comment(del.getAttribute('data-plb-cdel')); return; }
        var nav = ev.target.closest('.feed-plb__nav');
        if (nav) { plb_go(nav.getAttribute('data-plb') === 'next' ? 1 : -1); plb_autoplay(); }
    });

    function plb_go(dir) {
        var slides = inner.querySelectorAll('.feed-plb__slide'); var n = slides.length; if (n < 2) { return; }
        var cur = 0; slides.forEach(function (s, i) { if (s.classList.contains('is-on')) { cur = i; } });
        var next = (cur + dir + n) % n;
        slides.forEach(function (s, i) { var on = i === next; s.classList.toggle('is-on', on); if (!on) { var v = s.querySelector('video'); if (v) { v.pause(); } } });
        inner.querySelectorAll('.feed-plb__dot').forEach(function (d, i) { d.classList.toggle('is-on', i === next); });
    }
    function plb_autoplay() {
        clearInterval(plb_timer);
        if (inner.querySelectorAll('.feed-plb__slide').length > 1) {
            plb_timer = setInterval(function () {
                if (lb.hidden) { clearInterval(plb_timer); return; }
                var v = inner.querySelector('.feed-plb__slide.is-on video');
                if (v && !v.paused) { return; }
                plb_go(1);
            }, 4500);
        }
    }

    // ---- engagement: likes, views, comments ----
    function render_engage(p) {
        var bar = document.getElementById('feed_lb_engage'); if (!bar) { return; }
        bar.innerHTML =
            '<button type="button" class="feed-plb__like' + (p.liked ? ' is-liked' : '') + '" data-plb-like>' +
                '<i class="fa-' + (p.liked ? 'solid' : 'regular') + ' fa-heart"></i> <span>' + (p.likes || 0) + '</span></button>' +
            '<span class="feed-plb__estat"><i class="fa-regular fa-comment"></i> ' + (p.comments || 0) + '</span>' +
            '<span class="feed-plb__estat feed-plb__estat--views"><i class="fa-regular fa-eye"></i> ' + (p.views || 0) + '</span>';
    }
    function do_like() {
        if (!LOGGED_IN) { window.location = '/'; return; }
        if (!plb_post) { return; }
        ApiDataSvc.apiCall('post', 'post_like', { id: plb_post.id }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o) { toast('Could not like'); return; }
            if (o.need_login) { window.location = '/'; return; }
            if (!o.success) { toast(o.message || 'Could not like'); return; }
            plb_post.liked = o.liked; plb_post.likes = o.likes; render_engage(plb_post);
            var c = card_by_id[plb_post.id]; if (c) { c.liked = o.liked; c.likes = o.likes; }
            sync_card_counts(plb_post.id);
        });
    }
    function record_view(p) {
        ApiDataSvc.apiCall('post', 'post_view', { id: p.id }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (o && o.success && typeof o.views === 'number') {
                p.views = o.views;
                var c = card_by_id[p.id]; if (c) { c.views = o.views; }
                var v = document.querySelector('#feed_lb_engage .feed-plb__estat--views');
                if (v) { v.innerHTML = '<i class="fa-regular fa-eye"></i> ' + o.views; }
                sync_card_counts(p.id);
            }
        });
    }
    function load_comments(p) {
        var box = document.getElementById('feed_lb_comments'); if (!box) { return; }
        box.innerHTML = '<div class="feed-plb__cload">Loading comments…</div>';
        ApiDataSvc.apiCall('post', 'post_comments', { id: p.id }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o || !o.success) { box.innerHTML = ''; return; }
            render_comments(o);
        });
    }
    function render_comments(o) {
        var box = document.getElementById('feed_lb_comments'); if (!box) { return; }
        var html = '<div class="feed-plb__clist">';
        if (!o.comments.length) { html += '<p class="feed-plb__cempty">No comments yet' + (o.can_comment ? ' — be the first.' : '.') + '</p>'; }
        o.comments.forEach(function (c) {
            html += '<div class="feed-plb__c">' +
                '<span class="feed-plb__cav">' + esc(c.initial) + '</span>' +
                '<div class="feed-plb__cmain"><div class="feed-plb__chead"><span class="feed-plb__cname">' + esc(c.name) + '</span><span class="feed-plb__cwhen">' + esc(c.when) + '</span>' +
                (c.can_delete ? '<button type="button" class="feed-plb__cdel" data-plb-cdel="' + c.id + '" aria-label="Delete comment"><i class="fa-solid fa-xmark"></i></button>' : '') +
                '</div><p class="feed-plb__cbody">' + esc(c.body).replace(/\n/g, '<br>') + '</p></div></div>';
        });
        html += '</div>';
        if (o.can_comment) {
            html += '<form class="feed-plb__cform" id="feed_lb_cform"><input type="text" class="feed-plb__cinput" id="feed_lb_cinput" placeholder="Add a comment…" maxlength="2000" autocomplete="off"><button type="submit" class="feed-plb__csend">Post</button></form>';
        } else if (!LOGGED_IN && o.comments_enabled) {
            html += '<p class="feed-plb__cnote"><a href="/">Log in</a> to comment.</p>';
        } else if (!o.comments_enabled) {
            html += '<p class="feed-plb__cnote">Comments are turned off for this post.</p>';
        }
        box.innerHTML = html;
        var form = document.getElementById('feed_lb_cform');
        if (form) { form.onsubmit = function (ev) { ev.preventDefault(); do_comment(); }; }
    }
    function do_comment() {
        var input = document.getElementById('feed_lb_cinput'); if (!input || !plb_post) { return; }
        var body = input.value.trim(); if (body === '' || input.dataset.busy === '1') { return; }   // a double click posts once
        input.dataset.busy = '1'; var release = setTimeout(function () { input.dataset.busy = ''; }, 15000);
        ApiDataSvc.apiCall('post', 'post_comment_add', { id: plb_post.id, body: body }, function (resp) {
            input.dataset.busy = ''; clearTimeout(release);
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o) { toast('Could not post'); return; }
            if (o.need_login) { window.location = '/'; return; }
            if (!o.success) { toast(o.message || 'Could not post'); return; }
            input.value = ''; plb_post.comments = o.count; render_engage(plb_post);
            var c = card_by_id[plb_post.id]; if (c) { c.comments = o.count; }
            sync_card_counts(plb_post.id);
            load_comments(plb_post);
        });
    }
    function do_delete_comment(id) {
        ApiDataSvc.apiCall('post', 'post_comment_delete', { comment_id: id }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) { o = null; }
            if (!o || !o.success) { toast(o && o.message ? o.message : 'Could not delete'); return; }
            if (plb_post) {
                plb_post.comments = o.count; render_engage(plb_post);
                var c = card_by_id[plb_post.id]; if (c) { c.comments = o.count; }
                sync_card_counts(plb_post.id);
                load_comments(plb_post);
            }
        });
    }

    // --------------------------------------------------------------- wire-up
    $('#feed_retry').on('click', function () { load_feed(false); });
    // "N new posts" → jump to top and refresh the feed from the newest.
    $pill.on('click', function () {
        hide_pill();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        load_feed(false);
    });
    load_feed(false);
})();
