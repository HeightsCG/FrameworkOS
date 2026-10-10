<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/reports — open user reports. Dismiss / remove post / suspend: public/js/admin.js (#admReports). */
$n = array('reports' => count((array) $this->reports_queue));
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Reports</h1><p class="adm-head__sub"><?php echo $n['reports'] > 0 ? $n['reports'] . ' open report' . ($n['reports'] === 1 ? '' : 's') : 'No open reports'; ?></p></div>
</header>
    <section class="adm-group adm-panel adm-box" data-group="reports" data-panel="reports">
        <header class="adm-box__h"><h2 class="adm-box__t">Reports <b class="adm-count" data-count="reports"><?php echo count((array) $this->reports_queue); ?></b></h2></header>
        <?php if (empty($this->reports_queue)): ?>
            <?php echo adm_empty('No open reports', 'fa-flag'); ?>
        <?php else: ?>
        <table class="adm-t">
            <thead><tr><th>Reported</th><th>Reason</th><th>By</th><th>When</th><th></th></tr></thead>
            <tbody id="admReports">
            <?php foreach ($this->reports_queue as $rp): ?>
                <tr class="adm-rrow" data-report="<?php echo (int) $rp['id']; ?>" data-type="<?php echo $e($rp['target_type']); ?>">
                    <td class="adm-t__main"><?php if ($rp['target_type'] === 'post'): ?><?php echo adm_pill('Post', 'gray'); ?> <?php echo $e($rp['post_caption'] !== '' ? mb_substr($rp['post_caption'], 0, 70) : ('#' . $rp['target_id'])); ?><?php if ($rp['creator_handle'] !== ''): ?><span class="adm-t__sub"><a href="/@<?php echo $e($rp['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($rp['creator_handle']); ?></a></span><?php endif; ?><?php else: ?><?php echo adm_pill('Creator', 'gray'); ?> <a href="/@<?php echo $e($rp['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($rp['creator_handle']); ?></a><?php endif; ?></td>
                    <td><?php echo $e($rp['reason_label']); ?><?php if ($rp['details'] !== ''): ?><span class="adm-t__sub" title="<?php echo $e($rp['details']); ?>"><?php echo $e(mb_substr($rp['details'], 0, 80)); ?></span><?php endif; ?></td>
                    <td class="adm-t__muted">@<?php echo $e($rp['reporter_handle']); ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($rp['created_at'], true)); ?></td>
                    <td class="adm-t__act"><?php echo adm_row_menu('Report actions', array(
                        array('text' => 'Dismiss', 'attrs' => 'data-report-action="dismiss"'),
                        $rp['target_type'] === 'post' ? array('text' => 'Remove post…', 'attrs' => 'data-report-action="remove"', 'danger' => true) : null,
                        array('text' => 'Suspend account…', 'attrs' => 'data-report-action="suspend"', 'danger' => true),
                    )); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="adm-quiet" id="admReportsNone" hidden>All reports resolved.</p>
        <?php endif; ?>
    </section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
