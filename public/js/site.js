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

        function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
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
        function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
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

    // Creator setup widget: open/collapse (remembered per browser) and per-step skip.
    (function () {
        var $w = $('#setupWidget'); if (!$w.length) { return; }
        var KEY = 'cls_setup_open', open = false;
        try { open = localStorage.getItem(KEY) === '1'; } catch (e) {}
        function set(on) {
            open = !!on;
            $w.attr('data-open', open ? '1' : '0');
            $('#setupWidgetPanel').prop('hidden', !open);
            $w.find('[data-setup-toggle][aria-expanded]').attr('aria-expanded', open ? 'true' : 'false');
            try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) {}
        }
        set(open);
        document.body.classList.add('has-setup-widget');
        $w.on('click', '[data-setup-toggle]', function () { set(!open); });
        // Clicking anywhere else collapses the panel so it never sits over what you're working on.
        $(document).on('mousedown', function (e) {
            if (open && !$(e.target).closest('#setupWidget, .swal2-container').length) { set(false); }
        });
        // After a save that can complete a step, re-read progress and tick the widget in place.
        var REFRESH_ON = /\/api\/(inbox_settings_save|save_creator_profile|upload_creator_image|save_brand_identity|save_creator_plan|toggle_creator_plan|connect_account|start_payout_onboarding|post_publish|post_schedule|auto_message_save)\b/;
        function applyProgress(o) {
            var d = o.required_done, t = o.required_total, pct = t > 0 ? Math.round(d / t * 100) : 0;
            (o.steps || []).forEach(function (s) {
                var $li = $w.find('.setup-widget__step[data-step="' + s.key + '"]');
                if (!$li.length) { return; }
                if (s.done && !$li.hasClass('is-done')) {
                    $li.addClass('is-done').find('.setup-widget__mark').html('<i class="fa-solid fa-check"></i>');
                    $li.find('.setup-widget__skip').remove();
                }
            });
            $w.find('.setup-widget__ring span').text(d + '/' + t);
            $w.find('.setup-widget__ring').css('--pct', pct); $w.find('.setup-widget__fill').css('width', pct + '%');
            if (o.complete) {
                $w.find('.setup-widget__title, .setup-widget__pilltext').text('You\u2019re all set');
                $w.find('.setup-widget__count').text('All ' + t + ' steps done');
                if (!$w.find('.setup-widget__done').length) {
                    $w.find('.setup-widget__foot').append('<button type="button" class="btn btn-primary btn-sm setup-widget__done" data-setup-dismiss data-setup-complete="1">Done</button>');
                }
            } else {
                $w.find('.setup-widget__count').text(d + ' of ' + t + ' steps done');
            }
        }
        $(document).on('ajaxSuccess', function (e, xhr, settings) {
            if (!settings || !settings.url || !REFRESH_ON.test(settings.url)) { return; }
            ApiDataSvc.apiCall('post', 'setup_progress', {}, function (resp) {
                var o = null; try { o = JSON.parse(resp); } catch (err) {}
                if (o && o.success) { applyProgress(o); }
            });
        });
        $w.on('click', '[data-setup-skip]', function () {
            var key = $(this).attr('data-setup-skip'), $li = $(this).closest('.setup-widget__step');
            ApiDataSvc.apiCall('post', 'setup_skip_step', { key: key }, function (resp) {
                var o = null; try { o = JSON.parse(resp); } catch (err) {}
                if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not skip that step.'); } return; }
                $li.slideUp(140, function () { $(this).remove(); });
                applyProgress(o);
            });
        });
    })();

    // Creator setup checklist: hide the widget (and /setup's "Hide this checklist") after a confirm.
    $(document).on('click', '[data-setup-dismiss]', function (e) {
        e.preventDefault();
        function hide() {
            ApiDataSvc.apiCall('post', 'setup_dismiss', {}, function (resp) {
                var o = null; try { o = JSON.parse(resp); } catch (err) {}
                if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not hide the checklist.'); } return; }
                $('#setupWidget').fadeOut(160, function () { $(this).remove(); document.body.classList.remove('has-setup-widget'); });
                $('.setup__foot').remove();
                if (window.toastr) { toastr.success('Checklist hidden. Find it any time at /setup.'); }
            });
        }
        if (typeof Swal === 'undefined') { return; }
        var complete = $(this).is('[data-setup-complete]');   // "Done" on a finished checklist vs hiding an unfinished one
        Swal.fire({
            title: complete ? 'All set?' : 'Hide the setup checklist?',
            text: complete ? 'The checklist closes for good. Every step stays reachable at /setup and in Settings.'
                           : 'You can still open it any time at /setup. It won\u2019t come back on its own.',
            width: 440, showCancelButton: true, reverseButtons: true,
            confirmButtonText: complete ? 'I\u2019m Finished' : 'Hide Checklist', cancelButtonText: complete ? 'Not Yet' : 'Keep',
            confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779'
        }).then(function (r) { if (r.isConfirmed) { hide(); } });
    });

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

    // Block — any [data-block-user="<id>"] element (profile, inbox thread, audience row).
    // Confirms, posts block_user, then fires 'cls:blocked' so the page can drop the account from view.
    $(document).on('click', '[data-block-user]', function (e) {
        e.preventDefault();
        var $el = $(this);
        var id = parseInt($el.attr('data-block-user'), 10) || 0;
        var name = $el.attr('data-block-name') || 'this account';
        var redirect = $el.attr('data-block-redirect') || '';
        if (!id || !window.Swal) { return; }
        Swal.fire({
            titleText: 'Block ' + name + '?',
            text: 'They won\'t be able to see your page or posts, follow, subscribe, buy from you, or message you, and you won\'t see them. You can unblock from Settings.',
            width: 460,
            showCancelButton: true, reverseButtons: true,
            confirmButtonText: 'Block', cancelButtonText: 'Cancel',
            confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779'
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            ApiDataSvc.apiCall('post', 'block_user', { user_id: id }, function (resp) {
                var o = null; try { o = JSON.parse(resp); } catch (err) {}
                if (o && o.success) {
                    if (window.toastr) { toastr.success('Blocked ' + $('<div>').text(name).html()); }
                    $(document).trigger('cls:blocked', [id]);
                    if (redirect) { window.location = redirect; }
                } else if (o && o.need_login) { window.location = '/'; }
                else if (window.toastr) { toastr.error((o && o.message) || 'Could not block this account'); }
            });
        });
    });

    // Walkthrough videos — any [data-tutorial] element (Tutorials::button, /support list) opens
    // the player. It can open over another modal (post editor, automation editor), so it sits above it.
    $(document).on('click', '[data-tutorial]', function (e) {
        e.preventDefault();
        if (!window.bootstrap) { return; }
        var $el = $(this);
        var $m = $('#tutModal');
        if (!$m.length) {
            $m = $('<div class="modal fade tut-modal" id="tutModal" tabindex="-1" aria-hidden="true" aria-labelledby="tutModalTitle">'
                + '<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">'
                + '<div class="modal-header"><h5 class="modal-title" id="tutModalTitle"></h5>'
                + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
                + '<div class="modal-body tut-modal__body"><video class="tut-modal__video" controls playsinline preload="metadata"></video>'
                + '<details class="tut-modal__steps"><summary>Transcript<i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><ol></ol></details>'
                + '</div></div></div></div>').appendTo('body');
            $m.on('shown.bs.modal', function () {
                var v = $m.find('video')[0];
                v.focus();   // take focus from the editor underneath, so Escape closes the video and not the editor
                var p = v.play(); if (p && p.catch) { p.catch(function () {}); }
            });
            $m.on('hidden.bs.modal', function () {
                var v = $m.find('video')[0]; v.pause(); v.removeAttribute('src'); v.load();
                if ($('.modal.show').length) { $('body').addClass('modal-open'); }   // keep the modal underneath scroll-locked
                var opener = $m.data('opener'); if (opener && document.body.contains(opener)) { opener.focus(); }
            });
        }
        var steps = []; try { steps = JSON.parse($el.attr('data-tut-steps') || '[]'); } catch (err) {}
        $m.data('opener', this);
        $m.find('.modal-title').text($el.attr('data-tut-title') || 'Walkthrough');
        $m.find('video').attr({ src: $el.attr('data-tut-src'), poster: $el.attr('data-tut-poster') || null });
        $m.find('.tut-modal__steps').prop('open', false).prop('hidden', !steps.length)
            .find('ol').empty().append(steps.map(function (s) { return $('<li>').text(s); }));
        bootstrap.Modal.getOrCreateInstance($m[0]).show();
        $('.modal-backdrop').last().addClass('tut-backdrop');
    });

    // Global loading bar — shows on every AJAX request so users see activity.
    var $loadbar = $('<div id="app-loadbar"></div>').appendTo('body');
    $(document).ajaxStart(function () {
        $loadbar.removeClass('is-done').addClass('is-active');
    });
    $(document).ajaxStop(function () {
        $loadbar.removeClass('is-active').addClass('is-done');
    });


    // Safety net for every /api/ call started from a button: if the server answers with an error or a non-JSON page,
    // the page's own callback may never re-enable the button (or throws parsing it). Put the button back.
    $(document).ajaxSend(function (e, xhr, s) {
        if (!s || !/\/api\//.test(s.url || '')) { return; }
        var b = document.activeElement;
        if (b && (b.tagName === 'BUTTON' || (b.tagName === 'INPUT' && /submit|button/i.test(b.type)))) { xhr._clsBtn = b; xhr._clsHtml = b.innerHTML; }
    });
    $(document).ajaxComplete(function (e, xhr) {
        var b = xhr._clsBtn; if (!b) { return; }
        var ok = xhr.status >= 200 && xhr.status < 300, json = true;
        try { JSON.parse(xhr.responseText); } catch (x) { json = false; }
        if (ok && json) { return; }
        setTimeout(function () {   // after the page's own handler ran (or threw)
            if (b.disabled) { b.disabled = false; if (b.tagName === 'BUTTON') { b.innerHTML = xhr._clsHtml; } }
            if (ok && window.toastr && !document.querySelector('#toast-container .toast-error')) { toastr.error('Something went wrong. Please try again.'); }   // unless the page already said so
        }, 0);
    });

});
