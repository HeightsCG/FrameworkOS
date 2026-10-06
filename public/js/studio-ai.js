/* Content Studio AI additions, kept out of studio.js: Edit By Instruction and version history in the Library
   drawer, plus the composer's AI disclosure and Story switches. Talks to studio.js only through document events
   (cs:detail in, cs:asset-added / cs:open-asset / cs:use-in-post out). Shared pieces come from AiTools.
   Generation itself (images, videos, scenes) lives under /influencers. */
jQuery(function ($) {
    "use strict";

    var CFG = window.CS_CONFIG || {}, T = window.AiTools;
    if (!T || !$('#csTabs').length) { return; }
    var esc = T.esc, api = T.api, err = T.err;
    T.set_balance((CFG.ai || {}).balance);

    /* =====================================================================
     * Library drawer: Edit, Replicate, version history
     * =================================================================== */
    $(document).on('cs:detail', function (e, a) {
        var $box = $('#csDvAi').empty();
        if (a && a.type === 'video' && a.status === 'ready') {
            // Scrub the player above to a moment, then export it as an image (which opens here with Edit and Replicate).
            $box.html('<label class="form-label cs-dv__label">Frames</label><div class="cs-dv__ai cs-dv__ai--one">' +
                '<button type="button" class="btn btn-outline-secondary" id="csDvFrame"><i class="fa-regular fa-image" aria-hidden="true"></i> Export This Frame</button>' + ((window.TUT_BTN || {})['30'] || '') + '</div>');
            $('#csDvFrame').on('click', function () {
                var $b = $(this).prop('disabled', true), v = $('#csDetailBody video')[0];
                $b.html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Exporting');
                T.export_frame(a.id, v ? v.currentTime : 0, function (frame) {
                    $b.prop('disabled', false).html('<i class="fa-regular fa-image" aria-hidden="true"></i> Export This Frame');
                    if (!frame) { return; }
                    toastr.success('Frame saved to your Library');
                    $(document).trigger('cs:asset-added', [frame.id]);
                    $(document).trigger('cs:open-asset', [frame.id]);
                });
            });
            return;
        }
        if (!a || a.type !== 'image' || a.status !== 'ready' || !CFG.can_ai) { return; }
        var blocked = a.moderation === 'blocked';
        $box.html(
            '<label class="form-label cs-dv__label">AI Tools</label>' +
            '<div class="cs-dv__ai">' +
              '<button type="button" class="btn btn-outline-secondary" id="csDvEdit"' + (blocked ? ' disabled' : '') + '><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>' + ((window.TUT_BTN || {})['25'] || '') +
              '<a class="btn btn-outline-secondary' + (blocked ? ' disabled' : '') + '" href="/influencers/replicate/0/' + a.id + '" id="csDvReplicate"' + (blocked ? ' aria-disabled="true" tabindex="-1"' : '') + '><i class="fa-solid fa-clone" aria-hidden="true"></i> Replicate</a>' +
            '</div>' +
            '<div id="csDvVersions"></div>');
        var ready = ((CFG.influencers || {}).ready || []);
        if (ready.length) { $('#csDvReplicate').attr('href', '/influencers/replicate/' + ready[0].id + '/' + a.id); } else { $('#csDvReplicate').remove(); }
        $('#csDvEdit').on('click', function () {
            T.edit({ id: a.id, display_url: a.preview_url, thumb_url: a.thumb_url, name: a.name }, function (made) {
                $(document).trigger('cs:asset-added', [made.id]);
                $(document).trigger('cs:open-asset', [made.id]);   // the drawer moves to the new version
            });
        });
        versions(a.id);
    });
    function versions(asset_id) {
        api('media_versions', { asset_id: asset_id }, function (o) {
            var list = (o && o.success) ? (o.versions || []) : [];
            if (list.length < 2) { $('#csDvVersions').empty(); return; }
            $('#csDvVersions').html('<label class="form-label cs-dv__label">Versions</label><ol class="cs-dv__versions">' + list.map(function (v, i) {
                var label = v.change !== '' ? v.change : (i === 0 ? 'Original' : 'Version ' + (i + 1));
                return '<li><button type="button" class="cs-dv__version' + (v.current ? ' is-on' : '') + '" data-version="' + v.id + '"' + (v.current ? ' aria-current="true"' : '') + '>' +
                    (v.thumb_url ? '<img src="' + esc(v.thumb_url) + '" alt="">' : '<span class="cs-dv__vph"><i class="fa-regular fa-image" aria-hidden="true"></i></span>') +
                    '<span>' + esc(label) + '</span></button></li>';
            }).join('') + '</ol>');
        });
    }
    $(document).on('click', '.cs-dv__version', function () { if (!$(this).hasClass('is-on')) { $(document).trigger('cs:open-asset', [parseInt($(this).data('version'), 10)]); } });

    /* ---- composer: AI disclosure and Story placement on the Distribution section ---- */
    var STORY_PLATFORMS = ['instagram', 'facebook'];
    function has_ai(c) { return (c.assets || []).some(function (a) { return a.provenance === 'generated' || a.provenance === 'edited'; }); }
    function dest_extras(c, accounts) {
        var $wrap = $('#csCompSocial');
        $wrap.find('.cs-pe__story').remove(); $('#csPeAiRow').remove();
        c.story_accounts = (c.story_accounts || []).map(String);
        (accounts || []).forEach(function (a) {
            if (STORY_PLATFORMS.indexOf(String(a.platform).toLowerCase()) < 0) { return; }
            var $box = $wrap.find('input[data-acct]').filter(function () { return String($(this).data('acct')) === String(a.id); });
            if (!$box.length || $box.prop('disabled')) { return; }
            var on = c.story_accounts.indexOf(String(a.id)) >= 0, id = 'csPeStory_' + String(a.id).replace(/[^A-Za-z0-9_-]/g, '_');
            $box.closest('.cs-pe__dest').after('<label class="cs-pe__story" for="' + id + '" data-story="' + T.esc(a.id) + '"' + ($box.prop('checked') ? '' : ' hidden') + '>' +
                '<span class="cs-pe__storylabel">Post As Story</span>' +
                '<span class="form-check form-switch cs-pe__switch"><input class="form-check-input" type="checkbox" role="switch" id="' + id + '"' + (on ? ' checked' : '') + '></span></label>');
        });
        if (has_ai(c)) {
            $wrap.after('<div class="cs-pe__switchrow" id="csPeAiRow"><label class="cs-pe__switchtext" for="csPeAiDisclose"><span class="cs-pe__switchlabel">AI Disclosure</span></label>' +
                '<div class="form-check form-switch cs-pe__switch"><input class="form-check-input" type="checkbox" role="switch" id="csPeAiDisclose"' + (c.ai_disclosure === 0 || c.ai_disclosure === '0' ? '' : ' checked') + '></div></div>');
        }
        $wrap.off('change.csx').on('change.csx', '.cs-pe__story input', function () {
            var id = String($(this).closest('.cs-pe__story').data('story'));
            c.story_accounts = c.story_accounts.filter(function (x) { return x !== id; });
            if (this.checked) { c.story_accounts.push(id); }
        });
        $('#csPeAiDisclose').off('change').on('change', function () { c.ai_disclosure = this.checked ? 1 : 0; });
    }
    var dest_last = null;
    $(document).on('cs:dest-rendered', function (e, c, accounts) { dest_last = [c, accounts]; dest_extras(c, accounts); });
    // Media can change after the list was drawn: show or drop the AI switch to match.
    $(document).on('click', '#csComposer', function () {
        if (dest_last && has_ai(dest_last[0]) !== ($('#csPeAiRow').length > 0)) { dest_extras(dest_last[0], dest_last[1]); }
    });
    $(document).on('cs:dest-changed', function (e, c) {
        $('#csCompSocial .cs-pe__story').each(function () {
            var id = String($(this).data('story')), on = c.share.has(id);
            $(this).prop('hidden', !on);
            if (!on) { $(this).find('input').prop('checked', false); c.story_accounts = (c.story_accounts || []).filter(function (x) { return x !== id; }); }
        });
    });
    /* ---- composer: the on-video line a Hook Overlay caption was written to follow ---- */
    $(document).on('cs:caption-written', function (e, o) {
        var hook = (o && o.success && o.hook) ? String(o.hook) : '';
        $('#csPeHookText').text(hook); $('#csPeHook').prop('hidden', hook === '');
    });
    $(document).on('click', '#csPeHookCopy', function () {
        var t = $('#csPeHookText').text();
        if (navigator.clipboard && t) { navigator.clipboard.writeText(t); toastr.success('Copied'); }
    });
});
