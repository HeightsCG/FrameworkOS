$(document).ready(function() {
    $('#acctBtn').on('click', function (e) {
        var open = $('#acctMenu').toggleClass('is-open').hasClass('is-open');
        $(this).toggleClass('is-active', open).attr('aria-expanded', open);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#acctMenu, #acctBtn').length) {
            $('#acctMenu').removeClass('is-open');
            $('#acctBtn').removeClass('is-active').attr('aria-expanded', false);
        }
    });

    // Mobile nav: hamburger drops the sidebar menu down as a panel.
    $('#appNavToggle').on('click', function () {
        var open = $('.app-nav').toggleClass('is-open').hasClass('is-open');
        $(this).toggleClass('is-active', open).attr('aria-expanded', open);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.app-nav, #appNavToggle').length) {
            $('.app-nav').removeClass('is-open');
            $('#appNavToggle').removeClass('is-active').attr('aria-expanded', false);
        }
    });

    $(document).on('click', '.app-logout', function (e) {
        ApiDataSvc.apiCall('post', 'logout', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                window.location.reload();
            } else {
                toastr.error(o.message);
            }
        });
    });

    // Universal search (PRD §28) — live creator + content results under the top-chrome box.
    (function () {
        var $input = $('#app_search'), $panel = $('#appSearchPanel');
        if (!$input.length || !$panel.length) { return; }
        var timer = null;

        function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML; }
        function ini(n) { return (String(n || '?').trim().charAt(0) || '?').toUpperCase(); }
        function hide() { $panel.prop('hidden', true).empty(); }

        function render(o, q) {
            var creators = o.creators || [], posts = o.posts || [], h = '';
            if (!creators.length && !posts.length) {
                $panel.html('<div class="app-search__empty">No matches for &ldquo;' + esc(q) + '&rdquo;</div>').prop('hidden', false);
                return;
            }
            if (creators.length) {
                h += '<div class="app-search__group"><div class="app-search__group-label">Creators</div>';
                creators.forEach(function (c) {
                    var av = c.avatar
                        ? '<span class="app-search__av" style="background-image:url(\'' + esc(c.avatar) + '\')"></span>'
                        : '<span class="app-search__av">' + esc(ini(c.name)) + '</span>';
                    var badge = c.verified ? ' <i class="fa-solid fa-circle-check app-search__badge" title="Verified"></i>' : '';
                    h += '<a class="app-search__item" href="/@' + encodeURIComponent(c.handle) + '">' + av
                        + '<span class="app-search__body"><span class="app-search__name">' + esc(c.name) + badge + '</span>'
                        + '<span class="app-search__meta">@' + esc(c.handle) + '</span></span></a>';
                });
                h += '</div>';
            }
            if (posts.length) {
                h += '<div class="app-search__group"><div class="app-search__group-label">Content</div>';
                posts.forEach(function (p) {
                    h += '<a class="app-search__item" href="/@' + encodeURIComponent(p.creator_handle) + '">'
                        + '<span class="app-search__ic"><i class="fa-solid fa-image"></i></span>'
                        + '<span class="app-search__body"><span class="app-search__name">' + esc(p.caption || 'Untitled post') + '</span>'
                        + '<span class="app-search__meta">by @' + esc(p.creator_handle) + '</span></span></a>';
                });
                h += '</div>';
            }
            $panel.html(h).prop('hidden', false);
        }

        function search(q) {
            ApiDataSvc.apiCall('post', 'search', { q: q }, function (r) {
                var o = null; try { o = JSON.parse(r); } catch (e) {}
                if (o && o.success && ($input.val() || '').trim() === q) { render(o, q); }
            });
        }

        $input.on('input', function () {
            var q = (this.value || '').trim();
            clearTimeout(timer);
            // Ignore programmatic autofill (fires without the field being focused).
            if (q.length < 2 || document.activeElement !== this) { hide(); return; }
            timer = setTimeout(function () { search(q); }, 220);
        });
        $input.on('focus', function () {
            if ((this.value || '').trim().length >= 2 && $panel.children().length) { $panel.prop('hidden', false); }
        });
        $input.on('keydown', function (e) {
            if (e.key === 'Escape') { hide(); this.blur(); return; }
            var $items = $panel.find('.app-search__item');
            if (!$items.length) { return; }
            var idx = $items.index($items.filter('.is-active'));
            if (e.key === 'ArrowDown') { e.preventDefault(); idx = (idx + 1) % $items.length; }
            else if (e.key === 'ArrowUp') { e.preventDefault(); idx = (idx - 1 + $items.length) % $items.length; }
            else if (e.key === 'Enter') { if (idx >= 0) { window.location = $items.eq(idx).attr('href'); } return; }
            else { return; }
            $items.removeClass('is-active').eq(idx).addClass('is-active')[0].scrollIntoView({ block: 'nearest' });
        });
        $(document).on('click', function (e) { if (!$(e.target).closest('.app-search').length) { hide(); } });
    })();

    // In-platform notifications bell (PRD §27).
    (function () {
        var $btn = $('#notifBtn'), $panel = $('#notifPanel'), $badge = $('#notifBadge'), $list = $('#notifList');
        if (!$btn.length) { return; }
        function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML; }
        function ftime(iso) { if (!iso) { return ''; } var d = new Date(String(iso).replace(' ', 'T') + 'Z'); return isNaN(d) ? '' : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }
        function count() {
            ApiDataSvc.apiCall('post', 'notifications_unread_count', {}, function (r) {
                var o = null; try { o = JSON.parse(r); } catch (e) {}
                var n = (o && o.count) ? o.count : 0;
                if (n > 0) { $badge.text(n > 99 ? '99+' : n).prop('hidden', false); } else { $badge.prop('hidden', true); }
            });
        }
        function render(list) {
            if (!list.length) { $list.html('<div class="app-notif__empty">No notifications yet.</div>'); return; }
            var h = '';
            list.forEach(function (n) {
                var inner = '<span class="app-notif-item__ic"><i class="fa-solid ' + esc(n.icon || 'fa-bell') + '"></i></span>'
                    + '<span class="app-notif-item__body"><span class="app-notif-item__title">' + esc(n.title) + '</span>'
                    + (n.body ? '<span class="app-notif-item__text">' + esc(n.body) + '</span>' : '')
                    + '<span class="app-notif-item__time">' + ftime(n.created_at) + '</span></span>';
                var cls = 'app-notif-item' + (n.read ? '' : ' is-unread');
                h += n.link
                    ? '<a class="' + cls + '" href="' + esc(n.link) + '">' + inner + '</a>'
                    : '<div class="' + cls + '">' + inner + '</div>';
            });
            $list.html(h);
        }
        function load() { ApiDataSvc.apiCall('post', 'notifications_list', {}, function (r) { var o = null; try { o = JSON.parse(r); } catch (e) {} if (o && o.success) { render(o.notifications); } }); }
        $btn.on('click', function (e) {
            e.stopPropagation();
            if ($panel.prop('hidden')) {
                $panel.prop('hidden', false); load();
                ApiDataSvc.apiCall('post', 'notifications_mark_read', {}, function () { $badge.prop('hidden', true); });
            } else { $panel.prop('hidden', true); }
        });
        $('#notifMarkAll').on('click', function (e) { e.stopPropagation(); ApiDataSvc.apiCall('post', 'notifications_mark_read', {}, function () { $badge.prop('hidden', true); load(); }); });
        $(document).on('click', function (e) { if (!$(e.target).closest('#appNotif').length) { $panel.prop('hidden', true); } });
        count();
        setInterval(count, 30000);
    })();

    // Report (trust & safety, PRD §35–37) — any [data-report-type][data-report-id] element
    // opens a reason picker and files a report.
    $(document).on('click', '[data-report-type]', function (e) {
        e.preventDefault();
        var type = $(this).attr('data-report-type');
        var id = parseInt($(this).attr('data-report-id'), 10) || 0;
        if (!id || !window.Swal) { return; }
        Swal.fire({
            title: 'Report ' + (type === 'creator' ? 'this creator' : 'this content'),
            width: 460,
            html: '<div class="rpt-swal">'
                + '<label for="rptReason">Reason</label>'
                + '<select id="rptReason">'
                + '<option value="spam">Spam or scam</option>'
                + '<option value="harassment">Harassment or hate</option>'
                + '<option value="nudity">Unlabeled adult content</option>'
                + '<option value="illegal">Illegal or dangerous</option>'
                + '<option value="impersonation">Impersonation</option>'
                + '<option value="copyright">Copyright / DMCA</option>'
                + '<option value="other">Other</option>'
                + '</select>'
                + '<label for="rptDetails">Details <span class="rpt-swal__opt">(optional)</span></label>'
                + '<textarea id="rptDetails" rows="3" maxlength="2000" placeholder="Add any context that helps us review this."></textarea>'
                + '</div>',
            focusConfirm: false, showCancelButton: true, reverseButtons: true,
            confirmButtonText: 'Submit report', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779',
            preConfirm: function () {
                return { reason: document.getElementById('rptReason').value, details: document.getElementById('rptDetails').value };
            }
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) { return; }
            ApiDataSvc.apiCall('post', 'report_submit', { target_type: type, target_id: id, reason: r.value.reason, details: r.value.details }, function (resp) {
                var o = null; try { o = JSON.parse(resp); } catch (e) {}
                if (o && o.success) { if (window.toastr) { toastr.success('Thanks — our team will review this.'); } }
                else if (o && o.need_login) { window.location = '/'; }
                else if (window.toastr) { toastr.error((o && o.message) || 'Could not submit report'); }
            });
        });
    });

    // Global loading bar — shows on every AJAX request so users see activity.
    var $loadbar = $('<div id="app-loadbar"></div>').appendTo('body');
    $(document).ajaxStart(function () {
        $loadbar.removeClass('is-done').addClass('is-active');
    });
    $(document).ajaxStop(function () {
        $loadbar.removeClass('is-active').addClass('is-done');
    });

});