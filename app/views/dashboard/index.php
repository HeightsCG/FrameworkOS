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
    $s = $this->stats;
    $kpis = array(
        array('label' => 'Earnings',    'value' => $fmt_money($s['ppv_cents']),        'sub' => 'from pay-per-view',                          'icon' => 'fa-coins'),
        array('label' => 'Subscribers', 'value' => $fmt_num($s['subscribers']),        'sub' => $fmt_money($s['mrr_cents']) . '/mo recurring', 'icon' => 'fa-heart'),
        array('label' => 'Followers',   'value' => $fmt_num($s['followers']),          'sub' => 'total following you',                        'icon' => 'fa-user-plus'),
        array('label' => 'Views',       'value' => $fmt_num($s['views']),              'sub' => $fmt_num($s['unlocks']) . ' PPV unlocks',     'icon' => 'fa-eye'),
    );
?>
    <div class="dash__kpis">
        <?php foreach ($kpis as $k): ?>
        <div class="kpi">
            <div class="kpi__top"><span class="kpi__label"><?php echo $k['label']; ?></span><span class="kpi__icon"><i class="fa-solid <?php echo $k['icon']; ?>"></i></span></div>
            <div class="kpi__value"><?php echo $k['value']; ?></div>
            <div class="kpi__sub"><?php echo htmlspecialchars($k['sub'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php
    // ---- Views trend (last 30 days) → one bar per day; empty days show a baseline tick ----
    $series  = $this->views_series;
    $vals    = array_map(function ($p) { return (int) $p['value']; }, $series);
    $maxv    = max(1, max($vals));
    $total30 = array_sum($vals);
    $n       = count($series);
    ?>
    <div class="dash__panel dash__chart">
        <div class="dash__panel-head">
            <div>
                <h2 class="dash__panel-title">Views</h2>
                <span class="dash__panel-sub">Last 30 days</span>
            </div>
            <div class="dash__chart-total"><strong><?php echo $fmt_num($total30); ?></strong> views</div>
        </div>
        <div class="dash__bars" role="img" aria-label="Daily views over the last 30 days">
            <?php foreach ($series as $p): $v = (int) $p['value']; $h = $v > 0 ? max(8, (int) round($v / $maxv * 100)) : 0; ?>
            <div class="dash__col" title="<?php echo htmlspecialchars(date('D, M j', strtotime((string) $p['date'])) . ' — ' . $v . ' view' . ($v === 1 ? '' : 's'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="dash__col-fill<?php echo $v > 0 ? ' is-on' : ''; ?>" style="height:<?php echo $h; ?>%"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="dash__chart-axis">
            <?php $step = max(1, (int) floor(($n - 1) / 5)); for ($i = 0; $i < $n; $i += $step): ?>
            <span><?php echo date('M j', strtotime((string) $series[$i]['date'])); ?></span>
            <?php endfor; ?>
            <span><?php echo date('M j', strtotime((string) $series[$n - 1]['date'])); ?></span>
        </div>
    </div>

    <div class="dash__cols">
        <!-- Top posts -->
        <div class="dash__panel">
            <div class="dash__panel-head"><h2 class="dash__panel-title">Top posts</h2><span class="dash__panel-sub">by views</span></div>
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

        <!-- Recent earnings -->
        <div class="dash__panel">
            <div class="dash__panel-head"><h2 class="dash__panel-title">Recent unlocks</h2><span class="dash__panel-sub">pay-per-view</span></div>
            <?php if (empty($this->recent_unlocks)): ?>
                <div class="dash__empty">No pay-per-view unlocks yet.</div>
            <?php else: ?>
            <div class="dash__list">
                <?php foreach ($this->recent_unlocks as $u): $cap = trim((string) $u['caption']); ?>
                <div class="dash__row dash__row--static">
                    <span class="dash__row-main">
                        <span class="dash__row-title"><?php echo $cap !== '' ? htmlspecialchars(mb_substr($cap, 0, 48), ENT_QUOTES, 'UTF-8') : 'Untitled post'; ?></span>
                        <span class="dash__row-meta"><?php echo htmlspecialchars($fmt_when((string) $u['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span class="dash__amount">+<?php echo $fmt_num($u['price_credits']); ?> cr</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

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
