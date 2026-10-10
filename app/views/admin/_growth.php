<?php
/* Admin > Growth > Funnel: the signup funnel by UTC day, by first-touch source, referred signups, free creators building. Demo accounts are
   left out. Every period (7 / 30 / 90 days) is rendered here; the period switch just shows one set. */
$g_pct = function ($n, $of) { return $of > 0 ? round($n * 100 / $of) . '%' : '0%'; };
$g_cell = function ($n, $of) use ($g_pct) { return '<td class="adm-r adm-t__num' . ((int) $n === 0 ? ' adm-t__muted' : '') . '">' . $g_pct((int) $n, (int) $of) . ' <span class="adm-t__faint">' . (int) $n . '</span></td>'; };
$g_sum = function ($rows) { $t = array('signups' => 0, 'verified' => 0, 'became_creator' => 0, 'paid' => 0, 'referred' => 0); foreach ($rows as $r) { foreach ($t as $k => $v) { $t[$k] += (int) $r[$k]; } } return $t; };
?>
<section class="adm-panel" data-panel="growth">
    <div class="adm-toolbar">
        <div class="adm-seg adm-gperiod" role="tablist" aria-label="Period">
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-g="7">7 days</button>
            <button type="button" class="adm-seg__b is-on" role="tab" aria-selected="true" data-g="30">30 days</button>
            <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-g="90">90 days</button>
        </div>
        <?php foreach (array(7, 30, 90) as $gd): ?>
        <span class="adm-toolbar__note fz-period__range" data-g="<?php echo $gd; ?>"<?php echo $gd !== 30 ? ' hidden' : ''; ?>><?php echo gmdate('M j', time() - ($gd - 1) * 86400) . ' to ' . gmdate('M j, Y'); ?> (UTC)</span>
        <?php endforeach; ?>
    </div>

    <?php if (empty($this->growth)): ?>
        <?php echo adm_empty('Growth data unavailable', 'fa-chart-line'); ?>
    <?php endif; ?>

    <?php foreach ((array) $this->growth as $gd => $gs): $gt = $g_sum($gs['days']); ?>
    <div class="adm-gset" data-g="<?php echo (int) $gd; ?>"<?php echo (int) $gd !== 30 ? ' hidden' : ''; ?>>
        <section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Signups by day</h2></header>
        <?php if (empty($gs['days'])): ?>
            <?php echo adm_empty('No signups in this period', 'fa-user-plus'); ?>
        <?php else: ?>
        <table class="adm-t adm-t--num">
            <thead><tr><th>Day</th><th class="adm-r">Signups</th><th class="adm-r">Verified</th><th class="adm-r">Creators</th><th class="adm-r">Paid</th><th class="adm-r">Referred</th></tr></thead>
            <tbody>
                <?php foreach ($gs['days'] as $r): ?>
                <tr><td class="adm-t__nowrap"><?php echo $e(gmdate('D, M j', strtotime($r['day'] . ' 00:00:00 UTC'))); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo (int) $r['signups']; ?></td><?php echo $g_cell($r['verified'], $r['signups']) . $g_cell($r['became_creator'], $r['signups']) . $g_cell($r['paid'], $r['signups']) . $g_cell($r['referred'], $r['signups']); ?></tr>
                <?php endforeach; ?>
                <tr class="adm-t__total"><td>Total</td><td class="adm-r adm-t__num"><?php echo $gt['signups']; ?></td><?php echo $g_cell($gt['verified'], $gt['signups']) . $g_cell($gt['became_creator'], $gt['signups']) . $g_cell($gt['paid'], $gt['signups']) . $g_cell($gt['referred'], $gt['signups']); ?></tr>
            </tbody>
        </table>
        <?php endif; ?>
        </section>

        <section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Signups by source</h2></header>
        <?php if (empty($gs['sources'])): ?>
            <?php echo adm_empty('No signups in this period', 'fa-user-plus'); ?>
        <?php else: ?>
        <table class="adm-t adm-t--num">
            <thead><tr><th>Source</th><th>Medium</th><th class="adm-r">Signups</th><th class="adm-r">Verified</th><th class="adm-r">Creators</th><th class="adm-r">Paid</th><th class="adm-r">Referred</th></tr></thead>
            <tbody>
                <?php foreach ($gs['sources'] as $r): ?>
                <tr><td><?php echo $e($r['source'] === 'direct' ? 'Direct' : $r['source']); ?></td><td class="adm-t__muted"><?php echo $e($r['medium'] !== '' ? $r['medium'] : '—'); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo (int) $r['signups']; ?></td><?php echo $g_cell($r['verified'], $r['signups']) . $g_cell($r['became_creator'], $r['signups']) . $g_cell($r['paid'], $r['signups']) . $g_cell($r['referred'], $r['signups']); ?></tr>
                <?php endforeach; ?>
                <tr class="adm-t__total"><td>Total</td><td></td><td class="adm-r adm-t__num"><?php echo $gt['signups']; ?></td><?php echo $g_cell($gt['verified'], $gt['signups']) . $g_cell($gt['became_creator'], $gt['signups']) . $g_cell($gt['paid'], $gt['signups']) . $g_cell($gt['referred'], $gt['signups']); ?></tr>
            </tbody>
        </table>
        <?php endif; ?>
        </section>

        <section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Referred signups</h2></header>
        <?php if (empty($gs['referred'])): ?>
            <?php echo adm_empty('No referred signups in this period', 'fa-user-plus'); ?>
        <?php else: ?>
        <table class="adm-t">
            <thead><tr><th>Account</th><th>Signed up</th><th>Verified</th><th>Role</th><th>Paid</th><th>Referred by</th></tr></thead>
            <tbody>
                <?php foreach ($gs['referred'] as $r): ?>
                <tr class="adm-t__link" data-href="/admin/user/<?php echo (int) $r['user_id']; ?>">
                    <td class="adm-t__who"><?php echo adm_who('@' . $r['u_name'], $r['u_name'], '/admin/user/' . (int) $r['user_id'], '', (string) $r['user_email']); ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($r['created_at'], true)); ?></td>
                    <td><?php echo adm_pill((int) $r['email_verified'] === 1 ? 'Verified' : 'Not verified', (int) $r['email_verified'] === 1 ? 'ok' : 'gray'); ?></td>
                    <td class="adm-t__muted"><?php echo $e((string) ($r['role_name'] ?? '') !== '' ? $r['role_name'] : 'User'); ?></td>
                    <td><?php echo adm_pill((int) $r['paid'] === 1 ? 'Paid' : 'Free', (int) $r['paid'] === 1 ? 'ok' : 'gray'); ?></td>
                    <td><?php if ((string) ($r['referrer_u_name'] ?? '') !== ''): ?><a href="/admin/user/<?php echo (int) $r['referrer_id']; ?>">@<?php echo $e($r['referrer_u_name']); ?></a><?php else: ?><span class="adm-t__muted">—</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </section>

        <?php /* build before you pay: Free creators who started building, and how many upgraded (AdminModel::free_builders) */
        $gb_rows = (array) ($gs['builders'] ?? array());
        $gb_t = array('creators' => 0, 'built' => 0, 'upgraded' => 0, 'still_free' => 0, 'paid_direct' => 0);
        foreach ($gb_rows as $r) { foreach ($gb_t as $k => $v) { $gb_t[$k] += (int) $r[$k]; } } ?>
        <section class="adm-box"><header class="adm-box__h"><h2 class="adm-box__t">Free creators building</h2></header>
        <?php if (empty($gb_rows)): ?>
            <?php echo adm_empty('No new creators in this period', 'fa-user-plus'); ?>
        <?php else: ?>
        <table class="adm-t adm-t--num">
            <thead><tr><th>Day</th><th class="adm-r">Creators</th><th class="adm-r">Built on Free</th><th class="adm-r">Upgraded</th><th class="adm-r">Still on Free</th><th class="adm-r">Paid first</th></tr></thead>
            <tbody>
                <?php foreach ($gb_rows as $r): ?>
                <tr><td class="adm-t__nowrap"><?php echo $e(gmdate('D, M j', strtotime($r['day'] . ' 00:00:00 UTC'))); ?></td><td class="adm-r adm-t__num adm-t__strong"><?php echo (int) $r['creators']; ?></td><?php echo $g_cell($r['built'], $r['creators']) . $g_cell($r['upgraded'], $r['creators']) . $g_cell($r['still_free'], $r['creators']) . $g_cell($r['paid_direct'], $r['creators']); ?></tr>
                <?php endforeach; ?>
                <tr class="adm-t__total"><td>Total</td><td class="adm-r adm-t__num"><?php echo $gb_t['creators']; ?></td><?php echo $g_cell($gb_t['built'], $gb_t['creators']) . $g_cell($gb_t['upgraded'], $gb_t['creators']) . $g_cell($gb_t['still_free'], $gb_t['creators']) . $g_cell($gb_t['paid_direct'], $gb_t['creators']); ?></tr>
            </tbody>
        </table>
        <?php endif; ?>
        </section>
    </div>
    <?php endforeach; ?>
    <?php include __DIR__ . '/_promo_setting.php'; ?>
</section>
