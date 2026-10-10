<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <h1 class="adm-head__title">Niches</h1>
    <span class="adm-head__sub">The Creator Directory categories and the choices in creator Settings</span>
</header>
<?php include __DIR__ . '/_niches.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-niches.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-niches.js'); ?>"></script>
