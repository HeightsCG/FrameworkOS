<?php
/* Opens every admin page: the stylesheet, the helpers, the section tab row (and the sub-tab row for Growth, Content
   and System). The app's own left menu stays. The page sets $this->page; AdminController::shell sets $this->section. */
require_once __DIR__ . '/_rowmenu.php';
require_once __DIR__ . '/_ui.php';
include __DIR__ . '/_helpers.php';
$adm_page = (string) ($this->page ?? ''); $adm_sec = (string) ($this->section ?? 'today');
$adm_sections = array(
    array('today', 'Today', '/admin', 0),
    array('moderation', 'Moderation', '/admin/moderation', (int) ($nav['queue'] ?? 0)),
    array('users', 'Users', '/admin/users', 0),
    array('financials', 'Financials', '/admin/financials', 0), array('sales', 'Sales', '/admin/sales', 0),
    array('billing', 'Billing', '/admin/billing', 0),
    array('growth', 'Growth', '/admin/growth', 0), array('content', 'Content', '/admin/content', 0), array('system', 'System', '/admin/system', 0),
);
$adm_groups = array(
    'growth'  => array(array('growth', 'Funnel', '/admin/growth'), array('leads', 'Leads', '/admin/leads'), array('founding', 'Founding', '/admin/founding'), array('affiliates', 'Affiliates', '/admin/affiliates')),
    'content' => array(array('content', 'Articles', '/admin/content'), array('scenes', 'Scenes', '/admin/scenes'), array('niches', 'Niches', '/admin/niches')),
    'system'  => array(array('system', 'Audit Log', '/admin/system'), array('jobs', 'Jobs', '/admin/jobs')),
);
$adm_tabs = $adm_groups[$adm_sec] ?? array();
?>
<link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>">
<div class="adm" data-admin-page="<?php echo $e($adm_page); ?>">
    <nav class="adm-nav" aria-label="Admin sections">
        <?php foreach ($adm_sections as $sct): $on = $sct[0] === $adm_sec; ?><a class="adm-nav__a<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo $e($sct[2]); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><?php echo $e($sct[1]); ?><?php if ($sct[3] > 0): ?> <b class="adm-nav__n"><?php echo (int) $sct[3]; ?></b><?php endif; ?></a><?php endforeach; ?>
    </nav>
    <?php if ($adm_tabs): ?>
    <nav class="adm-subnav" aria-label="<?php echo $e(ucfirst($adm_sec)); ?> pages">
        <?php foreach ($adm_tabs as $t): $on = $t[0] === $adm_page; ?><a class="adm-subnav__a<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo $e($t[2]); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><?php echo $e($t[1]); ?><?php if ($t[0] === 'content' && (int) ($nav['articles'] ?? 0) > 0): ?> <b><?php echo (int) $nav['articles']; ?></b><?php elseif ($t[0] === 'affiliates' && (int) ($nav['affiliates'] ?? 0) > 0): ?> <b><?php echo (int) $nav['affiliates']; ?></b><?php endif; ?></a><?php endforeach; ?>
    </nav>
    <?php endif; ?>
