<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/sales — every one-off sale (refund in the row menu for pay-per-view, bundles and messages) and chargebacks. */
$kinds = AdminModel::SALE_KINDS; $kn = array('all' => count((array) $this->sales)); foreach ((array) $this->sales as $sl) { $kn[$sl['kind']] = ($kn[$sl['kind']] ?? 0) + 1; } ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Sales</h1><p class="adm-head__sub"><?php echo count((array) $this->sales); ?> recent sales · refunded pay-per-view, bundle and message sales drop off</p></div>
</header>
<section class="adm-box">
    <header class="adm-box__h">
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search buyer, item or creator" aria-label="Search sales" data-search-for="admSales"></div>
        <div class="adm-seg" role="tablist" aria-label="Type" data-filter-for="admSales" data-filter-attr="data-kind">
            <button type="button" role="tab" class="adm-seg__b is-on" aria-selected="true" data-filter="all">All <b><?php echo $kn['all']; ?></b></button>
            <?php foreach ($kinds as $k => $l): ?><button type="button" role="tab" class="adm-seg__b" aria-selected="false" data-filter="<?php echo $k; ?>"><?php echo $e($l); ?> <b><?php echo (int) ($kn[$k] ?? 0); ?></b></button><?php endforeach; ?>
        </div>
    </header>
    <?php if (empty($this->sales)): ?><?php echo adm_empty('No sales yet', 'fa-receipt'); ?><?php else: ?>
    <table class="adm-t" id="admSales" data-sortable data-pager>
        <thead><tr><th data-sort="text">Buyer</th><th data-sort="text">Item</th><th data-sort="text">Creator</th><th data-sort="text">Type</th><th class="adm-r" data-sort="num">Amount</th><th data-sort="text" data-sorted="desc">Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($this->sales as $sale): ?>
            <tr class="adm-srow adm-t__link" data-kind="<?php echo $e($sale['kind']); ?>" data-ref="<?php echo (int) $sale['ref_id']; ?>" data-fan="<?php echo (int) $sale['fan_id']; ?>" data-href="/admin/user/<?php echo (int) $sale['fan_id']; ?>">
                <td class="adm-t__who"><?php echo adm_who($sale['fan_name'], $sale['fan_handle'], '/admin/user/' . (int) $sale['fan_id'], $sale['fan_avatar']); ?></td>
                <td class="adm-t__trunc" title="<?php echo $e($sale['item']); ?>"><?php echo $e($sale['item'] !== '' ? $sale['item'] : 'Untitled'); ?></td>
                <td class="adm-t__muted adm-t__nowrap">@<?php echo $e($sale['creator_handle']); ?></td>
                <td><?php echo adm_pill($kinds[$sale['kind']] ?? $sale['kind'], 'gray'); ?></td>
                <td class="adm-r adm-t__num adm-scell--amt" data-value="<?php echo ((int) $sale['credits']) / 10; ?>">$<?php echo number_format(((int) $sale['credits']) / 10, 2); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($sale['created_at']); ?>"><?php echo $e($fmt($sale['created_at'], true)); ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Sale actions', array(array('text' => 'Open buyer', 'href' => '/admin/user/' . (int) $sale['fan_id']), $sale['refundable'] ? array('text' => 'Refund…', 'attrs' => 'data-refund', 'danger' => true) : null)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admSalesNone" hidden>No matching sales.</p>
    <?php endif; ?>
</section>

<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Chargebacks<?php if (!empty($this->chargebacks)): ?> <b class="adm-count"><?php echo count($this->chargebacks); ?></b><?php endif; ?></h2><span class="adm-box__note">Disputed card charges · the account is suspended automatically</span></header>
    <?php if (empty($this->chargebacks)): ?><?php echo adm_empty('No chargebacks', 'fa-credit-card'); ?><?php else: ?>
    <table class="adm-t" data-sortable>
        <thead><tr><th data-sort="text">Account</th><th data-sort="text">Reason</th><th class="adm-r" data-sort="num">Amount</th><th>Status</th><th data-sort="text" data-sorted="desc">Date</th></tr></thead>
        <tbody>
        <?php foreach ($this->chargebacks as $cb): ?>
            <tr>
                <td class="adm-t__main"><?php echo $cb['handle'] ? '@' . $e($cb['handle']) : '<span class="adm-t__muted">Unmatched</span>'; ?></td>
                <td class="adm-t__muted adm-t__trunc"><?php echo $e($cb['reason'] ?: '—'); ?></td>
                <td class="adm-r adm-t__num" data-value="<?php echo ((int) $cb['amount_cents']) / 100; ?>">$<?php echo number_format(((int) $cb['amount_cents']) / 100, 2); ?></td>
                <td><?php echo adm_pill('Suspended'); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($cb['created_at']); ?>"><?php echo $e($fmt($cb['created_at'], true)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
