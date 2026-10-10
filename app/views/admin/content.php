<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <h1 class="adm-head__title">Articles</h1>
    <span class="adm-head__sub"><?php echo count((array) $this->seo_published); ?> published · <?php echo count((array) $this->seo_keywords); ?> keywords queued · <?php echo count((array) $this->seo_review); ?> unpublished</span>
</header>
<?php include __DIR__ . '/_content.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-content.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-content.js'); ?>"></script>
