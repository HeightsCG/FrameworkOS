<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/feedback — notes sent from the Feedback link in the top bar (FeedbackModel), newest first. A row opens the
   whole note in a dialog; Mark Reviewed / Mark New go through admin_feedback_status. Opens on the New filter. */
$fb = (array) $this->feedback;
$fb_cnt = array('all' => count($fb), 'new' => 0, 'reviewed' => 0, 'idea' => 0, 'problem' => 0, 'praise' => 0, 'other' => 0);
foreach ($fb as $f) { $fb_cnt[$f['status'] === 'reviewed' ? 'reviewed' : 'new']++; if (isset($fb_cnt[$f['kind']])) { $fb_cnt[$f['kind']]++; } }
$fb_tone = array('idea' => 'acc', 'problem' => 'bad', 'praise' => 'ok', 'other' => 'gray');
?>
<link rel="stylesheet" href="/css/admin-feedback.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin-feedback.css'); ?>">
<header class="adm-head">
    <div><h1 class="adm-head__title">Feedback</h1><p class="adm-head__sub"><?php echo $fb_cnt['new'] > 0 ? number_format($fb_cnt['new']) . ' new note' . ($fb_cnt['new'] === 1 ? '' : 's') : 'Nothing new'; ?></p></div>
</header>
<section class="adm-box">
    <header class="adm-box__h">
        <div class="adm-search" role="search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" placeholder="Search feedback, name or page" autocomplete="off" aria-label="Search feedback" data-search-for="admFeedback">
        </div>
        <div class="adm-seg" role="tablist" aria-label="Status" data-filter-for="admFeedback" data-filter-attr="data-f">
            <?php foreach (array('new' => 'New', 'reviewed' => 'Reviewed', 'idea' => 'Ideas', 'problem' => 'Problems', 'praise' => 'Praise', 'other' => 'Other', 'all' => 'All') as $k => $l): ?><button type="button" role="tab" class="adm-seg__b<?php echo $k === 'new' ? ' is-on' : ''; ?>" aria-selected="<?php echo $k === 'new' ? 'true' : 'false'; ?>" data-filter="<?php echo $k; ?>"><?php echo $l; ?> <b data-fb-count="<?php echo $k; ?>"><?php echo $fb_cnt[$k]; ?></b></button><?php endforeach; ?>
        </div>
    </header>
    <?php if (empty($fb)): ?><?php echo adm_empty('No feedback yet. Notes sent from the Feedback link show up here.', 'fa-message'); ?><?php else: ?>
    <table class="adm-t" id="admFeedback" data-sortable data-pager>
        <thead><tr><th data-sort="text">Feedback</th><th data-sort="text">From</th><th data-sort="text">Type</th><th data-sort="text">Page</th><th data-sort="text" data-sorted="desc">Received</th><th data-sort="text">Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($fb as $f):
            $name = trim((string) $f['first_name'] . ' ' . (string) $f['last_name']); $name = $name !== '' ? $name : '@' . $f['u_name'];
            $kind = FeedbackModel::KINDS[$f['kind']] ?? 'Other';
            $rev  = $f['status'] === 'reviewed'; ?>
        <tr class="adm-fbrow adm-t__link" tabindex="0" data-fb-id="<?php echo (int) $f['id']; ?>" data-f="<?php echo $rev ? 'reviewed' : 'new'; ?> <?php echo $e($f['kind']); ?>"
            data-search="<?php echo $e(mb_strtolower($name . ' @' . $f['u_name'] . ' ' . $f['page_url'])); ?>"
            data-message="<?php echo $e($f['message']); ?>" data-name="<?php echo $e($name); ?>" data-handle="<?php echo $e($f['u_name']); ?>" data-uid="<?php echo (int) $f['user_id']; ?>"
            data-kind="<?php echo $e($kind); ?>" data-page="<?php echo $e($f['page_url']); ?>" data-title="<?php echo $e($f['page_title']); ?>"
            data-when="<?php echo $e($fmt($f['created_at'], true)); ?>" data-agent="<?php echo $e($f['user_agent']); ?>" data-viewport="<?php echo $e($f['viewport']); ?>">
            <td class="adm-fbrow__msg"><span class="adm-fbrow__text"><?php echo $e($f['message']); ?></span></td>
            <td class="adm-t__who" data-value="<?php echo $e(mb_strtolower($name)); ?>"><?php echo adm_who($name, $f['u_name'], '/admin/user/' . (int) $f['user_id'], (string) ($f['avatar_url'] ?? '')); ?></td>
            <td><?php echo adm_pill($kind, $fb_tone[$f['kind']] ?? 'gray'); ?></td>
            <td class="adm-t__muted adm-t__trunc" title="<?php echo $e($f['page_url']); ?>"><?php echo $e($f['page_url'] !== '' ? $f['page_url'] : '—'); ?></td>
            <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($f['created_at']); ?>"><?php echo $e($fmt($f['created_at'], true)); ?></td>
            <td data-fb-status><?php echo adm_pill($rev ? 'Reviewed' : 'New', $rev ? 'gray' : 'warn'); ?></td>
            <td class="adm-t__act"><?php echo adm_row_menu('Actions for this feedback', array(
                array('text' => 'View Feedback', 'attrs' => 'data-fb-view'),
                array('text' => $rev ? 'Mark New' : 'Mark Reviewed', 'attrs' => 'data-fb-set="' . ($rev ? 'new' : 'reviewed') . '"'),
                array('text' => 'Open account', 'href' => '/admin/user/' . (int) $f['user_id']),
            )); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admFeedbackNone" hidden>Nothing here.</p>
    <?php endif; ?>
</section>

<div class="modal fade" id="admFeedbackModal" tabindex="-1" aria-hidden="true" aria-labelledby="adm_fb_title">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adm_fb_title">Feedback</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="adm-fb-meta">
                    <div><dt>From</dt><dd><a id="adm_fb_from" href="#"></a></dd></div>
                    <div><dt>Type</dt><dd id="adm_fb_kind"></dd></div>
                    <div><dt>Received</dt><dd id="adm_fb_when"></dd></div>
                    <div><dt>Page</dt><dd><a id="adm_fb_page" href="#" target="_blank" rel="noopener"></a></dd></div>
                </dl>
                <p class="adm-fb-text" id="adm_fb_text"></p>
                <p class="adm-fb-agent" id="adm_fb_agent"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="adm-btn" data-bs-dismiss="modal">Close</button>
                <button type="button" class="adm-btn adm-btn--primary" id="adm_fb_mark">Mark Reviewed</button>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script>
$(function () {
    var fb_open_row = null;

    // Open on New: the filter bar starts on it, so apply it to the table once admin.js has bound the bar.
    $('[data-filter-for="admFeedback"] [data-filter="new"]').trigger('click');

    function fb_status_html(status) {
        return status === 'reviewed' ? '<span class="adm-pill adm-pill--gray">Reviewed</span>' : '<span class="adm-pill adm-pill--warn">New</span>';
    }

    function fb_counts() {
        var counts = { new: 0, reviewed: 0 };
        $('#admFeedback tbody tr').each(function () {
            var f = ' ' + ($(this).attr('data-f') || '') + ' ';
            if (f.indexOf(' reviewed ') !== -1) { counts.reviewed++; } else { counts.new++; }
        });
        $('[data-fb-count="new"]').text(counts.new);
        $('[data-fb-count="reviewed"]').text(counts.reviewed);
    }

    function fb_set(row, status) {
        ApiDataSvc.apiCall('post', 'admin_feedback_status', {
            feedback_id: row.attr('data-fb-id'),
            status: status
        }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            var kind = (row.attr('data-f') || '').split(' ')[1] || '';
            row.attr('data-f', status + ' ' + kind);
            row.find('[data-fb-status]').html(fb_status_html(status));
            row.find('[data-fb-set]').attr('data-fb-set', status === 'reviewed' ? 'new' : 'reviewed').text(status === 'reviewed' ? 'Mark New' : 'Mark Reviewed');
            fb_counts();
            toastr.success(o.message);
            if (fb_open_row && fb_open_row.is(row)) { fb_mark_label(); }
        });
    }

    function fb_mark_label() {
        var reviewed = (' ' + (fb_open_row.attr('data-f') || '') + ' ').indexOf(' reviewed ') !== -1;
        $('#adm_fb_mark').text(reviewed ? 'Mark New' : 'Mark Reviewed').attr('data-to', reviewed ? 'new' : 'reviewed');
    }

    function fb_view(row) {
        fb_open_row = row;
        var page = row.attr('data-page') || '';
        var title = row.attr('data-title') || '';
        var agent = row.attr('data-agent') || '';
        var viewport = row.attr('data-viewport') || '';
        $('#adm_fb_from').text(row.attr('data-name') + ' (@' + row.attr('data-handle') + ')').attr('href', '/admin/user/' + row.attr('data-uid'));
        $('#adm_fb_kind').text(row.attr('data-kind'));
        $('#adm_fb_when').text(row.attr('data-when'));
        if (page !== '') { $('#adm_fb_page').text(title !== '' ? title + ' (' + page + ')' : page).attr('href', page).show(); } else { $('#adm_fb_page').text('').hide(); }
        $('#adm_fb_text').text(row.attr('data-message'));
        $('#adm_fb_agent').text(agent + (viewport !== '' ? ' · ' + viewport : '')).toggle(agent !== '' || viewport !== '');
        fb_mark_label();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('admFeedbackModal')).show();
    }

    $('#admFeedback').on('click', 'tr.adm-fbrow', function (e) {
        if ($(e.target).closest('a, button, .dropdown').length) { return; }
        fb_view($(this));
    });
    $('#admFeedback').on('keydown', 'tr.adm-fbrow', function (e) {
        if (e.key === 'Enter' && e.target === this) { fb_view($(this)); }
    });
    $('#admFeedback').on('click', '[data-fb-view]', function () {
        fb_view($(this).closest('tr'));
    });
    $('#admFeedback').on('click', '[data-fb-set]', function () {
        fb_set($(this).closest('tr'), $(this).attr('data-fb-set'));
    });
    $('#adm_fb_mark').on('click', function () {
        if (fb_open_row) { fb_set(fb_open_row, $(this).attr('data-to')); }
    });
});
</script>
