<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php
/* /admin > Affiliates: applications and affiliates, the commissions ledger, payout requests. Row actions in the ⋯ menu (public/js/admin-affiliates.js). */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$af_rows    = (array) ($this->affiliates ?? array());
$af_ledger  = (array) ($this->aff_ledger ?? array());
$af_payouts = (array) ($this->aff_payouts ?? array());
$af_tz  = (string) ($this->timezone ?? 'UTC');
$af_day = function ($utc) use ($af_tz) {
    if ((string) $utc === '') { return ''; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($af_tz ?: 'UTC')); return $d->format('M j, Y'); } catch (\Throwable $ex) { return ''; }
};
$af_usd  = function ($c) { return Affiliates::money($c); };
$af_tags = array('approved' => 'adm-pill--ok', 'pending' => 'adm-pill--warn', 'requested' => 'adm-pill--warn', 'earned' => 'adm-pill--warn', 'paid' => 'adm-pill--ok', 'rejected' => 'adm-pill--bad', 'reversed' => 'adm-pill--bad', 'disabled' => '');
$af_pending = 0; foreach ($af_rows as $r) { if ((string) $r['status'] === 'pending') { $af_pending++; } }
$af_open = 0; $af_open_cents = 0; foreach ($af_payouts as $p) { if ((string) $p['status'] === 'requested') { $af_open++; $af_open_cents += (int) $p['amount_cents']; } }
$af_owed = (int) ($this->aff_owed ?? 0);
$af_name = function ($r) { $n = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']); return $n !== '' ? $n : '@' . $r['u_name']; };
?>
<section class="adm-sec adm-panel" data-panel="affiliates">
    <div class="adm-sec__head">
        <div class="adm-subtabs" role="tablist" aria-label="Affiliates" id="admAffTabs">
            <button type="button" class="adm-subtab is-active" role="tab" aria-selected="true" data-aff-tab="list">Affiliates <b><?php echo count($af_rows); ?></b></button>
            <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-aff-tab="ledger">Commissions <b><?php echo count($af_ledger); ?></b></button>
            <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-aff-tab="payouts">Payouts <b><?php echo count($af_payouts); ?></b></button>
        </div>
        <form method="post" action="/api/admin_affiliates_csv">
            <?php echo CSRF::field(); ?>
            <button type="submit" class="adm-btn"><i class="fa-solid fa-download" aria-hidden="true"></i> Export CSV</button>
        </form>
    </div>
    <div class="adm-kpis">
        <div class="adm-kpi"><div class="adm-kpi__top"><span class="adm-kpi__label">Applications waiting</span><i class="adm-kpi__ic fa-solid fa-inbox" aria-hidden="true"></i></div><div class="adm-kpi__val"><?php echo number_format($af_pending); ?></div><div class="adm-kpi__sub"><?php echo number_format(count($af_rows) - $af_pending); ?> decided</div></div>
        <div class="adm-kpi"><div class="adm-kpi__top"><span class="adm-kpi__label">Payout requests</span><i class="adm-kpi__ic fa-solid fa-building-columns" aria-hidden="true"></i></div><div class="adm-kpi__val"><?php echo $af_usd($af_open_cents); ?></div><div class="adm-kpi__sub"><?php echo number_format($af_open); ?> to pay by bank</div></div>
        <div class="adm-kpi"><div class="adm-kpi__top"><span class="adm-kpi__label">Earned, not paid</span><i class="adm-kpi__ic fa-solid fa-handshake" aria-hidden="true"></i></div><div class="adm-kpi__val"><?php echo $af_usd($af_owed); ?></div><div class="adm-kpi__sub"><?php echo (int) Affiliates::RATE_PERCENT; ?>% of plan payments</div></div>
    </div>

    <div data-aff-pane="list">
    <?php if (empty($af_rows)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-handshake"></i></span><p class="adm-empty__t">No Affiliates Yet</p><p class="adm-empty__x">Applications from /affiliates/apply show here.</p></div>
    <?php else: ?>
    <div class="adm-table" style="--adm-cols:minmax(200px,1.3fr) minmax(140px,1fr) 100px 70px 70px 100px 100px 40px">
        <div class="adm-table__head"><span>Affiliate</span><span>Promotes on</span><span>Status</span><span>Clicks</span><span>Signups</span><span>Earned</span><span>Paid</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($af_rows as $r): $st = (string) $r['status']; $site = html_entity_decode((string) $r['website'], ENT_QUOTES, 'UTF-8'); ?>
            <div class="adm-urow adm-afrow" data-affiliate="<?php echo (int) $r['id']; ?>">
                <div class="adm-ucell adm-ucell--user"><span class="adm-uinfo"><a class="adm-uinfo__name" href="/admin/user/<?php echo (int) $r['user_id']; ?>"><?php echo $e($af_name($r)); ?></a><span class="adm-uinfo__meta"><?php echo $e($r['code']); ?> &middot; <?php echo $e($af_day($r['created_at'])); ?></span></span></div>
                <div class="adm-ucell adm-ucell--muted" title="<?php echo $e(html_entity_decode((string) $r['note'], ENT_QUOTES, 'UTF-8')); ?>"><a href="<?php echo $e($site); ?>" target="_blank" rel="noopener nofollow"><?php echo $e(preg_replace('#^https?://(www\.)?#i', '', $site)); ?></a></div>
                <div class="adm-ucell"><span class="adm-pill <?php echo $e($af_tags[$st] ?? ''); ?>"><?php echo $e(ucfirst($st)); ?></span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo number_format((int) $r['clicks']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo number_format((int) $r['signups']); ?></div>
                <div class="adm-ucell"><?php echo $af_usd($r['earned_cents']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $af_usd($r['paid_cents']); ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Affiliate actions', array(
                    in_array($st, array('pending', 'rejected', 'disabled'), true) ? array('text' => 'Approve', 'attrs' => 'data-aff-set="approved"') : null,
                    $st === 'pending' ? array('text' => 'Reject', 'attrs' => 'data-aff-set="rejected"', 'danger' => true) : null,
                    $st === 'approved' ? array('text' => 'Disable', 'attrs' => 'data-aff-set="disabled"', 'danger' => true) : null,
                )); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <div data-aff-pane="ledger" hidden>
    <?php if (empty($af_ledger)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-receipt"></i></span><p class="adm-empty__t">No Commissions Yet</p></div>
    <?php else: ?>
    <div class="adm-table" style="--adm-cols:110px minmax(120px,1fr) minmax(120px,1fr) 90px 100px 100px 100px">
        <div class="adm-table__head"><span>Date</span><span>Affiliate</span><span>Referred account</span><span>Charge</span><span>Invoice</span><span>Commission</span><span>Status</span></div>
        <div class="adm-table__body">
            <?php foreach ($af_ledger as $c): $st = (string) $c['status']; ?>
            <div class="adm-urow">
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($af_day($c['created_at'])); ?></div>
                <div class="adm-ucell"><?php echo $e($c['code']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><a href="/admin/user/<?php echo (int) $c['referred_user_id']; ?>">@<?php echo $e($c['referred_handle']); ?></a></div>
                <div class="adm-ucell adm-ucell--muted">#<?php echo (int) $c['charge_id']; ?> <?php echo $e((int) $c['invoice_cents'] < 0 ? 'adjust' : (string) $c['kind']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $af_usd($c['invoice_cents']); ?></div>
                <div class="adm-ucell"><?php echo $af_usd($c['commission_cents']); ?></div>
                <div class="adm-ucell"><span class="adm-pill <?php echo $e($af_tags[$st] ?? ''); ?>"><?php echo $e(ucfirst($st)); ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <div data-aff-pane="payouts" hidden>
    <?php if (empty($af_payouts)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-building-columns"></i></span><p class="adm-empty__t">No Payout Requests Yet</p></div>
    <?php else: ?>
    <div class="adm-table" style="--adm-cols:minmax(200px,1.3fr) 110px 110px 100px 110px minmax(120px,1fr) 40px">
        <div class="adm-table__head"><span>Affiliate</span><span>Requested</span><span>Amount</span><span>Status</span><span>Paid</span><span>Note</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($af_payouts as $p): $st = (string) $p['status']; ?>
            <div class="adm-urow adm-afpay" data-payout="<?php echo (int) $p['id']; ?>">
                <div class="adm-ucell adm-ucell--user"><span class="adm-uinfo"><a class="adm-uinfo__name" href="/admin/user/<?php echo (int) $p['user_id']; ?>"><?php echo $e($af_name($p)); ?></a><span class="adm-uinfo__meta"><?php echo $e($p['code']); ?><?php if ((string) $p['user_email'] !== ''): ?> &middot; <?php echo $e($p['user_email']); ?><?php endif; ?></span></span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($af_day($p['requested_at'])); ?></div>
                <div class="adm-ucell"><?php echo $af_usd($p['amount_cents']); ?></div>
                <div class="adm-ucell"><span class="adm-pill <?php echo $e($af_tags[$st] ?? ''); ?>"><?php echo $e(ucfirst($st)); ?></span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($af_day($p['paid_at'])); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e(html_entity_decode((string) $p['note'], ENT_QUOTES, 'UTF-8')); ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo $st === 'requested' ? adm_row_menu('Payout actions', array(
                    array('text' => 'Mark Paid', 'attrs' => 'data-aff-payout="paid"'),
                    array('text' => 'Reject', 'attrs' => 'data-aff-payout="rejected"', 'danger' => true),
                )) : ''; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    </div>
</section>
