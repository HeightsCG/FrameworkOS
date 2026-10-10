<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/billing — every paid creator plan and monthly pack, past due first. Retry from the row menu (admin_billing_retry). */
$cnt = array('all' => count((array) $this->billing), 'past_due' => 0, 'active' => 0, 'canceling' => 0, 'canceled' => 0);
foreach ((array) $this->billing as $b) { $cnt[(string) $b['status']] = ($cnt[(string) $b['status']] ?? 0) + 1; if (!empty($b['cancel_at_period_end'])) { $cnt['canceling']++; } } ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Billing</h1><p class="adm-head__sub"><?php echo $cnt['all']; ?> accounts<?php echo $cnt['past_due'] > 0 ? ' · ' . $cnt['past_due'] . ' past due' : ''; ?></p></div>
</header>
<section class="adm-box">
    <header class="adm-box__h">
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search account or plan" aria-label="Search billing" data-search-for="admBilling"></div>
        <div class="adm-seg" role="tablist" aria-label="Status" data-filter-for="admBilling" data-filter-attr="data-bstatus">
            <?php foreach (array('all' => 'All', 'past_due' => 'Past due', 'active' => 'Active', 'canceling' => 'Canceling', 'canceled' => 'Canceled') as $k => $l): ?><button type="button" role="tab" class="adm-seg__b<?php echo $k === 'all' ? ' is-on' : ''; ?>" aria-selected="<?php echo $k === 'all' ? 'true' : 'false'; ?>" data-filter="<?php echo $k; ?>"><?php echo $l; ?> <b><?php echo (int) ($cnt[$k] ?? 0); ?></b></button><?php endforeach; ?>
        </div>
    </header>
    <?php if (empty($this->billing)): ?><?php echo adm_empty('No paid plans or monthly packs yet', 'fa-credit-card'); ?><?php else: ?>
    <table class="adm-t" id="admBilling" data-sortable data-pager>
        <thead><tr><th data-sort="text">Account</th><th data-sort="text">Plan</th><th>Add-ons</th><th data-sort="text">Status</th><th data-sort="text">Next charge</th><th data-sort="text">Last charge</th><th></th></tr></thead>
        <tbody>
        <?php foreach ((array) $this->billing as $b):
            $bn = trim($b['first_name'] . ' ' . $b['last_name']); $bn = $bn !== '' ? $bn : '@' . $b['u_name'];
            $bt = PlanTiers::get((string) $b['plan_key']); $bst = (string) $b['status'];
            $flt = $bst . (!empty($b['cancel_at_period_end']) ? ' canceling' : '');
            $adds = array();
            if ((int) $b['influencer_slots'] > 0) { $adds[] = (int) $b['influencer_slots'] . ' extra influencer' . ((int) $b['influencer_slots'] === 1 ? '' : 's'); }
            if ((int) $b['pack_dollars'] > 0) { $adds[] = '$' . (int) $b['pack_dollars'] . ' AI credit pack (' . number_format(PlanTiers::pack_credits((int) $b['pack_dollars'])) . ' AI credits)'; }
            $bnx = BillingService::next_charge($b);
            $label = $bst === 'past_due' ? 'Past due' : (!empty($b['cancel_at_period_end']) && $bst === 'active' ? 'Canceling' : ucfirst($bst)); ?>
        <tr class="adm-urow adm-t__link" data-uid="<?php echo (int) $b['user_id']; ?>" data-href="/admin/user/<?php echo (int) $b['user_id']; ?>" data-bstatus="<?php echo $e($flt); ?>">
            <td class="adm-t__who"><?php echo adm_who($bn, $b['u_name'], '/admin/user/' . (int) $b['user_id'], '', (string) $b['user_email']); ?></td>
            <td class="adm-t__nowrap"><?php echo $e($bt ? $bt['name'] : $b['plan_key']); ?></td>
            <td class="adm-t__muted adm-t__trunc" title="<?php echo $e($adds ? implode(', ', $adds) : ''); ?>"><?php echo $e($adds ? implode(', ', $adds) : '—'); ?></td>
            <td><?php echo adm_pill($label); ?></td>
            <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($bnx ? $bnx['at'] : ''); ?>"><?php echo $bnx ? $e(BillingService::money($bnx['total']) . ' · ' . $fmt($bnx['at'])) : '—'; ?><?php if ($bst === 'past_due' && !empty($b['next_retry_at'])): ?><span class="adm-t__sub">Retry <?php echo $e($fmt($b['next_retry_at'])); ?></span><?php endif; ?></td>
            <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($b['last_charge_at'] ?? ''); ?>" <?php echo !empty($b['last_failure']) ? 'title="' . $e($b['last_failure']) . '"' : ''; ?>><?php echo !empty($b['last_charge_at']) ? $e(ucfirst(str_replace('_', ' ', (string) $b['last_charge_status'])) . ' · ' . BillingService::money((int) $b['last_amount_cents'])) : '—'; ?></td>
            <td class="adm-t__act"><?php echo adm_row_menu('Billing actions', array(array('text' => 'Open account', 'href' => '/admin/user/' . (int) $b['user_id']), $bst === 'past_due' ? array('text' => 'Retry charge now', 'attrs' => 'data-billing-retry') : null)); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admBillingNone" hidden>No matching accounts.</p>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
