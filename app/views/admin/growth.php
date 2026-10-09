<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head"><div><h1 class="adm-head__title">Funnel</h1><p class="adm-head__sub">Signups by day and by source, referred signups, and free creators building. Demo accounts left out.</p></div></header>
<?php include __DIR__ . '/_growth.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
