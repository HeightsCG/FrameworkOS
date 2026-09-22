<?php /* Admin > Audit Log: every staff action, newest first (AuditModel). */ ?>
<section class="adm-sec adm-panel" data-panel="audit">
    <div class="adm-sec__head">
        <h2 class="adm-sec__title">Audit Log</h2>
        <input type="search" class="form-control adm-ausearch" id="admAuditSearch" placeholder="Search by staff, account or action" aria-label="Search the audit log">
    </div>
    <div class="adm-table adm-table--audit">
        <div class="adm-table__head"><span>When</span><span>Staff</span><span>Action</span><span>Account</span><span>Details</span><span>IP address</span></div>
        <div class="adm-table__body" id="admAudit">
            <?php $audit_rows = $this->audit; $audit_show_target = true; include __DIR__ . '/_audit_rows.php'; ?>
            <div class="adm-empty" id="admAuditNone"<?php echo empty($this->audit) ? '' : ' hidden'; ?>><span class="adm-empty__ic"><i class="fa-solid fa-clipboard-list"></i></span><p class="adm-empty__t">Nothing Recorded</p></div>
        </div>
    </div>
</section>
