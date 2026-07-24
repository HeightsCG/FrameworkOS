<link rel="stylesheet" href="/css/dashboard.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/dashboard.css'); ?>">
<?php
$fmt_money = function ($cents) { return '$' . number_format(((int) $cents) / 100, 2); };
$fmt_num   = function ($n) { return number_format((int) $n); };
// Stored times are UTC — render them in the creator's timezone.
$dash_tz   = (string) ($this->timezone ?? 'UTC');
$fmt_when  = function ($utc) use ($dash_tz) {
    if ((string) $utc === '') { return ''; }
    try {
        $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($dash_tz ?: 'UTC'));
        return $d->format('M j, g:i A');
    } catch (\Throwable $e) { return ''; }
};
$first      = trim((string) ($this->display_name ?? ''));
$first_word = $first !== '' ? preg_split('/\s+/', $first)[0] : '';
?>
<div class="dash">
    <header class="dash__head">
        <h1 class="dash__title">Analytics</h1>
        <p class="dash__sub"><?php echo $first_word !== '' ? 'Welcome back, ' . htmlspecialchars($first_word, ENT_QUOTES, 'UTF-8') . '.' : 'Welcome back.'; ?></p>
    </header>

<?php if (empty($this->is_creator)): ?>
    <div class="dash__gate">
        <i class="fa-solid fa-chart-line dash__gate-icon"></i>
        <h2 class="dash__gate-title">Your creator dashboard lives here</h2>
        <p class="dash__gate-text">Turn on your creator account to publish content, earn from subscriptions and pay-per-view, and track how it's performing.</p>
        <a href="/account/settings?section=creator" class="btn btn-primary">Become a creator</a>
    </div>
<?php else:
    $s   = $this->stats;
    $rev = $this->revenue_breakdown;                 // scoped to the selected range
    $rev_all = $this->revenue_lifetime;              // all-time, for KPI context
    $range = (int) ($this->range ?? 30);
    $range_label = 'Last ' . $range . ' days';

    $follower_series = $this->follower_series;
    $revenue_series  = $this->revenue_series;
    $revenue_period_cents = array_sum(array_map(function ($p) { return (int) $p['value']; }, $revenue_series));

    $cmp = $this->compare;
    $cur = $cmp['current'];
    $dl  = $cmp['delta'];

    // % change badge vs. the previous period of equal length. null delta = no prior baseline.
    $delta_badge = function ($d) {
        if ($d === null) { return '<span class="kpi__delta kpi__delta--new">new</span>'; }
        $d = (int) $d;
        if ($d === 0) { return '<span class="kpi__delta kpi__delta--flat">0%</span>'; }
        $up = $d > 0;
        return '<span class="kpi__delta kpi__delta--' . ($up ? 'up' : 'down') . '">'
             . ($up ? '&#9650;' : '&#9660;') . ' ' . ($up ? '+' : '') . $d . '%</span>';
    };

    $kpis = array(
        array('label' => 'Revenue',        'value' => $fmt_money($cur['revenue_cents']), 'delta' => $dl['revenue_cents'], 'sub' => $fmt_money($rev_all['total_cents']) . ' all-time',                          'icon' => 'fa-coins'),
        array('label' => 'New subscribers', 'value' => $fmt_num($cur['subscribers']),    'delta' => $dl['subscribers'],   'sub' => $fmt_num($s['subscribers']) . ' active · ' . $fmt_money($s['mrr_cents']) . '/mo', 'icon' => 'fa-heart'),
        array('label' => 'New followers',  'value' => $fmt_num($cur['followers']),        'delta' => $dl['followers'],     'sub' => $fmt_num($s['followers']) . ' total',                                        'icon' => 'fa-user-plus'),
        array('label' => 'Views',          'value' => $fmt_num($cur['views']),            'delta' => $dl['views'],         'sub' => $fmt_num($s['unique_visitors']) . ' unique · ' . $fmt_num($s['views']) . ' all-time', 'icon' => 'fa-eye'),
    );

    // Shared daily-bar renderer for the trend charts. $money → format values as dollars.
    $render_bars = function ($series, $money = false) use ($fmt_num, $fmt_money) {
        $n    = count($series);
        $vals = array_map(function ($p) { return (int) $p['value']; }, $series);
        $maxv = max(1, max($vals));
        echo '<div class="dash__bars" role="img" aria-label="Daily trend over the last ' . (int) $n . ' days">';
        foreach ($series as $p) {
            $v = (int) $p['value'];
            $h = $v > 0 ? max(8, (int) round($v / $maxv * 100)) : 0;
            $lbl = $money ? $fmt_money($v) : ($fmt_num($v));
            $tip = date('D, M j', strtotime((string) $p['date'])) . ' — ' . $lbl;
            echo '<div class="dash__col" title="' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '">'
               . '<div class="dash__col-fill' . ($v > 0 ? ' is-on' : '') . '" style="height:' . $h . '%"></div></div>';
        }
        echo '</div><div class="dash__chart-axis">';
        $step = max(1, (int) floor(($n - 1) / 5));
        for ($i = 0; $i < $n; $i += $step) { echo '<span>' . date('M j', strtotime((string) $series[$i]['date'])) . '</span>'; }
        if ((($n - 1) % $step) !== 0) { echo '<span>' . date('M j', strtotime((string) $series[$n - 1]['date'])) . '</span>'; }
        echo '</div>';
    };
?>
    <div class="dash__toolbar">
        <span class="dash__toolbar-label"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?> <span class="dash__toolbar-hint">vs. previous <?php echo (int) $range; ?> days</span></span>
        <div class="dash__range" role="tablist" aria-label="Date range">
            <?php foreach (array(7 => '7 days', 30 => '30 days', 90 => '90 days') as $r => $lbl): ?>
            <a class="dash__range-btn<?php echo $range === $r ? ' is-active' : ''; ?>" href="<?php echo $r === 30 ? '/dashboard' : '/dashboard/index/' . $r; ?>"><?php echo $lbl; ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="dash__kpis">
        <?php foreach ($kpis as $k): ?>
        <div class="kpi">
            <div class="kpi__top"><span class="kpi__label"><?php echo $k['label']; ?></span><span class="kpi__icon"><i class="fa-solid <?php echo $k['icon']; ?>"></i></span></div>
            <div class="kpi__value"><?php echo $k['value']; ?> <?php echo $delta_badge($k['delta']); ?></div>
            <div class="kpi__sub"><?php echo htmlspecialchars($k['sub'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="dash__tabs" id="dashTabs" role="tablist">
        <button type="button" class="dash__tab is-active" data-panel="revenue" role="tab"><i class="fa-solid fa-sack-dollar"></i> Revenue</button>
        <button type="button" class="dash__tab" data-panel="audience" role="tab"><i class="fa-solid fa-users"></i> Audience</button>
        <button type="button" class="dash__tab" data-panel="customers" role="tab"><i class="fa-solid fa-heart"></i> Customers</button>
    </div>

    <?php
    // ================= REVENUE TAB =================
    $offerings = array(
        array('Pay-per-view', (int) $rev['ppv_cents'],     'fa-unlock'),
        array('Bundles',      (int) $rev['bundle_cents'],  'fa-layer-group'),
        array('Events',       (int) $rev['event_cents'],   'fa-calendar-days'),
        array('Services',     (int) $rev['service_cents'], 'fa-briefcase'),
    );
    $rev_max = max(1, (int) $rev['ppv_cents'], (int) $rev['bundle_cents'], (int) $rev['event_cents'], (int) $rev['service_cents']);
    $sale_tag = array('ppv' => 'PPV', 'bundle' => 'Bundle', 'event' => 'Event', 'service' => 'Service');
    ?>
    <section class="dash__tab-panel is-active" data-panel="revenue">
        <div class="dash__panel">
            <div class="dash__panel-head">
                <div><h2 class="dash__panel-title">Revenue by offering</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?> · net of platform fees</span></div>
                <div class="dash__chart-total"><strong><?php echo $fmt_money($rev['total_cents']); ?></strong> total</div>
            </div>
            <?php if ((int) $rev['total_cents'] === 0): ?>
            <div class="dash__empty">No revenue in this period. Sell pay-per-view content, bundles, events, or services to see the breakdown here.</div>
            <?php else: ?>
            <div class="dash__break">
                <?php foreach ($offerings as $o): $pct = $o[1] > 0 ? max(3, (int) round($o[1] / $rev_max * 100)) : 0; ?>
                <div class="dash__break-row">
                    <span class="dash__break-label"><i class="fa-solid <?php echo $o[2]; ?>"></i> <?php echo htmlspecialchars($o[0], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="dash__break-track"><span class="dash__break-fill<?php echo $o[1] > 0 ? ' is-on' : ''; ?>" style="width:<?php echo $pct; ?>%"></span></span>
                    <span class="dash__break-val"><?php echo $fmt_money($o[1]); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="dash__panel dash__chart">
            <div class="dash__panel-head">
                <div><h2 class="dash__panel-title">Revenue</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, "UTF-8"); ?></span></div>
                <div class="dash__chart-total"><strong><?php echo $fmt_money($revenue_period_cents); ?></strong> earned</div>
            </div>
            <?php $render_bars($revenue_series, true); ?>
        </div>

        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Recent sales</h2><span class="dash__panel-sub">all offerings</span></div><a class="dash__export" href="/dashboard/export"><i class="fa-solid fa-download"></i> Export CSV</a></div>
            <?php if (empty($this->recent_sales)): ?>
                <div class="dash__empty">No sales yet.</div>
            <?php else: ?>
            <div class="dash__list">
                <?php foreach ($this->recent_sales as $u): $item = trim((string) $u['item']); ?>
                <div class="dash__row dash__row--static">
                    <span class="dash__row-main">
                        <span class="dash__row-title"><?php echo $item !== '' ? htmlspecialchars(mb_substr($item, 0, 60), ENT_QUOTES, 'UTF-8') : 'Untitled'; ?></span>
                        <span class="dash__row-meta"><span class="dash__tag dash__tag--sub"><?php echo htmlspecialchars($sale_tag[$u['kind']] ?? 'Sale', ENT_QUOTES, 'UTF-8'); ?></span> <?php echo htmlspecialchars($fmt_when((string) $u['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span class="dash__amount">+<?php echo $fmt_num($u['credits']); ?> cr</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ================= AUDIENCE TAB ================= -->
    <section class="dash__tab-panel" data-panel="audience">
        <div class="dash__cols">
            <div class="dash__panel dash__chart">
                <div class="dash__panel-head">
                    <div><h2 class="dash__panel-title">Views</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, "UTF-8"); ?></span></div>
                    <div class="dash__chart-total"><strong><?php echo $fmt_num(array_sum(array_map(function ($p) { return (int) $p['value']; }, $this->views_series))); ?></strong> views</div>
                </div>
                <?php $render_bars($this->views_series, false); ?>
            </div>
            <div class="dash__panel dash__chart">
                <div class="dash__panel-head">
                    <div><h2 class="dash__panel-title">Follower growth</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, "UTF-8"); ?></span></div>
                    <div class="dash__chart-total"><strong><?php echo ((int) $cur['followers'] > 0 ? '+' : '') . $fmt_num($cur['followers']); ?></strong> new</div>
                </div>
                <?php $render_bars($follower_series, false); ?>
            </div>
        </div>

        <?php
        // ---- Traffic & conversion ----
        $pv    = $this->profile_views;
        $uniqv = (int) $pv['unique'];
        $follow_conv = $uniqv > 0 ? round((int) $cur['followers'] / $uniqv * 100, 1) : 0;
        $sub_conv    = $uniqv > 0 ? round((int) $cur['subscribers'] / $uniqv * 100, 1) : 0;
        $traffic_stats = array(
            array('Profile views',   $fmt_num($pv['views']),  'total visits'),
            array('Unique visitors',  $fmt_num($uniqv),        'distinct people'),
            array('Follow rate',      ($uniqv > 0 ? $follow_conv . '%' : '—'), 'visitors who followed'),
            array('Subscribe rate',   ($uniqv > 0 ? $sub_conv . '%' : '—'),    'visitors who subscribed'),
        );
        ?>
        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Traffic &amp; conversion</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?></span></div></div>
            <div class="dash__stats">
                <?php foreach ($traffic_stats as $st): ?>
                <div class="dash__stat">
                    <span class="dash__stat-label"><?php echo htmlspecialchars($st[0], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="dash__stat-value"><?php echo $st[1]; ?></span>
                    <span class="dash__stat-sub"><?php echo htmlspecialchars($st[2], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php $lc = $this->link_clicks; ?>
        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Link clicks</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?></span></div><div class="dash__chart-total"><strong><?php echo $fmt_num($lc['total']); ?></strong> clicks</div></div>
            <?php if (empty($lc['top'])): ?>
                <div class="dash__empty">No link clicks in this period. Clicks on the links on your profile are tracked here.</div>
            <?php else: ?>
            <div class="dash__list">
                <?php foreach ($lc['top'] as $l): ?>
                <div class="dash__row dash__row--static">
                    <span class="dash__row-main">
                        <span class="dash__row-title"><?php echo htmlspecialchars(mb_substr((string) $l['title'], 0, 50), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dash__row-meta"><?php echo htmlspecialchars(preg_replace('#^https?://(www\.)?#i', '', (string) $l['url']), ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span class="dash__amount"><?php echo $fmt_num($l['clicks']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // ---- Best-time-to-post heatmap (views by day × hour, creator timezone) ----
        $hm    = $this->heatmap;
        $hgrid = $hm['grid'];
        $hmax  = max(1, (int) $hm['max']);
        $hdays = array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');
        $hhour = function ($h) { $ap = $h < 12 ? 'a' : 'p'; $h12 = $h % 12; if ($h12 === 0) { $h12 = 12; } return $h12 . $ap; };
        ?>
        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">When your audience is active</h2><span class="dash__panel-sub">Content views by day &amp; hour · <?php echo htmlspecialchars($dash_tz, ENT_QUOTES, 'UTF-8'); ?></span></div></div>
            <?php if ((int) $hm['max'] === 0): ?>
            <div class="dash__empty">Not enough view data yet. As people view your content, the busiest days and hours show up here.</div>
            <?php else: ?>
            <div class="dash__heat">
                <div class="dash__heat-axis"><?php for ($h = 0; $h < 24; $h += 6): ?><span style="grid-column:<?php echo $h + 1; ?> / span 6"><?php echo $hhour($h); ?></span><?php endfor; ?></div>
                <?php foreach ($hdays as $di => $dname): ?>
                <div class="dash__heat-row">
                    <span class="dash__heat-day"><?php echo $dname; ?></span>
                    <div class="dash__heat-cells">
                        <?php for ($h = 0; $h < 24; $h++): $n = (int) $hgrid[$di][$h]; $a = $n > 0 ? round(0.18 + 0.82 * $n / $hmax, 3) : 0; ?>
                        <span class="dash__heat-cell" <?php echo $n > 0 ? 'style="background:rgba(91,75,224,' . $a . ')"' : ''; ?> title="<?php echo $dname . ' ' . $hhour($h) . ' — ' . $n . ' view' . ($n === 1 ? '' : 's'); ?>"></span>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    // ================= CUSTOMERS TAB =================
    $cst = $this->customers;
    $mov = $this->sub_movement;
    $cust_stats = array(
        array('Paying customers', $fmt_num($cst['customers']),  'people who bought at least once'),
        array('Repeat rate',      $cst['repeat_pct'] . '%',     $fmt_num($cst['repeat']) . ' bought more than once'),
        array('Avg per customer', $fmt_money($cst['arpu_cents']), 'gross spend, lifetime'),
        array('Subscriber change', ($mov['net'] > 0 ? '+' : '') . $fmt_num($mov['net']), '+' . $fmt_num($mov['new']) . ' new · -' . $fmt_num($mov['churned']) . ' churned'),
    );
    ?>
    <section class="dash__tab-panel" data-panel="customers">
        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Customers &amp; subscribers</h2><span class="dash__panel-sub">Customers all-time · subscriber change <?php echo htmlspecialchars(strtolower($range_label), ENT_QUOTES, 'UTF-8'); ?></span></div></div>
            <div class="dash__stats">
                <?php foreach ($cust_stats as $st): ?>
                <div class="dash__stat">
                    <span class="dash__stat-label"><?php echo htmlspecialchars($st[0], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="dash__stat-value"><?php echo $st[1]; ?></span>
                    <span class="dash__stat-sub"><?php echo htmlspecialchars($st[2], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dash__panel">
            <div class="dash__panel-head"><h2 class="dash__panel-title">Top content</h2><span class="dash__panel-sub">by revenue</span></div>
            <?php if (empty($this->top_posts)): ?>
                <div class="dash__empty">No published posts yet.</div>
            <?php else: ?>
            <div class="dash__list">
                <?php foreach ($this->top_posts as $p): $cap = trim((string) $p['caption']); ?>
                <a class="dash__row" href="/studio">
                    <span class="dash__row-main">
                        <span class="dash__row-title"><?php echo $cap !== '' ? htmlspecialchars(mb_substr($cap, 0, 60), ENT_QUOTES, 'UTF-8') : 'Untitled post'; ?></span>
                        <span class="dash__row-meta"><i class="fa-regular fa-eye"></i> <?php echo $fmt_num($p['views']); ?> · <i class="fa-regular fa-heart"></i> <?php echo $fmt_num($p['likes']); ?><?php if ((int) $p['earnings_cents'] > 0): ?> · <?php echo $fmt_money($p['earnings_cents']); ?><?php endif; ?></span>
                    </span>
                    <?php if ($p['audience'] === 'ppv'): ?><span class="dash__tag">PPV</span><?php elseif ($p['audience'] === 'subscribers'): ?><span class="dash__tag dash__tag--sub">Subs</span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // ---- Content mix by audience type ----
        $cm = $this->content_mix;
        $mix_types = array('free' => 'Free', 'subscribers' => 'Subscribers', 'ppv' => 'Pay-per-view');
        $mix_has = false; foreach ($cm as $mrow) { if ((int) $mrow['posts'] > 0) { $mix_has = true; break; } }
        ?>
        <div class="dash__panel">
            <div class="dash__panel-head"><h2 class="dash__panel-title">Content mix</h2><span class="dash__panel-sub">by audience type</span></div>
            <?php if (!$mix_has): ?>
            <div class="dash__empty">No published content yet.</div>
            <?php else: ?>
            <div class="dash__list">
                <?php foreach ($mix_types as $key => $label): $m = $cm[$key] ?? array('posts' => 0, 'views' => 0, 'earnings' => 0); ?>
                <div class="dash__row dash__row--static">
                    <span class="dash__row-main">
                        <span class="dash__row-title"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dash__row-meta"><?php echo $fmt_num($m['posts']); ?> post<?php echo (int) $m['posts'] === 1 ? '' : 's'; ?> · <i class="fa-regular fa-eye"></i> <?php echo $fmt_num($m['views']); ?> views</span>
                    </span>
                    <span class="dash__amount"><?php echo (int) $m['earnings'] > 0 ? $fmt_money($m['earnings']) : '—'; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!empty($this->needs_plan)): ?>
    <div class="plan-lock">
        <div class="plan-lock__card">
            <div class="plan-lock__icon"><i class="fa-solid fa-lock"></i></div>
            <h2 class="plan-lock__title">Subscribe to a Plan</h2>
            <p class="plan-lock__text">Unlock the Content Studio, publishing, scheduling, and analytics.</p>
            <a href="/account/billing" class="plan-lock__btn">Choose a Plan</a>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>
<script src="/js/dashboard.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/dashboard.js'); ?>"></script>
