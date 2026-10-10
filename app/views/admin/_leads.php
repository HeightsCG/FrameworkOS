<?php
/* Admin > Growth > Leads: people who used a free tool (/tools/*), newest first, filtered by tool; Export CSV posts admin_leads_csv. */
$lead_counts = array('all' => count($this->leads));
foreach (LeadsModel::SOURCES as $ls_key => $ls_label) { $lead_counts[$ls_key] = 0; }
foreach ($this->leads as $ld) { if (isset($lead_counts[$ld['source']])) { $lead_counts[$ld['source']]++; } }
?>
<section class="adm-box adm-panel" data-panel="leads">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Leads <b class="adm-count"><?php echo (int) $lead_counts['all']; ?></b></h2>
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search name, email or niche" aria-label="Search leads" data-search-for="admLeads"></div>
        <div class="adm-seg" role="tablist" aria-label="Lead source" id="admLeadTabs">
            <button type="button" class="adm-seg__b is-active is-on" role="tab" aria-selected="true" data-lead="">All <b><?php echo (int) $lead_counts['all']; ?></b></button>
            <?php foreach (LeadsModel::SOURCES as $ls_key => $ls_label): ?>
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-lead="<?php echo $e($ls_key); ?>"><?php echo $e($ls_label); ?> <b><?php echo (int) $lead_counts[$ls_key]; ?></b></button>
            <?php endforeach; ?>
        </div>
        <form method="post" action="/api/admin_leads_csv" id="admLeadsCsv" class="adm-box__end">
            <?php echo CSRF::field(); ?>
            <input type="hidden" name="source" value="" id="admLeadsCsvSource">
            <button type="submit" class="adm-btn">Export CSV</button>
        </form>
    </header>
    <table class="adm-t" id="admLeads" data-sortable data-pager>
        <thead><tr><th data-sort="text" data-sorted="desc">When</th><th data-sort="text">Name</th><th data-sort="text">Email</th><th data-sort="text">Tool</th><th data-sort="text">Niche</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($this->leads as $ld):
            $lx = (array) json_decode((string) ($ld['extra'] ?? ''), true);
            $bits = array(); foreach ($lx as $lk => $lv) { if ((string) $lv !== '') { $bits[] = ucfirst($lk) . ': ' . $lv; } } ?>
            <tr class="adm-leadrow" data-source="<?php echo $e($ld['source']); ?>">
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($ld['created_at']); ?>"><?php echo $e($fmt($ld['created_at'], true)); ?></td>
                <td class="adm-t__main"><?php echo $e($ld['first_name']); ?></td>
                <td><?php echo $e($ld['email']); ?></td>
                <td class="adm-t__muted"><?php echo $e(LeadsModel::SOURCES[$ld['source']] ?? $ld['source']); ?></td>
                <td><?php echo $e($ld['niche']); ?></td>
                <td class="adm-t__muted adm-t__trunc" title="<?php echo $e(implode(' · ', $bits)); ?>"><?php echo $e(implode(' · ', $bits)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admLeadsNone"<?php echo empty($this->leads) ? '' : ' hidden'; ?>>No leads yet.</p>
</section>
