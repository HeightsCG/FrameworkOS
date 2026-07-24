/* Creator analytics dashboard — client-side tabs (same pattern as the admin page),
   with the active tab kept in the URL hash so a date-range reload stays on it. */
(function () {
    var tabs = document.getElementById('dashTabs');
    if (!tabs) { return; }
    var PANELS = ['revenue', 'audience', 'customers'];

    function activate(panel) {
        if (PANELS.indexOf(panel) === -1) { return false; }
        tabs.querySelectorAll('.dash__tab').forEach(function (t) {
            t.classList.toggle('is-active', t.getAttribute('data-panel') === panel);
        });
        document.querySelectorAll('.dash__tab-panel').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-panel') === panel);
        });
        return true;
    }

    tabs.addEventListener('click', function (e) {
        var btn = e.target.closest('.dash__tab');
        if (!btn) { return; }
        var panel = btn.getAttribute('data-panel');
        activate(panel);
        try { history.replaceState(null, '', '#' + panel); } catch (x) { location.hash = panel; }
    });

    // Restore the tab from the hash on load (e.g. after switching the date range).
    activate((location.hash || '').replace(/^#/, ''));

    // Carry the current tab through the date-range links (they reload the page).
    document.querySelectorAll('.dash__range-btn').forEach(function (a) {
        a.addEventListener('click', function () {
            if (location.hash) { a.setAttribute('href', a.getAttribute('href').split('#')[0] + location.hash); }
        });
    });
})();
