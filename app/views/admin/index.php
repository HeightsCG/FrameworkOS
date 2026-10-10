<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin — Today: five KPI cards, Needs attention + Jobs side by side, one Activity table (signups, sales, payouts,
   staff actions) with a type filter. Data: AdminController::indexAction. */
$s = $this->stats; $f = $this->fin;
$daily = (array) $this->daily; $n60 = count($daily); $last30 = array_slice($daily, -30); $prev30 = array_slice($daily, 0, max(0, $n60 - 30));
$col = function ($rows, $k) { return array_map(function ($r) use ($k) { return (float) $r[$k]; }, $rows); };
$sum = function ($rows, $k) { $t = 0; foreach ($rows as $r) { $t += (float) $r[$k]; } return $t; };
$today = gmdate('Y-m-d'); $yesterday = gmdate('Y-m-d', time() - 86400); $sign_today = 0; $sign_yday = 0;
foreach ($last30 as $r) { if ($r['d'] === $today) { $sign_today = (int) $r['signups']; } if ($r['d'] === $yesterday) { $sign_yday = (int) $r['signups']; } }
$month = !empty($this->series) ? $this->series[count($this->series) - 1] : array('revenue' => 0);
$prev_m = count($this->series) > 1 ? $this->series[count($this->series) - 2] : array('revenue' => 0);
// Owed to creators, day by day: today's figure walked back through each day's net creator movement.
$owed = array(); $run = (int) $f['owed_creators']; $earned = $col($last30, 'earned');
for ($i = count($earned) - 1; $i >= 0; $i--) { $owed[$i] = max(0, $run); $run -= $earned[$i]; }
ksort($owed); $owed = array_values($owed);
$accounts = $col($last30, 'accounts');
$attention = array(
    array('Reports',      (int) ($nav['reports'] ?? 0),      'open',          '/admin/reports'),
    array('Verification', (int) ($nav['verification'] ?? 0) + (int) ($nav['age'] ?? 0), 'pending', '/admin/verification'),
    array('Support',      (int) ($nav['support'] ?? 0),      'waiting on us', '/admin/support'),
    array('Billing',      (int) ($nav['billing'] ?? 0),      'past due',      '/admin/billing'),
    array('Articles',     (int) ($nav['articles'] ?? 0),     'unpublished',   '/admin/content'),
    array('Affiliates',   (int) ($nav['affiliates'] ?? 0),   'to settle',     '/admin/affiliates'),
);
$attention = array_values(array_filter($attention, function ($a) { return $a[1] > 0; }));
$types = array('all' => 'All', 'signup' => 'Signups', 'sale' => 'Sales', 'payout' => 'Payouts', 'staff' => 'Staff actions');
$tn = array('all' => count((array) $this->activity)); foreach ((array) $this->activity as $ev) { $tn[$ev['type']] = ($tn[$ev['type']] ?? 0) + 1; }
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Today</h1><p class="adm-head__sub"><?php echo $e((new DateTime('now', new DateTimeZone($tz)))->format('l, F j, Y')); ?></p></div>
</header>

<div class="adm-kpis adm-kpis--5">
    <?php echo adm_kpi('MRR', $usd($f['plan_mrr']), adm_delta($sum($last30, 'plans'), $sum($prev30, 'plans')), $col($last30, 'plans'), 'plan payments, 30d'); ?>
    <?php echo adm_kpi('Revenue this month', $usd($month['revenue']), adm_delta($month['revenue'], $prev_m['revenue']), $col($last30, 'revenue'), 'vs last month'); ?>
    <?php echo adm_kpi('Owed to creators', $usd($f['owed_creators']), adm_delta($owed[count($owed) - 1] ?? 0, $owed[0] ?? 0, true), $owed, 'vs 30d ago'); ?>
    <?php echo adm_kpi('Signups today', number_format($sign_today), adm_delta($sign_today, $sign_yday), $col($last30, 'signups'), 'vs yesterday'); ?>
    <?php echo adm_kpi('Accounts', number_format((int) $s['users']), adm_delta($accounts[count($accounts) - 1] ?? 0, $accounts[0] ?? 0), $accounts, 'vs 30d ago'); ?>
</div>

<div class="adm-cols adm-cols--7-5">
    <section class="adm-box">
        <header class="adm-box__h"><h2 class="adm-box__t">Needs attention<?php if ($attention): ?> <b class="adm-count"><?php echo count($attention); ?></b><?php endif; ?></h2></header>
        <?php if (empty($attention)): ?>
            <?php echo adm_empty('All clear', 'fa-circle-check'); ?>
        <?php else: ?>
        <table class="adm-t adm-t--attn">
            <tbody>
            <?php foreach ($attention as $a): ?>
                <tr class="adm-t__link" data-href="<?php echo $e($a[3]); ?>">
                    <td class="adm-t__main"><a href="<?php echo $e($a[3]); ?>"><?php echo $e($a[0]); ?></a></td>
                    <td class="adm-t__muted"><b class="adm-count adm-count--warn"><?php echo number_format($a[1]); ?></b> <?php echo $e($a[2]); ?></td>
                    <td class="adm-t__go"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
    <section class="adm-box">
        <header class="adm-box__h"><h2 class="adm-box__t">Jobs<?php if (!empty($this->jobs_bad)): ?> <b class="adm-count adm-count--bad"><?php echo count($this->jobs_bad); ?></b><?php endif; ?></h2><a class="adm-box__link" href="/admin/jobs">View all</a></header>
        <?php if (empty($this->jobs_bad)): ?>
            <?php echo adm_empty('All jobs healthy', 'fa-circle-check'); ?>
        <?php else: ?>
        <table class="adm-t">
            <tbody>
            <?php foreach ($this->jobs_bad as $j): ?>
                <tr>
                    <td class="adm-t__main adm-t__trunc"><?php echo $e(str_replace(array('Seo ', 'Db '), array('SEO ', 'DB '), ucwords(str_replace('_', ' ', $j['name'])))); ?></td>
                    <td><?php echo adm_pill(($j['finished_at'] !== '' && !$j['ok']) ? 'Failed' : 'Overdue'); ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $j['finished_at'] !== '' ? $e($ago($j['finished_at'])) : 'Never ran'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>

<section class="adm-box">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Activity <b class="adm-count"><?php echo (int) $tn['all']; ?></b></h2>
        <div class="adm-box__tools">
            <div class="adm-seg" role="tablist" aria-label="Activity type" data-filter-for="admActivity" data-filter-attr="data-type">
                <?php foreach ($types as $k => $label): ?><button type="button" role="tab" class="adm-seg__b<?php echo $k === 'all' ? ' is-on' : ''; ?>" aria-selected="<?php echo $k === 'all' ? 'true' : 'false'; ?>" data-filter="<?php echo $k; ?>"><?php echo $e($label); ?> <b><?php echo (int) ($tn[$k] ?? 0); ?></b></button><?php endforeach; ?>
            </div>
        </div>
    </header>
    <?php if (empty($this->activity)): ?>
        <?php echo adm_empty('No activity yet', 'fa-clock'); ?>
    <?php else: ?>
    <table class="adm-t adm-t--activity" id="admActivity" data-sortable data-pager>
        <thead><tr><th data-sort="text">Type</th><th data-sort="text">Who</th><th>What</th><th class="adm-r" data-sort="num">Amount</th><th data-sort="text" data-sorted="desc">When</th></tr></thead>
        <tbody>
        <?php foreach ($this->activity as $ev): ?>
            <tr class="adm-t__link" data-type="<?php echo $e($ev['type']); ?>" data-href="<?php echo $e($ev['href']); ?>">
                <td><?php echo adm_pill($ev['label'], $ev['type'] === 'sale' ? 'ok' : ($ev['type'] === 'payout' ? 'warn' : ($ev['type'] === 'signup' ? 'info' : 'gray'))); ?></td>
                <td class="adm-t__who"><?php echo adm_who($ev['who'], $ev['handle'], '/admin/user/' . (int) $ev['who_id'], $ev['avatar']); ?></td>
                <td class="adm-t__trunc" title="<?php echo $e($ev['what']); ?>"><?php echo $e($ev['what']); ?></td>
                <td class="adm-r adm-t__num" data-value="<?php echo $e(preg_replace('/[^0-9.\-]/', '', (string) $ev['amount'])); ?>"><?php echo $e($ev['amount']); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($ev['at']); ?>"><?php echo $e($fmt($ev['at'], true)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admActivityNone" hidden>Nothing of this type.</p>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
