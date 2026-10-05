/* Launch Campaign (Audience page): set up the launch, review and edit the written drafts, then schedule everything at once. */
$(function () {
    var $modal = $('#lcModal');
    if (!$modal.length) { return; }
    var opts = null, busy = false;

    function parse(r) { try { return (typeof r === 'string') ? JSON.parse(r) : r; } catch (e) { return null; } }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function api(action, body, cb) { ApiDataSvc.apiCall('post', action, body, function (resp) { cb(parse(resp)); }); }
    function error(msg) { $('#lcError').text(msg || '').prop('hidden', !msg); }
    function fail(o, fallback) {
        if (o && o.need_plan) { window.location = '/account/billing'; return; }
        error((o && o.message) || fallback);
    }

    function stage(name) {   // loading | load_error | form | review
        $('#lcLoading').prop('hidden', name !== 'loading');
        $('#lcLoadError').prop('hidden', name !== 'load_error');
        $('#lcForm').prop('hidden', name !== 'form');
        $('#lcReview').prop('hidden', name !== 'review');
        $('#lcWrite').prop('hidden', name === 'review');
        $('#lcConfirm, #lcBack').prop('hidden', name !== 'review');
        $('#lcCancel').prop('hidden', name === 'review');
        $('#lcSummary').text('');
        error('');
        if (name === 'form') { can_write(); } else if (name !== 'review') { $('#lcWrite').prop('disabled', true); }
    }
    function can_write() { $('#lcWrite').prop('disabled', busy || $('#lcWhat').val().trim() == '' || $('#lcAt').val() == ''); }

    function load() {
        stage('loading');
        api('launch_campaign_options', {}, function (o) {
            if (!o || !o.success) { if (o && o.need_plan) { window.location = '/account/billing'; return; } stage('load_error'); return; }
            opts = o;
            var days = '';
            for (var d = 0; d <= o.max_days; d++) { days += '<option value="' + d + '"' + (d === 3 ? ' selected' : '') + '>' + (d === 0 ? 'None' : d + (d === 1 ? ' Day' : ' Days')) + '</option>'; }
            $('#lcDays').html(days);
            $('#lcAt').val(o.launch_at);
            $('#lcInfl').html('<option value="0">Yourself</option>' + (o.influencers || []).map(function (i) { return '<option value="' + i.id + '">' + esc(i.name) + '</option>'; }).join(''));
            $('#lcInflWrap').prop('hidden', !(o.influencers || []).length);
            $('#lcDest').html('<option value="cls">Creator Link Studio</option>' + (o.fanvue ? '<option value="fanvue">Creator Link Studio And Fanvue</option>' : ''));
            var segs = '';
            $.each(o.labels || {}, function (key, label) {
                if (key === 'all') { return; }
                var n = (o.counts && o.counts[key] != null) ? o.counts[key] : 0;
                segs += '<label class="lc__check"><input class="form-check-input" type="checkbox" value="' + esc(key) + '"' + (key === 'followers' || key === 'subscribers' ? ' checked' : '') + '> ' + esc(label) + ' <small>' + n + '</small></label>';
            });
            $('#lcSegs').html(segs);
            $('#lcAccs').html((o.accounts || []).map(function (a) { return '<label class="lc__check"><input class="form-check-input" type="checkbox" value="' + esc(a.id) + '"> ' + esc(a.name) + ' <small>' + esc(a.platform) + '</small></label>'; }).join(''));
            $('#lcAccWrap').prop('hidden', !(o.accounts || []).length);
            stage('form');
        });
    }

    function setup() {
        return {
            what: $('#lcWhat').val().trim(), launch_at: $('#lcAt').val(), days: $('#lcDays').val(), influencer_id: $('#lcInfl').val() || 0,
            destination: $('#lcDest').val() || 'cls', promo_code: $('#lcCode').val().trim(), promo_percent: $('#lcPct').val() || 0, promo_days: $('#lcValid').val() || 7,
            segments: $('#lcSegs input:checked').map(function () { return this.value; }).get(),
            share_accounts: $('#lcAccs input:checked').map(function () { return this.value; }).get()
        };
    }

    function render_items(items) {
        $('#lcItems').html(items.map(function (it, i) {
            return '<div class="lc__item" data-i="' + i + '" data-key="' + esc(it.key) + '" data-kind="' + esc(it.kind) + '" data-type="' + esc(it.type) + '">' +
                '<div class="lc__itemhead"><span class="lc__itemlabel"><i class="fa-solid ' + (it.type === 'message' ? 'fa-envelope' : 'fa-image') + '" aria-hidden="true"></i>' + esc(it.label) + '</span>' +
                '<label class="visually-hidden" for="lcItemAt' + i + '">Time</label><input type="datetime-local" class="form-control" id="lcItemAt' + i + '" data-at value="' + esc(it.at) + '"></div>' +
                '<div><label class="visually-hidden" for="lcItemText' + i + '">Text</label><textarea class="form-control" id="lcItemText' + i + '" data-text rows="3" maxlength="' + (it.type === 'message' ? 2000 : 3000) + '">' + esc(it.text) + '</textarea></div>' +
                '</div>';
        }).join(''));
        summary();
    }
    function collect() {
        return $('#lcItems .lc__item').map(function () {
            var $i = $(this);
            return { key: $i.data('key'), kind: $i.data('kind'), type: $i.data('type'), at: $i.find('[data-at]').val(), text: $i.find('[data-text]').val() };
        }).get();
    }
    function summary() {
        var posts = 0, msgs = 0;
        $.each(collect(), function (i, it) { if (String(it.text).trim() == '') { return; } if (it.type === 'message') { msgs++; } else { posts++; } });
        $('#lcSummary').text(posts + (posts === 1 ? ' post' : ' posts') + ', ' + msgs + (msgs === 1 ? ' message' : ' messages'));
        $('#lcConfirm').prop('disabled', busy || (posts + msgs) === 0);
    }

    $('#lcOpen').on('click', function () { bootstrap.Modal.getOrCreateInstance($modal[0]).show(); if (!opts) { load(); } });
    $('#lcRetry').on('click', load);
    $('#lcWhat, #lcAt').on('input change', can_write);
    $('#lcCode').on('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        $('#lcPct, #lcValid').prop('disabled', this.value == '');
    });

    $('#lcWrite').on('click', function () {
        var s = setup();
        if (s.promo_code != '' && !(parseInt(s.promo_percent, 10) >= 1 && parseInt(s.promo_percent, 10) <= 100)) { error('Set the promo discount between 1 and 100 percent.'); $('#lcPct').trigger('focus'); return; }
        var $b = $(this), html = $b.html();
        busy = true; error('');
        $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status"></span> Writing');
        api('launch_campaign_draft', s, function (o) {
            busy = false; $b.html(html);
            if (!o || !o.success) { can_write(); fail(o, 'Could not write the drafts right now.'); return; }
            stage('review');
            var notes = [];
            if (!o.written) { notes.push('The writer was unavailable, so these are plain starting points to edit.'); }
            if (s.destination === 'fanvue') { notes.push('Posts also go to Fanvue. Messages are sent on Creator Link Studio only.'); }
            $('#lcNote').text(notes.join(' ')).prop('hidden', !notes.length);
            render_items(o.items || []);
        });
    });
    $('#lcBack').on('click', function () { stage('form'); });
    $('#lcItems').on('input', '[data-text]', summary);

    $('#lcConfirm').on('click', function () {
        var s = setup(); s.items = collect();
        var $b = $(this), html = $b.html();
        busy = true; error('');
        $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status"></span> Scheduling');
        api('launch_campaign_confirm', s, function (o) {
            busy = false; $b.html(html);
            if (!o || !o.success) { summary(); fail(o, 'Could not schedule the campaign.'); return; }
            bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
            toastr.success(o.message || 'Campaign scheduled');
            if (o.share_errors && o.share_errors.length) { toastr.warning('Some cross-posts were not scheduled: ' + o.share_errors[0]); }
            $('#lcWhat, #lcCode, #lcPct').val(''); $('#lcPct, #lcValid').prop('disabled', true);
            stage('form');
        });
    });
});
