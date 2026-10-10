<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/moderation — flagged and unscanned media. Approve / Block: public/js/admin.js (#admMod). */
$queue = (array) $this->queue;
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Moderation</h1><p class="adm-head__sub"><?php echo count($queue) > 0 ? count($queue) . ' image' . (count($queue) === 1 ? '' : 's') . ' to review' : 'Nothing to review'; ?></p></div>
</header>
    <section class="adm-group adm-panel adm-box" data-group="moderation" data-panel="moderation">
        <header class="adm-box__h"><h2 class="adm-box__t">Moderation <b class="adm-count" data-count="moderation"><?php echo count($queue); ?></b></h2></header>
        <?php if (empty($queue)): ?>
            <?php echo adm_empty('Nothing to review', 'fa-image'); ?>
        <?php else: ?>
        <div class="adm-mod" id="admMod">
            <?php foreach ($queue as $a): ?>
            <div class="adm-card" data-asset="<?php echo (int) $a['id']; ?>">
                <div class="adm-card__img" style="background-image:url('<?php echo $e($a['thumb']); ?>')" data-full="<?php echo $e($a['full']); ?>" role="button" tabindex="0" aria-label="View larger">
                    <span class="adm-card__badge"><?php echo adm_pill($a['status'] === 'flagged' ? 'Flagged' : 'Unscanned'); ?></span>
                </div>
                <div class="adm-card__body">
                    <a class="adm-card__creator" href="/@<?php echo $e($a['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($a['creator_handle']); ?></a>
                    <?php if ($a['status'] === 'flagged' && ($a['labels'] !== '' || $a['score'] !== null)): ?><span class="adm-card__ai"><?php echo $a['labels'] !== '' ? $e($a['labels']) : 'adult'; ?><?php echo $a['score'] !== null ? ' · ' . number_format($a['score'] * 100) . '%' : ''; ?></span><?php endif; ?>
                    <span class="adm-card__when"><?php echo $e($fmt($a['created_at'], true)); ?></span>
                </div>
                <div class="adm-card__acts">
                    <button type="button" class="adm-btn adm-btn--sm" data-mod="approve">Approve</button>
                    <button type="button" class="adm-btn adm-btn--sm adm-btn--danger" data-mod="block">Block</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
