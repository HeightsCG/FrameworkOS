<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php
/* Admin > Growth > Testimonials: every row, approve or remove in the ⋯ menu, add with the button (public/js/admin-testimonials.js). */
$ts_rows = (array) ($this->testimonials ?? array());
$ts_approved = 0; foreach ($ts_rows as $r) { if ((string) $r['status'] === 'approved') { $ts_approved++; } }
?>
<section class="adm-panel" data-panel="testimonials">
    <div class="adm-box adm-box--strip"><dl class="adm-strip">
        <div><dt>Approved</dt><dd><?php echo number_format($ts_approved); ?></dd><dd class="adm-strip__sub">of <?php echo (int) Proof::TESTIMONIALS_MIN; ?> needed to show the row</dd></div>
        <div><dt>Pending</dt><dd><?php echo number_format(count($ts_rows) - $ts_approved); ?></dd><dd class="adm-strip__sub">not shown on the home page</dd></div>
    </dl></div>
    <div class="adm-box">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Testimonials <b class="adm-count"><?php echo count($ts_rows); ?></b></h2>
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search name or quote" aria-label="Search testimonials" data-search-for="admTestimonials"></div>
        <div class="adm-seg" role="tablist" aria-label="Status" data-filter-for="admTestimonials" data-filter-attr="data-status">
            <button type="button" role="tab" class="adm-seg__b is-on" aria-selected="true" data-filter="all">All <b><?php echo count($ts_rows); ?></b></button>
            <button type="button" role="tab" class="adm-seg__b" aria-selected="false" data-filter="approved">Approved <b><?php echo $ts_approved; ?></b></button>
            <button type="button" role="tab" class="adm-seg__b" aria-selected="false" data-filter="pending">Pending <b><?php echo count($ts_rows) - $ts_approved; ?></b></button>
        </div>
    </header>
    <?php if (empty($ts_rows)): ?>
        <?php echo adm_empty('No testimonials yet', 'fa-quote-left'); ?>
    <?php else: ?>
    <table class="adm-t" id="admTestimonials" data-sortable data-pager>
        <thead><tr><th data-sort="text">Name</th><th data-sort="text">Niche</th><th>Quote</th><th data-sort="text">Status</th><th data-sort="text" data-sorted="desc">Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ts_rows as $r): $st = (string) $r['status']; ?>
            <tr class="adm-tsrow" data-testimonial="<?php echo (int) $r['id']; ?>" data-status="<?php echo $e($st); ?>">
                <td class="adm-t__who"><?php echo adm_who((string) $r['first_name'], '', '', (string) ($r['photo_url'] ?? ''), ''); ?></td>
                <td class="adm-t__muted"><?php echo $e((string) $r['niche']); ?></td>
                <td class="adm-t__trunc" title="<?php echo $e((string) $r['quote']); ?>">&ldquo;<?php echo $e((string) $r['quote']); ?>&rdquo;</td>
                <td><?php echo adm_pill(ucfirst($st), $st === 'approved' ? 'good' : ''); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($r['created_at']); ?>"><?php echo $e($fmt($r['created_at'])); ?><?php if ((string) ($r['created_by_name'] ?? '') !== ''): ?><span class="adm-t__sub">by @<?php echo $e($r['created_by_name']); ?></span><?php endif; ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Testimonial actions', array(
                    $st === 'approved' ? array('text' => 'Unapprove', 'attrs' => 'data-testimonial-action="unapprove"') : array('text' => 'Approve', 'attrs' => 'data-testimonial-action="approve"'),
                    array('text' => 'Remove', 'attrs' => 'data-testimonial-action="remove"', 'danger' => true),
                )); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admTestimonialsNone" hidden>No matching testimonials.</p>
    <?php endif; ?>
    </div>
</section>
