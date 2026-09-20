        </div>
    </div>

    <?php if ((int) Session::get('user_id') > 0): ?>
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
            compose: function(preset){ stash(preset); window.location.href = '/inbox/new'; },
            badge: badge
        };
        badge();
        setInterval(badge, 20000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
