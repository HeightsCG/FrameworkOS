<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/support — the help-desk list with its status filters; rows open /support/ticket/<id>: public/js/admin.js ([data-sup]). */
$sup_status = array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed');
$sup_counts = array('open' => 0, 'answered' => 0, 'closed' => 0);
foreach ((array) $this->support as $st) { if (isset($sup_counts[$st['status']])) { $sup_counts[$st['status']]++; } }
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Support</h1><p class="adm-head__sub"><?php echo $sup_counts['open']; ?> waiting on us · <?php echo $sup_counts['answered']; ?> replied · <?php echo $sup_counts['closed']; ?> closed</p></div>
</header>
    <section class="adm-group adm-panel adm-box" data-group="support" data-panel="support">
        <header class="adm-box__h"><h2 class="adm-box__t">Support <b class="adm-count" data-count="support"><?php echo $sup_counts['open']; ?></b></h2>
            <div class="adm-seg" role="tablist" aria-label="Support status">
                <button type="button" class="adm-seg__b is-on" role="tab" aria-selected="true" data-sup="open">Waiting on us <b><?php echo $sup_counts['open']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-sup="answered">Replied <b><?php echo $sup_counts['answered']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-sup="closed">Closed <b><?php echo $sup_counts['closed']; ?></b></button>
                <button type="button" class="adm-seg__b" role="tab" aria-selected="false" data-sup="all">All <b><?php echo count((array) $this->support); ?></b></button>
            </div>
        </header>
        <table class="adm-t">
            <thead><tr><th>Request</th><th>From</th><th>Topic</th><th class="adm-r">Messages</th><th>Last activity</th><th>Status</th></tr></thead>
            <tbody id="admSupport">
            <?php foreach ((array) $this->support as $st): $nm = trim((string) $st['first_name'] . ' ' . (string) $st['last_name']); ?>
                <tr class="adm-suprow adm-t__link" data-href="/support/ticket/<?php echo (int) $st['id']; ?>" data-status="<?php echo $e($st['status']); ?>"<?php echo $st['status'] !== 'open' ? ' hidden' : ''; ?>>
                    <td class="adm-t__main"><a href="/support/ticket/<?php echo (int) $st['id']; ?>"><?php echo $e($st['subject']); ?></a></td>
                    <td class="adm-t__who"><?php echo adm_who($nm, $st['u_name'], '/admin/user/' . (int) $st['user_id']); ?></td>
                    <td class="adm-t__muted"><?php echo $e(SupportModel::CATEGORIES[$st['category']] ?? 'Something else'); ?></td>
                    <td class="adm-r adm-t__num adm-t__muted"><?php echo (int) $st['message_count']; ?></td>
                    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($st['last_message_at'], true)); ?></td>
                    <td><?php echo adm_pill($sup_status[$st['status']] ?? $st['status'], $st['status'] === 'open' ? 'warn' : ($st['status'] === 'answered' ? 'ok' : 'gray')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="adm-quiet" id="admSupportNone"<?php echo $sup_counts['open'] > 0 ? ' hidden' : ''; ?>>Nothing here.</p>
    </section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
