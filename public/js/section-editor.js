/* Section editor (.cs-ae-modal): the approved editor shell's behaviour for simple editors (Events, Services).
   Left nav + mobile <select> switch one section at a time; segmented controls (.cs-ae__seg) write into a hidden
   input; live nav summaries; inline errors that flag their section. */
window.SectionEditor = function (modalEl) {
    var navItems = modalEl.querySelectorAll('.cs-ae__navitem');
    var navSelect = modalEl.querySelector('.cs-ae__navselect');
    var sections = modalEl.querySelectorAll('.cs-ae__section');

    function show(key, focusHeading) {
        sections.forEach(function (s) { s.hidden = (s.getAttribute('data-section') !== key); });
        navItems.forEach(function (b) {
            if (b.getAttribute('data-section') === key) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); }
        });
        if (navSelect) { navSelect.value = key; }
        var main = modalEl.querySelector('.cs-ae__main'); if (main) { main.scrollTop = 0; }
        if (focusHeading) { var h = modalEl.querySelector('.cs-ae__section[data-section="' + key + '"] .cs-ae__h'); if (h) { h.focus(); } }
    }
    navItems.forEach(function (b, i) {
        b.addEventListener('click', function () { show(b.getAttribute('data-section'), true); });
        b.addEventListener('keydown', function (e) {
            var n = (e.key === 'ArrowDown') ? i + 1 : (e.key === 'ArrowUp') ? i - 1 : -1;
            if (n < 0 || n >= navItems.length) { return; }
            e.preventDefault(); navItems[n].focus(); show(navItems[n].getAttribute('data-section'), false);
        });
    });
    if (navSelect) { navSelect.addEventListener('change', function () { show(navSelect.value, true); }); }

    /* Segmented control bound to a hidden input: seg(groupEl, inputEl, attr, onChange). */
    function seg(group, input, attr, onChange) {
        function set(v) {
            input.value = v;
            group.querySelectorAll('.cs-seg__opt').forEach(function (o) {
                var on = (o.getAttribute(attr) === v);
                o.classList.toggle('is-on', on); o.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (onChange) { onChange(v); }
        }
        group.addEventListener('click', function (e) {
            var o = e.target.closest('.cs-seg__opt'); if (!o || o.disabled) { return; }
            set(o.getAttribute(attr));
        });
        return set;
    }

    function error(field, msg) {
        var input = modalEl.querySelector('#' + field.input), p = modalEl.querySelector('#' + field.err);
        if (input) { input.classList.toggle('is-invalid', !!msg); }
        if (p) { p.textContent = msg || ''; p.hidden = !msg; }
    }
    function clearErrors() {
        modalEl.querySelectorAll('.cs-ae__error').forEach(function (p) { p.textContent = ''; p.hidden = true; });
        modalEl.querySelectorAll('.is-invalid').forEach(function (i) { i.classList.remove('is-invalid'); });
        modalEl.querySelectorAll('.cs-ae__navflag').forEach(function (f) { f.hidden = true; });
    }
    /* errors: [{section, input, err, msg}] — shows each, flags its section, opens + focuses the first. */
    function showErrors(errors) {
        clearErrors();
        errors.forEach(function (x) {
            error(x, x.msg);
            var flag = modalEl.querySelector('.cs-ae__navitem[data-section="' + x.section + '"] .cs-ae__navflag'); if (flag) { flag.hidden = false; }
        });
        if (errors.length) {
            show(errors[0].section, false);
            var first = modalEl.querySelector('#' + errors[0].input); if (first) { first.focus(); }
        }
    }
    function summary(key, text) {
        var s = modalEl.querySelector('.cs-ae__navsum[data-sum="' + key + '"]'); if (s) { s.textContent = text || ''; }
    }
    function reveal(el, on) { if (el) { el.hidden = !on; } }

    return { show: show, seg: seg, showErrors: showErrors, clearErrors: clearErrors, summary: summary, reveal: reveal, first: navItems.length ? navItems[0].getAttribute('data-section') : '' };
};
