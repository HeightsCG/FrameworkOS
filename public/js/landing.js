/* Creator Link Studio — public landing page behavior.
   Auth requests live in the inline script on the page (unchanged
   sign-in/register/MFA/forgot flow); this file only handles the modal shell
   and the one-time reveal-on-scroll. */

$(document).ready(function() {

    /* ---------- Phone menu (Features / Pricing / Blog / Sign In) ---------- */
    $('#ld_nav_toggle').on('click', function() {
        var is_open = $('#ld_nav_links').toggleClass('is-open').hasClass('is-open');
        $(this).attr('aria-expanded', is_open ? 'true' : 'false');
    });
    $(document).on('click', function(e) {
        if ($(e.target).closest('#ld_nav_toggle').length) { return; }
        if ($(e.target).closest('#ld_nav_links').length && !$(e.target).closest('a, button').length) { return; }
        $('#ld_nav_links').removeClass('is-open');
        $('#ld_nav_toggle').attr('aria-expanded', 'false');
    });

    /* ---------- Auth modal shell ---------- */

    var auth_overlay = document.getElementById('ld_auth');
    var auth_dialog = auth_overlay ? auth_overlay.querySelector('.ld-auth__dialog') : null;
    var last_focused = null;

    function show_auth_panel(panel) {
        $('#login_form, #mfa_form, #forgot_form, #register_form').hide();
        if (panel === 'register') {
            $('#register_form').show();
            if (window.CLSTrack) { window.CLSTrack('sign_up_start', {}); }
        } else {
            $('#login_form').show();
        }
    }

    function open_auth(panel) {
        if (!auth_overlay) { return; }
        last_focused = document.activeElement;
        show_auth_panel(panel);
        auth_overlay.hidden = false;
        document.body.classList.add('ld-auth-open');
        var first_input = auth_dialog.querySelector('input:not([type=hidden])');
        if (first_input) { first_input.focus(); }
    }

    function close_auth() {
        if (!auth_overlay || auth_overlay.hidden) { return; }
        auth_overlay.hidden = true;
        document.body.classList.remove('ld-auth-open');
        if (last_focused && typeof last_focused.focus === 'function') { last_focused.focus(); }
    }

    /* ?plan= / ?role= / ?ref= on a sign-up link or this page: kept in the cls_signup cookie (30 days) for the
       register post and the Google sign-up (UsersModel::signup_params cleans them server side). */
    function remember_signup(query) {
        var q = new URLSearchParams(query || '');
        var keep = {};
        try { keep = JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )cls_signup=([^;]*)/) || [])[1] || '')) || {}; } catch (err) { keep = {}; }
        var found = false;
        ['plan', 'role', 'ref'].forEach(function(k) {
            if (q.get(k)) { keep[k] = q.get(k).slice(0, 40); found = true; }
        });
        if (!found) { return; }
        document.cookie = 'cls_signup=' + encodeURIComponent(JSON.stringify(keep)) + '; max-age=2592000; path=/; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
    }

    /* A creator sign-up (role=creator in the cookie or this page's URL, or force) shows the Creator Agreement checkbox; anyone else does not. */
    function sync_creator_terms(force) {
        var role = new URLSearchParams(location.search).get('role') || '';
        if (role !== 'creator') {
            try { role = (JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )cls_signup=([^;]*)/) || [])[1] || '')) || {}).role || ''; } catch (err) { role = ''; }
        }
        if (force === true) { role = 'creator'; }
        var slot = document.getElementById('register_creator_slot');
        var tpl = document.getElementById('register_creator_tpl');
        if (!slot || !tpl) { return; }
        if (role === 'creator') {
            if (!slot.firstElementChild) { slot.appendChild(tpl.content.cloneNode(true)); }
            slot.hidden = false;
        } else {
            slot.innerHTML = '';
            slot.hidden = true;
        }
    }
    sync_creator_terms();
    window.cls_show_creator_terms = function() { sync_creator_terms(true); };   // auth_head.php: the server asked for the agreement

    $(document).on('click', '[data-auth]', function(e) {
        e.preventDefault();
        var href = $(this).attr('href') || '';
        if (href.indexOf('?') !== -1) { remember_signup(href.split('?')[1].split('#')[0]); }
        sync_creator_terms();
        open_auth($(this).attr('data-auth'));
    });

    $(document).on('click', '.ld-auth__veil, .ld-auth__close', function() {
        close_auth();
    });

    /* Arriving from a public page ("Sign In" / "Create Your Account" links to
       /?auth=login or /?auth=register): open the matching dialog on load. */
    var auth_param = new URLSearchParams(location.search).get('auth');
    if (auth_param === 'login' || auth_param === 'register' || auth_param === 'mfa') {   // mfa: back from Google, code still needed (auth_head.php shows that panel)
        var auth_trigger = document.querySelector('[data-auth="' + (auth_param === 'mfa' ? 'login' : auth_param) + '"]');
        if (auth_trigger) { auth_trigger.click(); }
    }
    if (auth_param === 'register') { remember_signup(location.search); sync_creator_terms(); }   // after the click: this page's own params win

    $(document).on('keydown', function(e) {
        if (!auth_overlay || auth_overlay.hidden) { return; }

        if (e.key === 'Escape') {
            close_auth();
            return;
        }

        // Keep Tab focus inside the dialog while it is open.
        if (e.key === 'Tab') {
            var focusables = auth_dialog.querySelectorAll(
                'a[href], a.cos-link, button, input:not([type=hidden]), [tabindex]:not([tabindex="-1"])'
            );
            var visible = [];
            for (var i = 0; i < focusables.length; i++) {
                if (focusables[i].offsetParent !== null) { visible.push(focusables[i]); }
            }
            if (visible.length === 0) { return; }
            var first = visible[0];
            var last = visible[visible.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (document.activeElement === last || !auth_dialog.contains(document.activeElement))) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    /* ---------- EARN. as living particle light ----------
       The word is rebuilt as ~4k physical particles on a canvas: they assemble
       into the glyphs on load, scatter around the visitor's cursor, and spring
       back when it leaves. The real text stays in the DOM underneath for
       accessibility, touch devices, reduced motion, and any failure path. */

    (function() {
        var earn = document.querySelector('.ld-kx__line--earn');
        var row = document.querySelector('.ld-kx__earnrow');
        var kx = document.querySelector('.ld-kx');
        if (!earn || !row || !kx) { return; }
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        if (!window.matchMedia('(pointer: fine)').matches) { return; }
        if (window.innerWidth < 901) { return; }

        var canvas = document.createElement('canvas');
        canvas.className = 'ld-kx__dust';
        canvas.setAttribute('aria-hidden', 'true');
        row.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        if (!ctx) { canvas.remove(); return; }

        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var particles = [];
        var bands = ['rgb(70,70,78)', 'rgb(17,17,20)', 'rgb(0,0,0)'];
        var mouse_x = -9999, mouse_y = -9999;
        var cw = 0, ch = 0, dot = 3;
        var in_view = false, raf = null;

        function build() {
            var r = earn.getBoundingClientRect();
            var rr = row.getBoundingClientRect();
            if (r.width < 50) { return false; }
            cw = Math.ceil(r.width + 40);
            ch = Math.ceil(r.height * 1.5);
            canvas.style.left = (r.left - rr.left - 20) + 'px';
            canvas.style.top = (r.top - rr.top - r.height * .25) + 'px';
            canvas.style.width = cw + 'px';
            canvas.style.height = ch + 'px';
            canvas.width = Math.round(cw * dpr);
            canvas.height = Math.round(ch * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            var cs = getComputedStyle(earn);
            ctx.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
            try { ctx.letterSpacing = cs.letterSpacing; } catch (e) {}
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#000';
            ctx.fillText('EARN.', 20, ch / 2);

            var img;
            try { img = ctx.getImageData(0, 0, canvas.width, canvas.height).data; }
            catch (e) { canvas.remove(); return false; }
            ctx.clearRect(0, 0, cw, ch);

            var gap = Math.max(4, Math.round(cw / 200));
            dot = Math.max(2, gap - 2);
            particles = [];
            for (var y = 0; y < ch; y += gap) {
                for (var x = 0; x < cw; x += gap) {
                    var alpha = img[((Math.floor(y * dpr) * canvas.width) + Math.floor(x * dpr)) * 4 + 3];
                    if (alpha > 128) {
                        particles.push({
                            hx: x, hy: y,
                            x: cw / 2 + (Math.random() - .5) * cw * 1.4,
                            y: ch / 2 + (Math.random() - .5) * ch * 3,
                            vx: 0, vy: 0,
                            b: Math.min(2, Math.floor(x / cw * 3))
                        });
                    }
                }
            }
            earn.style.visibility = 'hidden';
            return particles.length > 0;
        }

        var R = 120, R2 = R * R;
        function step() {
            raf = null;
            ctx.clearRect(0, 0, cw, ch);
            var i, p, dx, dy, d2, d, f;
            for (var b = 0; b < 3; b++) {
                ctx.fillStyle = bands[b];
                for (i = 0; i < particles.length; i++) {
                    p = particles[i];
                    if (p.b !== b) { continue; }
                    p.vx += (p.hx - p.x) * .022;
                    p.vy += (p.hy - p.y) * .022;
                    dx = p.x - mouse_x; dy = p.y - mouse_y;
                    d2 = dx * dx + dy * dy;
                    if (d2 < R2) {
                        d = Math.sqrt(d2) || 1;
                        f = (R - d) / R * 5.5;
                        p.vx += dx / d * f;
                        p.vy += dy / d * f;
                    }
                    p.vx *= .88; p.vy *= .88;
                    p.x += p.vx; p.y += p.vy;
                    ctx.fillRect(p.x, p.y, dot, dot);
                }
            }
            if (in_view) { raf = requestAnimationFrame(step); }
        }

        function start() { if (!raf && in_view && particles.length) { raf = requestAnimationFrame(step); } }

        kx.addEventListener('mousemove', function(e) {
            var r = canvas.getBoundingClientRect();
            mouse_x = e.clientX - r.left;
            mouse_y = e.clientY - r.top;
        }, { passive: true });
        kx.addEventListener('mouseleave', function() { mouse_x = -9999; mouse_y = -9999; }, { passive: true });

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function(entries) {
                in_view = entries[0].isIntersecting;
                start();
            }).observe(kx);
        } else {
            in_view = true;
        }

        var resize_t = null;
        window.addEventListener('resize', function() {
            clearTimeout(resize_t);
            resize_t = setTimeout(function() {
                earn.style.visibility = '';
                if (window.innerWidth < 901) { canvas.remove(); particles = []; return; }
                build();
                start();
            }, 200);
        }, { passive: true });

        function boot() {
            // let the entry choreography land first, then take over with physics
            setTimeout(function() { if (build()) { start(); } }, 1400);
        }
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(boot); } else { boot(); }
    })();

    /* ---------- Cursor-reactive light + tilt on the hero canvas ---------- */

    (function() {
        var kx = document.querySelector('.ld-kx');
        if (!kx) { return; }
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        if (!window.matchMedia('(pointer: fine)').matches) { return; }

        var tx = .68, ty = .38, cx = .68, cy = .38, raf = null;

        function frame() {
            raf = null;
            cx += (tx - cx) * .08;
            cy += (ty - cy) * .08;
            kx.style.setProperty('--mx', (cx * 100).toFixed(2) + '%');
            kx.style.setProperty('--my', (cy * 100).toFixed(2) + '%');
            kx.style.setProperty('--rx', ((cy - .5) * -3).toFixed(2) + 'deg');
            kx.style.setProperty('--ry', ((cx - .5) * 4).toFixed(2) + 'deg');
            if (Math.abs(tx - cx) > .001 || Math.abs(ty - cy) > .001) { raf = requestAnimationFrame(frame); }
        }

        kx.addEventListener('mousemove', function(e) {
            var r = kx.getBoundingClientRect();
            tx = (e.clientX - r.left) / r.width;
            ty = (e.clientY - r.top) / r.height;
            if (!raf) { raf = requestAnimationFrame(frame); }
        }, { passive: true });

        /* CREATE. letters dodge the cursor */
        var mags = kx.querySelectorAll('.ld-mag');
        kx.addEventListener('mousemove', function(e) {
            for (var i = 0; i < mags.length; i++) {
                var r = mags[i].getBoundingClientRect();
                var mx = r.left + r.width / 2, my = r.top + r.height / 2;
                var dx = mx - e.clientX, dy = my - e.clientY;
                var d = Math.sqrt(dx * dx + dy * dy);
                if (d < 200) {
                    var f = (1 - d / 200);
                    mags[i].style.transform = 'translate(' + (dx / d * f * 16).toFixed(1) + 'px,' + (dy / d * f * 16).toFixed(1) + 'px) rotate(' + (dx > 0 ? f * 5 : f * -5).toFixed(1) + 'deg)';
                } else if (mags[i].style.transform) {
                    mags[i].style.transform = '';
                }
            }
        }, { passive: true });
        kx.addEventListener('mouseleave', function() {
            for (var i = 0; i < mags.length; i++) { mags[i].style.transform = ''; }
        }, { passive: true });
    })();

    /* ---------- Tab hero (every page): big words are tabs that drive one live panel ---------- */

    document.querySelectorAll('.hx').forEach(function(hx) {
        var tabs = [].slice.call(hx.querySelectorAll('.hx__w'));
        var panes = [].slice.call(hx.querySelectorAll('.hx__pane'));
        if (!tabs.length) { return; }
        var current = Math.max(0, tabs.findIndex(function(t) { return t.classList.contains('is-on'); }));

        function show_tab(i, by_user) {
            current = i;
            tabs.forEach(function(t, k) {
                var on = k === i;
                t.classList.toggle('is-on', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.setAttribute('tabindex', on ? '0' : '-1');
                var bar = t.querySelector('.hx__bar i');
                bar.style.animation = 'none';
                void bar.offsetWidth;
                bar.style.animation = '';
            });
            panes.forEach(function(p, k) { p.hidden = k !== i; });
            panes[i].querySelectorAll('.hx__tl').forEach(function(tl) { if (tl._set) { tl._set(tl._stage); } });
            if (by_user) { hx.classList.add('is-paused'); }
        }

        tabs.forEach(function(t, i) {
            t.addEventListener('click', function() { show_tab(i, true); });
            t.addEventListener('keydown', function(e) {
                var fwd = e.key === 'ArrowDown' || e.key === 'ArrowRight', back = e.key === 'ArrowUp' || e.key === 'ArrowLeft';
                if (!fwd && !back) { return; }
                e.preventDefault();
                var next = (i + (fwd ? 1 : tabs.length - 1)) % tabs.length;
                show_tab(next, true);
                tabs[next].focus();
            });
            t.querySelector('.hx__bar i').addEventListener('animationend', function() {
                if (t.classList.contains('is-on') && !hx.classList.contains('is-paused')) { show_tab((current + 1) % tabs.length, false); }
            });
        });
        var mod = hx.querySelector('.hx__mod');
        if (mod) { mod.addEventListener('mouseenter', function() { hx.classList.add('is-paused'); }); }

        /* Tracked links count clicks */
        var total_el = hx.querySelector('[data-hx-total]');
        var total = total_el ? parseInt(total_el.getAttribute('data-hx-total'), 10) : 0;
        hx.querySelectorAll('.hx__lk').forEach(function(row) {
            row.addEventListener('click', function() {
                var n = parseInt(row.getAttribute('data-n'), 10) + 1;
                row.setAttribute('data-n', n);
                row.querySelector('b').textContent = n.toLocaleString();
                if (total_el) { total++; total_el.textContent = '+' + total.toLocaleString() + ' clicks'; }
                row.classList.add('is-bump');
                setTimeout(function() { row.classList.remove('is-bump'); }, 600);
            });
        });

        /* Timelines: click a step to move the marker */
        hx.querySelectorAll('.hx__tl').forEach(function(tl) {
            var nodes = [].slice.call(tl.querySelectorAll('.hx__nd'));
            var fill = tl.querySelector('.hx__fill');
            tl._stage = parseInt(tl.getAttribute('data-stage') || '1', 10);
            tl._set = function(i) {
                tl._stage = i;
                nodes.forEach(function(n, k) { n.classList.toggle('is-done', k < i); n.classList.toggle('is-cur', k === i); });
                var top = nodes[0].offsetTop + 24;
                fill.style.top = top + 'px';
                fill.style.height = (nodes[i].offsetTop + 24 - top) + 'px';
            };
            nodes.forEach(function(n, i) { n.addEventListener('click', function() { tl._set(i); }); });
            tl._set(tl._stage);
        });

        /* Option groups: one selected at a time */
        hx.querySelectorAll('.hx__acc[role=radiogroup]').forEach(function(group) {
            var opts = [].slice.call(group.querySelectorAll('.hx__opt'));
            opts.forEach(function(o) {
                o.addEventListener('click', function() {
                    opts.forEach(function(x) { x.classList.remove('is-on'); x.setAttribute('aria-checked', 'false'); });
                    o.classList.add('is-on');
                    o.setAttribute('aria-checked', 'true');
                });
            });
        });

        /* Switches */
        hx.querySelectorAll('.hx__sw').forEach(function(sw) {
            sw.addEventListener('click', function() {
                var on = !sw.classList.contains('is-on');
                sw.classList.toggle('is-on', on);
                sw.setAttribute('aria-checked', on ? 'true' : 'false');
            });
        });

        /* Chips with a live count */
        hx.querySelectorAll('.hx__chips').forEach(function(box) {
            var count = box.parentNode.querySelector('[data-hx-count]');
            var chips = [].slice.call(box.querySelectorAll('.hx__chip'));
            chips.forEach(function(c) {
                c.addEventListener('click', function() {
                    var on = !c.classList.contains('is-on');
                    c.classList.toggle('is-on', on);
                    c.setAttribute('aria-pressed', on ? 'true' : 'false');
                    var n = chips.filter(function(x) { return x.classList.contains('is-on'); }).length;
                    if (count) { count.textContent = n + ' ' + count.getAttribute('data-noun'); }
                });
            });
        });
    });

    /* ---------- One-time reveal on scroll ---------- */

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -10% 0px', threshold: 0.1 });

        document.querySelectorAll('.ld-reveal').forEach(function(el) {
            observer.observe(el);
        });
    } else {
        document.querySelectorAll('.ld-reveal').forEach(function(el) {
            el.classList.add('is-in');
        });
    }

});
