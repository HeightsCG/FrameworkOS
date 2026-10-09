<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head"><div><h1 class="adm-head__title">Niches</h1><p class="adm-head__sub">The Creator Directory categories and the choices in creator Settings</p></div></header>
<?php include __DIR__ . '/_niches.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin-niches.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-niches.js'); ?>"></script>
