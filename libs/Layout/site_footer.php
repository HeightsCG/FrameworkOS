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
