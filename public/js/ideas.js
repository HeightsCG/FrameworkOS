/* Ideas (/ideas): run the Fan Question Finder, poll the run, show themed ideas; past runs open the result or a Draft Post picker. */
$(function () {
    var $form = $('#ideRun'), $btn = $('#ideRunBtn'), $res = $('#ideResult'), $body = $('#ideResultBody');
    var poll_timer = null, polling_id = 0;
    var draft_modal = document.getElementById('ideDraftModal') ? bootstrap.Modal.getOrCreateInstance('#ideDraftModal') : null;

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function parse(d) { try { return JSON.parse(d); } catch (e) { return null; } }
    function compose_url(run_id, i) { return '/studio?compose=' + run_id + ':' + i; }
    function status_html(st) {
        var map = { done: ['on', 'Ready'], failed: ['off', 'Failed'] }, s = map[st] || ['draft', 'Running'];
        return '<span class="ev-status ev-status--' + s[0] + '"><span class="ev-status__dot"></span>' + s[1] + '</span>';
    }
    function set_running(on) {
        $btn.prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Finding Questions' : '<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Find Questions');
    }
    function update_row(o) {
        var $row = $('#ideRows .ide-row[data-run="' + o.run_id + '"]');
        if (!$row.length) { return; }
        $row.attr('data-status', o.status);
        $row.children().eq(1).text(o.status === 'done' ? o.ideas.length : 0);
        $row.children().eq(3).html(status_html(o.status));
    }
    function add_row(run_id, niche) {
        var $row = $('#ideRows .ide-row').first().clone();
        if (!$row.length) { window.location.reload(); return; }
        $row.attr({ 'data-run': run_id, 'data-status': 'queued' });
        $row.find('.ev-cell__name').text(niche);
        $row.children().eq(1).text('0');
        $row.children().eq(2).text('Just now');
        $row.children().eq(3).html(status_html('queued'));
        $('#ideRows').prepend($row);
        $('#ideEmpty').prop('hidden', true); $('#ideList').prop('hidden', false);
    }

    function show_loading(niche) {
        $('#ideResultTitle').text(niche);
        $('#ideResultSub').text('Reading the questions people ask. This takes a minute or two.');
        $body.html('<div class="ide-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Finding questions</div>');
        $res.prop('hidden', false);
    }
    function show_result(o) {
        $('#ideResultTitle').text(o.niche);
        if (o.status === 'failed') {
            $('#ideResultSub').text('');
            $body.html('<div class="ide-error"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> ' + esc(o.error || 'We could not finish this run. Try again in a minute.') + '</div>');
        } else {
            $('#ideResultSub').text(o.ideas.length + ' ideas from ' + o.questions_read + ' recent questions');
            var h = '<div class="ide-themes">', n = 0;
            (o.themes || []).forEach(function (t) {
                h += '<div class="ide-theme"><h3 class="ide-theme__t">' + esc(t.name) + '</h3><ul class="ide-ideas">';
                (t.ideas || []).forEach(function (idea) {
                    h += '<li><a class="ide-idea" href="' + compose_url(o.run_id, n) + '" title="Draft Post"><span>' + esc(idea) + '</span><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="visually-hidden">Draft Post</span></a></li>';
                    n++;
                });
                h += '</ul></div>';
            });
            h += '</div>';
            if ((o.subreddits || []).length) {
                h += '<h3 class="ide-theme__t ide-subs__t">Subreddits To Watch</h3><ul class="ide-subs">';
                o.subreddits.forEach(function (s) {
                    h += '<li><a href="https://www.reddit.com/r/' + encodeURIComponent(s.name) + '/" target="_blank" rel="noopener">r/' + esc(s.name) + '</a><span class="ide-subs__n">' + Number(s.members || 0).toLocaleString() + ' members</span>' + (s.why ? '<p>' + esc(s.why) + '</p>' : '') + '</li>';
                });
                h += '</ul>';
            }
            $body.html(h);
        }
        $res.prop('hidden', false);
    }

    function load(run_id, done) {
        ApiDataSvc.apiCall('post', 'tool_run_status', { run_id: run_id }, function (d) {
            var o = parse(d);
            if (!o || !o.success) { toastr.error((o && o.message) || 'Could not load that run.'); return; }
            done(o);
        });
    }
    function poll(run_id, tries) {
        clearTimeout(poll_timer); polling_id = run_id;
        load(run_id, function (o) {
            if (polling_id !== run_id) { return; }
            update_row(o);
            if (o.status === 'done' || o.status === 'failed') { set_running(false); polling_id = 0; show_result(o); if (o.status === 'failed') { toastr.error(o.error || 'That run failed.'); } return; }
            if (tries >= 75) { set_running(false); polling_id = 0; $('#ideResultSub').text('Still running in the background. Refresh in a minute to see the result.'); return; }
            poll_timer = setTimeout(function () { poll(run_id, tries + 1); }, 4000);
        });
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        var niche = $.trim($('#ideNiche').val());
        $('#ideNiche').removeClass('is-invalid');
        if (niche.length < 3) { $('#ideNiche').addClass('is-invalid').trigger('focus'); toastr.error('Add your niche'); return; }
        set_running(true);
        ApiDataSvc.apiCall('post', 'tool_run', { source: 'ideas', niche: niche }, function (d) {
            var o = parse(d);
            if (o && o.need_plan) { set_running(false); window.cls_need_plan(o); return; }
            if (!o || !o.success) { set_running(false); toastr.error((o && o.message) || 'Something went wrong. Please try again.'); return; }
            add_row(o.run_id, niche);
            show_loading(niche);
            poll(o.run_id, 0);
        });
    });

    function row_run(el) { return parseInt($(el).closest('.ide-row').attr('data-run'), 10) || 0; }
    function view(run_id) {
        var $row = $('#ideRows .ide-row[data-run="' + run_id + '"]');
        if ($row.attr('data-status') === 'queued' || $row.attr('data-status') === 'running') { show_loading($row.find('.ev-cell__name').text()); set_running(true); poll(run_id, 0); return; }
        load(run_id, function (o) { show_result(o); $('html, body').animate({ scrollTop: $res.offset().top - 80 }, 200); });
    }
    $('#ideRows').on('click', '.ide-row', function (e) {
        if ($(e.target).closest('.dropdown').length) { return; }
        view(row_run(this));
    });
    $('#ideRows').on('keydown', '.ide-row', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target === this) { e.preventDefault(); view(row_run(this)); }
    });
    $('#ideRows').on('click', '[data-ide-view]', function () { view(row_run(this)); });
    $('#ideRows').on('click', '[data-ide-draft]', function () {
        var run_id = row_run(this);
        load(run_id, function (o) {
            if (o.status !== 'done' || !o.ideas.length) { toastr.info(o.status === 'failed' ? 'That run failed, so it has no ideas.' : 'This run is still finding questions.'); return; }
            var h = '<ul class="ide-pick">';
            o.ideas.forEach(function (idea, i) { h += '<li><a class="ide-idea" href="' + compose_url(o.run_id, i) + '"><span>' + esc(idea) + '</span><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></a></li>'; });
            $('#ideDraftBody').html(h + '</ul>');
            if (draft_modal) { draft_modal.show(); }
        });
    });
    $('#ideResultClose').on('click', function () { $res.prop('hidden', true); });

    // a run still going when the page loads (a refresh mid-run) picks up where it was
    var $live = $('#ideRows .ide-row[data-status="queued"], #ideRows .ide-row[data-status="running"]').first();
    if ($live.length) { view(parseInt($live.attr('data-run'), 10)); }
});
