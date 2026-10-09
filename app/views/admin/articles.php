<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head"><div><h1 class="adm-head__title">Articles</h1><p class="adm-head__sub">The blog engine: what is live, what is queued to draft, what still needs a read</p></div></header>
<?php include __DIR__ . '/_content.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin-content.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-content.js'); ?>"></script>
