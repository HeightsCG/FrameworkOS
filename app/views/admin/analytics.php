<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/analytics — first-party analytics (analytics_events). Headline cards on every view; ?show= picks Traffic, Pages,
   Sources, Conversions, Product or Creators; ?days= the UTC window (7 / 30 / 90). Data: AdminController::analyticsAction. */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$an = (array) $this->an; $show = (string) $this->show; $days = (int) $this->days;
$now = (array) ($an['overview']['now'] ?? array()); $before = (array) ($an['overview']['before'] ?? array()); $daily = (array) ($an['daily'] ?? array());
$col = function ($rows, $k) { return array_map(function ($r) use ($k) { return (float) ($r[$k] ?? 0); }, $rows); };
$n = function ($v) { return number_format((int) $v); };
$usd = function ($c) { return '$' . number_format(((int) $c) / 100, 2); };
$pct = function ($a, $of) { return (int) $of > 0 ? round((int) $a * 100 / (int) $of, 1) . '%' : '—'; };
$url = function ($s, $d) { return '/admin/analytics?show=' . rawurlencode($s) . '&days=' . (int) $d; };
$titles = array('traffic' => 'Traffic', 'pages' => 'Pages', 'sources' => 'Sources', 'conversions' => 'Conversions', 'product' => 'Product', 'creators' => 'Creators');
$subs = array('traffic' => 'Visitors, sessions and page views recorded by this site, by day, device, browser and country',
              'pages' => 'Every page by views, visitors and entrances', 'sources' => 'Where sessions start: UTM source and medium, referrers, campaigns, landing pages',
              'conversions' => 'Sessions to signups to paid plans, by day and by source', 'product' => 'What accounts do: signups, sales, memberships, posts, AI generations, messages, cash-outs',
              'creators' => 'Creator pages by traffic and what that traffic bought');
$range = gmdate('M j', time() - ($days - 1) * 86400) . ' to ' . gmdate('M j, Y') . ' (UTC)';
?>
<header class="adm-head">
    <div><h1 class="adm-head__title"><?php echo $e($titles[$show] ?? 'Analytics'); ?></h1><p class="adm-head__sub"><?php echo $e($subs[$show] ?? ''); ?></p></div>
    <div class="adm-head__acts">
        <div class="adm-seg" role="tablist" aria-label="Period">
            <?php foreach (AdminController::ANALYTICS_DAYS as $d): ?><a class="adm-seg__b<?php echo $d === $days ? ' is-on' : ''; ?>" role="tab" aria-selected="<?php echo $d === $days ? 'true' : 'false'; ?>" href="<?php echo $e($url($show, $d)); ?>"><?php echo (int) $d; ?> days</a><?php endforeach; ?>
        </div>
        <span class="adm-head__note"><?php echo $e($range); ?></span>
    </div>
</header>

<?php if (!empty($this->an_error)): ?>
    <?php echo adm_empty('Analytics unavailable: run sql/2026-10-11_analytics.sql', 'fa-chart-line'); ?>
<?php else: ?>
<div class="adm-kpis adm-kpis--5">
    <?php echo adm_kpi('Visitors', $n($now['visitors'] ?? 0), adm_delta((int) ($now['visitors'] ?? 0), (int) ($before['visitors'] ?? 0)), $col($daily, 'visitors'), 'vs previous ' . $days . ' days'); ?>
    <?php echo adm_kpi('Sessions', $n($now['sessions'] ?? 0), adm_delta((int) ($now['sessions'] ?? 0), (int) ($before['sessions'] ?? 0)), $col($daily, 'sessions'), $n($now['new_visitors'] ?? 0) . ' first visits'); ?>
    <?php echo adm_kpi('Page views', $n($now['pageviews'] ?? 0), adm_delta((int) ($now['pageviews'] ?? 0), (int) ($before['pageviews'] ?? 0)), $col($daily, 'pageviews'), ((int) ($now['sessions'] ?? 0) > 0 ? round((int) $now['pageviews'] / (int) $now['sessions'], 1) : '0') . ' per session'); ?>
    <?php echo adm_kpi('Signups', $n($now['signups'] ?? 0), adm_delta((int) ($now['signups'] ?? 0), (int) ($before['signups'] ?? 0)), $col($daily, 'signups'), $pct($now['signups'] ?? 0, $now['sessions'] ?? 0) . ' of sessions'); ?>
    <?php echo adm_kpi('Paid plans', $n($now['plans_paid'] ?? 0), adm_delta((int) ($now['plans_paid'] ?? 0), (int) ($before['plans_paid'] ?? 0)), $col($daily, 'plans_paid'), $n($now['sales'] ?? 0) . ' sales · ' . $usd($now['value_cents'] ?? 0)); ?>
</div>

<?php if ($show === 'traffic'): ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">By day</h2><span class="adm-box__note"><?php echo $e($range); ?></span></header>
    <table class="adm-t adm-t--num" data-sortable>
        <thead><tr><th data-sort="text" data-sorted="desc">Day</th><th class="adm-r" data-sort="num">Visitors</th><th class="adm-r" data-sort="num">Sessions</th><th class="adm-r" data-sort="num">Page views</th><th class="adm-r" data-sort="num">Signups</th><th class="adm-r" data-sort="num">Trials</th><th class="adm-r" data-sort="num">Paid plans</th><th class="adm-r" data-sort="num">Sales</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($daily) as $r): ?>
            <tr><td class="adm-t__nowrap"><?php echo $e(gmdate('D, M j', strtotime($r['day'] . ' 00:00:00 UTC'))); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($r['visitors']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['sessions']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['pageviews']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['signups']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['trials'] ?? 0); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['plans_paid']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['sales']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<div class="adm-cols adm-cols--2">
    <?php foreach (array('device' => 'Devices', 'browser' => 'Browsers', 'os' => 'Operating systems', 'country' => 'Countries') as $k => $label): $rows = (array) ($an[$k] ?? array()); ?>
    <section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t"><?php echo $e($label); ?></h2></header>
        <?php if (empty($rows)): ?><?php echo adm_empty($k === 'country' ? 'No country data: the edge does not send a country header' : 'No page views yet', 'fa-chart-pie'); ?>
        <?php else: ?>
        <table class="adm-t adm-t--num"><thead><tr><th><?php echo $e($label); ?></th><th class="adm-r">Sessions</th><th class="adm-r">Share</th></tr></thead><tbody>
            <?php $tot = array_sum(array_map(function ($r) { return (int) $r['sessions']; }, $rows)); foreach ($rows as $r): ?>
            <tr><td><?php echo $e($r['label'] !== '' ? $r['label'] : 'Unknown'); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['sessions']); ?></td><td class="adm-r adm-t__num adm-t__muted"><?php echo $pct($r['sessions'], $tot); ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<?php elseif ($show === 'pages'): $rows = (array) ($an['pages'] ?? array()); ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Pages <b class="adm-count"><?php echo count($rows); ?></b></h2>
    <form class="adm-search" role="search" onsubmit="return false"><input type="search" class="adm-search__in" placeholder="Filter by path" data-search-for="admAnPages"></form></header>
    <?php if (empty($rows)): ?><?php echo adm_empty('No page views in this period', 'fa-file'); ?><?php else: ?>
    <table class="adm-t adm-t--num" id="admAnPages" data-sortable data-pager>
        <thead><tr><th data-sort="text">Path</th><th class="adm-r" data-sort="num" data-sorted="desc">Views</th><th class="adm-r" data-sort="num">Visitors</th><th class="adm-r" data-sort="num">Entrances</th><th class="adm-r" data-sort="num">Errors</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr data-search="<?php echo $e(mb_strtolower($r['path'])); ?>"><td class="adm-t__main adm-t__trunc"><a href="<?php echo $e($r['path']); ?>" target="_blank" rel="noopener"><?php echo $e($r['path']); ?></a></td><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($r['views']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['visitors']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['entrances']); ?></td><td class="adm-r adm-t__num<?php echo (int) $r['errors'] > 0 ? ' adm-neg' : ' adm-t__muted'; ?>"><?php echo $n($r['errors']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php elseif ($show === 'sources'): ?>
<?php $blocks = array(
    array('Sources', 'sources', array('source' => 'Source', 'medium' => 'Medium'), 'No sessions in this period'),
    array('Referrers', 'referrers', array('host' => 'Site'), 'No external referrers in this period'),
    array('Campaigns', 'campaigns', array('campaign' => 'Campaign', 'source' => 'Source', 'medium' => 'Medium'), 'No UTM campaigns in this period'),
    array('Landing pages', 'landing', array('path' => 'Path'), 'No sessions in this period'),
); ?>
<?php foreach ($blocks as $b): $rows = (array) ($an[$b[1]] ?? array()); $has_conv = isset($rows[0]['signups']); ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t"><?php echo $e($b[0]); ?> <b class="adm-count"><?php echo count($rows); ?></b></h2></header>
    <?php if (empty($rows)): ?><?php echo adm_empty($b[3], 'fa-diagram-project'); ?><?php else: ?>
    <table class="adm-t adm-t--num" data-sortable data-pager>
        <thead><tr><?php foreach ($b[2] as $k => $l): ?><th data-sort="text"><?php echo $e($l); ?></th><?php endforeach; ?><th class="adm-r" data-sort="num" data-sorted="desc">Sessions</th><?php if (isset($rows[0]['visitors'])): ?><th class="adm-r" data-sort="num">Visitors</th><?php endif; ?><?php if ($has_conv): ?><th class="adm-r" data-sort="num">Signups</th><th class="adm-r" data-sort="num">Signup rate</th><th class="adm-r" data-sort="num">Paid plans</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($b[2] as $k => $l): $v = (string) ($r[$k] ?? ''); ?><td class="<?php echo $k === array_key_first($b[2]) ? 'adm-t__main adm-t__trunc' : 'adm-t__muted'; ?>"><?php echo $e($v === 'direct' ? 'Direct' : ($v === '' ? '—' : $v)); ?></td><?php endforeach; ?><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($r['sessions']); ?></td><?php if (isset($r['visitors'])): ?><td class="adm-r adm-t__num"><?php echo $n($r['visitors']); ?></td><?php endif; ?><?php if ($has_conv): ?><td class="adm-r adm-t__num"><?php echo $n($r['signups']); ?></td><td class="adm-r adm-t__num adm-t__muted"><?php echo $pct($r['signups'], $r['sessions']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['plans_paid']); ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<?php elseif ($show === 'conversions'): $ev = (array) ($an['events'] ?? array()); $s = (int) ($now['sessions'] ?? 0); $su = (int) ($now['signups'] ?? 0); $ve = (int) ($ev['email_verified']['now']['n'] ?? 0); $pp = (int) ($now['plans_paid'] ?? 0); ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Funnel</h2><span class="adm-box__note"><?php echo $e($range); ?></span></header>
    <table class="adm-t adm-t--num">
        <thead><tr><th>Step</th><th class="adm-r">Count</th><th class="adm-r">Of sessions</th><th class="adm-r">Of previous step</th></tr></thead>
        <tbody>
            <?php $steps = array(array('Sessions', $s, $s), array('Signups', $su, $s), array('Email verified', $ve, $su), array('Paid plan', $pp, $ve)); foreach ($steps as $st): ?>
            <tr><td class="adm-t__main"><?php echo $e($st[0]); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($st[1]); ?></td><td class="adm-r adm-t__num adm-t__muted"><?php echo $pct($st[1], $s); ?></td><td class="adm-r adm-t__num adm-t__muted"><?php echo $pct($st[1], $st[2]); ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">By day</h2></header>
    <table class="adm-t adm-t--num" data-sortable>
        <thead><tr><th data-sort="text" data-sorted="desc">Day</th><th class="adm-r" data-sort="num">Sessions</th><th class="adm-r" data-sort="num">Signups</th><th class="adm-r" data-sort="num">Signup rate</th><th class="adm-r" data-sort="num">Trials</th><th class="adm-r" data-sort="num">Paid plans</th><th class="adm-r" data-sort="num">Sales</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($daily) as $r): ?>
            <tr><td class="adm-t__nowrap"><?php echo $e(gmdate('D, M j', strtotime($r['day'] . ' 00:00:00 UTC'))); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['sessions']); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($r['signups']); ?></td><td class="adm-r adm-t__num adm-t__muted"><?php echo $pct($r['signups'], $r['sessions']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['trials'] ?? 0); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['plans_paid']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['sales']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php foreach (array(array('By source', 'sources', 'source', 'Source'), array('By landing page', 'landing', 'path', 'Path')) as $b): $rows = (array) ($an[$b[1]] ?? array()); ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t"><?php echo $e($b[0]); ?></h2></header>
    <?php if (empty($rows)): ?><?php echo adm_empty('No sessions in this period', 'fa-diagram-project'); ?><?php else: ?>
    <table class="adm-t adm-t--num" data-sortable data-pager>
        <thead><tr><th data-sort="text"><?php echo $e($b[3]); ?></th><th class="adm-r" data-sort="num" data-sorted="desc">Sessions</th><th class="adm-r" data-sort="num">Signups</th><th class="adm-r" data-sort="num">Signup rate</th><th class="adm-r" data-sort="num">Trials</th><th class="adm-r" data-sort="num">Paid plans</th><th class="adm-r" data-sort="num">Plan rate</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $v = (string) $r[$b[2]]; ?>
            <tr><td class="adm-t__main adm-t__trunc"><?php echo $e($v === 'direct' ? 'Direct' : $v); ?><?php if ($b[2] === 'source' && (string) ($r['medium'] ?? '') !== ''): ?> <span class="adm-t__faint">/ <?php echo $e($r['medium']); ?></span><?php endif; ?></td><td class="adm-r adm-t__num"><?php echo $n($r['sessions']); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($r['signups']); ?></td><td class="adm-r adm-t__num adm-t__muted"><?php echo $pct($r['signups'], $r['sessions']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['trials'] ?? 0); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['plans_paid']); ?></td><td class="adm-r adm-t__num adm-t__muted"><?php echo $pct($r['plans_paid'], $r['sessions']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<?php elseif ($show === 'product'): $ev = (array) ($an['events'] ?? array()); $evd = (array) ($an['events_daily'] ?? array()); ?>
<?php $labels = array('signup' => 'Signups', 'email_verified' => 'Emails verified', 'plan_paid' => 'Plan payments', 'trial_started' => 'Free trials started', 'credits_bought' => 'Credit purchases', 'purchase' => 'Wallet sales (unlocks, bundles, messages, services, tickets)',
    'membership_paid' => 'Paid memberships started', 'membership_free' => 'Free memberships joined', 'post_published' => 'Posts published', 'ai_generation' => 'AI generations', 'message_sent' => 'Messages sent', 'cashout' => 'Cash-outs requested', 'link_click' => 'Profile link clicks', 'tracking_click' => 'Tracking link clicks'); ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Events</h2><span class="adm-box__note"><?php echo $e($range); ?>, vs the previous <?php echo (int) $days; ?> days</span></header>
    <?php if (empty($ev)): ?><?php echo adm_empty('No product events in this period', 'fa-bolt'); ?><?php else: ?>
    <table class="adm-t adm-t--num" data-sortable>
        <thead><tr><th data-sort="text">Event</th><th class="adm-r" data-sort="num" data-sorted="desc">Count</th><th class="adm-r" data-sort="num">Accounts</th><th class="adm-r" data-sort="num">Value</th><th class="adm-r">vs previous</th><th class="adm-r">Trend</th></tr></thead>
        <tbody>
        <?php foreach ($ev as $name => $v): ?>
            <tr><td class="adm-t__main"><?php echo $e($labels[$name] ?? $name); ?> <span class="adm-t__faint"><?php echo $e($name); ?></span></td><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($v['now']['n']); ?></td><td class="adm-r adm-t__num"><?php echo $n($v['now']['accounts']); ?></td><td class="adm-r adm-t__num<?php echo (int) $v['now']['value_cents'] === 0 ? ' adm-t__muted' : ''; ?>"><?php echo (int) $v['now']['value_cents'] === 0 ? '—' : $usd($v['now']['value_cents']); ?></td><td class="adm-r"><?php echo adm_delta((int) $v['now']['n'], (int) $v['before']['n']); ?></td><td class="adm-r"><?php echo adm_spark(array_map('floatval', (array) ($evd[$name] ?? array())), 90, 22); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php $recent = (array) ($an['recent'] ?? array()); ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Latest events <b class="adm-count"><?php echo count($recent); ?></b></h2></header>
    <?php if (empty($recent)): ?><?php echo adm_empty('No events yet', 'fa-bolt'); ?><?php else: ?>
    <table class="adm-t" data-pager>
        <thead><tr><th>When</th><th>Event</th><th>Account</th><th>Creator</th><th class="adm-r">Value</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr><td class="adm-t__nowrap adm-t__muted"><?php echo $e(gmdate('M j, H:i', strtotime($r['ts'] . ' UTC'))); ?></td><td class="adm-t__main"><?php echo $e($labels[$r['name']] ?? $r['name']); ?></td><td><?php echo (int) $r['user_id'] > 0 ? '<a href="/admin/user/' . (int) $r['user_id'] . '">@' . $e($r['u_name'] ?: $r['user_id']) . '</a>' : '<span class="adm-t__muted">visitor</span>'; ?></td><td><?php echo (int) $r['creator_id'] > 0 ? '<a href="/admin/user/' . (int) $r['creator_id'] . '">@' . $e($r['creator_handle'] ?: $r['creator_id']) . '</a>' : '<span class="adm-t__muted">—</span>'; ?></td><td class="adm-r adm-t__num<?php echo (int) $r['value_cents'] === 0 ? ' adm-t__muted' : ''; ?>"><?php echo (int) $r['value_cents'] === 0 ? '—' : $usd($r['value_cents']); ?></td><td class="adm-t__muted adm-t__trunc"><?php echo $e($r['meta'] !== '' ? $r['meta'] : $r['path']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php elseif ($show === 'creators'): $rows = (array) ($an['creators'] ?? array()); ?>
<section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Creator pages <b class="adm-count"><?php echo count($rows); ?></b></h2>
    <form class="adm-search" role="search" onsubmit="return false"><input type="search" class="adm-search__in" placeholder="Filter by handle" data-search-for="admAnCreators"></form></header>
    <?php if (empty($rows)): ?><?php echo adm_empty('No creator page traffic in this period', 'fa-user'); ?><?php else: ?>
    <table class="adm-t adm-t--num" id="admAnCreators" data-sortable data-pager>
        <thead><tr><th data-sort="text">Creator</th><th class="adm-r" data-sort="num" data-sorted="desc">Views</th><th class="adm-r" data-sort="num">Visitors</th><th class="adm-r" data-sort="num">Entrances</th><th class="adm-r" data-sort="num">Memberships</th><th class="adm-r" data-sort="num">Purchases</th><th class="adm-r" data-sort="num">Value</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $name = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']); ?>
            <tr class="adm-t__link" data-href="/admin/user/<?php echo (int) $r['creator_id']; ?>" data-search="<?php echo $e(mb_strtolower($name . ' @' . $r['u_name'])); ?>"><td class="adm-t__main"><?php echo adm_who($name !== '' ? $name : '@' . $r['u_name'], (string) $r['u_name'], '/admin/user/' . (int) $r['creator_id']); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo $n($r['views']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['visitors']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['entrances']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['memberships']); ?></td><td class="adm-r adm-t__num"><?php echo $n($r['purchases']); ?></td><td class="adm-r adm-t__num<?php echo (int) $r['value_cents'] === 0 ? ' adm-t__muted' : ''; ?>"><?php echo (int) $r['value_cents'] === 0 ? '—' : $usd($r['value_cents']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
