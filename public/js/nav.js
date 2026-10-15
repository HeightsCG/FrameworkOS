/* Public site menu (libs/Layout/public_nav.php, groups from libs/Classes/SiteNav.php), 2026-10-15.
   Desktop: each group button opens its panel on click or hover; Esc, a click outside or tabbing away closes it.
   Phones/tablets (the sheet opened by #ld_nav_toggle): the same buttons expand their group in place.
   Also the site-wide click tracking: any element with data-track="<event>" sends CLSTrack(event, {label, page}). */
(function () {
    var nav = document.getElementById('ld_nav_links');
    var mq = window.matchMedia('(max-width: 1080px)');
    var hover_ok = window.matchMedia('(hover: hover) and (pointer: fine)');
    var groups = nav ? [].slice.call(nav.querySelectorAll('.mn__g')) : [];
    var timer = null;

    function btn_of(g) { return g.querySelector('.mn__top'); }
    function set_open(g, open) {
        g.classList.toggle('is-open', open);
        btn_of(g).setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && window.CLSTrack) { window.CLSTrack('nav_open', { group: g.getAttribute('data-mn') }); }
    }
    function close_all(except) { groups.forEach(function (g) { if (g !== except && g.classList.contains('is-open')) { set_open(g, false); } }); }

    groups.forEach(function (g) {
        var btn = btn_of(g);
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !g.classList.contains('is-open');
            if (!mq.matches) { close_all(g); }
            set_open(g, open);
        });
        g.addEventListener('mouseenter', function () {
            if (mq.matches || !hover_ok.matches) { return; }
            clearTimeout(timer);
            timer = setTimeout(function () { close_all(g); if (!g.classList.contains('is-open')) { set_open(g, true); } }, 80);
        });
        g.addEventListener('mouseleave', function () {
            if (mq.matches || !hover_ok.matches) { return; }
            clearTimeout(timer);
            timer = setTimeout(function () { set_open(g, false); }, 220);
        });
        g.addEventListener('focusout', function (e) {
            if (mq.matches) { return; }
            if (!g.contains(e.relatedTarget)) { set_open(g, false); }
        });
    });

    document.addEventListener('click', function (e) {
        if (!mq.matches && nav && !nav.contains(e.target)) { close_all(null); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        groups.forEach(function (g) {
            if (g.classList.contains('is-open')) { set_open(g, false); btn_of(g).focus(); }
        });
    });
    // leaving the phone layout (rotate, resize) resets the expanded groups
    var on_mq = function () { close_all(null); };
    if (mq.addEventListener) { mq.addEventListener('change', on_mq); } else if (mq.addListener) { mq.addListener(on_mq); }

    /* Click tracking: data-track="cta_click" data-track-label="..." [data-track-tool="..."] on any link or button. */
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-track]') : null;
        if (!el || !window.CLSTrack) { return; }
        var p = { label: el.getAttribute('data-track-label') || (el.textContent || '').trim().slice(0, 60), page: location.pathname };
        var tool = el.getAttribute('data-track-tool'); if (tool) { p.tool = tool; }
        window.CLSTrack(el.getAttribute('data-track'), p);
    });
})();
