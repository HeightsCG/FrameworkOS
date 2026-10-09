<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/queue — everything waiting on staff, grouped by kind. Chips show one group or all. The groups keep the DOM
   hooks the handlers in public/js/admin.js key on (#admMod, #admReports, #admVerif, [data-sup], [data-billing-retry], [data-age-reset]). */
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
$av_pill = array('pending' => 'adm-pill--warn', 'verified' => 'adm-pill--ok', 'failed' => 'adm-pill--bad');
$av_label = array('pending' => 'Pending', 'verified' => 'Verified', 'failed' => 'Failed');
?>
<header class="adm-head">
    <div>
        <h1 class="adm-head__title">Queue</h1>
        <p class="adm-head__sub"><?php echo $total > 0 ? number_format($total) . ' item' . ($total === 1 ? '' : 's') . ' waiting' : 'Nothing is waiting'; ?></p>
    </div>
</header>

<div class="adm-chips" id="admQueueChips" role="tablist" aria-label="Queue groups">
    <?php foreach ($chips as $k => $label): $c = $k === 'all' ? $total : $n[$k]; ?>
    <a class="adm-chip<?php echo $show === $k ? ' is-on' : ''; ?>" role="tab" aria-selected="<?php echo $show === $k ? 'true' : 'false'; ?>" href="/admin/queue<?php echo $k === 'all' ? '' : '?show=' . $k; ?>" data-show="<?php echo $k; ?>"><?php echo $e($label); ?> <b><?php echo number_format($c); ?></b></a>
    <?php endforeach; ?>
</div>

<div class="adm-groups" id="admQueue">

    <!-- Moderation -->
    <section class="adm-group adm-panel" data-group="moderation" data-panel="moderation"<?php echo ($show !== 'all' && $show !== 'moderation') ? ' hidden' : ''; ?>>
        <header class="adm-group__head"><h2 class="adm-group__h">Moderation</h2><span class="adm-group__n" data-count="moderation"><?php echo count($queue); ?></span></header>
        <?php if (empty($queue)): ?>
            <p class="adm-none adm-none--line">Nothing to review.</p>
        <?php else: ?>
        <div class="adm-mod" id="admMod">
            <?php foreach ($queue as $a): ?>
            <div class="adm-card" data-asset="<?php echo (int) $a['id']; ?>">
                <div class="adm-card__img" style="background-image:url('<?php echo $e($a['thumb']); ?>')" data-full="<?php echo $e($a['full']); ?>" role="button" tabindex="0" aria-label="View larger">
                    <span class="adm-card__badge adm-card__badge--<?php echo $a['status'] === 'flagged' ? 'flag' : 'pend'; ?>"><?php echo $a['status'] === 'flagged' ? 'Flagged' : 'Unscanned'; ?></span>
                </div>
                <div class="adm-card__body">
                    <a class="adm-card__creator" href="/@<?php echo $e($a['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($a['creator_handle']); ?></a>
                    <?php if ($a['status'] === 'flagged' && ($a['labels'] !== '' || $a['score'] !== null)): ?>
                        <span class="adm-card__ai"><?php echo $a['labels'] !== '' ? $e($a['labels']) : 'adult'; ?><?php echo $a['score'] !== null ? ' · ' . number_format($a['score'] * 100) . '%' : ''; ?></span>
                    <?php endif; ?>
                    <span class="adm-card__when"><?php echo $e($ago($a['created_at'])); ?></span>
                </div>
                <div class="adm-card__acts">
                    <button type="button" class="adm-btn adm-btn--sm" data-mod="approve"><i class="fa-solid fa-check" aria-hidden="true"></i> Approve</button>
                    <button type="button" class="adm-btn adm-btn--sm adm-btn--danger" data-mod="block"><i class="fa-solid fa-ban" aria-hidden="true"></i> Block</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- Reports -->
    <section class="adm-group adm-panel" data-group="reports" data-panel="reports"<?php echo ($show !== 'all' && $show !== 'reports') ? ' hidden' : ''; ?>>
        <header class="adm-group__head"><h2 class="adm-group__h">Reports</h2><span class="adm-group__n" data-count="reports"><?php echo count((array) $this->reports_queue); ?></span></header>
        <?php if (empty($this->reports_queue)): ?>
            <p class="adm-none adm-none--line">No open reports.</p>
        <?php else: ?>
        <div class="adm-table adm-table--reports">
            <div class="adm-table__head"><span>Reported</span><span>Reason</span><span>By</span><span>When</span><span></span></div>
            <div class="adm-table__body" id="admReports">
                <?php foreach ($this->reports_queue as $rp): ?>
                <div class="adm-row adm-rrow" data-report="<?php echo (int) $rp['id']; ?>" data-type="<?php echo $e($rp['target_type']); ?>">
                    <div class="adm-ucell adm-rcell--target">
                        <?php if ($rp['target_type'] === 'post'): ?>
                            <span class="adm-tag adm-tag--ppv">Post</span>
                            <span class="adm-rcell__what"><?php echo $e($rp['post_caption'] !== '' ? mb_substr($rp['post_caption'], 0, 70) : ('#' . $rp['target_id'])); ?></span>
                            <?php if ($rp['creator_handle'] !== ''): ?><a class="adm-rcell__who" href="/@<?php echo $e($rp['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($rp['creator_handle']); ?></a><?php endif; ?>
                        <?php else: ?>
                            <span class="adm-tag adm-tag--flag">Creator</span>
                            <a class="adm-rcell__what" href="/@<?php echo $e($rp['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($rp['creator_handle']); ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="adm-ucell">
                        <span class="adm-rcell__reason"><?php echo $e($rp['reason_label']); ?></span>
                        <?php if ($rp['details'] !== ''): ?><span class="adm-rcell__detail" title="<?php echo $e($rp['details']); ?>"><?php echo $e(mb_substr($rp['details'], 0, 60)); ?></span><?php endif; ?>
                    </div>
                    <div class="adm-ucell adm-ucell--muted">@<?php echo $e($rp['reporter_handle']); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($rp['created_at'], true)); ?></div>
                    <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Report actions', array(
                        array('text' => 'Dismiss', 'attrs' => 'data-report-action="dismiss"'),
                        $rp['target_type'] === 'post' ? array('text' => 'Remove post…', 'attrs' => 'data-report-action="remove"', 'danger' => true) : null,
                        array('text' => 'Suspend account…', 'attrs' => 'data-report-action="suspend"', 'danger' => true),
                    )); ?></div>
                </div>
                <?php endforeach; ?>
                <p class="adm-none" id="admReportsNone" hidden>All reports resolved.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <!-- Verification: identity requests + age checks -->
    <section class="adm-group adm-panel" data-group="verification" data-panel="verification"<?php echo ($show !== 'all' && $show !== 'verification') ? ' hidden' : ''; ?>>
        <header class="adm-group__head"><h2 class="adm-group__h">Verification</h2><span class="adm-group__n" data-count="verification"><?php echo $n['verification']; ?></span></header>
        <h3 class="adm-group__sub">Identity requests <span><?php echo count((array) $this->verifications); ?></span></h3>
        <?php if (empty($this->verifications)): ?>
            <p class="adm-none adm-none--line">No pending requests.</p>
        <?php else: ?>
        <div class="adm-table adm-table--verif">
            <div class="adm-table__head"><span>Creator</span><span>Legal name</span><span>Note</span><span>When</span><span></span></div>
            <div class="adm-table__body" id="admVerif">
                <?php foreach ($this->verifications as $v): $vname = trim((string) ($v['name'] ?? '')); $vname = $vname !== '' ? $vname : ('@' . $v['u_name']); ?>
                <div class="adm-row adm-vrow" data-verif="<?php echo (int) $v['id']; ?>">
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($vname)); ?></span>
                        <span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $v['user_id']; ?>"><?php echo $e($vname); ?></a><span class="adm-uinfo__meta"><a href="/@<?php echo $e($v['u_name']); ?>" target="_blank" rel="noopener">@<?php echo $e($v['u_name']); ?></a></span></span>
                    </div>
                    <div class="adm-ucell"><?php echo $e($v['full_name'] !== '' ? $v['full_name'] : '—'); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $v['note'] !== '' ? $e(mb_substr((string) $v['note'], 0, 80)) : '—'; ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($v['created_at'])); ?></div>
                    <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Verification actions', array(
                        array('text' => 'Approve', 'attrs' => 'data-verif-action="approve"'),
                        array('text' => 'Reject…', 'attrs' => 'data-verif-action="reject"', 'danger' => true),
                    )); ?></div>
                </div>
                <?php endforeach; ?>
                <p class="adm-none" id="admVerifNone" hidden>No pending requests.</p>
            </div>
        </div>
        <?php endif; ?>

        <h3 class="adm-group__sub adm-group__sub--gap">Age checks <span><?php echo (int) $ac['pending']; ?> pending</span></h3>
        <div class="adm-switch" role="tablist" aria-label="Age check status" id="admAgeTabs">
            <button type="button" class="adm-switch__b is-on" role="tab" aria-selected="true" data-age="open">Needs a look <b><?php echo (int) $ac['pending'] + (int) $ac['failed']; ?></b></button>
            <button type="button" class="adm-switch__b" role="tab" aria-selected="false" data-age="pending">Pending <b><?php echo (int) $ac['pending']; ?></b></button>
            <button type="button" class="adm-switch__b" role="tab" aria-selected="false" data-age="failed">Failed <b><?php echo (int) $ac['failed']; ?></b></button>
            <button type="button" class="adm-switch__b" role="tab" aria-selected="false" data-age="verified">Verified <b><?php echo (int) $ac['verified']; ?></b></button>
            <button type="button" class="adm-switch__b" role="tab" aria-selected="false" data-age="all">All <b><?php echo count((array) $this->age_rows); ?></b></button>
        </div>
        <div class="adm-table adm-table--age">
            <div class="adm-table__head"><span>Account</span><span>Status</span><span>Provider</span><span>Adult content</span><span>Updated</span><span></span></div>
            <div class="adm-table__body" id="admAge">
                <?php foreach ((array) $this->age_rows as $av): $an = trim((string) $av['name']); $an = $an !== '' ? $an : ('@' . $av['u_name']); $st = (string) $av['status']; ?>
                <div class="adm-row adm-agerow" data-age-row="<?php echo (int) $av['user_id']; ?>" data-status="<?php echo $e($st); ?>"<?php echo $st === 'verified' ? ' hidden' : ''; ?>>
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($an)); ?></span>
                        <span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $av['user_id']; ?>"><?php echo $e($an); ?></a><span class="adm-uinfo__meta">@<?php echo $e($av['u_name']); ?> · <?php echo $e($av['role_name'] ?: 'User'); ?></span></span>
                    </div>
                    <div class="adm-ucell"><span class="adm-pill <?php echo $e($av_pill[$st] ?? ''); ?>"><?php echo $e($av_label[$st] ?? ucfirst($st)); ?></span><?php if ($st === 'verified' && (string) $av['verified_at'] !== ''): ?> <span class="adm-ucell--muted"><?php echo $e($fmt($av['verified_at'])); ?></span><?php endif; ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e(ucfirst((string) $av['provider'])); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo !empty($av['adult_content_enabled']) ? 'Shown' : 'Hidden'; ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($av['updated_at'], true)); ?></div>
                    <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Age check actions', array(
                        array('text' => 'Open account', 'href' => '/admin/user/' . (int) $av['user_id']),
                        array('text' => 'Reset age verification…', 'attrs' => 'data-age-reset', 'danger' => true),
                    )); ?></div>
                </div>
                <?php endforeach; ?>
                <p class="adm-none" id="admAgeNone"<?php echo ((int) $ac['pending'] + (int) $ac['failed']) > 0 ? ' hidden' : ''; ?>>No age checks need a look.</p>
            </div>
        </div>
    </section>

    <!-- Support -->
    <section class="adm-group adm-panel" data-group="support" data-panel="support"<?php echo ($show !== 'all' && $show !== 'support') ? ' hidden' : ''; ?>>
        <header class="adm-group__head"><h2 class="adm-group__h">Support</h2><span class="adm-group__n" data-count="support"><?php echo $sup_counts['open']; ?></span></header>
        <div class="adm-switch" role="tablist" aria-label="Support status">
            <button type="button" class="adm-switch__b is-on" role="tab" aria-selected="true" data-sup="open">Waiting on us <b><?php echo $sup_counts['open']; ?></b></button>
            <button type="button" class="adm-switch__b" role="tab" aria-selected="false" data-sup="answered">Replied <b><?php echo $sup_counts['answered']; ?></b></button>
            <button type="button" class="adm-switch__b" role="tab" aria-selected="false" data-sup="closed">Closed <b><?php echo $sup_counts['closed']; ?></b></button>
            <button type="button" class="adm-switch__b" role="tab" aria-selected="false" data-sup="all">All <b><?php echo count((array) $this->support); ?></b></button>
        </div>
        <div class="adm-table adm-table--support">
            <div class="adm-table__head"><span>Request</span><span>From</span><span>Topic</span><span>Messages</span><span>Last activity</span><span>Status</span></div>
            <div class="adm-table__body" id="admSupport">
                <?php foreach ((array) $this->support as $st): $nm = trim((string) $st['first_name'] . ' ' . (string) $st['last_name']); ?>
                <div class="adm-row adm-suprow" role="link" tabindex="0" data-href="/support/ticket/<?php echo (int) $st['id']; ?>" data-status="<?php echo $e($st['status']); ?>"<?php echo $st['status'] !== 'open' ? ' hidden' : ''; ?>>
                    <span class="adm-ucell adm-suprow__subject"><?php echo $e($st['subject']); ?></span>
                    <span class="adm-ucell"><span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $st['user_id']; ?>"><?php echo $e($nm !== '' ? $nm : '@' . $st['u_name']); ?></a><span class="adm-uinfo__meta">@<?php echo $e($st['u_name']); ?></span></span></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e(SupportModel::CATEGORIES[$st['category']] ?? 'Something else'); ?></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo (int) $st['message_count']; ?></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($st['last_message_at'], true)); ?></span>
                    <span class="adm-ucell"><span class="adm-tag adm-tag--sup-<?php echo $e($st['status']); ?>"><?php echo $e($sup_status[$st['status']] ?? $st['status']); ?></span></span>
                </div>
                <?php endforeach; ?>
                <p class="adm-none" id="admSupportNone"<?php echo $sup_counts['open'] > 0 ? ' hidden' : ''; ?>>Nothing here.</p>
            </div>
        </div>
    </section>

    <!-- Billing: past due -->
    <section class="adm-group adm-panel" data-group="billing" data-panel="billing"<?php echo ($show !== 'all' && $show !== 'billing') ? ' hidden' : ''; ?>>
        <header class="adm-group__head"><h2 class="adm-group__h">Billing</h2><span class="adm-group__n" data-count="billing"><?php echo count((array) $this->billing); ?></span></header>
        <?php if (empty($this->billing)): ?>
            <p class="adm-none adm-none--line">No past-due plans.</p>
        <?php else: ?>
        <div class="adm-table adm-table--billing">
            <div class="adm-table__head"><span>Account</span><span>Plan</span><span>Add-ons</span><span>Status</span><span>Next charge</span><span>Last charge</span><span></span></div>
            <div class="adm-table__body">
            <?php foreach ((array) $this->billing as $b): $bn = trim($b['first_name'] . ' ' . $b['last_name']); $bn = $bn !== '' ? $bn : '@' . $b['u_name']; $bt = PlanTiers::get((string) $b['plan_key']);
                $adds = array();
                if ((int) $b['influencer_slots'] > 0) { $adds[] = (int) $b['influencer_slots'] . ' extra influencer' . ((int) $b['influencer_slots'] === 1 ? '' : 's'); }
                if ((int) $b['pack_dollars'] > 0) { $adds[] = '$' . (int) $b['pack_dollars'] . ' AI credit pack'; } ?>
            <div class="adm-row adm-urow" data-uid="<?php echo (int) $b['user_id']; ?>">
                <div class="adm-ucell adm-ucell--user"><span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $b['user_id']; ?>"><?php echo $e($bn); ?></a><span class="adm-uinfo__meta"><?php echo $e($b['user_email']); ?></span></span></div>
                <div class="adm-ucell"><?php echo $e($bt ? $bt['name'] : $b['plan_key']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($adds ? implode(', ', $adds) : '—'); ?></div>
                <div class="adm-ucell"><span class="adm-status adm-status--off"><span class="adm-status__dot"></span>Past due</span></div>
                <div class="adm-ucell adm-ucell--muted"><?php $bnx = BillingService::next_charge($b); echo $bnx ? $e(BillingService::money($bnx['total']) . ' · ' . $fmt($bnx['at'])) : '—'; ?><?php if (!empty($b['next_retry_at'])): ?><br>Retry <?php echo $e($fmt($b['next_retry_at'])); ?><?php endif; ?></div>
                <div class="adm-ucell adm-ucell--muted" <?php echo !empty($b['last_failure']) ? 'title="' . $e($b['last_failure']) . '"' : ''; ?>><?php echo !empty($b['last_charge_at']) ? $e(ucfirst(str_replace('_', ' ', (string) $b['last_charge_status'])) . ' · ' . BillingService::money((int) $b['last_amount_cents'])) : '—'; ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Billing actions', array(array('text' => 'Retry charge now', 'attrs' => 'data-billing-retry'), array('text' => 'Open account', 'href' => '/admin/user/' . (int) $b['user_id']))); ?></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
