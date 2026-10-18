<?php /* Message a user as Creator Link Studio: opened by any [data-message-user] button (user page header, Users row menu). admin-messages.js sends it. */ ?>
<div class="modal fade" id="admMsgModal" tabindex="-1" aria-hidden="true" aria-labelledby="admMsgTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="admMsgTitle">Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="adm-msgm__from">From <b><?php echo htmlspecialchars(PlatformAccount::NAME, ENT_QUOTES, 'UTF-8'); ?></b> to <b id="admMsgTo"></b>, in their Inbox and by email</p>
                <div class="adm-field"><label for="admMsgBody">Message</label><textarea class="form-control" id="admMsgBody" rows="6"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="adm-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="adm-btn adm-btn--primary" id="admMsgSend">Send Message</button>
            </div>
        </div>
    </div>
</div>
