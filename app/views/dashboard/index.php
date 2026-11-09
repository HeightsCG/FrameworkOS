<link rel="stylesheet" href="/css/dashboard.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/dashboard.css'); ?>">
<?php
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
<?php if ((string) ($this->verify_email ?? '') !== ''): $ve = htmlspecialchars((string) $this->verify_email, ENT_QUOTES, 'UTF-8'); ?>
    <div class="plan-bar verify-bar" role="status" id="verify_bar">
        <?php if (!empty($this->verify_bounced)): ?>
        <p class="plan-bar__text"><i class="fa-solid fa-envelope verify-bar__icon" aria-hidden="true"></i><strong>Confirm your email.</strong> We couldn't deliver email to <?php echo $ve; ?>. </p>
        <a href="/account/settings?section=account" class="btn btn-secondary plan-bar__btn">Change Email</a>
        <?php else: ?>
        <p class="plan-bar__text"><i class="fa-solid fa-envelope verify-bar__icon" aria-hidden="true"></i><strong>Confirm your email.</strong> We sent a link to <?php echo $ve; ?>. </p>
        <button type="button" class="btn btn-secondary plan-bar__btn" id="verify_resend">Resend Email</button>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (!empty($this->needs_plan)): ?>
    <div class="plan-bar" role="status">
        <p class="plan-bar__text"><strong>You're on Free.</strong> Your content and history are still here. Choose a plan to create and publish.</p>
        <a href="/account/billing" class="btn btn-primary plan-bar__btn">Choose a Plan</a>
    </div>
<?php endif; ?>

<?php if (empty($this->is_creator)): ?>
    <div class="dash__gate">
        <i class="fa-solid fa-chart-line dash__gate-icon"></i>
        <h2 class="dash__gate-title">Your creator dashboard lives here</h2>
        <p class="dash__gate-text">Turn on your creator account to publish content, grow your audience and track how it's performing.</p>
        <a href="/account/settings?section=creator" class="btn btn-primary">Become a Creator</a>
    </div>
<?php else:
    $s   = $this->stats;
    $range = (int) ($this->range ?? 30);
    $range_label = 'Last ' . $range . ' days';

    $follower_series = $this->follower_series;

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

    $n_of = function ($n, $one, $many) use (&$fmt_num) { return $fmt_num($n) . ' ' . ((int) $n === 1 ? $one : $many); };
    // Shorten a caption at a word boundary with an ellipsis (never mid-word).
    $clip = function ($t, $n) {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $t));
        if (mb_strlen($t) <= $n) { return $t; }
        $cut = mb_substr($t, 0, $n);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > $n * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-") . '…';
    };
    $kpis = array(
        array('label' => 'Followers',      'value' => $fmt_num($cur['followers']),        'delta' => $dl['followers'],     'sub' => $fmt_num($s['followers']) . ' total',                             'icon' => 'fa-user-plus'),
        array('label' => 'Views',          'value' => $fmt_num($cur['views']),            'delta' => $dl['views'],         'sub' => $fmt_num($s['views']) . ' all-time',                                        'icon' => 'fa-eye'),
        array('label' => 'Posts',          'value' => $fmt_num($cur['posts']),            'delta' => $dl['posts'],         'sub' => $fmt_num($s['published_posts']) . ' published all-time',                    'icon' => 'fa-photo-film'),
        array('label' => 'Engagement',     'value' => $cur['engagement'] . '%',           'delta' => $dl['engagement'],    'sub' => $n_of($cur['likes'], 'like', 'likes') . ' · ' . $n_of($cur['comments'], 'comment', 'comments'),  'icon' => 'fa-comment-dots'),
    );

    // Shared daily-bar renderer for the trend charts.
    $render_bars = function ($series) use ($fmt_num) {
        $n    = count($series);
        $vals = array_map(function ($p) { return (int) $p['value']; }, $series);
        $maxv = max(1, max($vals));
        echo '<div class="dash__bars" role="img" aria-label="Daily trend over the last ' . (int) $n . ' days">';
        foreach ($series as $p) {
            $v = (int) $p['value'];
            $h = $v > 0 ? max(8, (int) round($v / $maxv * 100)) : 0;
            $lbl = $fmt_num($v);
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
        <button type="button" class="dash__tab is-active" data-panel="content" role="tab"><i class="fa-solid fa-photo-film"></i> Content</button>
        <button type="button" class="dash__tab" data-panel="audience" role="tab"><i class="fa-solid fa-users"></i> Audience</button>
    </div>

    <?php
    // ================= CONTENT TAB =================
    $pt  = $this->posts_table;
    $ss  = $this->share_stats;
    $avg_views  = (int) $cur['posts'] > 0 ? (int) round((int) $cur['views'] / (int) $cur['posts']) : 0;
    $share_rate = (int) $cur['posts'] > 0 ? (int) round(min((int) $ss['posts_shared'], (int) $cur['posts']) / (int) $cur['posts'] * 100) : 0;
    $content_stats = array(
        array('Views per post',  $fmt_num($avg_views),                                          'avg, posts published in period'),
        array('Engagement rate', $cur['engagement'] . '%',                                       'likes + comments per view'),
        array('Shared',          $share_rate . '%',                                              $fmt_num($ss['posts_shared']) . ' of ' . $fmt_num($cur['posts']) . ' posts cross-posted'),
    );
    $plat_icons = function (array $plats) {
        $h = '';
        foreach ($plats as $pl) { $h .= '<i class="' . AnalyticsModel::platform_icon($pl) . '" title="' . htmlspecialchars($pl === 'x' ? 'X' : ($pl === 'tiktok' ? 'TikTok' : ucfirst($pl)), ENT_QUOTES, 'UTF-8') . '"></i>'; }
        return $h;
    };
    $bucket_label = array('delivered' => 'Delivered', 'pending' => 'Pending', 'failed' => 'Failed');
    $plat_name = function ($pl) { $n = array('x' => 'X', 'tiktok' => 'TikTok', 'tiktok_business' => 'TikTok', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn'); return $n[$pl] ?? ucfirst((string) $pl); };
    ?>
    <section class="dash__tab-panel is-active" data-panel="content">
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
            <div class="dash__empty">No published posts yet. Publish from the Content Studio and every post shows up here with its views, engagement, and where it was shared.</div>
            <?php else: ?>
            <div class="dash__table-wrap">
            <table class="dash__table" id="dashPosts">
                <thead>
                    <tr>
                        <th data-sort="text" class="dash__th--post">Post</th>
                        <th data-sort="num">Views</th>
                        <th data-sort="num">Engagement</th>
                        <th data-sort="num" class="dash__th--social">Social</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pt as $p):
                    $cap  = trim((string) $p['caption']);
                    $when = $p['state'] === 'scheduled' ? 'Scheduled ' . $fmt_when((string) $p['scheduled_at']) : $fmt_when((string) $p['published_at']);
                    $eng  = $p['engagement'];
                ?>
                    <tr>
                        <td data-v="<?php echo htmlspecialchars(mb_strtolower($cap), ENT_QUOTES, 'UTF-8'); ?>">
                            <a class="dash__cell-post" href="/studio">
                                <span class="dash__row-title"><?php echo $cap !== '' ? htmlspecialchars($clip($cap, 56), ENT_QUOTES, 'UTF-8') : 'Untitled post'; ?></span>
                                <span class="dash__row-meta"><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </td>
                        <td data-v="<?php echo (int) $p['views_period']; ?>"><span class="dash__num"><?php echo $fmt_num($p['views_period']); ?></span><span class="dash__num-sub"><?php echo $fmt_num($p['views']); ?> all-time</span></td>
                        <td data-v="<?php echo $eng === null ? -1 : $eng; ?>">
                            <span class="dash__num<?php echo $eng === null ? ' dash__num--na' : ''; ?>"><?php echo $eng === null ? '—' : $eng . '%'; ?></span>
                            <?php if ((int) $p['likes'] > 0 || (int) $p['comments'] > 0): ?><span class="dash__num-sub"><?php echo $n_of($p['likes'], 'like', 'likes'); ?> · <?php echo $n_of($p['comments'], 'comment', 'comments'); ?></span><?php endif; ?>
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
                            <span class="dash__row-title"><?php echo $cap !== '' ? htmlspecialchars($clip($cap, 48), ENT_QUOTES, 'UTF-8') : 'Post removed'; ?></span>
                            <span class="dash__row-meta"><span class="dash__bucket dash__bucket--<?php echo $r['bucket']; ?>"><?php echo $bucket_label[$r['bucket']]; ?></span> <?php echo htmlspecialchars($fmt_when($r['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                        <span class="dash__plats"><?php echo $plat_icons($r['platforms']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash__panel">
            <div class="dash__panel-head"><h2 class="dash__panel-title">Top Content</h2><span class="dash__panel-sub">by views</span></div>
            <?php if (empty($this->top_posts)): ?>
                <div class="dash__empty">No published posts yet.</div>
            <?php else: ?>
            <div class="dash__list">
                <?php foreach ($this->top_posts as $p): $cap = trim((string) $p['caption']); ?>
                <a class="dash__row" href="/studio">
                    <span class="dash__row-main">
                        <span class="dash__row-title"><?php echo $cap !== '' ? htmlspecialchars($clip($cap, 60), ENT_QUOTES, 'UTF-8') : 'Untitled post'; ?></span>
                        <span class="dash__row-meta"><i class="fa-regular fa-eye"></i> <?php echo $fmt_num($p['views']); ?> · <i class="fa-regular fa-heart"></i> <?php echo $fmt_num($p['likes']); ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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
                <div class="dash__stat">
                    <span class="dash__stat-label">AI Credits Earned</span>
                    <span class="dash__stat-value"><?php echo $fmt_num($ref['credits'] ?? 0); ?></span>
                    <span class="dash__stat-sub"><?php echo (int) ReferralRewards::CREDITS; ?> for each signup that starts a paid plan</span>
                </div>
            </div>
        </div>

        <div class="dash__cols">
            <div class="dash__panel dash__chart">
                <div class="dash__panel-head">
                    <div><h2 class="dash__panel-title">Views</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, "UTF-8"); ?></span></div>
                    <div class="dash__chart-total"><strong><?php echo $fmt_num(array_sum(array_map(function ($p) { return (int) $p['value']; }, $this->views_series))); ?></strong> views</div>
                </div>
                <?php $render_bars($this->views_series); ?>
            </div>
            <div class="dash__panel dash__chart">
                <div class="dash__panel-head">
                    <div><h2 class="dash__panel-title">Follower Growth</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, "UTF-8"); ?></span></div>
                    <div class="dash__chart-total"><strong><?php echo ((int) $cur['followers'] > 0 ? '+' : '') . $fmt_num($cur['followers']); ?></strong> new</div>
                </div>
                <?php $render_bars($follower_series); ?>
            </div>
        </div>

        <?php
        // ---- Traffic & conversion ----
        $pv    = $this->profile_views;
        $uniqv = (int) $pv['unique'];
        $follow_conv = $uniqv > 0 ? round((int) $cur['followers'] / $uniqv * 100, 1) : 0;
        $traffic_stats = array(
            array('Profile views',   $fmt_num($pv['views']),  'total visits'),
            array('Unique visitors',  $fmt_num($uniqv),        'distinct people'),
            array('Follow rate',      ($uniqv > 0 ? $follow_conv . '%' : '—'), 'visitors who followed'),
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

        <?php $tr = is_array($this->traffic) ? $this->traffic : null; $tr_now = (array) ($tr['overview']['now'] ?? array()); $tr_before = (array) ($tr['overview']['before'] ?? array());
              $tr_delta = function ($a, $b) { $a = (int) $a; $b = (int) $b; if ($b === 0) { return $a > 0 ? 'new' : 'no change'; } $p = round(($a - $b) * 100 / $b); return ($p > 0 ? '+' : '') . $p . '% vs previous'; }; ?>
        <?php if ($tr !== null): ?>
        <div class="dash__panel">
            <div class="dash__panel-head"><div><h2 class="dash__panel-title">Where Visitors Come From</h2><span class="dash__panel-sub">last <?php echo (int) $this->range; ?> days (UTC) · your page and posts, measured by Creator Link Studio</span></div></div>
            <div class="dash__stats">
                <?php foreach (array(array('Page views', $tr_now['pageviews'] ?? 0, $tr_before['pageviews'] ?? 0), array('Visitors', $tr_now['visitors'] ?? 0, $tr_before['visitors'] ?? 0), array('Sessions', $tr_now['sessions'] ?? 0, $tr_before['sessions'] ?? 0), array('First visits', $tr_now['new_visitors'] ?? 0, $tr_before['new_visitors'] ?? 0)) as $st): ?>
                <div class="dash__stat">
                    <span class="dash__stat-label"><?php echo htmlspecialchars($st[0], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="dash__stat-value"><?php echo $fmt_num($st[1]); ?></span>
                    <span class="dash__stat-sub"><?php echo htmlspecialchars($tr_delta($st[1], $st[2]), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="dash__cols dash__cols--below">
                <div>
                    <div class="dash__panel-head"><div><h3 class="dash__panel-title">Top Sources</h3><span class="dash__panel-sub">where sessions start</span></div></div>
                    <?php if (empty($tr['sources'])): ?><div class="dash__empty">No visits in this period.</div><?php else: ?>
                    <div class="dash__list">
                        <?php foreach ($tr['sources'] as $s): ?>
                        <div class="dash__row dash__row--static">
                            <span class="dash__row-main"><span class="dash__row-title"><?php echo htmlspecialchars($s['source'] === 'direct' ? 'Direct' : $s['source'], ENT_QUOTES, 'UTF-8'); ?></span><span class="dash__row-meta"><?php echo htmlspecialchars($s['medium'] !== '' ? $s['medium'] : 'typed or bookmarked', ENT_QUOTES, 'UTF-8'); ?></span></span>
                            <span class="dash__amount"><?php echo $fmt_num($s['sessions']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="dash__panel-head"><div><h3 class="dash__panel-title">Top Pages</h3><span class="dash__panel-sub">views on your page</span></div></div>
                    <?php if (empty($tr['pages'])): ?><div class="dash__empty">No page views in this period.</div><?php else: ?>
                    <div class="dash__list">
                        <?php foreach ($tr['pages'] as $pg): ?>
                        <div class="dash__row dash__row--static">
                            <span class="dash__row-main"><span class="dash__row-title"><?php echo htmlspecialchars(mb_substr((string) $pg['path'], 0, 60), ENT_QUOTES, 'UTF-8'); ?></span><span class="dash__row-meta"><?php echo $fmt_num($pg['visitors']); ?> visitors</span></span>
                            <span class="dash__amount"><?php echo $fmt_num($pg['views']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                        <span class="dash__row-title"><?php echo htmlspecialchars($clip($l['title'], 50), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dash__row-meta"><?php echo htmlspecialchars(preg_replace('#^https?://(www\.)?#i', '', (string) $l['url']), ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span class="dash__amount"><?php echo $fmt_num($l['clicks']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php $tl = (array) ($this->tracking_links ?? array()); $tl_base = rtrim(Main::get_base_domain(), '/'); ?>
        <div class="dash__panel" id="dashTracking">
            <div class="dash__panel-head">
                <div><h2 class="dash__panel-title">Tracking Links</h2><span class="dash__panel-sub"><?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <button type="button" class="btn btn-secondary dash-tl__new" id="dashTlNew"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Tracking Link</button>
            </div>
            <?php if (empty($tl)): ?>
            <div class="dash__empty">No tracking links yet. Put one in a bio, post or ad to see the clicks, follows and signups it brings in.</div>
            <?php else: ?>
            <div class="dash__table-wrap">
            <table class="dash__table dash__table--compact dash-tl">
                <thead>
                    <tr>
                        <th>Link</th>
                        <th>Clicks</th>
                        <th>Follows</th>
                        <th>Signups</th>
                        <th class="dash-tl__act" aria-label="Actions"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tl as $t):
                    $t_sys = strpos((string) $t['code'], 'cls') === 0;
                    $t_url = $tl_base . '/go/' . $t['code'];
                ?>
                    <tr>
                        <td>
                            <span class="dash__row-title"><?php echo htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="dash__row-meta"><?php echo $t_sys ? 'Clicks from other creators&#039; pages' : htmlspecialchars(preg_replace('#^https?://#i', '', $t_url) . ((string) $t['target_path'] !== '' ? ' to ' . $t['target_path'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td data-label="Clicks"><?php echo $fmt_num($t['clicks']); ?></td>
                        <td data-label="Follows"><?php echo $fmt_num($t['follows']); ?></td>
                        <td data-label="Signups"><?php echo $fmt_num($t['signups']); ?></td>
                        <td class="dash-tl__act">
                            <div class="dropdown"><button type="button" class="dash-more" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Tracking link actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item" data-tl-copy="<?php echo htmlspecialchars($t_url, ENT_QUOTES, 'UTF-8'); ?>">Copy Link</button></li>
                                    <?php if (!$t_sys): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button type="button" class="dropdown-item text-danger" data-tl-delete="<?php echo (int) $t['id']; ?>">Delete</button></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
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

<?php endif; ?>
</div>
<div class="modal fade" id="dashTlModal" tabindex="-1" aria-labelledby="dashTlModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dashTlModalTitle">New tracking link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="dashTlLabel" maxlength="120" placeholder="Instagram bio">
                    <label for="dashTlLabel">Label</label>
                </div>
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="dashTlCode" maxlength="32" placeholder="summer25">
                    <label for="dashTlCode">Code (Optional)</label>
                </div>
                <div class="form-floating">
                    <input type="text" class="form-control" id="dashTlPath" maxlength="200" placeholder="/links">
                    <label for="dashTlPath">Page Path (Optional)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="dashTlSave">Create Link</button>
            </div>
        </div>
    </div>
</div>
<script src="/js/dashboard.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/dashboard.js'); ?>"></script>
<script>
/* Tracking Links (Audience tab): create, copy, delete. */
$(function () {
    var $modal = $('#dashTlModal');
    $('#dashTlNew').on('click', function () {
        $('#dashTlLabel, #dashTlCode, #dashTlPath').val('');
        $modal.modal('show');
    });
    $modal.on('shown.bs.modal', function () { $('#dashTlLabel').trigger('focus'); });
    $('#dashTlSave').on('click', function () {
        var label = ($('#dashTlLabel').val() || '').trim();
        if (label == '') { toastr.error('A label is required'); $('#dashTlLabel').focus(); return; }
        var $b = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'tracking_link_save', { label: label, code: ($('#dashTlCode').val() || '').trim(), target_path: ($('#dashTlPath').val() || '').trim() }, function (data) {
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { $b.prop('disabled', false); toastr.error((o && o.message) || 'Could not create the link'); return; }
            toastr.success(o.message);
            window.location.reload();   // the Audience tab is kept in the hash
        });
    });
    $(document).on('click', '[data-tl-copy]', function () {
        var url = $(this).attr('data-tl-copy');
        if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { toastr.success('Link copied'); }, function () { toastr.error('Could not copy the link'); }); }
        else { toastr.info(url); }
    });
    $(document).on('click', '[data-tl-delete]', function () {
        var id = $(this).attr('data-tl-delete');
        Swal.fire({ title: 'Delete this tracking link?', text: 'The link stops working and its numbers leave this table.', icon: 'warning', showCancelButton: true, reverseButtons: true,
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel', customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false }).then(function (r) {
            if (!r.isConfirmed) { return; }
            ApiDataSvc.apiCall('post', 'tracking_link_delete', { id: id }, function (data) {
                var o = null; try { o = JSON.parse(data); } catch (e) {}
                if (!o || !o.success) { toastr.error((o && o.message) || 'Could not delete the link'); return; }
                toastr.success(o.message);
                window.location.reload();
            });
        });
    });
});
</script>
<?php if ((string) ($this->verify_email ?? '') !== ''): ?>
<script>
$(document).on('click', '#verify_resend', function () {
    var $b = $(this).prop('disabled', true).text('Sending…');
    ApiDataSvc.apiCall('post', 'resend_my_verification', {}, function (data) {
        var o = null; try { o = JSON.parse(data); } catch (e) {}
        if (o && o.success && o.verified) { $('#verify_bar').remove(); toastr.success(o.message); return; }
        if (o && o.success) {
            toastr.success(o.message);
            $b.text('Email Sent');
            setTimeout(function () { $b.text('Resend Email').prop('disabled', false); }, 30000);
            return;
        }
        $b.text('Resend Email').prop('disabled', false);
        toastr.error(o ? o.message : 'Something went wrong.');
    });
});
</script>
<?php endif; ?>
