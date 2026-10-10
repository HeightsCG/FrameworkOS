<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/queue — everything waiting on staff, grouped by kind. Filters show one group or all. The groups keep the DOM
   hooks the handlers in public/js/admin.js key on (#admMod, #admReports, #admVerif, #admAge, [data-sup], [data-billing-retry], [data-age-reset]). */
$queue = (array) $this->queue;
$sup_status = array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed');
$sup_counts = array('open' => 0, 'answered' => 0, 'closed' => 0);
foreach ((array) $this->support as $st) { if (isset($sup_counts[$st['status']])) { $sup_counts[$st['status']]++; } }
$ac = (array) $this->age_counts;
$n = array(
    'moderation'   => count($queue),
    'reports'      => count((array) $this->reports_queue),
    'verification' => count((array) $this->verifications) + (int) $ac['pending'],
    'support'      => $sup_counts['open'],
    'billing'      => count((array) $this->billing),
);
$total = array_sum($n);
$show = in_array((string) $this->show, array_keys($n), true) ? (string) $this->show : 'all';
$chips = array('all' => 'All', 'moderation' => 'Moderation', 'reports' => 'Reports', 'verification' => 'Verification', 'support' => 'Support', 'billing' => 'Billing');
$av_label = array('pending' => 'Pending', 'verified' => 'Verified', 'failed' => 'Failed');
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Queue</h1><p class="adm-head__sub"><?php echo $total > 0 ? number_format($total) . ' item' . ($total === 1 ? '' : 's') . ' waiting on staff' : 'Nothing waiting on staff'; ?></p></div>
    <div class="adm-head__acts">
        <div class="adm-seg" id="admQueueChips" role="tablist" aria-label="Queue groups">
            <?php foreach ($chips as $k => $label): $c = $k === 'all' ? $total : $n[$k]; ?>
            <a class="adm-seg__b adm-chip<?php echo $show === $k ? ' is-on' : ''; ?>" role="tab" aria-selected="<?php echo $show === $k ? 'true' : 'false'; ?>" href="/admin/queue<?php echo $k === 'all' ? '' : '?show=' . $k; ?>" data-show="<?php echo $k; ?>"><?php echo $e($label); ?> <b><?php echo number_format($c); ?></b></a>
            <?php endforeach; ?>
        </div>
    </div>
</header>
<?php $zero = array(); foreach ($chips as $k => $label) { if ($k !== 'all' && $n[$k] === 0) { $zero[] = $label; } } ?>

<div class="adm-groups" id="admQueue">

    <!-- Moderation -->
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
                    <?php if ($a['status'] === 'flagged' && ($a['labels'] !== '' || $a['score'] !== null)): ?><span class="adm-card__ai"><?php echo $a['labels'] !== '' ? $e($a['labels']) : 'adult'; ?><?php echo $a['score'] !== null ? ' · ' . number_format($a['score'] * 100) . '%' : ''; ?></span><?php endif; ?>
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

    <!-- Reports -->
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

    <!-- Verification: identity requests + age checks -->
    <section class="adm-group adm-panel adm-box" data-group="verification" data-panel="verification" data-empty="<?php echo $n['verification'] === 0 ? '1' : '0'; ?>"<?php echo (($show !== 'all' && $show !== 'verification') || ($show === 'all' && $n['verification'] === 0)) ? ' hidden' : ''; ?>>
        <header class="adm-box__h"><h2 class="adm-box__t">Verification <b class="adm-count" data-count="verification"><?php echo $n['verification']; ?></b></h2></header>
        <h3 class="adm-h3">Identity requests <span><?php echo count((array) $this->verifications); ?></span></h3>
        <?php if (empty($this->verifications)): ?>
            <p class="adm-quiet">No pending identity requests.</p>
        <?php else: ?>
        <table class="adm-t">
            <thead><tr><th>Creator</th><th>Legal name</th><th>Note</th><th>When</th><th></th></tr></thead>
            <tbody id="admVerif">
            <?php foreach ($this->verifications as $v): $vname = trim((string) ($v['name'] ?? '')); $vname = $vname !== '' ? $vname : ('@' . $v['u_name']); ?>
                <tr class="adm-vrow adm-t__link" data-verif="<?php echo (int) $v['id']; ?>" data-href="/admin/user/<?php echo (int) $v['user_id']; ?>">
                    <td class="adm-t__who"><?php echo adm_who($vname, $v['u_name'], '/admin/user/' . (int) $v['user_id']); ?></td>
                    <td><?php echo $e($v['full_name'] !== '' ? $v['full_name'] : '—'); ?></td>
                    <td class="adm-t__muted adm-t__wrap"><?php echo $v['note'] !== '' ? $e(mb_substr((string) $v['note'], 0, 120)) : '—'; ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($v['created_at'], true)); ?></td>
                    <td class="adm-t__act"><?php echo adm_row_menu('Verification actions', array(
                        array('text' => 'Approve', 'attrs' => 'data-verif-action="approve"'),
                        array('text' => 'Reject…', 'attrs' => 'data-verif-action="reject"', 'danger' => true),
                    )); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="adm-quiet" id="admVerifNone" hidden>No pending requests.</p>
        <?php endif; ?>

        <div class="adm-box__sub"><h3 class="adm-h3">Age checks <span><?php echo (int) $ac['pending']; ?> pending</span></h3>
            <div class="adm-seg" role="tablist" aria-label="Age check status" id="admAgeTabs">
                <button type="button" class="adm-seg__b is-on" role="tab" aria-selected="true" data-age="open">Needs a look <b><?php echo (int) $ac['pending'] + (int) $ac['failed']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="pending">Pending <b><?php echo (int) $ac['pending']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="failed">Failed <b><?php echo (int) $ac['failed']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="verified">Verified <b><?php echo (int) $ac['verified']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="all">All <b><?php echo count((array) $this->age_rows); ?></b></button>
            </div>
        </div>
        <table class="adm-t">
            <thead><tr><th>Account</th><th>Status</th><th>Provider</th><th>Adult content</th><th>Updated</th><th></th></tr></thead>
            <tbody id="admAge">
            <?php foreach ((array) $this->age_rows as $av): $an = trim((string) $av['name']); $an = $an !== '' ? $an : ('@' . $av['u_name']); $st = (string) $av['status']; ?>
                <tr class="adm-agerow adm-t__link" data-age-row="<?php echo (int) $av['user_id']; ?>" data-status="<?php echo $e($st); ?>" data-href="/admin/user/<?php echo (int) $av['user_id']; ?>"<?php echo $st === 'verified' ? ' hidden' : ''; ?>>
                    <td class="adm-t__who"><?php echo adm_who($an, $av['u_name'], '/admin/user/' . (int) $av['user_id'], '', '@' . $av['u_name'] . ' · ' . ($av['role_name'] ?: 'User')); ?></td>
                    <td><?php echo adm_pill($av_label[$st] ?? ucfirst($st)); ?><?php if ($st === 'verified' && (string) $av['verified_at'] !== ''): ?> <span class="adm-t__muted"><?php echo $e($fmt($av['verified_at'])); ?></span><?php endif; ?></td>
                    <td class="adm-t__muted"><?php echo $e(ucfirst((string) $av['provider'])); ?></td>
                    <td class="adm-t__muted"><?php echo !empty($av['adult_content_enabled']) ? 'Shown' : 'Hidden'; ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($av['updated_at'], true)); ?></td>
                    <td class="adm-t__act"><?php echo adm_row_menu('Age check actions', array(array('text' => 'Reset age verification…', 'attrs' => 'data-age-reset', 'danger' => true))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="adm-quiet" id="admAgeNone"<?php echo ((int) $ac['pending'] + (int) $ac['failed']) > 0 ? ' hidden' : ''; ?>>No age checks need a look.</p>
    </section>

    <!-- Support -->
    <section class="adm-group adm-panel adm-box" data-group="support" data-panel="support" data-empty="<?php echo $n['support'] === 0 ? '1' : '0'; ?>"<?php echo (($show !== 'all' && $show !== 'support') || ($show === 'all' && $n['support'] === 0)) ? ' hidden' : ''; ?>>
        <header class="adm-box__h"><h2 class="adm-box__t">Support <b class="adm-count" data-count="support"><?php echo $sup_counts['open']; ?></b></h2>
            <div class="adm-seg" role="tablist" aria-label="Support status">
                <button type="button" class="adm-seg__b is-on" role="tab" aria-selected="true" data-sup="open">Waiting on us <b><?php echo $sup_counts['open']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-sup="answered">Replied <b><?php echo $sup_counts['answered']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-sup="closed">Closed <b><?php echo $sup_counts['closed']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-sup="all">All <b><?php echo count((array) $this->support); ?></b></button>
            </div>
        </header>
        <table class="adm-t">
            <thead><tr><th>Request</th><th>From</th><th>Topic</th><th class="adm-r">Messages</th><th>Last activity</th><th>Status</th></tr></thead>
            <tbody id="admSupport">
            <?php foreach ((array) $this->support as $st): $nm = trim((string) $st['first_name'] . ' ' . (string) $st['last_name']); ?>
                <tr class="adm-suprow adm-t__link" data-href="/support/ticket/<?php echo (int) $st['id']; ?>" data-status="<?php echo $e($st['status']); ?>"<?php echo $st['status'] !== 'open' ? ' hidden' : ''; ?>>
                    <td class="adm-t__main"><a href="/support/ticket/<?php echo (int) $st['id']; ?>"><?php echo $e($st['subject']); ?></a></td>
                    <td class="adm-t__who"><?php echo adm_who($nm, $st['u_name'], '/admin/user/' . (int) $st['user_id']); ?></td>
                    <td class="adm-t__muted"><?php echo $e(SupportModel::CATEGORIES[$st['category']] ?? 'Something else'); ?></td>
                    <td class="adm-r adm-t__num adm-t__muted"><?php echo (int) $st['message_count']; ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($st['last_message_at'], true)); ?></td>
                    <td><?php echo adm_pill($sup_status[$st['status']] ?? $st['status'], $st['status'] === 'open' ? 'warn' : ($st['status'] === 'answered' ? 'ok' : 'gray')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="adm-quiet" id="admSupportNone"<?php echo $sup_counts['open'] > 0 ? ' hidden' : ''; ?>>Nothing here.</p>
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
<?php if ($zero && $show === 'all'): ?><p class="adm-zero" id="admQueueZero">Nothing waiting in <?php echo $e(implode(', ', array_slice($zero, 0, -1)) . (count($zero) > 1 ? ' and ' : '') . end($zero)); ?>.</p><?php endif; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
