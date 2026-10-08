<?php
/* Admin > Leads: people who used a free tool (/tools/*), newest first, filtered by tool; Export CSV posts admin_leads_csv. */
$lead_counts = array('all' => count($this->leads));
foreach (LeadsModel::SOURCES as $ls_key => $ls_label) { $lead_counts[$ls_key] = 0; }
foreach ($this->leads as $ld) { if (isset($lead_counts[$ld['source']])) { $lead_counts[$ld['source']]++; } }
?>
<section class="adm-sec adm-panel" data-panel="leads">
    <div class="adm-sec__head">
        <div class="adm-subtabs" role="tablist" aria-label="Lead source" id="admLeadTabs">
            <button type="button" class="adm-subtab is-active" role="tab" aria-selected="true" data-lead="">All <b><?php echo (int) $lead_counts['all']; ?></b></button>
            <?php foreach (LeadsModel::SOURCES as $ls_key => $ls_label): ?>
            <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-lead="<?php echo $e($ls_key); ?>"><?php echo $e($ls_label); ?> <b><?php echo (int) $lead_counts[$ls_key]; ?></b></button>
            <?php endforeach; ?>
        </div>
        <form method="post" action="/api/admin_leads_csv" id="admLeadsCsv">
            <?php echo CSRF::field(); ?>
            <input type="hidden" name="source" value="" id="admLeadsCsvSource">
            <button type="submit" class="adm-btn"><i class="fa-solid fa-download" aria-hidden="true"></i> Export CSV</button>
        </form>
    </div>
    <div class="adm-table adm-table--leads">
        <div class="adm-table__head"><span>When</span><span>Name</span><span>Email</span><span>Tool</span><span>Niche</span><span>Details</span></div>
        <div class="adm-table__body" id="admLeads">
            <?php foreach ($this->leads as $ld):
                $lx = (array) json_decode((string) ($ld['extra'] ?? ''), true);
                $bits = array(); foreach ($lx as $lk => $lv) { if ((string) $lv !== '') { $bits[] = ucfirst($lk) . ': ' . $lv; } }
            ?>
            <div class="adm-leadrow" data-source="<?php echo $e($ld['source']); ?>">
                <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($ld['created_at'], true)); ?></span>
                <span class="adm-ucell"><?php echo $e($ld['first_name']); ?></span>
                <span class="adm-ucell"><?php echo $e($ld['email']); ?></span>
                <span class="adm-ucell adm-ucell--muted"><?php echo $e(LeadsModel::SOURCES[$ld['source']] ?? $ld['source']); ?></span>
                <span class="adm-ucell"><?php echo $e($ld['niche']); ?></span>
                <span class="adm-ucell adm-ucell--muted" title="<?php echo $e(implode(' · ', $bits)); ?>"><?php echo $e(implode(' · ', $bits)); ?></span>
            </div>
            <?php endforeach; ?>
            <div class="adm-empty" id="admLeadsNone"<?php echo empty($this->leads) ? '' : ' hidden'; ?>>
                <span class="adm-empty__ic"><i class="fa-solid fa-envelope-open-text"></i></span>
                <p class="adm-empty__t">No Leads Yet</p>
            </div>
        </div>
    </div>
</section>
