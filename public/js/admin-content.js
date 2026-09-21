/* Admin › Content: keyword queue + article editor (SEO content engine). */
(function () {
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function ok(msg) { if (window.toastr) { toastr.success(msg); } }
    function bad(msg) { if (window.toastr) { toastr.error(msg || 'Something went wrong'); } }
    function confirmAction(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#5b4be0', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed ? (r.value === undefined ? true : r.value) : false; });
    }

    /* ---- Keyword queue (Content tab) ---- */
    var kwAdd = document.getElementById('admKwAdd');
    if (kwAdd) {
        kwAdd.addEventListener('submit', function (e) {
            e.preventDefault();
            var f = new FormData(kwAdd);
            ApiDataSvc.apiCall('post', 'seo_keyword_add', { keyword: f.get('keyword'), volume: f.get('volume'), difficulty: f.get('difficulty'), priority: f.get('priority') }, function (r) {
                var o = parse(r); if (!o || !o.success) { return bad(o && o.message); }
                ok('Keyword added'); window.location.reload();
            });
        });
    }
    var kws = document.getElementById('admKeywords');
    if (kws) {
        kws.addEventListener('change', function (e) {
            var inp = e.target.closest('.adm-kprio'); if (!inp) { return; }
            var id = parseInt(inp.closest('.adm-krow').getAttribute('data-keyword'), 10);
            ApiDataSvc.apiCall('post', 'seo_keyword_update', { id: id, priority: inp.value }, function (r) { var o = parse(r); o && o.success ? ok('Priority saved') : bad(o && o.message); });
        });
        kws.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-kw-action]'); if (!btn) { return; }
            var row = btn.closest('.adm-krow'); var id = parseInt(row.getAttribute('data-keyword'), 10); var action = btn.getAttribute('data-kw-action');
            if (action === 'skip' || action === 'requeue') {
                ApiDataSvc.apiCall('post', 'seo_keyword_update', { id: id, status: action === 'skip' ? 'skipped' : 'queued' }, function (r) { var o = parse(r); if (o && o.success) { window.location.reload(); } else { bad(o && o.message); } });
                return;
            }
            if (action === 'draft') {
                btn.disabled = true; btn.textContent = 'Drafting…';
                ApiDataSvc.apiCall('post', 'seo_draft_now', { keyword_id: id }, function (r) {
                    var o = parse(r);
                    if (o && o.success) { ok('Draft ready'); window.location = o.url; }
                    else { btn.disabled = false; btn.textContent = 'Draft Now'; bad(o && o.message); }
                });
            }
        });
    }
    document.querySelectorAll('[data-art-action="unpublish"]').forEach(function (b) {
        b.addEventListener('click', function () {
            var id = parseInt(b.closest('.adm-crow').getAttribute('data-article'), 10);
            confirmAction({ title: 'Unpublish this article?', text: 'It goes back to the review queue and drops out of the sitemap.', confirmButtonText: 'Unpublish' }).then(function (yes) {
                if (!yes) { return; }
                ApiDataSvc.apiCall('post', 'seo_article_unpublish', { id: id }, function (r) { var o = parse(r); if (o && o.success) { window.location.reload(); } else { bad(o && o.message); } });
            });
        });
    });

    /* ---- Editor ---- */
    var ed = document.getElementById('admEditor');
    if (!ed) { return; }
    var id = parseInt(ed.getAttribute('data-article'), 10);
    var form = document.getElementById('admArticleForm');
    var issues = document.getElementById('admIssues');
    var preview = document.getElementById('admPreview');
    function faqJson() {
        var out = [];
        document.querySelectorAll('#admFaq .adm-faq__row').forEach(function (row) {
            var q = row.querySelector('[data-faq-q]').value.trim(), a = row.querySelector('[data-faq-a]').value.trim();
            if (q || a) { out.push({ q: q, a: a }); }
        });
        return JSON.stringify(out);
    }
    function showIssues(list) {
        issues.innerHTML = '';
        (list || []).forEach(function (t) { var li = document.createElement('li'); li.textContent = t; issues.appendChild(li); });
        issues.hidden = !(list && list.length);
    }
    function save(cb) {
        var f = new FormData(form);
        ApiDataSvc.apiCall('post', 'seo_article_save', { id: id, title: f.get('title'), slug: f.get('slug'), meta_description: f.get('meta_description'), excerpt: f.get('excerpt'), secondary_keywords: f.get('secondary_keywords'), body_md: f.get('body_md'), faq: faqJson() }, function (r) {
            var o = parse(r); if (!o || !o.success) { if (o && o.errors) { showIssues(o.errors); } return bad(o && o.message); }
            showIssues(o.errors); ok(o.message);
            if (o.slug) { ed.setAttribute('data-slug', o.slug); preview.src = '/blog/' + o.slug + '?preview=1&t=' + Date.now(); }
            if (cb) { cb(o); }
        });
    }
    document.getElementById('admFaq').addEventListener('click', function (e) {
        if (e.target.closest('[data-faq-add]')) {
            var row = document.createElement('div'); row.className = 'adm-faq__row';
            row.innerHTML = '<input type="text" placeholder="Question" data-faq-q><textarea rows="2" placeholder="Answer" data-faq-a></textarea><button type="button" class="adm-btn adm-btn--danger" data-faq-del aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>';
            this.appendChild(row); return;
        }
        var del = e.target.closest('[data-faq-del]'); if (del) { del.closest('.adm-faq__row').remove(); }
    });
    ed.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ed]'); if (!btn) { return; }
        var action = btn.getAttribute('data-ed');
        if (action === 'save') { return save(); }
        if (action === 'publish') {
            save(function (o) {
                if (o.errors && o.errors.length) { return bad('Fix the issues listed before publishing'); }
                ApiDataSvc.apiCall('post', 'seo_article_publish', { id: id }, function (r) { var p = parse(r); if (p && p.success) { ok('Published'); window.location = p.url; } else { showIssues(p && p.errors); bad(p && p.message); } });
            }); return;
        }
        if (action === 'unpublish') {
            ApiDataSvc.apiCall('post', 'seo_article_unpublish', { id: id }, function (r) { var p = parse(r); p && p.success ? window.location.reload() : bad(p && p.message); }); return;
        }
        if (action === 'rewrite') {
            confirmAction({ title: 'Request a rewrite', input: 'textarea', inputPlaceholder: 'What should change', inputValidator: function (v) { return v && v.trim() ? undefined : 'Say what should change'; }, confirmButtonText: 'Rewrite' }).then(function (note) {
                if (!note) { return; }
                btn.disabled = true; btn.textContent = 'Rewriting…';
                ApiDataSvc.apiCall('post', 'seo_article_rewrite', { id: id, note: note }, function (r) { var p = parse(r); if (p && p.success) { window.location.reload(); } else { btn.disabled = false; btn.textContent = 'Request Rewrite'; bad(p && p.message); } });
            }); return;
        }
        if (action === 'cover') {
            btn.disabled = true; btn.textContent = 'Making Cover…';
            ApiDataSvc.apiCall('post', 'seo_article_cover', { id: id }, function (r) {
                var p = parse(r); btn.disabled = false; btn.textContent = 'New Cover';
                if (!p || !p.success) { return bad(p && p.message); }
                var cur = document.getElementById('admCoverImg');
                var img = document.createElement('img'); img.className = 'adm-cover__img'; img.id = 'admCoverImg'; img.alt = ''; img.src = p.url;
                cur.parentNode.replaceChild(img, cur);
                preview.src = '/blog/' + ed.getAttribute('data-slug') + '?preview=1&t=' + Date.now();
                ok(p.message);
            });
            return;
        }
        if (action === 'discard') {
            confirmAction({ title: 'Discard this article?', text: 'It is archived and the keyword goes back into the queue.', confirmButtonText: 'Discard', confirmButtonColor: '#e5484d' }).then(function (yes) {
                if (!yes) { return; }
                ApiDataSvc.apiCall('post', 'seo_article_discard', { id: id }, function (r) { var p = parse(r); if (p && p.success) { window.location = '/admin'; } else { bad(p && p.message); } });
            });
        }
    });
})();
