<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/verification — identity requests (approve / reject) and Didit age checks (reset): public/js/admin.js (#admVerif, #admAge). */
$ac = (array) $this->age_counts;
$av_label = array('pending' => 'Pending', 'verified' => 'Verified', 'failed' => 'Failed');
$n = array('verification' => count((array) $this->verifications) + (int) $ac['pending']);
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Verification</h1><p class="adm-head__sub"><?php echo count((array) $this->verifications); ?> identity request<?php echo count((array) $this->verifications) === 1 ? '' : 's'; ?> · <?php echo (int) $ac['pending']; ?> age check<?php echo (int) $ac['pending'] === 1 ? '' : 's'; ?> pending</p></div>
</header>
    <section class="adm-group adm-panel adm-box" data-group="verification" data-panel="verification">
        <header class="adm-box__h"><h2 class="adm-box__t">Verification <b class="adm-count" data-count="verification"><?php echo $n['verification']; ?></b></h2></header>
        <h3 class="adm-h3">Identity requests <span><?php echo count((array) $this->verifications); ?></span></h3>
        <?php if (empty($this->verifications)): ?>
            <p class="adm-quiet">No pending identity requests.</p>
        <?php else: ?>
        <table class="adm-t">
            <thead><tr><th>Creator</th><th>Legal name</th><th>Note</th><th>When</th><th></th></tr></thead>
            <tbody id="admVerif">
            <?php foreach ($this->verifications as $v): $vname = trim((string) ($v['name'] ?? '')); $vname = $vname !== '' ? $vname : ('@' . $v['u_name']); ?>
                <tr class="adm-vrow adm-t__link" data-verif="<?php echo (int) $v['id']; ?>" data-href="/admin/user/<?php echo (int) $v['user_id']; ?>">
                    <td class="adm-t__who"><?php echo adm_who($vname, $v['u_name'], '/admin/user/' . (int) $v['user_id']); ?></td>
                    <td><?php echo $e($v['full_name'] !== '' ? $v['full_name'] : '—'); ?></td>
                    <td class="adm-t__muted adm-t__wrap"><?php echo $v['note'] !== '' ? $e(mb_substr((string) $v['note'], 0, 120)) : '—'; ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($v['created_at'], true)); ?></td>
                    <td class="adm-t__act"><?php echo adm_row_menu('Verification actions', array(
                        array('text' => 'Approve', 'attrs' => 'data-verif-action="approve"'),
                        array('text' => 'Reject…', 'attrs' => 'data-verif-action="reject"', 'danger' => true),
                    )); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="adm-quiet" id="admVerifNone" hidden>No pending requests.</p>
        <?php endif; ?>

        <div class="adm-box__sub"><h3 class="adm-h3">Age checks <span><?php echo (int) $ac['pending']; ?> pending</span></h3>
            <div class="adm-seg" role="tablist" aria-label="Age check status" id="admAgeTabs">
                <button type="button" class="adm-seg__b is-on" role="tab" aria-selected="true" data-age="open">Needs a look <b><?php echo (int) $ac['pending'] + (int) $ac['failed']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="pending">Pending <b><?php echo (int) $ac['pending']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="failed">Failed <b><?php echo (int) $ac['failed']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="verified">Verified <b><?php echo (int) $ac['verified']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-age="all">All <b><?php echo count((array) $this->age_rows); ?></b></button>
            </div>
        </div>
        <table class="adm-t">
            <thead><tr><th>Account</th><th>Status</th><th>Provider</th><th>Adult content</th><th>Updated</th><th></th></tr></thead>
            <tbody id="admAge">
            <?php foreach ((array) $this->age_rows as $av): $an = trim((string) $av['name']); $an = $an !== '' ? $an : ('@' . $av['u_name']); $st = (string) $av['status']; ?>
                <tr class="adm-agerow adm-t__link" data-age-row="<?php echo (int) $av['user_id']; ?>" data-status="<?php echo $e($st); ?>" data-href="/admin/user/<?php echo (int) $av['user_id']; ?>"<?php echo $st === 'verified' ? ' hidden' : ''; ?>>
                    <td class="adm-t__who"><?php echo adm_who($an, $av['u_name'], '/admin/user/' . (int) $av['user_id'], '', '@' . $av['u_name'] . ' · ' . ($av['role_name'] ?: 'User')); ?></td>
                    <td><?php echo adm_pill($av_label[$st] ?? ucfirst($st)); ?><?php if ($st === 'verified' && (string) $av['verified_at'] !== ''): ?> <span class="adm-t__muted"><?php echo $e($fmt($av['verified_at'])); ?></span><?php endif; ?></td>
                    <td class="adm-t__muted"><?php echo $e(ucfirst((string) $av['provider'])); ?></td>
                    <td class="adm-t__muted"><?php echo !empty($av['adult_content_enabled']) ? 'Shown' : 'Hidden'; ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($av['updated_at'], true)); ?></td>
                    <td class="adm-t__act"><?php echo adm_row_menu('Age check actions', array(array('text' => 'Reset age verification…', 'attrs' => 'data-age-reset', 'danger' => true))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="adm-quiet" id="admAgeNone"<?php echo ((int) $ac['pending'] + (int) $ac['failed']) > 0 ? ' hidden' : ''; ?>>No age checks need a look.</p>
    </section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
