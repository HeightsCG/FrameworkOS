<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php
/* /admin > Founding: the /founding offer's spots (Founding::SPOTS) and every claim. Row actions in the ⋯ menu (public/js/admin-founding.js). */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fd_rows  = (array) ($this->founding ?? array());
$fd_taken = (int) ($this->founding_taken ?? 0);
$fd_tz    = (string) ($this->timezone ?? 'UTC');
$fd_day   = function ($utc) use ($fd_tz) {
    if ((string) $utc === '') { return 'Not yet'; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($fd_tz ?: 'UTC')); return $d->format('M j, Y'); } catch (\Throwable $ex) { return ''; }
};
$fd_n = array('active' => 0, 'claimed' => 0, 'lapsed' => 0, 'refused' => 0);
foreach ($fd_rows as $r) { $fd_n[(string) $r['status']] = ($fd_n[(string) $r['status']] ?? 0) + 1; }
$fd_tags = array('active' => 'adm-pill--ok', 'claimed' => 'adm-pill--warn', 'lapsed' => '', 'refused' => 'adm-pill--bad');
?>
<section class="adm-sec adm-panel" data-panel="founding">
    <div class="adm-kpis">
        <div class="adm-kpi"><div class="adm-kpi__top"><span class="adm-kpi__label">Spots taken</span><i class="adm-kpi__ic fa-solid fa-star" aria-hidden="true"></i></div><div class="adm-kpi__val"><?php echo number_format($fd_taken); ?></div><div class="adm-kpi__sub">of <?php echo number_format(Founding::SPOTS); ?>, <?php echo number_format(max(0, Founding::SPOTS - $fd_taken)); ?> left</div></div>
        <div class="adm-kpi"><div class="adm-kpi__top"><span class="adm-kpi__label">Active</span><i class="adm-kpi__ic fa-solid fa-circle-check" aria-hidden="true"></i></div><div class="adm-kpi__val"><?php echo number_format($fd_n['active']); ?></div><div class="adm-kpi__sub"><?php echo $e(Founding::fee_label()); ?> fee locked</div></div>
        <div class="adm-kpi"><div class="adm-kpi__top"><span class="adm-kpi__label">Lapsed or refused</span><i class="adm-kpi__ic fa-solid fa-circle-minus" aria-hidden="true"></i></div><div class="adm-kpi__val"><?php echo number_format($fd_n['lapsed'] + $fd_n['refused']); ?></div><div class="adm-kpi__sub">Spots not counted</div></div>
    </div>
    <?php if (empty($fd_rows)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-star"></i></span><p class="adm-empty__t">No Founding Creators Yet</p><p class="adm-empty__x">Claims from /founding show here once a creator starts the plan through the offer.</p></div>
    <?php else: ?>
    <div class="adm-table" style="--adm-cols:minmax(180px,1.1fr) 110px 110px 90px minmax(200px,1.6fr) 40px">
        <div class="adm-table__head"><span>Creator</span><span>Claimed</span><span>Activated</span><span>Status</span><span>Testimonial</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($fd_rows as $r):
                $fn = trim($r['first_name'] . ' ' . $r['last_name']); $fn = $fn !== '' ? $fn : '@' . $r['u_name'];
                $st = (string) $r['status'];
            ?>
            <div class="adm-urow adm-fdrow" data-claim="<?php echo (int) $r['id']; ?>">
                <div class="adm-ucell adm-ucell--user"><span class="adm-uinfo"><a class="adm-uinfo__name" href="/admin/user/<?php echo (int) $r['user_id']; ?>"><?php echo $e($fn); ?></a><span class="adm-uinfo__meta">@<?php echo $e($r['u_name']); ?> &middot; <?php echo $e($r['user_email']); ?></span></span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($fd_day($r['claimed_at'])); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($fd_day($r['activated_at'])); ?></div>
                <div class="adm-ucell"><span class="adm-pill <?php echo $e($fd_tags[$st] ?? ''); ?>"><?php echo $e(ucfirst($st)); ?></span></div>
                <div class="adm-ucell adm-ucell--muted"><?php if ((string) ($r['testimonial_text'] ?? '') !== ''): ?><span class="adm-uinfo"><span>&ldquo;<?php echo $e($r['testimonial_text']); ?>&rdquo;</span><span class="adm-uinfo__meta"><?php echo !empty($r['testimonial_consent']) ? 'May show name and handle' : 'Anonymous only'; ?> &middot; <?php echo $e($fd_day($r['testimonial_submitted_at'])); ?></span></span><?php else: ?><?php echo (string) $r['testimonial_requested_at'] !== '' ? 'Asked ' . $e($fd_day($r['testimonial_requested_at'])) : 'Not asked yet'; ?><?php endif; ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Founding actions', array(
                    ($st === 'active' && (string) $r['testimonial_requested_at'] === '') ? array('text' => 'Send Testimonial Request', 'attrs' => 'data-founding-action="testimonial"') : null,
                    $st !== 'refused' ? array('text' => 'Mark Refused', 'attrs' => 'data-founding-action="refuse"', 'danger' => true) : null,
                )); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
