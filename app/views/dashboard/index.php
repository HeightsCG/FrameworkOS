<link rel="stylesheet" href="/css/dashboard.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/dashboard.css'); ?>">
<?php
// Earnings come from the credit ledger (stored as cents, $1 = 10 credits) and are shown in credits. Memberships are
// real card money (Stripe), so MRR stays in dollars.
$fmt_money = function ($cents) { return Price::credits((int) round(((int) $cents) / 10)); };
$fmt_usd   = function ($cents) { return '$' . number_format(((int) $cents) / 100, 2); };
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
<?php if (!empty($this->needs_plan)): ?>
    <div class="plan-bar" role="status">
        <p class="plan-bar__text"><strong>You're on Free.</strong> Your earnings and history are still here. Choose a plan to create, publish and sell.</p>
        <a href="/account/billing" class="btn btn-primary plan-bar__btn">Choose a Plan</a>
    </div>
<?php endif; ?>

<?php if (empty($this->is_creator)): ?>
    <div class="dash__gate">
        <i class="fa-solid fa-chart-line dash__gate-icon"></i>
        <h2 class="dash__gate-title">Your creator dashboard lives here</h2>
        <p class="dash__gate-text">Turn on your creator account to publish content, earn from subscriptions and pay-per-view, and track how it's performing.</p>
        <a href="/account/settings?section=creator" class="btn btn-primary">Become a Creator</a>
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
        array('label' => 'Subscribers',    'value' => $fmt_num($cur['subscribers']),      'delta' => $dl['subscribers'],   'sub' => $fmt_num($s['subscribers']) . ' active · ' . $fmt_usd($s['mrr_cents']) . '/mo', 'icon' => 'fa-heart'),
        array('label' => 'Followers',      'value' => $fmt_num($cur['followers']),        'delta' => $dl['followers'],     'sub' => $fmt_num($s['followers']) . ' total',                             'icon' => 'fa-user-plus'),
        array('label' => 'Views',          'value' => $fmt_num($cur['views']),            'delta' => $dl['views'],         'sub' => $fmt_num($s['views']) . ' all-time',                                        'icon' => 'fa-eye'),
        array('label' => 'Posts',          'value' => $fmt_num($cur['posts']),            'delta' => $dl['posts'],         'sub' => $fmt_num($s['published_posts']) . ' published all-time',                    'icon' => 'fa-photo-film'),
        array('label' => 'Engagement',     'value' => $cur['engagement'] . '%',           'delta' => $dl['engagement'],    'sub' => $fmt_num($cur['likes']) . ' likes · ' . $fmt_num($cur['comments']) . ' comments',  'icon' => 'fa-comment-dots'),
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

    <?php if (!empty($this->nudge)): $nd = $this->nudge; ?>
    <div class="dash-nudge">
        <div class="dash-nudge__main">
            <div class="dash-nudge__title">You'd have kept $<?php echo number_format($nd['savings_cents'] / 100, 2); ?> more last month on <?php echo htmlspecialchars((string) $nd['tier']['name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <p class="dash-nudge__text">On $<?php echo number_format($nd['gross_cents'] / 100, 2); ?> of sales in the last 30 days, your plan and fees cost $<?php echo number_format($nd['current_cents'] / 100, 2); ?>. <?php echo htmlspecialchars((string) $nd['tier']['name'], ENT_QUOTES, 'UTF-8'); ?> would have cost $<?php echo number_format($nd['other_cents'] / 100, 2); ?>, including its $<?php echo number_format((int) $nd['tier']['price']); ?> monthly price and <?php echo (int) $nd['tier']['limits']['fee_percent']; ?>% take rate.</p>
        </div>
        <a href="/account/billing" class="btn btn-primary dash-nudge__btn">Upgrade to <?php echo htmlspecialchars((string) $nd['tier']['name'], ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
    <?php endif; ?>

    <div class="dash__tabs" id="dashTabs" role="tablist">
        <button type="button" class="dash__tab is-active" data-panel="revenue" role="tab"><i class="fa-solid fa-sack-dollar"></i> Revenue</button>
        <button type="button" class="dash__tab" data-panel="content" role="tab"><i class="fa-solid fa-photo-film"></i> Content</button>
        <button type="button" class="dash__tab" data-panel="audience" role="tab"><i class="fa-solid fa-users"></i> Audience</button>
        <button type="button" class="dash__tab" data-panel="customers" role="tab"><i class="fa-solid fa-heart"></i> Customers</button>
    </div>

    <?php
    // ================= REVENUE TAB =================
    $offerings = array(
        array('Pay-per-view', (int) $rev['ppv_cents'],     'fa-unlock'),
        array('Bundles',      (int) $rev['bundle_cents'],  'fa-layer-group'),
        array('Messages',     (int) $rev['message_cents'], 'fa-envelope'),
        array('Events',       (int) $rev['event_cents'],   'fa-calendar-days'),
        array('Services',     (int) $rev['service_cents'], 'fa-briefcase'),
        array('Tips',         (int) ($rev['tip_cents'] ?? 0), 'fa-coins'),
        array('Replays',      (int) ($rev['replay_cents'] ?? 0), 'fa-circle-play'),
    );
    $rev_max = max(1, (int) $rev['ppv_cents'], (int) $rev['bundle_cents'], (int) $rev['message_cents'], (int) $rev['event_cents'], (int) $rev['service_cents'], (int) ($rev['tip_cents'] ?? 0), (int) ($rev['replay_cents'] ?? 0));
    $sale_tag = array('ppv' => 'PPV', 'bundle' => 'Bundle', 'event' => 'Event', 'service' => 'Service', 'tip' => 'Tip', 'replay' => 'Replay');
    ?>
    <section class="dash__tab-panel is-active" data-panel="revenue">
        <div class="dash__panel">
            <div class="dash__panel-head">
                <div><h2 class="dash__panel-title">Revenue by Offering</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?> · net of platform fees</span></div>
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
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Recent Sales</h2><span class="dash__panel-sub">all offerings</span></div><a class="dash__export" href="/dashboard/export"><i class="fa-solid fa-download"></i> Export CSV</a></div>
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
                    <span class="dash__amount">+<?php echo Price::credits((int) $u['credits']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    // ================= CONTENT TAB =================
    $pt  = $this->posts_table;
    $ss  = $this->share_stats;
    $avg_views  = (int) $cur['posts'] > 0 ? (int) round((int) $cur['views'] / (int) $cur['posts']) : 0;
    $share_rate = (int) $cur['posts'] > 0 ? (int) round(min((int) $ss['posts_shared'], (int) $cur['posts']) / (int) $cur['posts'] * 100) : 0;
    $content_stats = array(
        array('Views per post',  $fmt_num($avg_views),                                          'avg, posts published in period'),
        array('Engagement rate', $cur['engagement'] . '%',                                       'likes + comments per view'),
        array('Unlock rate',     ((int) $cur['ppv_views'] > 0 ? $cur['unlock_rate'] . '%' : '—'), $fmt_num($cur['unlocks']) . ' unlocks · ' . $fmt_num($cur['ppv_views']) . ' PPV views'),
        array('Shared',          $share_rate . '%',                                              $fmt_num($ss['posts_shared']) . ' of ' . $fmt_num($cur['posts']) . ' posts cross-posted'),
    );
    $aud_tag = function ($a) { return $a === 'ppv' ? '<span class="dash__tag">PPV</span>' : ($a === 'subscribers' ? '<span class="dash__tag dash__tag--sub">Subs</span>' : ''); };
    $plat_icons = function (array $plats) {
        $h = '';
        foreach ($plats as $pl) { $h .= '<i class="' . AnalyticsModel::platform_icon($pl) . '" title="' . htmlspecialchars($pl === 'x' ? 'X' : ($pl === 'tiktok' ? 'TikTok' : ucfirst($pl)), ENT_QUOTES, 'UTF-8') . '"></i>'; }
        return $h;
    };
    $bucket_label = array('delivered' => 'Delivered', 'pending' => 'Pending', 'failed' => 'Failed');
    $plat_name = function ($pl) { $n = array('x' => 'X', 'tiktok' => 'TikTok', 'tiktok_business' => 'TikTok', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn'); return $n[$pl] ?? ucfirst((string) $pl); };
    ?>
    <section class="dash__tab-panel" data-panel="content">
        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Content Performance</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?></span></div></div>
            <div class="dash__stats">
                <?php foreach ($content_stats as $st): ?>
                <div class="dash__stat">
                    <span class="dash__stat-label"><?php echo htmlspecialchars($st[0], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="dash__stat-value"><?php echo $st[1]; ?></span>
                    <span class="dash__stat-sub"><?php echo htmlspecialchars($st[2], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dash__panel">
            <div class="dash__panel-head">
                <div><h2 class="dash__panel-title">All Posts</h2><span class="dash__panel-sub"><?php echo $fmt_num(count($pt)); ?> published or scheduled · views are <?php echo htmlspecialchars(strtolower($range_label), ENT_QUOTES, 'UTF-8'); ?>, the rest all-time · Social is the cross-posted copies</span></div>
            </div>
            <?php if (empty($pt)): ?>
            <div class="dash__empty">No published posts yet. Publish from the Content Studio and every post shows up here with its views, engagement, unlocks, revenue, and where it was shared.</div>
            <?php else: ?>
            <div class="dash__table-wrap">
            <table class="dash__table" id="dashPosts">
                <thead>
                    <tr>
                        <th data-sort="text" class="dash__th--post">Post</th>
                        <th data-sort="num">Views</th>
                        <th data-sort="num">Engagement</th>
                        <th data-sort="num">Revenue</th>
                        <th data-sort="num" class="dash__th--social">Social</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pt as $p):
                    $cap  = trim((string) $p['caption']);
                    $when = $p['state'] === 'scheduled' ? 'Scheduled ' . $fmt_when((string) $p['scheduled_at']) : $fmt_when((string) $p['published_at']);
                    $eng  = $p['engagement'];
                    $ur   = $p['unlock_rate'];
                ?>
                    <tr>
                        <td data-v="<?php echo htmlspecialchars(mb_strtolower($cap), ENT_QUOTES, 'UTF-8'); ?>">
                            <a class="dash__cell-post" href="/studio">
                                <span class="dash__row-title"><?php echo $cap !== '' ? htmlspecialchars(mb_substr($cap, 0, 56), ENT_QUOTES, 'UTF-8') : 'Untitled post'; ?></span>
                                <span class="dash__row-meta"><?php echo $aud_tag($p['audience']); ?> <?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </td>
                        <td data-v="<?php echo (int) $p['views_period']; ?>"><span class="dash__num"><?php echo $fmt_num($p['views_period']); ?></span><span class="dash__num-sub"><?php echo $fmt_num($p['views']); ?> all-time</span></td>
                        <td data-v="<?php echo $eng === null ? -1 : $eng; ?>">
                            <span class="dash__num<?php echo $eng === null ? ' dash__num--na' : ''; ?>"><?php echo $eng === null ? '—' : $eng . '%'; ?></span>
                            <?php if ((int) $p['likes'] > 0 || (int) $p['comments'] > 0): ?><span class="dash__num-sub"><?php echo $fmt_num($p['likes']); ?> likes · <?php echo $fmt_num($p['comments']); ?> comments</span><?php endif; ?>
                        </td>
                        <td data-v="<?php echo (int) $p['earnings_cents']; ?>">
                            <span class="dash__num<?php echo (int) $p['earnings_cents'] > 0 ? ' dash__num--money' : ' dash__num--na'; ?>"><?php echo (int) $p['earnings_cents'] > 0 ? $fmt_money($p['earnings_cents']) : '—'; ?></span>
                            <?php if ($p['audience'] === 'ppv' && (int) $p['unlocks'] > 0): ?><span class="dash__num-sub"><?php echo $fmt_num($p['unlocks']); ?> unlocks<?php echo $ur === null ? '' : ' · ' . $ur . '% of views'; ?></span><?php endif; ?>
                        </td>
                        <?php $so = $p['social']; $so_eng = $so ? (int) $so['likes'] + (int) $so['comments'] + (int) $so['shares'] : 0; ?>
                        <td class="dash__td--social" data-v="<?php echo $so ? (int) $so['views'] : (empty($p['platforms']) ? -1 : 0); ?>">
                            <?php if (empty($p['platforms'])): ?>
                            <span class="dash__num dash__num--na">—</span>
                            <?php else: ?>
                            <span class="dash__plats<?php echo $p['share_status'] === 'failed' ? ' is-failed' : ($p['share_status'] === 'pending' ? ' is-pending' : ''); ?>"><?php echo $plat_icons($p['platforms']); ?></span>
                            <?php if ($so): ?><span class="dash__num-sub"><?php echo $fmt_num($so['views']); ?> views · <?php echo $fmt_num($so_eng); ?> engagements</span><?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="dash__cols">
            <div class="dash__panel">
                <div class="dash__panel-head">
                    <div><h2 class="dash__panel-title">Cross-posting</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?> · by platform</span></div>
                    <div class="dash__chart-total"><strong><?php echo $fmt_num($ss['targets']); ?></strong> shares</div>
                </div>
                <?php if ((int) $ss['targets'] === 0): ?>
                <div class="dash__empty">Nothing cross-posted in this period. Pick accounts when you publish and each share is counted here.</div>
                <?php else: $pmax = max(1, max($ss['per_platform'])); ?>
                <div class="dash__break">
                    <?php foreach ($ss['per_platform'] as $pl => $n): $pct = max(3, (int) round($n / $pmax * 100)); ?>
                    <div class="dash__break-row">
                        <span class="dash__break-label"><i class="<?php echo AnalyticsModel::platform_icon($pl); ?>"></i> <?php echo htmlspecialchars($plat_name($pl), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dash__break-track"><span class="dash__break-fill is-on" style="width:<?php echo $pct; ?>%"></span></span>
                        <span class="dash__break-val"><?php echo $fmt_num($n); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="dash__buckets">
                    <?php foreach ($bucket_label as $key => $lbl): ?>
                    <span class="dash__bucket dash__bucket--<?php echo $key; ?>"><i class="fa-solid <?php echo $key === 'delivered' ? 'fa-circle-check' : ($key === 'failed' ? 'fa-circle-xmark' : 'fa-clock'); ?>"></i> <?php echo $fmt_num($ss['buckets'][$key]); ?> <?php echo strtolower($lbl); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($ss['metrics'])): ?>
                <div class="dash__panel-head dash__panel-head--inner"><div><h3 class="dash__panel-title dash__panel-title--sm">Engagement on Shared Copies</h3><span class="dash__panel-sub">all-time · synced from each platform</span></div></div>
                <div class="dash__table-wrap">
                <table class="dash__table dash__table--compact">
                    <thead><tr><th>Platform</th><th>Posts</th><th>Views</th><th>Likes</th><th>Comments</th><th>Shares</th></tr></thead>
                    <tbody>
                    <?php foreach ($ss['metrics'] as $pl => $m): ?>
                    <tr>
                        <td><span class="dash__break-label"><i class="<?php echo AnalyticsModel::platform_icon($pl); ?>"></i> <?php echo htmlspecialchars($plat_name($pl), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><span class="dash__num"><?php echo $fmt_num($m['posts']); ?></span></td>
                        <td><span class="dash__num"><?php echo $fmt_num($m['views']); ?></span></td>
                        <td><span class="dash__num"><?php echo $fmt_num($m['likes']); ?></span></td>
                        <td><span class="dash__num"><?php echo $fmt_num($m['comments']); ?></span></td>
                        <td><span class="dash__num"><?php echo $fmt_num($m['shares']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="dash__panel">
                <div class="dash__panel-head"><div><h2 class="dash__panel-title">Recent Shares</h2><span class="dash__panel-sub">social accounts</span></div></div>
                <?php if (empty($ss['recent'])): ?>
                <div class="dash__empty">No shares yet.</div>
                <?php else: ?>
                <div class="dash__list">
                    <?php foreach ($ss['recent'] as $r): $cap = trim((string) $r['caption']); ?>
                    <div class="dash__row dash__row--static">
                        <span class="dash__row-main">
                            <span class="dash__row-title"><?php echo $cap !== '' ? htmlspecialchars(mb_substr($cap, 0, 48), ENT_QUOTES, 'UTF-8') : 'Post removed'; ?></span>
                            <span class="dash__row-meta"><span class="dash__bucket dash__bucket--<?php echo $r['bucket']; ?>"><?php echo $bucket_label[$r['bucket']]; ?></span> <?php echo htmlspecialchars($fmt_when($r['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                        <span class="dash__plats"><?php echo $plat_icons($r['platforms']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ================= AUDIENCE TAB ================= -->
    <section class="dash__tab-panel" data-panel="audience">
        <?php $ref = (array) ($this->referred ?? array()); /* people who signed up from this creator's page (?ref=), all-time */ ?>
        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Referrals</h2><span class="dash__panel-sub">all-time · from your page</span></div></div>
            <div class="dash__stats">
                <div class="dash__stat">
                    <span class="dash__stat-label">Referred Signups</span>
                    <span class="dash__stat-value"><?php echo $fmt_num($ref['signups'] ?? 0); ?></span>
                    <span class="dash__stat-sub"><?php echo $fmt_num($ref['signups'] ?? 0); ?> signed up, <?php echo $fmt_num($ref['paid'] ?? 0); ?> on a paid plan</span>
                </div>
            </div>
        </div>

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
                    <div><h2 class="dash__panel-title">Follower Growth</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, "UTF-8"); ?></span></div>
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
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Link Clicks</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?></span></div><div class="dash__chart-total"><strong><?php echo $fmt_num($lc['total']); ?></strong> clicks</div></div>
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
                        <span class="dash__heat-cell" <?php echo $n > 0 ? 'style="background:rgba(255, 106, 19,' . $a . ')"' : ''; ?> title="<?php echo $dname . ' ' . $hhour($h) . ' — ' . $n . ' view' . ($n === 1 ? '' : 's'); ?>"></span>
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
            <div class="dash__panel-head"><h2 class="dash__panel-title">Top Content</h2><span class="dash__panel-sub">by revenue</span></div>
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
            <div class="dash__panel-head"><h2 class="dash__panel-title">Content Mix</h2><span class="dash__panel-sub">by audience type</span></div>
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

<?php endif; ?>
</div>
<script src="/js/dashboard.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/dashboard.js'); ?>"></script>
