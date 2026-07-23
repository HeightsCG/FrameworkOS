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
                    h += '<a class="app-search__item" href="/@' + encodeURIComponent(c.handle) + '">' + av
                        + '<span class="app-search__body"><span class="app-search__name">' + esc(c.name)
                        + ' <i class="fa-solid fa-circle-check app-search__badge"></i></span>'
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

    // Global loading bar — shows on every AJAX request so users see activity.
    var $loadbar = $('<div id="app-loadbar"></div>').appendTo('body');
    $(document).ajaxStart(function () {
        $loadbar.removeClass('is-done').addClass('is-active');
    });
    $(document).ajaxStop(function () {
        $loadbar.removeClass('is-active').addClass('is-done');
    });

});