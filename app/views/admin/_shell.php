<?php
/* Opens every admin page: the stylesheet, the helpers and the admin rail (grouped by job, with live counts from
   AdminController::rail_counts). The page sets $this->page to its rail key. Close with _shell_end.php. */
require_once __DIR__ . '/_rowmenu.php';
include __DIR__ . '/_helpers.php';
$adm_page = (string) ($this->page ?? '');
$adm_groups = array(
    array('', array(
        array('today', 'Today', '/admin', 'fa-sun', 0),
        array('queue', 'Queue', '/admin/queue', 'fa-inbox', (int) ($nav['queue'] ?? 0)),
    )),
    array('People', array(
        array('people', 'Users', '/admin/people', 'fa-users', 0),
    )),
    array('Money', array(
        array('money', 'Financials', '/admin/money', 'fa-chart-line', 0),
    )),
    array('Growth', array(
        array('growth', 'Funnel', '/admin/growth', 'fa-arrow-trend-up', 0),
        array('leads', 'Leads', '/admin/leads', 'fa-envelope-open-text', 0),
        array('founding', 'Founding', '/admin/founding', 'fa-star', 0),
        array('affiliates', 'Affiliates', '/admin/affiliates', 'fa-handshake', (int) ($nav['affiliates'] ?? 0)),
    )),
    array('Content', array(
        array('articles', 'Articles', '/admin/articles', 'fa-newspaper', (int) ($nav['articles'] ?? 0)),
        array('scenes', 'Scenes', '/admin/scenes', 'fa-panorama', 0),
        array('niches', 'Niches', '/admin/niches', 'fa-tags', 0),
    )),
    array('System', array(
        array('audit', 'Audit Log', '/admin/audit', 'fa-clipboard-list', 0),
        array('jobs', 'Jobs', '/admin/jobs', 'fa-clock', 0),
    )),
);
?>
<link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>">
<div class="adm" data-admin-page="<?php echo $e($adm_page); ?>">
    <aside class="adm-rail" aria-label="Admin sections">
        <nav class="adm-rail__nav">
            <?php foreach ($adm_groups as $g): ?>
            <div class="adm-rail__group">
                <?php if ($g[0] !== ''): ?><span class="adm-rail__label"><?php echo $e($g[0]); ?></span><?php endif; ?>
                <?php foreach ($g[1] as $it): $on = ($it[0] === $adm_page); ?>
                <a class="adm-rail__item<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo $e($it[2]); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><i class="fa-solid <?php echo $e($it[3]); ?>" aria-hidden="true"></i><span><?php echo $e($it[1]); ?></span><?php echo $badge($it[4]); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="adm-page">
