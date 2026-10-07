<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$ini = function ($n) { $n = trim((string) $n); return $n === '' ? '?' : mb_strtoupper(mb_substr($n, 0, 1)); };
$tz  = (string) ($this->timezone ?? 'UTC');
$fmt = function ($utc, $withTime = false) use ($tz) {
    if ((string) $utc === '') { return '—'; }
    try {
        $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
        return $d->format($withTime ? 'M j, Y g:i A' : 'M j, Y');
    } catch (\Throwable $ex) { return '—'; }
};
$s = $this->stats;
$review = (int) $s['mod_pending'] + (int) $s['mod_flagged'];
$me = (int) $this->me;
$queue = $this->queue;
$users = $this->users;
?>
<div class="adm">
    <header class="adm__head">
        <h1 class="adm__title">Admin</h1>
    </header>

<?php
$f = $this->fin; $usd = function ($c) { return '$' . number_format(((int) $c) / 100, 2); };
$recurring = (int) $f['plan_mrr'];
$ft = $f['sales_total'];
$tier_bits = array(); foreach (PlanTiers::all() as $pt) { $n = (int) ($f['plans'][$pt['key']]['n'] ?? 0); if ($n > 0) { $tier_bits[] = $n . ' ' . $pt['name']; } }
?>
    <div class="adm-tabs" id="admTabs">
        <button type="button" class="adm-tab is-active" data-panel="financials"><i class="fa-solid fa-chart-line"></i> Financials</button>
        <button type="button" class="adm-tab" data-panel="moderation"><i class="fa-solid fa-shield-halved"></i> Moderation<?php if ($review > 0): ?> <b class="adm-tab__badge"><?php echo (int) $review; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="reports"><i class="fa-solid fa-flag"></i> Reports<?php if ((int) $this->reports_open > 0): ?> <b class="adm-tab__badge"><?php echo (int) $this->reports_open; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="verification"><i class="fa-solid fa-user-check"></i> Verification<?php if ((int) $this->verif_pending > 0): ?> <b class="adm-tab__badge"><?php echo (int) $this->verif_pending; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="sales"><i class="fa-solid fa-receipt"></i> Sales</button>
<?php $bill_due = 0; foreach ((array) $this->billing as $b) { if ((string) $b['status'] === 'past_due') { $bill_due++; } } ?>
        <button type="button" class="adm-tab" data-panel="billing"><i class="fa-solid fa-credit-card"></i> Billing<?php if ($bill_due > 0): ?> <b class="adm-tab__badge"><?php echo $bill_due; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="users"><i class="fa-solid fa-users"></i> Users</button>
        <button type="button" class="adm-tab" data-panel="support"><i class="fa-solid fa-life-ring"></i> Support<?php if ((int) $this->support_open > 0): ?> <b class="adm-tab__badge"><?php echo (int) $this->support_open; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="content"><i class="fa-solid fa-newspaper"></i> Content<?php if (count($this->seo_review) > 0): ?> <b class="adm-tab__badge"><?php echo count($this->seo_review); ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="scenes"><i class="fa-solid fa-panorama"></i> Scenes</button>
        <button type="button" class="adm-tab" data-panel="audit"><i class="fa-solid fa-clipboard-list"></i> Audit Log</button>
    </div>

    <section class="adm-sec adm-panel is-active" data-panel="financials">
<?php
$usd = function ($c) { return ($c < 0 ? '−$' : '$') . number_format(abs((int) $c) / 100, 2); };
$tiers_all = PlanTiers::all();
$last12 = array_slice($this->series, -12);
?>
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
            <article class="fz-card fz-card--violet">
                <header class="fz-card__head"><span class="fz-card__ic"><i class="fa-solid fa-arrows-rotate"></i></span><span class="fz-card__label">Monthly Recurring Revenue</span></header>
                <div class="fz-card__val"><?php echo $usd($f['plan_mrr']); ?><small>right now</small></div>
                <div class="fz-split" aria-hidden="true"><?php foreach ($tiers_all as $i => $pt): $n = (int) ($f['plans'][$pt['key']]['n'] ?? 0); if ($n <= 0) { continue; } ?><span class="fz-split__seg fz-split__seg--<?php echo $i; ?>" style="flex:<?php echo $n; ?>"></span><?php endforeach; ?><?php if ((int) $f['plan_count'] === 0): ?><span class="fz-split__seg fz-split__seg--none" style="flex:1"></span><?php endif; ?></div>
                <ul class="fz-legend"><?php foreach ($tiers_all as $i => $pt): ?><li><i class="fz-split__seg--<?php echo $i; ?>"></i><?php echo $e($pt['name']); ?> <b><?php echo (int) ($f['plans'][$pt['key']]['n'] ?? 0); ?></b></li><?php endforeach; ?></ul>
            </article>
            <article class="fz-card fz-card--green" data-m="revenue">
                <header class="fz-card__head"><span class="fz-card__ic"><i class="fa-solid fa-sack-dollar"></i></span><span class="fz-card__label">Platform Revenue</span></header>
                <div class="fz-card__val" data-v>—</div>
                <span class="fz-delta" data-d></span>
                <dl class="fz-foot fz-foot--3"><div><dt>Plan payments</dt><dd data-f="plans">—</dd></div><div><dt>Fee on sales</dt><dd data-f="fee">—</dd></div><div><dt>Fee on memberships</dt><dd data-f="members">—</dd></div></dl>
            </article>
            <article class="fz-card fz-card--blue" data-m="cash_in">
                <header class="fz-card__head"><span class="fz-card__ic"><i class="fa-solid fa-arrow-down"></i></span><span class="fz-card__label">Money In From Credits</span></header>
                <div class="fz-card__val" data-v>—</div>
                <span class="fz-delta" data-d></span>
                <dl class="fz-foot"><div><dt>Credits bought</dt><dd data-f="credits">—</dd></div><div><dt>AI credits bought</dt><dd data-f="ai">—</dd></div></dl>
            </article>
            <article class="fz-card fz-card--amber" data-m="payouts">
                <header class="fz-card__head"><span class="fz-card__ic"><i class="fa-solid fa-building-columns"></i></span><span class="fz-card__label">Paid Out to Creators</span></header>
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
            <?php foreach (array('revenue' => array('Platform revenue', '#16a36a'), 'plans' => array('Plan payments', '#FF6A13'), 'fee' => array('Our fee on sales', '#0f8a5f'),
                                 'cash_in' => array('Money in from credits', '#2f7ae5'), 'refunds' => array('Refunds', '#d9463b'), 'payouts' => array('Paid out to creators', '#e08a12')) as $mk => $md): ?>
            <article class="fz-mini" data-m="<?php echo $mk; ?>" data-c="<?php echo $md[1]; ?>">
                <header><span><?php echo $e($md[0]); ?></span><b data-t>—</b></header>
                <svg class="fz-mini__svg" viewBox="0 0 300 90" preserveAspectRatio="none" aria-hidden="true"></svg>
                <footer><span data-a></span><span data-z></span></footer>
            </article>
            <?php endforeach; ?>
        </div>

        <div class="adm-sec__head" style="margin-top:1.6rem;"><h2 class="adm-sec__title">Sales by Type</h2></div>
        <div class="adm-table adm-table--fin">
            <div class="adm-table__head"><span>Type</span><span class="adm-r">Sales</span><span class="adm-r">Gross</span><span class="adm-r">Refunded</span><span class="adm-r">To creators</span><span class="adm-r">Our fee</span></div>
            <div class="adm-table__body" id="fzTypes"></div>
        </div>

        <div class="adm-sec__head" style="margin-top:1.6rem;"><h2 class="adm-sec__title">Monthly Breakdown</h2></div>
        <div class="adm-table adm-table--months">
            <div class="adm-table__head"><span>Month</span><span class="adm-r">Plan payments</span><span class="adm-r">Our fee on sales</span><span class="adm-r">Our fee on memberships</span><span class="adm-r">Platform revenue</span><span class="adm-r">Credits bought</span><span class="adm-r">AI credits bought</span><span class="adm-r">Refunds</span><span class="adm-r">Paid out</span></div>
            <div class="adm-table__body">
                <?php $mt = array('plans' => 0, 'fee' => 0, 'members' => 0, 'revenue' => 0, 'credits' => 0, 'ai' => 0, 'refunds' => 0, 'payouts' => 0);
                foreach (array_reverse($last12) as $m): foreach ($mt as $mk => $mv) { $mt[$mk] += (int) $m[$mk]; } ?>
                <div class="adm-mrow"><span class="adm-ucell"><?php echo $e(gmdate('F Y', strtotime($m['k'] . '-01'))); ?></span><?php foreach (array_keys($mt) as $col): ?><span class="adm-ucell adm-r<?php echo $col === 'revenue' ? ' adm-mrow__rev' : ''; ?><?php echo (int) $m[$col] === 0 ? ' adm-ucell--muted' : ''; ?>"><?php echo $usd($m[$col]); ?></span><?php endforeach; ?></div>
                <?php endforeach; ?>
                <div class="adm-mrow adm-frow--total"><span class="adm-ucell"><b>Total</b></span><?php foreach ($mt as $col => $v): ?><span class="adm-ucell adm-r"><b><?php echo $usd($v); ?></b></span><?php endforeach; ?></div>
            </div>
        </div>
    </section>

    <section class="adm-sec adm-panel" data-panel="moderation">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Moderation Queue</h2>
        </div>
        <?php if (empty($queue)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic"><i class="fa-solid fa-circle-check"></i></span>
                <p class="adm-empty__t">Nothing to Review</p>
                
            </div>
        <?php else: ?>
            <div class="adm-mod" id="admMod">
                <?php foreach ($queue as $a): ?>
                <div class="adm-card" data-asset="<?php echo (int) $a['id']; ?>">
                    <div class="adm-card__img" style="background-image:url('<?php echo $e($a['thumb']); ?>')">
                        <span class="adm-card__badge adm-card__badge--<?php echo $a['status'] === 'flagged' ? 'flag' : 'pend'; ?>">
                            <?php echo $a['status'] === 'flagged' ? 'Flagged' : 'Unscanned'; ?>
                        </span>
                    </div>
                    <div class="adm-card__body">
                        <a class="adm-card__creator" href="/@<?php echo $e($a['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($a['creator_handle']); ?></a>
                        <?php if ($a['status'] === 'flagged' && ($a['labels'] !== '' || $a['score'] !== null)): ?>
                            <span class="adm-card__ai"><i class="fa-solid fa-robot"></i> <?php echo $a['labels'] !== '' ? $e($a['labels']) : 'adult'; ?><?php echo $a['score'] !== null ? ' &middot; ' . number_format($a['score'] * 100) . '%' : ''; ?></span>
                        <?php endif; ?>
                        <span class="adm-card__when"><?php echo $e($fmt($a['created_at'])); ?></span>
                    </div>
                    <div class="adm-card__acts">
                        <button type="button" class="adm-btn adm-btn--ok" data-mod="approve"><i class="fa-solid fa-check"></i> Approve</button>
                        <button type="button" class="adm-btn adm-btn--danger" data-mod="block"><i class="fa-solid fa-ban"></i> Block</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="adm-sec adm-panel" data-panel="reports">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Reports</h2>
        </div>
        <?php if (empty($this->reports_queue)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic"><i class="fa-solid fa-flag"></i></span>
                <p class="adm-empty__t">No Open Reports</p>
                
            </div>
        <?php else: ?>
        <div class="adm-table adm-table--reports">
            <div class="adm-table__head"><span>Reported</span><span>Reason</span><span>By</span><span>When</span><span></span></div>
            <div class="adm-table__body" id="admReports">
                <?php foreach ($this->reports_queue as $rp): ?>
                <div class="adm-rrow" data-report="<?php echo (int) $rp['id']; ?>" data-type="<?php echo $e($rp['target_type']); ?>">
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
                <p class="adm__none" id="admReportsNone" hidden>All reports resolved.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="adm-sec adm-panel" data-panel="verification">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Verification Requests</h2>
        </div>
        <?php if (empty($this->verifications)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic"><i class="fa-solid fa-user-check"></i></span>
                <p class="adm-empty__t">No Pending Requests</p>
                
            </div>
        <?php else: ?>
        <div class="adm-table adm-table--verif">
            <div class="adm-table__head"><span>Creator</span><span>Legal name</span><span>Note</span><span>When</span><span></span></div>
            <div class="adm-table__body" id="admVerif">
                <?php foreach ($this->verifications as $v):
                    $vname = trim((string) ($v['name'] ?? '')); $vname = $vname !== '' ? $vname : ('@' . $v['u_name']);
                ?>
                <div class="adm-vrow" data-verif="<?php echo (int) $v['id']; ?>">
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($vname)); ?></span>
                        <span class="adm-uinfo"><span class="adm-uinfo__name"><?php echo $e($vname); ?></span><span class="adm-uinfo__meta"><a href="/@<?php echo $e($v['u_name']); ?>" target="_blank" rel="noopener">@<?php echo $e($v['u_name']); ?></a></span></span>
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
                <p class="adm__none" id="admVerifNone" hidden>No pending requests.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="adm-sec adm-panel" data-panel="sales">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Recent Sales</h2>
        </div>
        <?php if (empty($this->sales)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic" style="background:#f2f0f9;color:#8b83c4;"><i class="fa-solid fa-receipt"></i></span>
                <p class="adm-empty__t">No Sales Yet</p>
                
            </div>
        <?php else: ?>
        <div class="adm-table adm-table--sales">
            <div class="adm-table__head">
                <span>Buyer</span><span>Item</span><span>Type</span><span class="adm-r">Amount</span><span>Date</span><span></span>
            </div>
            <div class="adm-table__body" id="admSales">
                <?php foreach ($this->sales as $sale): ?>
                <div class="adm-srow" data-kind="<?php echo $e($sale['kind']); ?>" data-ref="<?php echo (int) $sale['ref_id']; ?>" data-fan="<?php echo (int) $sale['fan_id']; ?>">
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($sale['fan_name'])); ?></span>
                        <span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $sale['fan_id']; ?>">@<?php echo $e($sale['fan_handle']); ?></a></span>
                    </div>
                    <div class="adm-ucell adm-scell--item"><?php echo $e($sale['item'] !== '' ? $sale['item'] : 'Untitled'); ?></div>
                    <div class="adm-ucell"><span class="adm-tag adm-tag--<?php echo $sale['kind']; ?>"><?php echo $sale['kind'] === 'bundle' ? 'Bundle' : ($sale['kind'] === 'message' ? 'Message' : 'PPV'); ?></span></div>
                    <div class="adm-ucell adm-r adm-scell--amt">$<?php echo number_format(((int) $sale['credits']) / 10, 2); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($sale['created_at'], true)); ?></div>
                    <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Sale actions', array(array('text' => 'Refund…', 'attrs' => 'data-refund', 'danger' => true))); ?></div>
                </div>
                <?php endforeach; ?>
                <p class="adm__none" id="admSalesNone" hidden>All matching sales refunded.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($this->chargebacks)): ?>
    <section class="adm-sec adm-panel" data-panel="sales">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Chargebacks</h2>
            <span class="adm-sec__meta">Disputed Stripe charges &middot; account auto-suspended</span>
        </div>
        <div class="adm-table adm-table--cb">
            <div class="adm-table__head"><span>Account</span><span>Reason</span><span class="adm-r">Amount</span><span>Status</span><span>Date</span></div>
            <div class="adm-table__body">
                <?php foreach ($this->chargebacks as $cb): ?>
                <div class="adm-cbrow">
                    <div class="adm-ucell"><?php echo $cb['handle'] ? '@' . $e($cb['handle']) : '<span class="adm-ucell--muted">Unmatched</span>'; ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($cb['reason'] ?: '—'); ?></div>
                    <div class="adm-ucell adm-r">$<?php echo number_format(((int) $cb['amount_cents']) / 100, 2); ?></div>
                    <div class="adm-ucell"><span class="adm-tag adm-tag--flag">Suspended</span></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($cb['created_at'], true)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="adm-sec adm-panel" data-panel="billing">
        <div class="adm-sec__head"><h2 class="adm-sec__title">Plan Billing</h2></div>
        <?php if (empty($this->billing)): ?>
        <p class="adm-kv__none">No paid plans or monthly packs yet.</p>
        <?php else: ?>
        <div class="adm-table adm-table--billing">
            <div class="adm-table__head"><span>Account</span><span>Plan</span><span>Add-ons</span><span>Status</span><span>Next charge</span><span>Last charge</span><span></span></div>
            <div class="adm-table__body">
            <?php foreach ((array) $this->billing as $b):
                $bn = trim($b['first_name'] . ' ' . $b['last_name']); $bn = $bn !== '' ? $bn : '@' . $b['u_name'];
                $bt = PlanTiers::get((string) $b['plan_key']);
                $bst = (string) $b['status'];
                $adds = array();
                if ((int) $b['influencer_slots'] > 0) { $adds[] = (int) $b['influencer_slots'] . ' extra influencer' . ((int) $b['influencer_slots'] === 1 ? '' : 's'); }
                if ((int) $b['pack_dollars'] > 0) { $adds[] = '$' . (int) $b['pack_dollars'] . ' AI credit pack (' . number_format(PlanTiers::pack_credits((int) $b['pack_dollars'])) . ' AI credits)'; }
            ?>
            <div class="adm-urow" data-uid="<?php echo (int) $b['user_id']; ?>">
                <div class="adm-ucell adm-ucell--user"><span class="adm-uinfo"><a class="adm-uinfo__name" href="/admin/user/<?php echo (int) $b['user_id']; ?>"><?php echo $e($bn); ?></a><span class="adm-uinfo__meta"><?php echo $e($b['user_email']); ?></span></span></div>
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

    <section class="adm-sec adm-panel" data-panel="users">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Users</h2>
            <div class="adm-usearch">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="admUserSearch" placeholder="Search name, @handle, or email" autocomplete="off" maxlength="80">
            </div>
        </div>
        <div class="adm-table adm-table--users">
            <div class="adm-table__head">
                <span>User</span><span>Role</span><span>Status</span><span>Joined</span><span>Last active</span><span></span>
            </div>
            <div class="adm-table__body" id="admUsers">
                <?php foreach ($users as $u):
                    $name  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    $name  = $name !== '' ? $name : ('@' . $u['u_name']);
                    $isMe  = ((int) $u['user_id'] === $me);
                    $isAdm = !empty($u['is_admin']);
                    $dis   = ($u['user_status'] === 'Disabled');
                    $search = mb_strtolower($name . ' @' . $u['u_name'] . ' ' . $u['user_email']);
                ?>
                <div class="adm-urow" data-uid="<?php echo (int) $u['user_id']; ?>" data-search="<?php echo $e($search); ?>">
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($name)); ?></span>
                        <span class="adm-uinfo">
                            <a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $u['user_id']; ?>"><?php echo $e($name); ?></a><?php if ($isAdm): ?> <span class="adm-tag adm-tag--admin">Admin</span><?php endif; ?>
                            <span class="adm-uinfo__meta">@<?php echo $e($u['u_name']); ?> &middot; <?php echo $e($u['user_email']); ?></span>
                        </span>
                    </div>
                    <div class="adm-ucell"><span class="adm-role"><?php echo $e($u['role_name'] ?: 'User'); ?></span></div>
                    <div class="adm-ucell">
                        <span class="adm-status adm-status--<?php echo $dis ? 'off' : 'on'; ?>"><span class="adm-status__dot"></span><?php echo $dis ? 'Suspended' : 'Active'; ?></span>
                    </div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($u['created_at'])); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($u['last_active_at'], true)); ?></div>
                    <div class="adm-ucell adm-ucell--act">
                        <?php if (!$isMe && !$isAdm): ?>
                        <div class="dropdown">
                            <button type="button" class="adm-more" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Actions for @<?php echo $e($u['u_name']); ?>"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end adm-menu">
                                <?php if (!$dis): ?><li><button type="button" class="dropdown-item" data-impersonate="<?php echo (int) $u['user_id']; ?>" data-handle="<?php echo $e($u['u_name']); ?>">Sign in as user</button></li><?php endif; ?>
                                <li><button type="button" class="dropdown-item<?php echo $dis ? '' : ' adm-menu__danger'; ?>" data-status="<?php echo $dis ? 'Active' : 'Disabled'; ?>"><?php echo $dis ? 'Reactivate' : 'Suspend…'; ?></button></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <p class="adm__none" id="admUsersNone" hidden>No users match that search.</p>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/_support.php'; ?>
    <?php include __DIR__ . '/_content.php'; ?>
    <?php include __DIR__ . '/_scenes.php'; ?>
    <?php include __DIR__ . '/_audit.php'; ?>
</div>

<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-content.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-content.js'); ?>"></script>
<script src="/js/admin-scenes.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-scenes.js'); ?>"></script>
