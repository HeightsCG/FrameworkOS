<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<link rel="stylesheet" href="/css/affiliates.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/affiliates.css'); ?>">
<?php
/* /affiliates/dashboard: link, numbers, commissions (date filter) and payouts (AffiliatesController::dashboardAction). */
$e  = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$s  = $this->stats;
$tz = (string) ($this->timezone ?? 'UTC');
$day = function ($utc) use ($tz) {
    if ((string) $utc === '') { return ''; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j, Y'); } catch (\Throwable $ex) { return ''; }
};
$usd = function ($c) { return Affiliates::money($c); };
$can_pay = !$this->open_payout && (int) $s['available'] >= Price::PAYOUT_MIN_CENTS;
$kinds = array('subscribe' => 'New plan', 'upgrade' => 'Upgrade', 'renewal' => 'Renewal');
$filtered = $this->from !== '' || $this->to !== '';
?>
<div class="ev afl" id="aflDash" data-link="<?php echo $e($this->link); ?>">
    <header class="ev__head">
        <div>
            <h1 class="ev__title">Affiliates</h1>
            <p class="ev__sub">You earn <?php echo (int) Affiliates::RATE_PERCENT; ?>% of the plan payments of accounts you refer.</p>
        </div>
        <button type="button" class="ev-btn ev-btn--primary" id="aflPayout"<?php echo $can_pay ? '' : ' disabled'; ?> title="<?php echo $e($this->open_payout ? 'A payout request is waiting' : ($can_pay ? '' : 'Minimum payout is ' . Price::PAYOUT_MIN_LABEL)); ?>"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> Request Payout</button>
    </header>

    <div class="afl-link">
        <label class="afl-link__label" for="aflLink">Your Link</label>
        <div class="afl-link__row">
            <input type="text" class="form-control afl-link__input" id="aflLink" value="<?php echo $e($this->link); ?>" readonly>
            <button type="button" class="ev-btn" data-copy-link><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy Link</button>
        </div>
    </div>

    <dl class="afl-stats">
        <div class="afl-stat"><dt>Clicks</dt><dd><?php echo number_format((int) $s['clicks']); ?></dd></div>
        <div class="afl-stat"><dt>Signups</dt><dd><?php echo number_format((int) $s['signups']); ?></dd></div>
        <div class="afl-stat"><dt>Paying Accounts</dt><dd><?php echo number_format((int) $s['paying']); ?></dd></div>
        <div class="afl-stat"><dt>Earned</dt><dd><?php echo $usd($s['earned']); ?></dd><span class="afl-stat__sub"><?php echo $usd($s['available']); ?> available</span></div>
        <div class="afl-stat"><dt>Pending Payout</dt><dd><?php echo $usd($s['pending']); ?></dd></div>
        <div class="afl-stat"><dt>Paid</dt><dd><?php echo $usd($s['paid']); ?></dd></div>
    </dl>

    <section class="afl-sec">
        <div class="afl-sec__head">
            <h2 class="afl-sec__title">Commissions</h2>
            <form class="afl-filter" method="get" action="/affiliates/dashboard">
                <label class="visually-hidden" for="aflFrom">From</label>
                <input type="date" class="form-control" id="aflFrom" name="from" value="<?php echo $e($this->from); ?>">
                <span class="afl-filter__to">to</span>
                <label class="visually-hidden" for="aflTo">To</label>
                <input type="date" class="form-control" id="aflTo" name="to" value="<?php echo $e($this->to); ?>">
                <button type="submit" class="ev-btn">Filter</button>
                <?php if ($filtered): ?><a class="ev-btn" href="/affiliates/dashboard">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if (empty($this->commissions)): ?>
        <div class="ev-empty afl-empty">
            <span class="ev-empty__ic"><i class="fa-solid fa-handshake" aria-hidden="true"></i></span>
            <h3 class="ev-empty__t"><?php echo $filtered ? 'No Commissions in These Dates' : 'No Commissions Yet'; ?></h3>
            <p class="ev-empty__x"><?php echo $filtered ? 'Try a wider date range.' : 'Share your link. A commission shows here each time an account you referred pays for its plan.'; ?></p>
        </div>
        <?php else: ?>
        <div class="ev-table afl-table" style="--ev-cols:130px minmax(140px,1fr) 120px 120px 110px">
            <div class="ev-table__head"><span>Date</span><span>Payment</span><span>Invoice</span><span>Commission</span><span>Status</span></div>
            <div class="ev-table__body">
                <?php foreach ($this->commissions as $c):
                    $adj = (int) $c['invoice_cents'] < 0;
                    $st  = (string) $c['status'];
                ?>
                <div class="ev-row afl-row">
                    <div class="ev-cell ev-cell--muted"><?php echo $e($day($c['created_at'])); ?></div>
                    <div class="ev-cell"><?php echo $e($adj ? 'Dispute adjustment' : ($kinds[(string) $c['kind']] ?? 'Plan payment')); ?></div>
                    <div class="ev-cell ev-cell--muted"><?php echo $usd($c['invoice_cents']); ?></div>
                    <div class="ev-cell afl-num"><?php echo $usd($c['commission_cents']); ?></div>
                    <div class="ev-cell"><span class="ev-status ev-status--<?php echo $st === 'reversed' ? 'off' : ($st === 'paid' ? 'on' : 'draft'); ?>"><span class="ev-status__dot"></span><?php echo $e($st === 'earned' && (int) $c['payout_id'] > 0 ? 'Requested' : ucfirst($st)); ?></span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="afl-sec">
        <div class="afl-sec__head"><h2 class="afl-sec__title">Payouts</h2></div>
        <?php if (empty($this->payouts)): ?>
        <div class="ev-empty afl-empty">
            <span class="ev-empty__ic"><i class="fa-solid fa-building-columns" aria-hidden="true"></i></span>
            <h3 class="ev-empty__t">No Payouts Yet</h3>
            <p class="ev-empty__x">Request a payout once you have <?php echo $e(Price::PAYOUT_MIN_LABEL); ?> available. Payouts go to your bank.</p>
        </div>
        <?php else: ?>
        <div class="ev-table afl-table" style="--ev-cols:130px 120px 110px 130px minmax(120px,1fr)">
            <div class="ev-table__head"><span>Requested</span><span>Amount</span><span>Status</span><span>Paid</span><span>Note</span></div>
            <div class="ev-table__body">
                <?php foreach ($this->payouts as $p): $st = (string) $p['status']; ?>
                <div class="ev-row afl-row">
                    <div class="ev-cell ev-cell--muted"><?php echo $e($day($p['requested_at'])); ?></div>
                    <div class="ev-cell afl-num"><?php echo $usd($p['amount_cents']); ?></div>
                    <div class="ev-cell"><span class="ev-status ev-status--<?php echo $st === 'paid' ? 'on' : ($st === 'rejected' ? 'off' : 'draft'); ?>"><span class="ev-status__dot"></span><?php echo $e(ucfirst($st)); ?></span></div>
                    <div class="ev-cell ev-cell--muted"><?php echo $e($day($p['paid_at'])); ?></div>
                    <div class="ev-cell ev-cell--muted"><?php echo $e(html_entity_decode((string) $p['note'], ENT_QUOTES, 'UTF-8')); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>
<script src="/js/affiliates.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/affiliates.js'); ?>"></script>
