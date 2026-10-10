<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php
/* Admin > Growth > Affiliates: applications and affiliates, the commissions ledger, payout requests. Row actions in the ⋯ menu (public/js/admin-affiliates.js). */
$af_rows    = (array) ($this->affiliates ?? array());
$af_ledger  = (array) ($this->aff_ledger ?? array());
$af_payouts = (array) ($this->aff_payouts ?? array());
$af_day  = function ($utc) use ($fmt) { return (string) $utc === '' ? '' : $fmt($utc); };
$af_usd  = function ($c) { return Affiliates::money($c); };
$af_pending = 0; foreach ($af_rows as $r) { if ((string) $r['status'] === 'pending') { $af_pending++; } }
$af_open = 0; $af_open_cents = 0; foreach ($af_payouts as $p) { if ((string) $p['status'] === 'requested') { $af_open++; $af_open_cents += (int) $p['amount_cents']; } }
$af_owed = (int) ($this->aff_owed ?? 0);
$af_name = function ($r) { $n = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']); return $n !== '' ? $n : '@' . $r['u_name']; };
?>
<section class="adm-panel" data-panel="affiliates">
    <div class="adm-box adm-box--strip"><dl class="adm-strip">
        <div><dt>Applications waiting</dt><dd><?php echo number_format($af_pending); ?></dd><dd class="adm-strip__sub"><?php echo number_format(count($af_rows) - $af_pending); ?> decided</dd></div>
        <div><dt>Payout requests</dt><dd><?php echo $af_usd($af_open_cents); ?></dd><dd class="adm-strip__sub"><?php echo number_format($af_open); ?> to pay by bank</dd></div>
        <div><dt>Earned, not paid</dt><dd><?php echo $af_usd($af_owed); ?></dd><dd class="adm-strip__sub"><?php echo (int) Affiliates::RATE_PERCENT; ?>% of plan payments</dd></div>
    </dl></div>
    <div class="adm-box">
    <header class="adm-box__h">
        <div class="adm-seg" role="tablist" aria-label="Affiliates" id="admAffTabs">
            <button type="button" class="adm-seg__b is-active is-on" role="tab" aria-selected="true" data-aff-tab="list">Affiliates <b><?php echo count($af_rows); ?></b></button>
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-aff-tab="ledger">Commissions <b><?php echo count($af_ledger); ?></b></button>
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-aff-tab="payouts">Payouts <b><?php echo count($af_payouts); ?></b></button>
        </div>
        <form method="post" action="/api/admin_affiliates_csv" class="adm-box__end">
            <?php echo CSRF::field(); ?>
            <button type="submit" class="adm-btn">Export CSV</button>
        </form>
    </header>

    <div data-aff-pane="list">
    <?php if (empty($af_rows)): ?>
        <?php echo adm_empty('No affiliates yet', 'fa-handshake'); ?>
    <?php else: ?>
    <table class="adm-t" data-sortable>
        <thead><tr><th data-sort="text">Affiliate</th><th>Promotes on</th><th data-sort="text">Status</th><th class="adm-r" data-sort="num">Clicks</th><th class="adm-r" data-sort="num">Signups</th><th class="adm-r" data-sort="num">Earned</th><th class="adm-r" data-sort="num">Paid</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($af_rows as $r): $st = (string) $r['status']; $site = html_entity_decode((string) $r['website'], ENT_QUOTES, 'UTF-8'); ?>
            <tr class="adm-urow adm-afrow adm-t__link" data-affiliate="<?php echo (int) $r['id']; ?>" data-href="/admin/user/<?php echo (int) $r['user_id']; ?>">
                <td class="adm-t__who"><?php echo adm_who($af_name($r), $r['u_name'], '/admin/user/' . (int) $r['user_id'], '', '@' . $r['u_name'] . ' · ' . $r['code']); ?></td>
                <td class="adm-t__muted adm-t__trunc" title="<?php echo $e(html_entity_decode((string) $r['note'], ENT_QUOTES, 'UTF-8')); ?>"><a href="<?php echo $e($site); ?>" target="_blank" rel="noopener nofollow"><?php echo $e(preg_replace('#^https?://(www\.)?#', '', $site)); ?></a></td>
                <td><?php echo adm_pill(ucfirst($st)); ?></td>
                <td class="adm-r adm-t__num adm-t__muted" data-value="<?php echo (int) $r['clicks']; ?>"><?php echo number_format((int) $r['clicks']); ?></td>
                <td class="adm-r adm-t__num adm-t__muted" data-value="<?php echo (int) $r['signups']; ?>"><?php echo number_format((int) $r['signups']); ?></td>
                <td class="adm-r adm-t__num" data-value="<?php echo (int) $r['earned_cents']; ?>"><?php echo $af_usd($r['earned_cents']); ?></td>
                <td class="adm-r adm-t__num adm-t__muted" data-value="<?php echo (int) $r['paid_cents']; ?>"><?php echo $af_usd($r['paid_cents']); ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Affiliate actions', array(
                    in_array($st, array('pending', 'rejected', 'disabled'), true) ? array('text' => 'Approve', 'attrs' => 'data-aff-set="approved"') : null,
                    $st === 'pending' ? array('text' => 'Reject', 'attrs' => 'data-aff-set="rejected"', 'danger' => true) : null,
                    $st === 'approved' ? array('text' => 'Disable', 'attrs' => 'data-aff-set="disabled"', 'danger' => true) : null,
                )); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>

    <div data-aff-pane="ledger" hidden>
    <?php if (empty($af_ledger)): ?>
        <?php echo adm_empty('No commissions yet', 'fa-receipt'); ?>
    <?php else: ?>
    <table class="adm-t" data-sortable>
        <thead><tr><th data-sort="text" data-sorted="desc">Date</th><th data-sort="text">Affiliate</th><th data-sort="text">Referred account</th><th>Charge</th><th class="adm-r" data-sort="num">Invoice</th><th class="adm-r" data-sort="num">Commission</th><th data-sort="text">Status</th></tr></thead>
        <tbody>
        <?php foreach ($af_ledger as $c): $st = (string) $c['status']; ?>
            <tr>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($c['created_at']); ?>"><?php echo $e($af_day($c['created_at'])); ?></td>
                <td><?php echo $e($c['code']); ?></td>
                <td class="adm-t__main"><a href="/admin/user/<?php echo (int) $c['referred_user_id']; ?>">@<?php echo $e($c['referred_handle']); ?></a></td>
                <td class="adm-t__muted">#<?php echo (int) $c['charge_id']; ?> <?php echo $e((int) $c['invoice_cents'] < 0 ? 'adjust' : (string) $c['kind']); ?></td>
                <td class="adm-r adm-t__num adm-t__muted" data-value="<?php echo (int) $c['invoice_cents']; ?>"><?php echo $af_usd($c['invoice_cents']); ?></td>
                <td class="adm-r adm-t__num" data-value="<?php echo (int) $c['commission_cents']; ?>"><?php echo $af_usd($c['commission_cents']); ?></td>
                <td><?php echo adm_pill(ucfirst($st)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>

    <div data-aff-pane="payouts" hidden>
    <?php if (empty($af_payouts)): ?>
        <?php echo adm_empty('No payout requests yet', 'fa-building-columns'); ?>
    <?php else: ?>
    <table class="adm-t" data-sortable>
        <thead><tr><th data-sort="text">Affiliate</th><th data-sort="text" data-sorted="desc">Requested</th><th class="adm-r" data-sort="num">Amount</th><th data-sort="text">Status</th><th data-sort="text">Paid</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($af_payouts as $p): $st = (string) $p['status']; ?>
            <tr class="adm-urow adm-afpay adm-t__link" data-payout="<?php echo (int) $p['id']; ?>" data-href="/admin/user/<?php echo (int) $p['user_id']; ?>">
                <td class="adm-t__who"><?php echo adm_who($af_name($p), $p['u_name'], '/admin/user/' . (int) $p['user_id'], '', '@' . $p['u_name'] . ' · ' . $p['code']); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($p['requested_at']); ?>"><?php echo $e($af_day($p['requested_at'])); ?></td>
                <td class="adm-r adm-t__num" data-value="<?php echo (int) $p['amount_cents']; ?>"><?php echo $af_usd($p['amount_cents']); ?></td>
                <td><?php echo adm_pill(ucfirst($st)); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($p['paid_at']); ?>"><?php echo $e($af_day($p['paid_at'])); ?></td>
                <td class="adm-t__muted adm-t__trunc" title="<?php echo $e(html_entity_decode((string) $p['note'], ENT_QUOTES, 'UTF-8')); ?>"><?php echo $e(html_entity_decode((string) $p['note'], ENT_QUOTES, 'UTF-8')); ?></td>
                <td class="adm-t__act"><?php echo $st === 'requested' ? adm_row_menu('Payout actions', array(
                    array('text' => 'Mark Paid', 'attrs' => 'data-aff-payout="paid"'),
                    array('text' => 'Reject', 'attrs' => 'data-aff-payout="rejected"', 'danger' => true),
                )) : ''; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>
    </div>
</section>
