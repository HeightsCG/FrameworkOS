<?php
/* Admin > Support: the whole help-desk queue. Rows open /support/ticket/<id>, where staff reply. */
$sup_status = array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed');
$sup_counts = array('open' => 0, 'answered' => 0, 'closed' => 0);
foreach ($this->support as $st) { if (isset($sup_counts[$st['status']])) { $sup_counts[$st['status']]++; } }
?>
<section class="adm-sec adm-panel" data-panel="support">
    <div class="adm-subtabs" role="tablist" aria-label="Support">
        <button type="button" class="adm-subtab is-active" role="tab" aria-selected="true" data-sup="open">Waiting on Us <b><?php echo $sup_counts['open']; ?></b></button>
        <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-sup="answered">Replied <b><?php echo $sup_counts['answered']; ?></b></button>
        <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-sup="closed">Closed <b><?php echo $sup_counts['closed']; ?></b></button>
        <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-sup="all">All <b><?php echo count($this->support); ?></b></button>
    </div>
    <div class="adm-table adm-table--support">
        <div class="adm-table__head"><span>Request</span><span>From</span><span>Topic</span><span>Messages</span><span>Last activity</span><span>Status</span></div>
        <div class="adm-table__body" id="admSupport">
            <?php foreach ($this->support as $st): $nm = trim((string) $st['first_name'] . ' ' . (string) $st['last_name']); ?>
            <div class="adm-suprow" role="link" tabindex="0" data-href="/support/ticket/<?php echo (int) $st['id']; ?>" data-status="<?php echo $e($st['status']); ?>"<?php echo $st['status'] !== 'open' ? ' hidden' : ''; ?>>
                <span class="adm-ucell adm-suprow__subject"><?php echo $e($st['subject']); ?></span>
                <span class="adm-ucell"><span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $st['user_id']; ?>"><?php echo $e($nm !== '' ? $nm : '@' . $st['u_name']); ?></a><span class="adm-uinfo__sub">@<?php echo $e($st['u_name']); ?></span></span></span>
                <span class="adm-ucell adm-ucell--muted"><?php echo $e(SupportModel::CATEGORIES[$st['category']] ?? 'Something else'); ?></span>
                <span class="adm-ucell adm-ucell--muted"><?php echo (int) $st['message_count']; ?></span>
                <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($st['last_message_at'], true)); ?></span>
                <span class="adm-ucell"><span class="adm-tag adm-tag--sup-<?php echo $e($st['status']); ?>"><?php echo $e($sup_status[$st['status']] ?? $st['status']); ?></span></span>
            </div>
            <?php endforeach; ?>
            <div class="adm-empty" id="admSupportNone"<?php echo $sup_counts['open'] > 0 ? ' hidden' : ''; ?>>
                <span class="adm-empty__ic"><i class="fa-solid fa-life-ring"></i></span>
                <p class="adm-empty__t">Nothing Here</p>
            </div>
        </div>
    </div>
</section>
