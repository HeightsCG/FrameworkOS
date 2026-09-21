/* Audience / CRM page: filter, search, expand, tag and note each fan, message via widget. */
(function () {
    var list = document.getElementById('audList');
    if (!list) { return; }
    var filters = document.getElementById('audFilters');
    var search  = document.getElementById('audSearch');
    var none    = document.getElementById('audNone');
    var seg = 'all';

    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function segAttr(s) { return s === 'followers' ? 'follower' : s === 'subscribers' ? 'subscriber' : s === 'buyers' ? 'buyer' : 'all'; }

    function applyFilter() {
        var q = (search.value || '').trim().toLowerCase();
        var shown = 0;
        list.querySelectorAll('.aud-row').forEach(function (row) {
            var segOk = seg === 'all' || row.getAttribute('data-' + segAttr(seg)) === '1';
            var qOk   = q === '' || (row.getAttribute('data-search') || '').indexOf(q) >= 0;
            var vis   = segOk && qOk;
            row.style.display = vis ? '' : 'none';
            if (vis) { shown++; }
        });
        if (none) { none.hidden = shown > 0; }
    }

    filters.addEventListener('click', function (e) {
        var b = e.target.closest('.aud__chip'); if (!b) { return; }
        seg = b.getAttribute('data-seg');
        filters.querySelectorAll('.aud__chip').forEach(function (c) { c.classList.toggle('is-on', c === b); });
        applyFilter();
    });
    search.addEventListener('input', applyFilter);

    function refreshSearch(row) {
        var tags = [];
        row.querySelectorAll('.aud-tag').forEach(function (t) { tags.push((t.getAttribute('data-tag') || '').toLowerCase()); });
        row.setAttribute('data-search', ((row.getAttribute('data-search-base') || '') + ' ' + tags.join(' ')).trim());
    }

    function addChip(input, tag) {
        var span = document.createElement('span');
        span.className = 'aud-tag';
        span.setAttribute('data-tag', tag);
        span.textContent = tag;
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'aud-tag__x'; btn.setAttribute('aria-label', 'Remove tag');
        btn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        span.appendChild(btn);
        input.parentNode.insertBefore(span, input);
    }

    list.addEventListener('click', function (e) {
        var expand = e.target.closest('[data-expand]');
        if (expand) {
            var row = expand.closest('.aud-row');
            var open = row.classList.toggle('is-open');
            row.querySelector('.aud-row__detail').hidden = !open;
            return;
        }
        var msg = e.target.closest('[data-msg]');
        if (msg) {
            var id = parseInt(msg.getAttribute('data-msg'), 10);
            if (window.CLSMessenger && id) { window.CLSMessenger.openWith(id); }
            return;
        }
        var x = e.target.closest('.aud-tag__x');
        if (x) {
            var tagEl = x.closest('.aud-tag');
            var row2  = x.closest('.aud-row');
            var fan   = parseInt(row2.getAttribute('data-fan'), 10);
            var tag   = tagEl.getAttribute('data-tag');
            ApiDataSvc.apiCall('post', 'audience_tag_remove', { fan_id: fan, tag: tag }, function (resp) {
                var o = parse(resp);
                if (o && o.success) { tagEl.parentNode.removeChild(tagEl); refreshSearch(row2); applyFilter(); }
            });
            return;
        }
        var sv = e.target.closest('[data-note-save]');
        if (sv) {
            var row3 = sv.closest('.aud-row');
            var fan3 = parseInt(row3.getAttribute('data-fan'), 10);
            var note = row3.querySelector('.aud-note').value;
            sv.disabled = true;
            ApiDataSvc.apiCall('post', 'audience_note_save', { fan_id: fan3, note: note }, function (resp) {
                sv.disabled = false;
                var o = parse(resp);
                if (o && o.success) { if (window.toastr) { toastr.success('Note saved'); } }
                else if (window.toastr) { toastr.error((o && o.message) || 'Could not save note'); }
            });
            return;
        }
    });

    list.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        var input = e.target.closest('.aud-tag__input');
        if (!input) { return; }
        e.preventDefault();
        var tag = (input.value || '').trim();
        if (tag === '') { return; }
        var row = input.closest('.aud-row');
        var fan = parseInt(row.getAttribute('data-fan'), 10);
        var dup = false;
        row.querySelectorAll('.aud-tag').forEach(function (t) { if ((t.getAttribute('data-tag') || '').toLowerCase() === tag.toLowerCase()) { dup = true; } });
        if (dup) { input.value = ''; return; }
        ApiDataSvc.apiCall('post', 'audience_tag_add', { fan_id: fan, tag: tag }, function (resp) {
            var o = parse(resp);
            if (o && o.success) { addChip(input, tag); input.value = ''; refreshSearch(row); }
            else if (window.toastr) { toastr.error((o && o.message) || 'Could not add tag'); }
        });
    });

    // A blocked member leaves the list right away (site.js fires this after block_user succeeds).
    $(document).on('cls:blocked', function (e, id) {
        var row = document.querySelector('.aud-row[data-fan="' + id + '"]');
        if (row) { row.parentNode.removeChild(row); applyFilter(); }
    });
})();
