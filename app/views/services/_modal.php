<?php /* Create / Edit Service modal — the approved section-editor shell (same as events). Set $editing = true on the service page. Expects $e ($sv when editing). */ ?>
<div class="modal fade cs-ae-modal ev-editor" id="serviceModal" tabindex="-1" aria-hidden="true" aria-labelledby="svModalTitle">
    <div class="modal-dialog cs-ae">
        <div class="modal-content cs-ae__surface">
            <header class="cs-ae__header">
                <div class="cs-ae__heading">
                    <span class="cs-ae__eyebrow">Service</span>
                    <div class="cs-ae__titlerow"><h2 class="cs-ae__title" id="svModalTitle"><?php echo empty($editing) ? 'New Service' : 'Edit Service'; ?></h2></div>
                    <?php if (!empty($editing)): ?><p class="ev-editor__context" id="svModalSub"><?php echo $e(html_entity_decode((string) ($sv['name'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                </div>
                <div class="cs-ae__headtools">
                    <button type="button" class="btn-close cs-ae__close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </header>
            <div class="cs-ae__body">
                <?php require __DIR__ . '/_form.php'; ?>
            </div>
            <footer class="cs-ae__footer">
                <?php if (empty($editing)): ?>
                <label class="evm-live" for="svCreateLive">
                    <span class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="svCreateLive" checked></span>
                    <span class="evm-live__label">Live now</span>
                </label>
                <?php endif; ?>
                <div class="cs-ae__actions">
                    <button type="button" class="btn cs-ae__btn cs-ae__btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary cs-ae__btn" id="sv_save"><?php echo empty($editing) ? 'Create Service' : 'Save Changes'; ?></button>
                </div>
            </footer>
        </div>
    </div>
</div>
