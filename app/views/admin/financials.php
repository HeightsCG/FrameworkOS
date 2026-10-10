<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/financials — eight KPI cards (period switch fills the values: public/js/admin.js fz-*), revenue by month,
   sales by type, monthly breakdown. Sparklines are the last 30 days (fee on memberships: last 12 months). */
$f = $this->fin;
$last12 = array_slice((array) $this->series, -12);
$daily = array_slice((array) $this->daily, -30);
$col = function ($rows, $k) { return array_map(function ($r) use ($k) { return (float) $r[$k]; }, $rows); };
$tiers = array_values(array_filter(PlanTiers::offered(), function ($t) { return (int) $t['price'] > 0; }));   // paid plans only: Free has no MRR
$offered_keys = array_map(function ($t) { return $t['key']; }, $tiers);
foreach (PlanTiers::all() as $pt) { if (!in_array($pt['key'], $offered_keys, true) && (int) ($f['plans'][$pt['key']]['n'] ?? 0) > 0) { $pt['name'] .= ' (legacy)'; $tiers[] = $pt; } }
$plan_bits = array(); foreach ($tiers as $pt) { $plan_bits[] = (int) ($f['plans'][$pt['key']]['n'] ?? 0) . ' ' . $pt['name']; }
$comped = 0; foreach ((array) $f['plans'] as $pp) { $comped += (int) ($pp['comped'] ?? 0); }
if ($comped > 0) { $plan_bits[] = $comped . ' on a free code'; }
$card = function ($m, $label, $spark) { return '<div class="adm-kpi" data-m="' . $m . '"><span class="adm-kpi__l">' . $label . '</span><span class="adm-kpi__v" data-v>—</span><span class="adm-kpi__d"><span data-d></span></span><span class="adm-kpi__spark">' . adm_spark($spark) . '</span></div>'; };
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Financials</h1><p class="adm-head__sub">From the ledgers: plan payments, our fee on sales and memberships, credits, refunds and payouts</p></div>
    <div class="adm-head__acts">
        <div class="adm-seg fz-period" role="tablist" aria-label="Period">
            <button type="button" class="adm-seg__b is-on" role="tab" aria-selected="true" data-p="1">This month</button>
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-p="last">Last month</button>
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-p="3">3 months</button>
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-p="12">12 months</button>
        </div>
        <span class="adm-head__note" id="fzRange"></span>
    </div>
</header>

<div class="adm-kpis adm-kpis--4" id="fzCards" data-series="<?php echo $e(json_encode($this->series)); ?>">
    <?php echo adm_kpi('MRR', $usd($f['plan_mrr']), '<span class="adm-delta adm-delta--flat">' . $e(implode(' · ', $plan_bits)) . '</span>', $col($daily, 'plans'), ''); ?>
    <?php echo $card('revenue', 'Platform revenue', $col($daily, 'revenue')); ?>
    <?php echo $card('plans', 'Plan payments', $col($daily, 'plans')); ?>
    <?php echo $card('fee', 'Fee on sales', $col($daily, 'fee')); ?>
    <?php echo $card('members', 'Fee on memberships', $col($last12, 'members')); ?>
    <?php echo $card('cash_in', 'Credits bought', $col($daily, 'credits')); ?>
    <?php echo $card('refunds', 'Refunds', $col($daily, 'refunds')); ?>
    <?php echo $card('payouts', 'Paid out', $col($daily, 'payouts')); ?>
</div>

<section class="adm-box" id="fzRev">
    <header class="adm-box__h"><h2 class="adm-box__t">Revenue by month</h2><span class="adm-box__note">12-month total <b id="fzRevTotal">—</b></span></header>
    <div class="fz-rev__plot"><svg class="fz-rev__svg" role="img" aria-label="Platform revenue by month"></svg><div class="fz-tip" hidden></div></div>
</section>

<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Sales by type</h2><span class="adm-box__note">Selected period</span></header>
    <table class="adm-t adm-t--num">
        <thead><tr><th>Type</th><th class="adm-r">Sales</th><th class="adm-r">Gross</th><th class="adm-r">Refunded</th><th class="adm-r">To creators</th><th class="adm-r">Our fee</th></tr></thead>
        <tbody id="fzTypes"></tbody>
    </table>
</section>

<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Monthly breakdown</h2><span class="adm-box__note">Last 12 months</span></header>
    <table class="adm-t adm-t--num">
        <thead><tr><th>Month</th><th class="adm-r">Plan payments</th><th class="adm-r">Fee on sales</th><th class="adm-r">Fee on memberships</th><th class="adm-r">Platform revenue</th><th class="adm-r">Credits bought</th><th class="adm-r">AI credits</th><th class="adm-r">Refunds</th><th class="adm-r">Paid out</th></tr></thead>
        <tbody>
        <?php $mt = array('plans' => 0, 'fee' => 0, 'members' => 0, 'revenue' => 0, 'credits' => 0, 'ai' => 0, 'refunds' => 0, 'payouts' => 0);
        foreach (array_reverse($last12) as $m): foreach ($mt as $mk => $mv) { $mt[$mk] += (int) $m[$mk]; } ?>
            <tr><td class="adm-t__nowrap"><?php echo $e(gmdate('M Y', strtotime($m['k'] . '-01'))); ?></td><?php foreach (array_keys($mt) as $c): ?><td class="adm-r<?php echo $c === 'revenue' ? ' adm-t__strong' : ''; ?><?php echo (int) $m[$c] === 0 ? ' adm-t__muted' : ''; ?>"><?php echo $usd($m[$c]); ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
            <tr class="adm-t__total"><td>Total</td><?php foreach ($mt as $c => $v): ?><td class="adm-r"><?php echo $usd($v); ?></td><?php endforeach; ?></tr>
        </tbody>
    </table>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
