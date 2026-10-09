<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin — Today: what needs a person, today's numbers, failing jobs and what just happened. Data: AdminController::indexAction. */
$s = $this->stats; $f = $this->fin;
$today = gmdate('Y-m-d'); $sign_today = 0; $sign_7 = 0; $paid_7 = 0;
foreach ((array) $this->signups as $r) { $sign_7 += (int) $r['signups']; $paid_7 += (int) $r['paid']; if ((string) $r['day'] === $today) { $sign_today = (int) $r['signups']; } }
$month = !empty($this->series) ? $this->series[count($this->series) - 1] : array('revenue' => 0, 'cash_in' => 0, 'plans' => 0, 'fee' => 0, 'members' => 0);
$prev  = count($this->series) > 1 ? $this->series[count($this->series) - 2] : null;
$jobs_bad = array(); foreach ((array) $this->cron_jobs as $j) { if (($j['finished_at'] !== '' && !$j['ok']) || $j['stale']) { $jobs_bad[] = $j; } }
$now_local = new DateTime('now', new DateTimeZone($tz));
$tiles = array(
    array('Moderation',   (int) ($nav['moderation'] ?? 0),   '/admin/queue?show=moderation',   'fa-shield-halved', 'to review'),
    array('Reports',      (int) ($nav['reports'] ?? 0),      '/admin/queue?show=reports',      'fa-flag',          'open'),
    array('Verification', (int) ($nav['verification'] ?? 0) + (int) ($nav['age'] ?? 0), '/admin/queue?show=verification', 'fa-user-check', 'waiting'),
    array('Support',      (int) ($nav['support'] ?? 0),      '/admin/queue?show=support',      'fa-life-ring',     'waiting on us'),
    array('Billing',      (int) ($nav['billing'] ?? 0),      '/admin/queue?show=billing',      'fa-credit-card',   'past due'),
    array('Articles',     (int) ($nav['articles'] ?? 0),     '/admin/articles',                'fa-newspaper',     'unpublished'),
    array('Affiliates',   (int) ($nav['affiliates'] ?? 0),   '/admin/affiliates',              'fa-handshake',     'to settle'),
);
$waiting = (int) ($nav['queue'] ?? 0) + (int) ($nav['articles'] ?? 0) + (int) ($nav['affiliates'] ?? 0);
?>
<header class="adm-head">
    <div>
        <h1 class="adm-head__title">Today</h1>
        <p class="adm-head__sub"><?php echo $e($now_local->format('l, F j')); ?> · <?php echo $waiting > 0 ? $waiting . ' item' . ($waiting === 1 ? '' : 's') . ' waiting on you' : 'Nothing is waiting on you'; ?></p>
    </div>
    <div class="adm-head__acts">
        <a class="adm-btn adm-btn--primary" href="/admin/queue"><i class="fa-solid fa-inbox" aria-hidden="true"></i> Open Queue</a>
    </div>
</header>

<div class="adm-tiles" aria-label="Needs you">
    <?php foreach ($tiles as $t): $zero = $t[1] === 0; ?>
    <a class="adm-tile<?php echo $zero ? ' is-clear' : ''; ?>" href="<?php echo $e($t[2]); ?>">
        <span class="adm-tile__ic"><i class="fa-solid <?php echo $e($t[3]); ?>" aria-hidden="true"></i></span>
        <span class="adm-tile__n"><?php echo $zero ? '0' : number_format($t[1]); ?></span>
        <span class="adm-tile__l"><?php echo $e($t[0]); ?></span>
        <span class="adm-tile__s"><?php echo $zero ? 'Clear' : $e($t[4]); ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="adm-nums">
    <div class="adm-num"><span class="adm-num__l">Signups today</span><b class="adm-num__v"><?php echo number_format($sign_today); ?></b><span class="adm-num__s"><?php echo number_format($sign_7); ?> in 7 days · <?php echo number_format($paid_7); ?> paid</span></div>
    <div class="adm-num"><span class="adm-num__l">Platform revenue this month</span><b class="adm-num__v"><?php echo $usd($month['revenue']); ?></b><span class="adm-num__s"><?php echo $prev ? 'Last month ' . $usd($prev['revenue']) : 'Plans, fees on sales and memberships'; ?></span></div>
    <div class="adm-num"><span class="adm-num__l">Monthly recurring</span><b class="adm-num__v"><?php echo $usd($f['plan_mrr']); ?></b><span class="adm-num__s"><?php echo number_format((int) $f['plan_count']); ?> paid plan<?php echo (int) $f['plan_count'] === 1 ? '' : 's'; ?></span></div>
    <div class="adm-num"><span class="adm-num__l">Owed to creators</span><b class="adm-num__v"><?php echo $usd($f['owed_creators']); ?></b><span class="adm-num__s">Held in wallets <?php echo $usd($f['credits_held']); ?></span></div>
    <div class="adm-num"><span class="adm-num__l">Accounts</span><b class="adm-num__v"><?php echo number_format((int) $s['users']); ?></b><span class="adm-num__s"><?php echo number_format((int) $s['creators']); ?> creators · <?php echo number_format((int) $s['active_subs']); ?> memberships</span></div>
</div>

<div class="adm-cols adm-cols--7-5">
    <div class="adm-stack">
        <section class="adm-box">
            <header class="adm-box__head"><h2 class="adm-box__h">New Accounts</h2><a class="adm-box__link" href="/admin/people">All users</a></header>
            <?php if (empty($this->new_users)): ?><p class="adm-none">No accounts yet.</p><?php else: ?>
            <div class="adm-table adm-table--plain" style="--adm-cols:minmax(160px,1.6fr) 64px 90px 70px">
                <div class="adm-table__head"><span>Account</span><span>Role</span><span>Status</span><span>Joined</span></div>
                <div class="adm-table__body">
                <?php foreach ($this->new_users as $u): $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); $name = $name !== '' ? $name : ('@' . $u['u_name']); $dis = ($u['user_status'] === 'Disabled'); ?>
                    <div class="adm-row">
                        <div class="adm-ucell adm-ucell--user"><span class="adm-uav"><?php echo $e($ini($name)); ?></span><span class="adm-uinfo"><a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $u['user_id']; ?>"><?php echo $e($name); ?></a><span class="adm-uinfo__meta">@<?php echo $e($u['u_name']); ?> · <?php echo $e($u['user_email']); ?></span></span></div>
                        <div class="adm-ucell adm-ucell--muted"><?php echo $e($u['role_name'] ?: 'User'); ?></div>
                        <div class="adm-ucell"><span class="adm-status adm-status--<?php echo $dis ? 'off' : 'on'; ?>"><span class="adm-status__dot"></span><?php echo $dis ? 'Suspended' : 'Active'; ?></span></div>
                        <div class="adm-ucell adm-ucell--muted"><?php echo $e($ago($u['created_at'])); ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section class="adm-box">
            <header class="adm-box__head"><h2 class="adm-box__h">Recent Sales</h2><a class="adm-box__link" href="/admin/money#sales">All sales</a></header>
            <?php if (empty($this->sales)): ?><p class="adm-none">No sales yet.</p><?php else: ?>
            <div class="adm-table adm-table--plain" style="--adm-cols:minmax(110px,1fr) minmax(120px,1.4fr) 64px 70px 70px">
                <div class="adm-table__head"><span>Buyer</span><span>Item</span><span>Type</span><span class="adm-r">Amount</span><span>When</span></div>
                <div class="adm-table__body">
                <?php foreach ($this->sales as $sale): ?>
                    <div class="adm-row">
                        <div class="adm-ucell"><a class="adm-user-link adm-strong" href="/admin/user/<?php echo (int) $sale['fan_id']; ?>">@<?php echo $e($sale['fan_handle']); ?></a></div>
                        <div class="adm-ucell adm-ucell--ellipsis"><?php echo $e($sale['item'] !== '' ? $sale['item'] : 'Untitled'); ?></div>
                        <div class="adm-ucell"><span class="adm-tag adm-tag--<?php echo $e($sale['kind']); ?>"><?php echo $sale['kind'] === 'bundle' ? 'Bundle' : ($sale['kind'] === 'message' ? 'Message' : 'PPV'); ?></span></div>
                        <div class="adm-ucell adm-r adm-strong">$<?php echo number_format(((int) $sale['credits']) / 10, 2); ?></div>
                        <div class="adm-ucell adm-ucell--muted"><?php echo $e($ago($sale['created_at'])); ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="adm-stack">
        <section class="adm-box<?php echo $jobs_bad ? ' adm-box--alert' : ''; ?>">
            <header class="adm-box__head"><h2 class="adm-box__h">Jobs</h2><a class="adm-box__link" href="/admin/jobs">All jobs</a></header>
            <?php if (empty($jobs_bad)): ?>
                <p class="adm-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> All <?php echo count((array) $this->cron_jobs); ?> scheduled jobs ran on time.</p>
            <?php else: ?>
                <ul class="adm-list">
                <?php foreach ($jobs_bad as $j): ?>
                    <li class="adm-list__item">
                        <span class="adm-list__main"><b><?php echo $e(str_replace(array('Seo ', 'Db '), array('SEO ', 'DB '), ucwords(str_replace('_', ' ', $j['name'])))); ?></b><?php if ($j['note'] !== ''): ?><small><?php echo $e($j['note']); ?></small><?php endif; ?></span>
                        <span class="adm-list__side"><?php if ($j['finished_at'] !== '' && !$j['ok']): ?><span class="adm-pill adm-pill--bad">Failed</span><?php else: ?><span class="adm-pill adm-pill--warn">Stale</span><?php endif; ?><small><?php echo $j['finished_at'] !== '' ? $e($ago($j['finished_at'])) : 'Never ran'; ?></small></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="adm-box">
            <header class="adm-box__head"><h2 class="adm-box__h">Staff Actions</h2><a class="adm-box__link" href="/admin/audit">Audit log</a></header>
            <?php if (empty($this->audit)): ?><p class="adm-none">Nothing recorded yet.</p><?php else: ?>
            <ul class="adm-list">
            <?php foreach ($this->audit as $ar): $admin = $ar['admin_name'] !== '' ? $ar['admin_name'] : '@' . $ar['admin_handle']; $target = $ar['target_user_id'] ? ($ar['target_name'] !== '' ? $ar['target_name'] : '@' . $ar['target_handle']) : ''; ?>
                <li class="adm-list__item">
                    <span class="adm-list__main"><b><?php echo $e(AuditModel::LABELS[$ar['action']] ?? $ar['action']); ?></b><small><?php echo $e($admin); ?><?php if ($target !== ''): ?> · <a class="adm-user-link" href="/admin/user/<?php echo (int) $ar['target_user_id']; ?>"><?php echo $e($target); ?></a><?php endif; ?></small></span>
                    <span class="adm-list__side"><small><?php echo $e($ago($ar['created_at'])); ?></small></span>
                </li>
            <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="adm-box">
            <header class="adm-box__head"><h2 class="adm-box__h">This Month</h2><a class="adm-box__link" href="/admin/money">Financials</a></header>
            <dl class="adm-kv">
                <div><dt>Plan payments</dt><dd><?php echo $usd($month['plans']); ?></dd></div>
                <div><dt>Fee on sales</dt><dd><?php echo $usd($month['fee']); ?></dd></div>
                <div><dt>Fee on memberships</dt><dd><?php echo $usd($month['members']); ?></dd></div>
                <div><dt>Credits bought</dt><dd><?php echo $usd($month['cash_in']); ?></dd></div>
                <div><dt>Refunds</dt><dd><?php echo $usd($month['refunds'] ?? 0); ?></dd></div>
                <div><dt>Paid out</dt><dd><?php echo $usd($month['payouts'] ?? 0); ?></dd></div>
            </dl>
        </section>
    </div>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
