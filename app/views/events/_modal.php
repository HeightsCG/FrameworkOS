<?php /* Create / Edit Event modal — the approved section-editor shell. Set $editing = true on the event page. Expects $e, $tz, $tiers. */ ?>
<div class="modal fade cs-ae-modal ev-editor" id="eventModal" tabindex="-1" aria-hidden="true" aria-labelledby="evModalTitle">
    <div class="modal-dialog cs-ae">
        <div class="modal-content cs-ae__surface">
            <header class="cs-ae__header">
                <div class="cs-ae__heading">
                    <span class="cs-ae__eyebrow">Event</span>
                    <div class="cs-ae__titlerow"><h2 class="cs-ae__title" id="evModalTitle"><?php echo empty($editing) ? 'New Event' : 'Edit Event'; ?></h2></div>
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
                <label class="evm-live" for="evCreateLive">
                    <span class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="evCreateLive" checked></span>
                    <span class="evm-live__label">Live now</span>
                </label>
                <?php endif; ?>
                <div class="cs-ae__actions">
                    <button type="button" class="btn cs-ae__btn cs-ae__btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary cs-ae__btn" id="ev_save"><?php echo empty($editing) ? 'Create Event' : 'Save Changes'; ?></button>
                </div>
            </footer>
        </div>
    </div>
</div>
