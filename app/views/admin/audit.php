<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Audit Log</h1><p class="adm-head__sub">Every staff action, newest first</p></div>
    <div class="adm-search adm-search--static"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="admAuditSearch" placeholder="Staff, account or action" aria-label="Search the audit log"></div>
</header>
<div class="adm-table adm-table--audit">
    <div class="adm-table__head"><span>When</span><span>Staff</span><span>Action</span><span>Account</span><span>Details</span><span>IP address</span></div>
    <div class="adm-table__body" id="admAudit">
        <?php $audit_rows = $this->audit; $audit_show_target = true; include __DIR__ . '/_audit_rows.php'; ?>
        <p class="adm-none" id="admAuditNone"<?php echo empty($this->audit) ? '' : ' hidden'; ?>>Nothing recorded.</p>
    </div>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
