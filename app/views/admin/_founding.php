<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php
/* Admin > Growth > Founding: the /founding offer's spots (Founding::SPOTS) and every claim. Row actions in the ⋯ menu (public/js/admin-founding.js). */
$fd_rows  = (array) ($this->founding ?? array());
$fd_taken = (int) ($this->founding_taken ?? 0);
$fd_day   = function ($utc) use ($fmt) { return (string) $utc === '' ? 'Not yet' : $fmt($utc); };
$fd_n = array('active' => 0, 'claimed' => 0, 'lapsed' => 0, 'refused' => 0);
foreach ($fd_rows as $r) { $fd_n[(string) $r['status']] = ($fd_n[(string) $r['status']] ?? 0) + 1; }
?>
<section class="adm-panel" data-panel="founding">
    <div class="adm-box adm-box--strip"><dl class="adm-strip">
        <div><dt>Spots taken</dt><dd><?php echo number_format($fd_taken); ?></dd><dd class="adm-strip__sub">of <?php echo number_format(Founding::SPOTS); ?></dd></div>
        <div><dt>Active</dt><dd><?php echo number_format($fd_n['active']); ?></dd><dd class="adm-strip__sub"><?php echo number_format($fd_n['claimed']); ?> claimed, not activated</dd></div>
        <div><dt>Lapsed or refused</dt><dd><?php echo number_format($fd_n['lapsed'] + $fd_n['refused']); ?></dd><dd class="adm-strip__sub">spots freed again</dd></div>
    </dl></div>
    <div class="adm-box">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Claims <b class="adm-count"><?php echo count($fd_rows); ?></b></h2>
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search creator" aria-label="Search founding claims" data-search-for="admFounding"></div>
        <div class="adm-seg" role="tablist" aria-label="Status" data-filter-for="admFounding" data-filter-attr="data-status">
            <button type="button" role="tab" class="adm-seg__b is-on" aria-selected="true" data-filter="all">All <b><?php echo count($fd_rows); ?></b></button>
            <?php foreach (array('active' => 'Active', 'claimed' => 'Claimed', 'lapsed' => 'Lapsed', 'refused' => 'Refused') as $k => $l): ?><button type="button" role="tab" class="adm-seg__b" aria-selected="false" data-filter="<?php echo $k; ?>"><?php echo $l; ?> <b><?php echo (int) $fd_n[$k]; ?></b></button><?php endforeach; ?>
        </div>
    </header>
    <?php if (empty($fd_rows)): ?>
        <?php echo adm_empty('No founding creators yet', 'fa-star'); ?>
    <?php else: ?>
    <table class="adm-t" id="admFounding" data-sortable data-pager>
        <thead><tr><th data-sort="text">Creator</th><th data-sort="text" data-sorted="desc">Claimed</th><th data-sort="text">Activated</th><th data-sort="text">Status</th><th>Testimonial</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($fd_rows as $r): $fn = trim($r['first_name'] . ' ' . $r['last_name']); $fn = $fn !== '' ? $fn : '@' . $r['u_name']; $st = (string) $r['status']; ?>
            <tr class="adm-fdrow adm-t__link" data-claim="<?php echo (int) $r['id']; ?>" data-status="<?php echo $e($st); ?>" data-href="/admin/user/<?php echo (int) $r['user_id']; ?>">
                <td class="adm-t__who"><?php echo adm_who($fn, $r['u_name'], '/admin/user/' . (int) $r['user_id'], '', (string) ($r['user_email'] ?? '')); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($r['claimed_at']); ?>"><?php echo $e($fd_day($r['claimed_at'])); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($r['activated_at']); ?>"><?php echo $e($fd_day($r['activated_at'])); ?></td>
                <td><?php echo adm_pill(ucfirst($st)); ?></td>
                <td class="adm-t__muted adm-t__trunc" title="<?php echo $e((string) ($r['testimonial_text'] ?? '')); ?>"><?php if ((string) ($r['testimonial_text'] ?? '') !== ''): ?>&ldquo;<?php echo $e($r['testimonial_text']); ?>&rdquo;<span class="adm-t__sub"><?php echo !empty($r['testimonial_consent']) ? 'May show name and handle' : 'Anonymous only'; ?> · <?php echo $e($fd_day($r['testimonial_submitted_at'])); ?></span><?php else: ?><?php echo (string) $r['testimonial_requested_at'] !== '' ? 'Asked ' . $e($fd_day($r['testimonial_requested_at'])) : 'Not asked yet'; ?><?php endif; ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Founding actions', array(
                    ($st === 'active' && (string) $r['testimonial_requested_at'] === '') ? array('text' => 'Send Testimonial Request', 'attrs' => 'data-founding-action="testimonial"') : null,
                    $st !== 'refused' ? array('text' => 'Mark Refused', 'attrs' => 'data-founding-action="refuse"', 'danger' => true) : null,
                )); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admFoundingNone" hidden>No matching claims.</p>
    <?php endif; ?>
    </div>
</section>
