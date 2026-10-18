<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <h1 class="adm-head__title">Founding creators</h1>
</header>
<?php include __DIR__ . '/_founding.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-founding.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-founding.js'); ?>"></script>
