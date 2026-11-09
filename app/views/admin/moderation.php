<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/moderation — moderation, reports, verification (identity checks) and past-due billing,
   each in its own panel, with the sub-menu showing one or all. The panels keep the DOM hooks the handlers in
   public/js/admin.js key on (#admMod, #admReports, #admVerif, [data-billing-retry]). Support is its own tab (support.php). */
$queue = (array) $this->queue;
$n = array(
    'moderation'   => count($queue),
    'reports'      => count((array) $this->reports_queue),
    'verification' => 0,   // ID checks are decided by Didit: pending ones wait on the user, never on staff
    'billing'      => count((array) $this->billing),
);
$total = array_sum($n);
$verif = 0; foreach ((array) $this->verifications as $v) { if ((string) $v['status'] === 'pending') { $verif++; } }   // shown in the Verification panel and chip, but never counted as waiting on staff (Didit decides)
$show = in_array((string) $this->show, array_keys($n), true) ? (string) $this->show : 'all';
$chips = array('all' => 'All', 'moderation' => 'Moderation', 'reports' => 'Reports', 'verification' => 'Verification', 'billing' => 'Billing');
?>
<?php $zero = array(); foreach ($chips as $k => $label) { if ($k !== 'all' && $k !== 'verification' && $n[$k] === 0) { $zero[] = $label; } } ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Moderation</h1><p class="adm-head__sub"><?php echo $total > 0 ? number_format($total) . ' item' . ($total === 1 ? '' : 's') . ' waiting on staff' : 'Nothing waiting on staff'; ?></p><?php if ($zero && $total > 0): ?><p class="adm-zero" id="admQueueZero"<?php echo $show === 'all' ? '' : ' hidden'; ?>>Nothing waiting in <?php echo $e(implode(', ', array_slice($zero, 0, -1)) . (count($zero) > 1 ? ' and ' : '') . end($zero)); ?>.</p><?php endif; ?></div>
    <div class="adm-head__acts">
        <div class="adm-seg" id="admQueueChips" role="tablist" aria-label="Moderation sections">
            <?php foreach ($chips as $k => $label): $c = $k === 'all' ? $total : ($k === 'verification' ? $verif : $n[$k]); ?>
            <a class="adm-seg__b adm-chip<?php echo $show === $k ? ' is-on' : ''; ?>" role="tab" aria-selected="<?php echo $show === $k ? 'true' : 'false'; ?>" href="/admin/moderation<?php echo $k === 'all' ? '' : '?show=' . $k; ?>" data-show="<?php echo $k; ?>"><?php echo $e($label); ?> <b><?php echo number_format($c); ?></b></a>
            <?php endforeach; ?>
        </div>
    </div>
</header>

<div class="adm-groups" id="admQueue">

    <section class="adm-box" id="admQueueClear"<?php echo ($show === 'all' && $total === 0) ? '' : ' hidden'; ?>><?php echo adm_empty('Nothing waiting on staff', 'fa-circle-check'); ?></section>

    <section class="adm-group adm-panel adm-box" data-group="moderation" data-panel="moderation" data-empty="<?php echo $n['moderation'] === 0 ? '1' : '0'; ?>"<?php echo (($show !== 'all' && $show !== 'moderation') || ($show === 'all' && $n['moderation'] === 0)) ? ' hidden' : ''; ?>>
        <header class="adm-box__h"><h2 class="adm-box__t">Moderation <b class="adm-count" data-count="moderation"><?php echo count($queue); ?></b></h2></header>
        <?php if (empty($queue)): ?>
            <?php echo adm_empty('Nothing to review', 'fa-image'); ?>
        <?php else: ?>
        <div class="adm-mod" id="admMod">
            <?php foreach ($queue as $a): ?>
            <div class="adm-card" data-asset="<?php echo (int) $a['id']; ?>">
                <div class="adm-card__img" style="background-image:url('<?php echo $e($a['thumb']); ?>')" data-full="<?php echo $e($a['full']); ?>" role="button" tabindex="0" aria-label="View larger">
                    <span class="adm-card__badge"><?php echo adm_pill($a['status'] === 'flagged' ? 'Flagged' : 'Unscanned'); ?></span>
                </div>
                <div class="adm-card__body">
                    <a class="adm-card__creator" href="/@<?php echo $e($a['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($a['creator_handle']); ?></a>
                    <?php if ($a['status'] === 'flagged' && ($a['labels'] !== '' || $a['score'] !== null)): ?><span class="adm-card__ai"><?php echo $a['labels'] !== '' ? $e($a['labels']) : 'flagged'; ?><?php echo $a['score'] !== null ? ' · ' . number_format($a['score'] * 100) . '%' : ''; ?></span><?php endif; ?>
                    <span class="adm-card__when"><?php echo $e($fmt($a['created_at'], true)); ?></span>
                </div>
                <div class="adm-card__acts">
                    <button type="button" class="adm-btn adm-btn--sm" data-mod="approve">Approve</button>
                    <button type="button" class="adm-btn adm-btn--sm adm-btn--danger" data-mod="block">Block</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="adm-group adm-panel adm-box" data-group="reports" data-panel="reports" data-empty="<?php echo $n['reports'] === 0 ? '1' : '0'; ?>"<?php echo (($show !== 'all' && $show !== 'reports') || ($show === 'all' && $n['reports'] === 0)) ? ' hidden' : ''; ?>>
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

    <section class="adm-group adm-panel adm-box" data-group="verification" data-panel="verification" data-empty="<?php echo $verif === 0 ? '1' : '0'; ?>"<?php echo (($show !== 'all' && $show !== 'verification') || ($show === 'all' && $verif === 0)) ? ' hidden' : ''; ?>>
        <header class="adm-box__h"><h2 class="adm-box__t">Verification <b class="adm-count"><?php echo $verif; ?></b></h2></header>
        <?php $vlabel = array('approved' => array('Verified', 'ok'), 'rejected' => array('Declined', 'bad'), 'pending' => array('In progress', 'gray'), 'revoked' => array('Badge removed', 'gray')); ?>
        <div class="adm-part" data-part-empty="<?php echo empty($this->verifications) ? '1' : '0'; ?>">
        <div class="adm-part__h"><h3 class="adm-h3">ID checks <b class="adm-count"><?php echo count((array) $this->verifications); ?></b></h3></div>
        <?php if (empty($this->verifications)): ?>
            <?php echo adm_empty('No creator has started an ID check yet', 'fa-id-card'); ?>
        <?php else: ?>
        <table class="adm-t">
            <thead><tr><th>Creator</th><th>Result</th><th>Name on ID</th><th>Reason</th><th>Checked</th><th></th></tr></thead>
            <tbody id="admVerif">
            <?php foreach ($this->verifications as $v): $vname = trim((string) ($v['name'] ?? '')); $vname = $vname !== '' ? $vname : ('@' . $v['u_name']); $vl = $vlabel[(string) $v['status']] ?? array(ucfirst((string) $v['status']), 'gray'); ?>
                <tr class="adm-vrow adm-t__link" data-user="<?php echo (int) $v['user_id']; ?>" data-href="/admin/user/<?php echo (int) $v['user_id']; ?>">
                    <td class="adm-t__who"><?php echo adm_who($vname, $v['u_name'], '/admin/user/' . (int) $v['user_id']); ?></td>
                    <td class="adm-vrow__st"><?php echo adm_pill($vl[0], $vl[1]); ?></td>
                    <td><?php echo $e(trim((string) $v['full_name']) !== '' ? $v['full_name'] : '—'); ?></td>
                    <td class="adm-t__muted adm-t__wrap"><?php echo $e((string) $v['status'] === 'rejected' && trim((string) $v['review_note']) !== '' ? $v['review_note'] : '—'); ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($v['decided_at'] ?: $v['created_at'], true)); ?></td>
                    <td class="adm-t__act"><?php echo (int) $v['verified'] === 1 ? adm_row_menu('Verification actions', array(
                        array('text' => 'Remove Badge…', 'attrs' => 'data-verif-action="revoke"', 'danger' => true),
                    )) : ''; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>

    </section>

    <!-- Billing: past due -->
    <section class="adm-group adm-panel adm-box" data-group="billing" data-panel="billing" data-empty="<?php echo $n['billing'] === 0 ? '1' : '0'; ?>"<?php echo (($show !== 'all' && $show !== 'billing') || ($show === 'all' && $n['billing'] === 0)) ? ' hidden' : ''; ?>>
        <header class="adm-box__h"><h2 class="adm-box__t">Billing <b class="adm-count" data-count="billing"><?php echo count((array) $this->billing); ?></b></h2><a class="adm-box__link" href="/admin/billing">View all</a></header>
        <?php if (empty($this->billing)): ?>
            <?php echo adm_empty('No past-due plans', 'fa-credit-card'); ?>
        <?php else: ?>
        <table class="adm-t">
            <thead><tr><th>Account</th><th>Plan</th><th>Next charge</th><th>Last charge</th><th></th></tr></thead>
            <tbody>
            <?php foreach ((array) $this->billing as $b): $bn = trim($b['first_name'] . ' ' . $b['last_name']); $bn = $bn !== '' ? $bn : '@' . $b['u_name']; $bt = PlanTiers::get((string) $b['plan_key']); $bnx = BillingService::next_charge($b); ?>
                <tr class="adm-urow adm-t__link" data-uid="<?php echo (int) $b['user_id']; ?>" data-href="/admin/user/<?php echo (int) $b['user_id']; ?>">
                    <td class="adm-t__who"><?php echo adm_who($bn, $b['u_name'], '/admin/user/' . (int) $b['user_id'], '', (string) $b['user_email']); ?></td>
                    <td class="adm-t__nowrap"><?php echo $e($bt ? $bt['name'] : $b['plan_key']); ?> <?php echo adm_pill('Past due'); ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $bnx ? $e(BillingService::money($bnx['total']) . ' · ' . $fmt($bnx['at'])) : '—'; ?><?php if (!empty($b['next_retry_at'])): ?><span class="adm-t__sub">Retry <?php echo $e($fmt($b['next_retry_at'])); ?></span><?php endif; ?></td>
                    <td class="adm-t__muted adm-t__nowrap" <?php echo !empty($b['last_failure']) ? 'title="' . $e($b['last_failure']) . '"' : ''; ?>><?php echo !empty($b['last_charge_at']) ? $e(ucfirst(str_replace('_', ' ', (string) $b['last_charge_status'])) . ' · ' . BillingService::money((int) $b['last_amount_cents'])) : '—'; ?><?php if (!empty($b['last_failure'])): ?><span class="adm-t__sub"><?php echo $e($b['last_failure']); ?></span><?php endif; ?></td>
                    <td class="adm-t__act"><?php echo adm_row_menu('Billing actions', array(array('text' => 'Retry charge now', 'attrs' => 'data-billing-retry'))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
