        </div>
    </div>

    <?php if ((int) Session::get('user_id') > 0): ?>
    <?php // A trial started with no card holds most of its AI credits until a card is added: the credit links say so (0 for everyone else)
          $cls_held = 0; try { $cls_acct = BillingService::account(Permissions::creator_id()); if (BillingService::in_no_card_trial($cls_acct)) { $cls_held = (int) $cls_acct['trial_credits_held']; } } catch (\Throwable $e) {} ?>
    <script>window.CLS_TRIAL_HELD = <?php echo (int) $cls_held; ?>;
    /* "Buy AI Credits" for most accounts; during a no-card trial, adding a card (no charge) unlocks the credits it holds. */
    window.cls_credits_link = function (short) {
        var held = parseInt(window.CLS_TRIAL_HELD, 10) || 0;
        if (held > 0) { return '<a class="cls-unlock" href="/account/billing?add_card=1">' + (short ? 'Not enough left. ' : '') + 'Add a Card to Unlock ' + held.toLocaleString() + ' More AI Credits</a>'; }
        return null;
    };</script>
    <?php /* Feedback dialog, opened from the Feedback link in the top bar on every signed-in page (public/js/feedback.js). */ ?>
    <div class="modal fade fb-modal" id="feedback_modal" tabindex="-1" aria-hidden="true" aria-labelledby="feedback_title">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="feedback_title">Send feedback</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <span class="fb-modal__label" id="feedback_kind_label">Type</span>
                    <div class="fb-kinds" role="radiogroup" aria-labelledby="feedback_kind_label">
                        <?php foreach (array('idea' => array('Idea', 'fa-regular fa-lightbulb'), 'problem' => array('Problem', 'fa-solid fa-bug'), 'praise' => array('Praise', 'fa-regular fa-thumbs-up'), 'other' => array('Other', 'fa-regular fa-comment')) as $fb_k => $fb_v): ?>
                        <button type="button" class="fb-kind" role="radio" aria-checked="false" data-kind="<?php echo $fb_k; ?>"><i class="<?php echo $fb_v[1]; ?>" aria-hidden="true"></i><span><?php echo $fb_v[0]; ?></span></button>
                        <?php endforeach; ?>
                    </div>
                    <label class="fb-modal__label" for="feedback_message">Your Feedback</label>
                    <textarea class="form-control fb-modal__text" id="feedback_message" rows="6"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="feedback_send">Send Feedback</button>
                </div>
            </div>
        </div>
    </div>
    <script src="/js/feedback.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/feedback.js'); ?>"></script>
    <script src="/js/send-media.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/send-media.js'); ?>"></script>
    <!-- Messages live on /inbox. This keeps the sidebar unread count fresh and gives other pages one hook to open a thread. -->
    <script>
    (function () {
        if (typeof window.jQuery === 'undefined' || typeof window.ApiDataSvc === 'undefined') { return; }
        var $ = window.jQuery, $badge = $('#appInboxBadge');
        function badge(){ if(!$badge.length){ return; } ApiDataSvc.apiCall('post','message_unread_count',{},function(r){ var o=null; try{o=JSON.parse(r);}catch(e){} var n=(o&&o.count)?o.count:0; if(n>0){ $badge.text(n>99?'99+':n).prop('hidden',false); } else { $badge.prop('hidden',true); } }); }
        function stash(preset){ try { sessionStorage.setItem('cls_inbox_preset', JSON.stringify(preset || {})); } catch (e) {} }
        window.CLSMessenger = window.CLSMessenger || {
            open: function(){ window.location.href = '/inbox'; },
            openWith: function(uid, preset){ uid = parseInt(uid, 10) || 0; if (uid <= 0) { return; } if (preset) { stash(preset); } window.location.href = '/inbox/with/' + uid; },
            compose: function(preset){ if (preset && preset.assets && preset.assets.length && window.CLSSendMedia) { window.CLSSendMedia.open(preset.assets); return; } stash(preset); window.location.href = '/inbox/new'; },
            badge: badge
        };
        badge();
        setInterval(badge, 20000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
