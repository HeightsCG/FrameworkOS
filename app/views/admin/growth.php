<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <h1 class="adm-head__title">Funnel</h1>
    <span class="adm-head__sub">Signups by day and by source, referred signups, free creators building · demo accounts left out</span>
</header>
<?php include __DIR__ . '/_growth.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
