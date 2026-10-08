<?php
/* Admin > Growth: the signup funnel by UTC day, by first-touch source, and the referred signups. Demo accounts are left out.
   Every period (7 / 30 / 90 days) is rendered here; the period switch just shows one set. */
$g_pct = function ($n, $of) { return $of > 0 ? round($n * 100 / $of) . '%' : '0%'; };
$g_cell = function ($n, $of) use ($g_pct) { return '<span class="adm-ucell' . ((int) $n === 0 ? ' adm-ucell--muted' : '') . '">' . $g_pct((int) $n, (int) $of) . ' <span class="adm-grow__n">' . (int) $n . '</span></span>'; };
$g_sum = function ($rows) { $t = array('signups' => 0, 'verified' => 0, 'became_creator' => 0, 'paid' => 0, 'referred' => 0); foreach ($rows as $r) { foreach ($t as $k => $v) { $t[$k] += (int) $r[$k]; } } return $t; };
?>
<section class="adm-sec adm-panel" data-panel="growth">
    <div class="fz-top">
        <div class="adm-gperiod" role="tablist" aria-label="Period">
            <button type="button" role="tab" aria-selected="false" data-g="7">Last 7 Days</button>
            <button type="button" class="is-on" role="tab" aria-selected="true" data-g="30">Last 30 Days</button>
            <button type="button" role="tab" aria-selected="false" data-g="90">Last 90 Days</button>
        </div>
        <?php foreach (array(7, 30, 90) as $gd): ?>
        <span class="fz-period__range" data-g="<?php echo $gd; ?>"<?php echo $gd !== 30 ? ' hidden' : ''; ?>><?php echo gmdate('M j', time() - ($gd - 1) * 86400) . ' to ' . gmdate('M j, Y'); ?> (UTC)</span>
        <?php endforeach; ?>
    </div>

    <?php if (empty($this->growth)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-arrow-trend-up"></i></span><p class="adm-empty__t">Growth Data Unavailable</p></div>
    <?php endif; ?>

    <?php foreach ((array) $this->growth as $gd => $gs): $gt = $g_sum($gs['days']); ?>
    <div class="adm-gset" data-g="<?php echo (int) $gd; ?>"<?php echo (int) $gd !== 30 ? ' hidden' : ''; ?>>
        <div class="adm-sec__head"><h2 class="adm-sec__title">Signups by Day</h2></div>
        <?php if (empty($gs['days'])): ?>
            <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-user-plus"></i></span><p class="adm-empty__t">No Signups in This Period</p></div>
        <?php else: ?>
        <div class="adm-table adm-table--gdays">
            <div class="adm-table__head"><span>Day</span><span>Signups</span><span>Verified</span><span>Creators</span><span>Paid</span><span>Referred</span></div>
            <div class="adm-table__body">
                <?php foreach ($gs['days'] as $r): ?>
                <div class="adm-grow"><span class="adm-ucell"><?php echo $e(gmdate('D, M j', strtotime($r['day'] . ' 00:00:00 UTC'))); ?></span><span class="adm-ucell"><b><?php echo (int) $r['signups']; ?></b></span><?php echo $g_cell($r['verified'], $r['signups']) . $g_cell($r['became_creator'], $r['signups']) . $g_cell($r['paid'], $r['signups']); ?><span class="adm-ucell<?php echo (int) $r['referred'] === 0 ? ' adm-ucell--muted' : ''; ?>"><?php echo (int) $r['referred']; ?></span></div>
                <?php endforeach; ?>
                <div class="adm-grow adm-frow--total"><span class="adm-ucell"><b>Total</b></span><span class="adm-ucell"><b><?php echo $gt['signups']; ?></b></span><?php echo $g_cell($gt['verified'], $gt['signups']) . $g_cell($gt['became_creator'], $gt['signups']) . $g_cell($gt['paid'], $gt['signups']); ?><span class="adm-ucell"><b><?php echo $gt['referred']; ?></b></span></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="adm-sec__head" style="margin-top:1.6rem;"><h2 class="adm-sec__title">Signups by Source</h2></div>
        <?php if (empty($gs['sources'])): ?>
            <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-signs-post"></i></span><p class="adm-empty__t">No Signups in This Period</p></div>
        <?php else: ?>
        <div class="adm-table adm-table--gsrc">
            <div class="adm-table__head"><span>Source</span><span>Medium</span><span>Signups</span><span>Verified</span><span>Creators</span><span>Paid</span><span>Referred</span></div>
            <div class="adm-table__body">
                <?php foreach ($gs['sources'] as $r): ?>
                <div class="adm-grow"><span class="adm-ucell"><?php echo $e($r['source'] === 'direct' ? 'Direct' : $r['source']); ?></span><span class="adm-ucell adm-ucell--muted"><?php echo $e($r['medium'] !== '' ? $r['medium'] : 'None'); ?></span><span class="adm-ucell"><b><?php echo (int) $r['signups']; ?></b></span><?php echo $g_cell($r['verified'], $r['signups']) . $g_cell($r['became_creator'], $r['signups']) . $g_cell($r['paid'], $r['signups']); ?><span class="adm-ucell<?php echo (int) $r['referred'] === 0 ? ' adm-ucell--muted' : ''; ?>"><?php echo (int) $r['referred']; ?></span></div>
                <?php endforeach; ?>
                <div class="adm-grow adm-frow--total"><span class="adm-ucell"><b>Total</b></span><span class="adm-ucell"></span><span class="adm-ucell"><b><?php echo $gt['signups']; ?></b></span><?php echo $g_cell($gt['verified'], $gt['signups']) . $g_cell($gt['became_creator'], $gt['signups']) . $g_cell($gt['paid'], $gt['signups']); ?><span class="adm-ucell"><b><?php echo $gt['referred']; ?></b></span></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="adm-sec__head" style="margin-top:1.6rem;"><h2 class="adm-sec__title">Referred Signups</h2></div>
        <?php if (empty($gs['referred'])): ?>
            <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-user-group"></i></span><p class="adm-empty__t">No Referred Signups in This Period</p></div>
        <?php else: ?>
        <div class="adm-table adm-table--gref">
            <div class="adm-table__head"><span>Account</span><span>Signed up</span><span>Verified</span><span>Role</span><span>Paid</span><span>Referred by</span></div>
            <div class="adm-table__body">
                <?php foreach ($gs['referred'] as $r): ?>
                <div class="adm-grow">
                    <span class="adm-ucell"><a class="adm-uinfo adm-user-link" href="/admin/user/<?php echo (int) $r['user_id']; ?>"><span class="adm-uinfo__name">@<?php echo $e($r['u_name']); ?></span><span class="adm-uinfo__sub"><?php echo $e($r['user_email']); ?></span></a></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($r['created_at'], true)); ?></span>
                    <span class="adm-ucell"><span class="adm-tag <?php echo (int) $r['email_verified'] === 1 ? 'adm-tag--sup-answered' : 'adm-tag--off'; ?>"><?php echo (int) $r['email_verified'] === 1 ? 'Yes' : 'No'; ?></span></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e((string) ($r['role_name'] ?? '') !== '' ? $r['role_name'] : 'User'); ?></span>
                    <span class="adm-ucell"><span class="adm-tag <?php echo (int) $r['paid'] === 1 ? 'adm-tag--sup-answered' : 'adm-tag--off'; ?>"><?php echo (int) $r['paid'] === 1 ? 'Yes' : 'No'; ?></span></span>
                    <span class="adm-ucell"><?php if ((string) ($r['referrer_u_name'] ?? '') !== ''): ?><a class="adm-user-link" href="/admin/user/<?php echo (int) $r['referrer_id']; ?>">@<?php echo $e($r['referrer_u_name']); ?></a><?php else: ?><span class="adm-ucell--muted">Deleted account</span><?php endif; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php /* build before you pay: Free creators who started building, and how many upgraded (AdminModel::free_builders) */
        $gb_rows = (array) ($gs['builders'] ?? array());
        $gb_t = array('creators' => 0, 'built' => 0, 'upgraded' => 0, 'still_free' => 0, 'paid_direct' => 0);
        foreach ($gb_rows as $r) { foreach ($gb_t as $k => $v) { $gb_t[$k] += (int) $r[$k]; } } ?>
        <div class="adm-sec__head" style="margin-top:1.6rem;"><h2 class="adm-sec__title">Free Creators Building</h2></div>
        <?php if (empty($gb_rows)): ?>
            <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-hammer"></i></span><p class="adm-empty__t">No New Creators in This Period</p></div>
        <?php else: ?>
        <div class="adm-table adm-table--gdays">
            <div class="adm-table__head"><span>Day</span><span>Creators</span><span>Built on Free</span><span>Upgraded</span><span>Still on Free</span><span>Paid First</span></div>
            <div class="adm-table__body">
                <?php foreach ($gb_rows as $r): ?>
                <div class="adm-grow"><span class="adm-ucell"><?php echo $e(gmdate('D, M j', strtotime($r['day'] . ' 00:00:00 UTC'))); ?></span><span class="adm-ucell"><b><?php echo (int) $r['creators']; ?></b></span><?php echo $g_cell($r['built'], $r['creators']) . $g_cell($r['upgraded'], $r['built']) . $g_cell($r['still_free'], $r['built']) . $g_cell($r['paid_direct'], $r['creators']); ?></div>
                <?php endforeach; ?>
                <div class="adm-grow adm-frow--total"><span class="adm-ucell"><b>Total</b></span><span class="adm-ucell"><b><?php echo $gb_t['creators']; ?></b></span><?php echo $g_cell($gb_t['built'], $gb_t['creators']) . $g_cell($gb_t['upgraded'], $gb_t['built']) . $g_cell($gb_t['still_free'], $gb_t['built']) . $g_cell($gb_t['paid_direct'], $gb_t['creators']); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>
