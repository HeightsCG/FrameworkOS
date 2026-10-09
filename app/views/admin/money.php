<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/money — financials (period cards, revenue by month, small multiples, sales by type, monthly table), plan billing,
   recent sales, chargebacks. One page, four sections, a sticky section index. Charts: public/js/admin.js (fz-*). */
$f = $this->fin; $ft = $f['sales_total'];
$last12 = array_slice((array) $this->series, -12);
// Plans offered today, plus any retired tier that still has live subscribers (never hide money that is in the total).
$tiers = array_values(array_filter(PlanTiers::offered(), function ($t) { return (int) $t['price'] > 0; }));   // paid plans only: Free has no MRR
$offered_keys = array_map(function ($t) { return $t['key']; }, $tiers);
foreach (PlanTiers::all() as $pt) { if (!in_array($pt['key'], $offered_keys, true) && (int) ($f['plans'][$pt['key']]['n'] ?? 0) > 0) { $pt['name'] .= ' (legacy)'; $tiers[] = $pt; } }
$bill_due = 0; foreach ((array) $this->billing as $b) { if ((string) $b['status'] === 'past_due') { $bill_due++; } }
?>
<header class="adm-head">
    <div>
        <h1 class="adm-head__title">Financials</h1>
        <p class="adm-head__sub">Plan payments, our fee on sales and memberships, credits, refunds and payouts, from the ledgers</p>
    </div>
</header>
<nav class="adm-index" aria-label="Sections" id="admIndex">
    <a href="#overview" class="is-on">Overview</a><a href="#billing">Plan Billing<?php echo $bill_due > 0 ? ' <b>' . $bill_due . '</b>' : ''; ?></a><a href="#sales">Recent Sales</a><a href="#chargebacks">Chargebacks</a>
</nav>

<section class="adm-sec" id="overview">
    <div class="fz-top">
        <div class="fz-period" role="tablist" aria-label="Period">
            <button type="button" class="is-on" role="tab" aria-selected="true" data-p="1">This Month</button>
            <button type="button" role="tab" aria-selected="false" data-p="last">Last Month</button>
            <button type="button" role="tab" aria-selected="false" data-p="3">Last 3 Months</button>
            <button type="button" role="tab" aria-selected="false" data-p="12">Last 12 Months</button>
        </div>
        <span class="fz-period__range" id="fzRange"></span>
    </div>

    <div class="fz" id="fzCards" data-series="<?php echo $e(json_encode($this->series)); ?>">
        <article class="fz-card">
            <header class="fz-card__head"><span class="fz-card__label">Monthly Recurring Revenue</span></header>
            <div class="fz-card__val"><?php echo $usd($f['plan_mrr']); ?><small>right now</small></div>
            <div class="fz-split" aria-hidden="true"><?php foreach ($tiers as $i => $pt): $n = (int) ($f['plans'][$pt['key']]['n'] ?? 0); if ($n <= 0) { continue; } ?><span class="fz-split__seg fz-split__seg--<?php echo $i; ?>" style="flex:<?php echo $n; ?>"></span><?php endforeach; ?><?php if ((int) $f['plan_count'] === 0): ?><span class="fz-split__seg fz-split__seg--none" style="flex:1"></span><?php endif; ?></div>
            <ul class="fz-legend"><?php foreach ($tiers as $i => $pt): ?><li><i class="fz-split__seg--<?php echo $i; ?>"></i><?php echo $e($pt['name']); ?> <b><?php echo (int) ($f['plans'][$pt['key']]['n'] ?? 0); ?></b></li><?php endforeach; ?></ul>
        </article>
        <article class="fz-card" data-m="revenue">
            <header class="fz-card__head"><span class="fz-card__label">Platform Revenue</span></header>
            <div class="fz-card__val" data-v>—</div>
            <span class="fz-delta" data-d></span>
            <dl class="fz-foot fz-foot--3"><div><dt>Plan payments</dt><dd data-f="plans">—</dd></div><div><dt>Fee on sales</dt><dd data-f="fee">—</dd></div><div><dt>Fee on memberships</dt><dd data-f="members">—</dd></div></dl>
        </article>
        <article class="fz-card" data-m="cash_in">
            <header class="fz-card__head"><span class="fz-card__label">Money In From Credits</span></header>
            <div class="fz-card__val" data-v>—</div>
            <span class="fz-delta" data-d></span>
            <dl class="fz-foot"><div><dt>Credits bought</dt><dd data-f="credits">—</dd></div><div><dt>AI credits bought</dt><dd data-f="ai">—</dd></div></dl>
        </article>
        <article class="fz-card" data-m="payouts">
            <header class="fz-card__head"><span class="fz-card__label">Paid Out to Creators</span></header>
            <div class="fz-card__val" data-v>—</div>
            <span class="fz-delta" data-d></span>
            <dl class="fz-foot"><div><dt>Refunds</dt><dd data-f="refunds">—</dd></div><div><dt>Owed now</dt><dd><?php echo $usd($f['owed_creators']); ?></dd></div></dl>
        </article>
    </div>

    <section class="fz-rev" id="fzRev">
        <header class="fz-rev__head">
            <div><h2 class="adm-sec__title">Revenue by Month</h2><p class="fz-rev__sub">Plan payments plus our fee on sales and memberships, last 12 months</p></div>
            <div class="fz-rev__total"><span>12-month total</span><b id="fzRevTotal">—</b></div>
        </header>
        <div class="fz-rev__plot"><svg class="fz-rev__svg" role="img" aria-label="Platform revenue by month"></svg><div class="fz-tip" hidden></div></div>
    </section>

    <div class="fz-minis" id="fzMinis">
        <?php foreach (array('revenue' => array('Platform revenue', '#1f9d6b'), 'plans' => array('Plan payments', '#FF6A13'), 'fee' => array('Our fee on sales', '#0f8a5f'),
                             'cash_in' => array('Money in from credits', '#3b6fd1'), 'refunds' => array('Refunds', '#cf3b3b'), 'payouts' => array('Paid out to creators', '#b07514')) as $mk => $md): ?>
        <article class="fz-mini" data-m="<?php echo $mk; ?>" data-c="<?php echo $md[1]; ?>">
            <header><span><?php echo $e($md[0]); ?></span><b data-t>—</b></header>
            <svg class="fz-mini__svg" viewBox="0 0 300 90" preserveAspectRatio="none" aria-hidden="true"></svg>
            <footer><span data-a></span><span data-z></span></footer>
        </article>
        <?php endforeach; ?>
    </div>

    <div class="adm-sec__head adm-sec__head--gap"><h2 class="adm-sec__title">Sales by Type</h2></div>
    <div class="adm-table adm-table--fin">
        <div class="adm-table__head"><span>Type</span><span class="adm-r">Sales</span><span class="adm-r">Gross</span><span class="adm-r">Refunded</span><span class="adm-r">To creators</span><span class="adm-r">Our fee</span></div>
        <div class="adm-table__body" id="fzTypes"></div>
    </div>

    <div class="adm-sec__head adm-sec__head--gap"><h2 class="adm-sec__title">Monthly Breakdown</h2></div>
    <div class="adm-table adm-table--months">
        <div class="adm-table__head"><span>Month</span><span class="adm-r">Plan payments</span><span class="adm-r">Our fee on sales</span><span class="adm-r">Our fee on memberships</span><span class="adm-r">Platform revenue</span><span class="adm-r">Credits bought</span><span class="adm-r">AI credits bought</span><span class="adm-r">Refunds</span><span class="adm-r">Paid out</span></div>
        <div class="adm-table__body">
            <?php $mt = array('plans' => 0, 'fee' => 0, 'members' => 0, 'revenue' => 0, 'credits' => 0, 'ai' => 0, 'refunds' => 0, 'payouts' => 0);
            foreach (array_reverse($last12) as $m): foreach ($mt as $mk => $mv) { $mt[$mk] += (int) $m[$mk]; } ?>
            <div class="adm-row adm-mrow"><span class="adm-ucell"><?php echo $e(gmdate('M Y', strtotime($m['k'] . '-01'))); ?></span><?php foreach (array_keys($mt) as $col): ?><span class="adm-ucell adm-r<?php echo $col === 'revenue' ? ' adm-mrow__rev' : ''; ?><?php echo (int) $m[$col] === 0 ? ' adm-ucell--muted' : ''; ?>"><?php echo $usd($m[$col]); ?></span><?php endforeach; ?></div>
            <?php endforeach; ?>
            <div class="adm-row adm-mrow adm-frow--total"><span class="adm-ucell"><b>Total</b></span><?php foreach ($mt as $col => $v): ?><span class="adm-ucell adm-r"><b><?php echo $usd($v); ?></b></span><?php endforeach; ?></div>
        </div>
    </div>
</section>

<section class="adm-sec" id="billing">
    <div class="adm-sec__head"><h2 class="adm-sec__title">Plan Billing</h2><span class="adm-sec__meta"><?php echo count((array) $this->billing); ?> accounts<?php echo $bill_due > 0 ? ' · ' . $bill_due . ' past due' : ''; ?></span></div>
    <?php if (empty($this->billing)): ?>
    <p class="adm-none adm-none--line">No paid plans or monthly packs yet.</p>
    <?php else: ?>
    <div class="adm-table adm-table--billing">
        <div class="adm-table__head"><span>Account</span><span>Plan</span><span>Add-ons</span><span>Status</span><span>Next charge</span><span>Last charge</span><span></span></div>
        <div class="adm-table__body">
        <?php foreach ((array) $this->billing as $b):
            $bn = trim($b['first_name'] . ' ' . $b['last_name']); $bn = $bn !== '' ? $bn : '@' . $b['u_name'];
            $bt = PlanTiers::get((string) $b['plan_key']); $bst = (string) $b['status'];
            $adds = array();
            if ((int) $b['influencer_slots'] > 0) { $adds[] = (int) $b['influencer_slots'] . ' extra influencer' . ((int) $b['influencer_slots'] === 1 ? '' : 's'); }
            if ((int) $b['pack_dollars'] > 0) { $adds[] = '$' . (int) $b['pack_dollars'] . ' AI credit pack (' . number_format(PlanTiers::pack_credits((int) $b['pack_dollars'])) . ' AI credits)'; } ?>
        <div class="adm-row adm-urow" data-uid="<?php echo (int) $b['user_id']; ?>">
            <div class="adm-ucell adm-ucell--user"><span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $b['user_id']; ?>"><?php echo $e($bn); ?></a><span class="adm-uinfo__meta"><?php echo $e($b['user_email']); ?></span></span></div>
            <div class="adm-ucell"><?php echo $e($bt ? $bt['name'] : $b['plan_key']); ?><?php if (!empty($b['cancel_at_period_end'])): ?> <span class="adm-pill adm-pill--warn">Canceling</span><?php endif; ?></div>
            <div class="adm-ucell adm-ucell--muted"><?php echo $e($adds ? implode(', ', $adds) : '—'); ?></div>
            <div class="adm-ucell"><span class="adm-status adm-status--<?php echo $bst === 'past_due' ? 'off' : 'on'; ?>"><span class="adm-status__dot"></span><?php echo $e($bst === 'past_due' ? 'Past due' : ucfirst($bst)); ?></span></div>
            <div class="adm-ucell adm-ucell--muted"><?php $bnx = BillingService::next_charge($b); echo $bnx ? $e(BillingService::money($bnx['total']) . ' · ' . $fmt($bnx['at'])) : '—'; ?><?php if ($bst === 'past_due' && !empty($b['next_retry_at'])): ?><br>Retry <?php echo $e($fmt($b['next_retry_at'])); ?><?php endif; ?></div>
            <div class="adm-ucell adm-ucell--muted" <?php echo !empty($b['last_failure']) ? 'title="' . $e($b['last_failure']) . '"' : ''; ?>><?php echo !empty($b['last_charge_at']) ? $e(ucfirst(str_replace('_', ' ', (string) $b['last_charge_status'])) . ' · ' . BillingService::money((int) $b['last_amount_cents'])) : '—'; ?></div>
            <div class="adm-ucell adm-ucell--act"><?php echo $bst === 'past_due' ? adm_row_menu('Billing actions', array(array('text' => 'Retry charge now', 'attrs' => 'data-billing-retry'))) : ''; ?></div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>

<section class="adm-sec" id="sales">
    <div class="adm-sec__head"><h2 class="adm-sec__title">Recent Sales</h2><span class="adm-sec__meta">Refunded sales drop off the list</span></div>
    <?php if (empty($this->sales)): ?>
    <p class="adm-none adm-none--line">No sales yet.</p>
    <?php else: ?>
    <div class="adm-table adm-table--sales">
        <div class="adm-table__head"><span>Buyer</span><span>Item</span><span>Type</span><span class="adm-r">Amount</span><span>Date</span><span></span></div>
        <div class="adm-table__body" id="admSales">
            <?php foreach ($this->sales as $sale): ?>
            <div class="adm-row adm-srow" data-kind="<?php echo $e($sale['kind']); ?>" data-ref="<?php echo (int) $sale['ref_id']; ?>" data-fan="<?php echo (int) $sale['fan_id']; ?>">
                <div class="adm-ucell adm-ucell--user">
                    <span class="adm-uav"><?php echo $e($ini($sale['fan_name'])); ?></span>
                    <span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $sale['fan_id']; ?>">@<?php echo $e($sale['fan_handle']); ?></a></span>
                </div>
                <div class="adm-ucell adm-ucell--ellipsis"><?php echo $e($sale['item'] !== '' ? $sale['item'] : 'Untitled'); ?></div>
                <div class="adm-ucell"><span class="adm-tag adm-tag--<?php echo $sale['kind']; ?>"><?php echo $sale['kind'] === 'bundle' ? 'Bundle' : ($sale['kind'] === 'message' ? 'Message' : 'PPV'); ?></span></div>
                <div class="adm-ucell adm-r adm-scell--amt">$<?php echo number_format(((int) $sale['credits']) / 10, 2); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($sale['created_at'], true)); ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Sale actions', array(array('text' => 'Refund…', 'attrs' => 'data-refund', 'danger' => true))); ?></div>
            </div>
            <?php endforeach; ?>
            <p class="adm-none" id="admSalesNone" hidden>All matching sales refunded.</p>
        </div>
    </div>
    <?php endif; ?>
</section>

<section class="adm-sec" id="chargebacks">
    <div class="adm-sec__head"><h2 class="adm-sec__title">Chargebacks</h2><span class="adm-sec__meta">Disputed card charges · the account is suspended automatically</span></div>
    <?php if (empty($this->chargebacks)): ?>
    <p class="adm-none adm-none--line">No chargebacks.</p>
    <?php else: ?>
    <div class="adm-table adm-table--cb">
        <div class="adm-table__head"><span>Account</span><span>Reason</span><span class="adm-r">Amount</span><span>Status</span><span>Date</span></div>
        <div class="adm-table__body">
            <?php foreach ($this->chargebacks as $cb): ?>
            <div class="adm-row adm-cbrow">
                <div class="adm-ucell"><?php echo $cb['handle'] ? '@' . $e($cb['handle']) : '<span class="adm-ucell--muted">Unmatched</span>'; ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($cb['reason'] ?: '—'); ?></div>
                <div class="adm-ucell adm-r">$<?php echo number_format(((int) $cb['amount_cents']) / 100, 2); ?></div>
                <div class="adm-ucell"><span class="adm-tag adm-tag--flag">Suspended</span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($cb['created_at'], true)); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
