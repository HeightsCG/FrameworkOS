<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Audit log</h1><p class="adm-head__sub">Every staff action, newest first</p></div>
</header>
<section class="adm-box">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Staff actions <b class="adm-count"><?php echo count((array) $this->audit); ?></b></h2>
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="admAuditSearch" placeholder="Search staff, account or action" aria-label="Search the audit log" data-search-for="admAudit"></div>
    </header>
    <?php if (empty($this->audit)): ?><?php echo adm_empty('Nothing recorded yet', 'fa-clipboard'); ?><?php else: ?>
    <table class="adm-t" id="admAudit" data-sortable data-pager>
        <thead><tr><th data-sort="text" data-sorted="desc">When</th><th data-sort="text">Staff</th><th data-sort="text">Action</th><th>Details</th><th>IP address</th></tr></thead>
        <tbody>
        <?php $audit_rows = $this->audit; $audit_show_target = true; include __DIR__ . '/_audit_rows.php'; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admAuditNone" hidden>Nothing matches.</p>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
