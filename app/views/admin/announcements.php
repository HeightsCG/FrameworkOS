<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/announcements — one message from Creator Link Studio to a whole audience: each user gets it in their Inbox (and can
   reply into the team inbox) and by email, unless they turned off Broadcasts emails. admin-announce.js sends it. */
$labels = (array) $this->labels; $counts = (array) $this->counts; $history = (array) $this->history; $delivered = (array) $this->delivered; ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Announcements</h1><p class="adm-head__sub">One message from <?php echo $e(PlatformAccount::NAME); ?> to every user in an audience, in their Inbox and by email</p></div>
</header>
<?php if (!$this->team_ok): ?>
<section class="adm-box"><?php echo adm_empty('The Creator Link Studio team account is missing. Run sql/2026-10-18_platform_account.sql.', 'fa-bullhorn'); ?></section>
<?php else: ?>
<section class="adm-box adm-ann" id="admAnnounce">
    <div class="adm-field"><span class="adm-field__label" id="admAnnAudL">Audience</span>
        <div class="adm-seg" role="radiogroup" aria-labelledby="admAnnAudL">
            <?php $first = true; foreach ($labels as $k => $l): ?><button type="button" role="radio" class="adm-seg__b<?php echo $first ? ' is-on' : ''; ?>" aria-checked="<?php echo $first ? 'true' : 'false'; ?>" data-segment="<?php echo $e($k); ?>" data-count="<?php echo (int) ($counts[$k] ?? 0); ?>"><?php echo $e($l); ?> <b><?php echo number_format((int) ($counts[$k] ?? 0)); ?></b></button><?php $first = false; endforeach; ?>
        </div>
    </div>
    <div class="adm-field"><label for="admAnnBody">Message</label><textarea class="form-control" id="admAnnBody" rows="7"></textarea></div>
    <footer class="adm-ann__foot">
        <span class="adm-ann__reach" id="admAnnReach" aria-live="polite"></span>
        <button type="button" class="adm-btn adm-btn--primary" id="admAnnSend" disabled>Send Announcement</button>
    </footer>
</section>
<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Sent <b class="adm-count"><?php echo count($history); ?></b></h2></header>
    <?php if (empty($history)): ?><?php echo adm_empty('No announcements yet', 'fa-bullhorn'); ?><?php else: ?>
    <table class="adm-t" id="admAnnHistory" data-sortable>
        <thead><tr><th data-sort="text" data-sorted="desc">Sent</th><th data-sort="text">Audience</th><th data-sort="num">Recipients</th><th data-sort="num">Delivered</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach ($history as $b): $d = (int) ($delivered[(int) $b['id']] ?? 0); $n = (int) $b['recipient_count']; ?>
            <tr>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($b['created_at']); ?>"><?php echo $e($fmt($b['created_at'], true)); ?></td>
                <td class="adm-t__nowrap"><?php echo $e($labels[(string) $b['audience']] ?? (string) $b['audience']); ?></td>
                <td data-value="<?php echo $n; ?>"><?php echo number_format($n); ?></td>
                <td data-value="<?php echo $d; ?>"><?php echo $d >= $n ? number_format($d) : number_format($d) . ' ' . adm_pill('Sending', 'warn'); ?></td>
                <td class="adm-t__muted adm-t__trunc" title="<?php echo $e(html_entity_decode((string) $b['body'], ENT_QUOTES, 'UTF-8')); ?>"><?php echo $e(html_entity_decode((string) $b['body'], ENT_QUOTES, 'UTF-8')); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-announce.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-announce.js'); ?>"></script>
