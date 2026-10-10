<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <h1 class="adm-head__title">Scenes</h1>
    <span class="adm-head__sub">Scene templates creators pick in Influencers, Generate Images</span>
</header>
<?php include __DIR__ . '/_scenes.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-scenes.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-scenes.js'); ?>"></script>
