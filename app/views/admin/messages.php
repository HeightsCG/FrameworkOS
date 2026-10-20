<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/messages — the Creator Link Studio team inbox. The list and the thread load from the API (admin-messages.js); ?c=<id> opens one. */ ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Messages</h1><p class="adm-head__sub">Conversations between users and <?php echo $e(PlatformAccount::NAME); ?></p></div>
    <?php if ($this->team_ok): ?><button type="button" class="adm-btn adm-btn--primary" data-message-new><i class="fa-solid fa-plus" aria-hidden="true"></i> New Message</button><?php endif; ?>
</header>
<?php if (!$this->team_ok): ?>
<section class="adm-box"><?php echo adm_empty('The Creator Link Studio team account is missing. Run sql/2026-10-18_platform_account.sql.', 'fa-envelope'); ?></section>
<?php else: ?>
<section class="adm-box adm-inbox" id="admInbox" data-open="<?php echo (int) $this->open_c; ?>">
    <aside class="adm-inbox__list" aria-label="Conversations">
        <div class="adm-inbox__items" id="admInboxItems" aria-busy="true"></div>
    </aside>
    <div class="adm-inbox__pane" id="admInboxPane">
        <div class="adm-inbox__none" id="admInboxNone"><?php echo adm_empty('Pick a conversation', 'fa-comments'); ?></div>
        <div class="adm-inbox__thread" id="admInboxThread" hidden>
            <header class="adm-inbox__head">
                <button type="button" class="adm-inbox__back" id="admInboxBack" aria-label="Back to conversations"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>
                <span id="admInboxWho"></span>
                <a class="adm-btn adm-btn--sm" id="admInboxAccount" href="#">Open Account</a>
            </header>
            <div class="adm-inbox__msgs" id="admInboxMsgs" aria-live="polite"></div>
            <form class="adm-inbox__compose" id="admInboxCompose">
                <label class="visually-hidden" for="admInboxBody">Reply</label>
                <textarea class="form-control" id="admInboxBody" rows="2" placeholder="Reply as <?php echo $e(PlatformAccount::NAME); ?>"></textarea>
                <button type="submit" class="adm-btn adm-btn--primary" id="admInboxSend" disabled>Send</button>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<?php include __DIR__ . '/_message_modal.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-messages.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-messages.js'); ?>"></script>
