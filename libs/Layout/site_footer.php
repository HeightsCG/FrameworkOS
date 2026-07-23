        </div>
    </div>

    <?php if ((int) Session::get('user_id') > 0): ?>
    <!-- Floating messenger (PRD §24) — global chrome for every signed-in page. -->
    <div class="msgr" id="msgr">
        <button type="button" class="msgr__toggle" id="msgrToggle" aria-label="Messages">
            <i class="fa-solid fa-comment-dots"></i>
            <span class="msgr__count" id="msgrCount" hidden></span>
        </button>
        <div class="msgr__panel" id="msgrPanel" hidden>
            <header class="msgr__head">
                <button type="button" class="msgr__ico" id="msgrBack" hidden aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
                <span class="msgr__title" id="msgrTitle">Messages</span>
                <button type="button" class="msgr__ico" id="msgrNew" aria-label="New message" title="New message"><i class="fa-solid fa-pen-to-square"></i></button>
                <button type="button" class="msgr__ico" id="msgrClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </header>
            <div class="msgr__inbox" id="msgrInbox">
                <p class="msgr__empty" id="msgrEmpty" hidden>No messages yet. Tap the compose button to message a creator.</p>
            </div>
            <div class="msgr__new" id="msgrNewView" hidden>
                <div class="msgr__modes" id="msgrModes" hidden>
                    <button type="button" class="msgr__mode is-on" data-mode="direct">Direct</button>
                    <button type="button" class="msgr__mode" data-mode="broadcast">Broadcast</button>
                </div>
                <div class="msgr__pane" id="msgrDirect">
                    <div class="msgr__search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="msgrSearch" placeholder="Search people&hellip;" autocomplete="off" maxlength="60">
                    </div>
                    <div class="msgr__people" id="msgrPeople"></div>
                </div>
                <div class="msgr__pane" id="msgrBcast" hidden>
                    <div class="msgr__seg" id="msgrSeg">
                        <button type="button" class="msgr__seg-opt is-on" data-seg="all">Everyone <b id="msgrSegAll">0</b></button>
                        <button type="button" class="msgr__seg-opt" data-seg="followers">Followers <b id="msgrSegFollowers">0</b></button>
                        <button type="button" class="msgr__seg-opt" data-seg="subscribers">Subscribers <b id="msgrSegSubscribers">0</b></button>
                    </div>
                    <form class="msgr__bcast" id="msgrBcastForm">
                        <textarea class="msgr__bcast-body" id="msgrBcastBody" placeholder="Write a message to your audience&hellip;" maxlength="2000"></textarea>
                        <button type="submit" class="msgr__bcast-send" id="msgrBcastSend" disabled>Send to <span id="msgrBcastN">0</span></button>
                    </form>
                </div>
            </div>
            <div class="msgr__thread" id="msgrThread" hidden>
                <div class="msgr__scroll" id="msgrScroll"></div>
                <form class="msgr__compose" id="msgrCompose">
                    <textarea class="msgr__input" id="msgrInput" rows="1" placeholder="Write a message&hellip;" maxlength="2000"></textarea>
                    <button type="submit" class="msgr__send" id="msgrSend" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>

    <style>
    .msgr{ position:fixed; right:22px; bottom:22px; z-index:1500; }
    .msgr__toggle{ position:relative; width:56px; height:56px; border:0; border-radius:50%; background:var(--violet,#5b4be0); color:#fff; font-size:1.35rem; cursor:pointer; box-shadow:0 10px 28px -8px rgba(91,75,224,.6); transition:transform .12s,background .12s; }
    .msgr__toggle:hover{ transform:translateY(-2px); background:var(--violet7,#4636c4); }
    .msgr__count{ position:absolute; top:-2px; right:-2px; min-width:20px; height:20px; padding:0 5px; border-radius:10px; background:#e5484d; color:#fff; font-size:.72rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 0 2px #fff; }
    .msgr__panel{ position:absolute; right:0; bottom:70px; width:370px; max-width:calc(100vw - 44px); height:520px; max-height:calc(100vh - 130px); background:#fff; border:1px solid #e7e7ee; border-radius:16px; box-shadow:0 24px 60px -20px rgba(20,16,40,.4); display:flex; flex-direction:column; overflow:hidden; }
    .msgr__panel[hidden]{ display:none; }
    .msgr__head{ flex:none; display:flex; align-items:center; gap:.5rem; padding:.7rem .85rem; border-bottom:1px solid #eee; min-height:52px; }
    .msgr__title{ flex:1 1 auto; min-width:0; font-size:1rem; font-weight:700; color:#1c1830; overflow:hidden; }
    .msgr__ico{ flex:none; width:30px; height:30px; border:0; border-radius:8px; background:transparent; color:#6b7280; font-size:.95rem; cursor:pointer; }
    .msgr__ico:hover{ background:#f2f1f7; color:#1c1830; }
    .msgr-peer{ display:flex; flex-direction:column; line-height:1.2; text-decoration:none; }
    .msgr-peer__name{ font-size:.95rem; font-weight:700; color:#1c1830; }
    .msgr-peer__sub{ font-size:.75rem; font-weight:500; color:#9a97a8; }
    a.msgr-peer:hover .msgr-peer__name{ color:var(--violet7,#4636c4); }
    .msgr-badge{ color:var(--violet,#5b4be0); font-size:.72rem; }
    .msgr__inbox{ flex:1 1 auto; overflow-y:auto; }
    .msgr__empty{ padding:2.4rem 1.2rem; text-align:center; color:#9a97a8; font-size:.88rem; line-height:1.5; }
    .msgr__new{ flex:1 1 auto; display:flex; flex-direction:column; min-height:0; }
    .msgr__modes{ flex:none; display:flex; gap:.4rem; padding:.55rem .7rem; border-bottom:1px solid #eee; }
    .msgr__mode{ flex:1 1 0; padding:.42rem; border:1px solid #e2e0ea; border-radius:8px; background:#fff; color:#6b7280; font-size:.82rem; font-weight:600; cursor:pointer; }
    .msgr__mode:hover{ color:#1c1830; }
    .msgr__mode.is-on{ background:var(--violet,#5b4be0); border-color:var(--violet,#5b4be0); color:#fff; }
    .msgr__pane{ flex:1 1 auto; display:flex; flex-direction:column; min-height:0; }
    .msgr__pane[hidden]{ display:none; }
    .msgr__search{ flex:none; display:flex; align-items:center; gap:.5rem; padding:.65rem .85rem; border-bottom:1px solid #eee; color:#9a97a8; }
    .msgr__search input{ flex:1 1 auto; min-width:0; border:0; outline:none; background:transparent; font-family:inherit; font-size:.9rem; color:#1c1830; }
    .msgr__people{ flex:1 1 auto; overflow-y:auto; }
    .msgr__seg{ flex:none; display:flex; flex-direction:column; gap:.4rem; padding:.7rem .8rem; border-bottom:1px solid #eee; }
    .msgr__seg-opt{ display:flex; align-items:center; justify-content:space-between; padding:.5rem .7rem; border:1px solid #e2e0ea; border-radius:8px; background:#fff; color:#1c1830; font-size:.85rem; font-weight:600; cursor:pointer; }
    .msgr__seg-opt:hover{ border-color:#c9c5da; }
    .msgr__seg-opt b{ font-size:.78rem; color:#9a97a8; font-weight:700; }
    .msgr__seg-opt.is-on{ border-color:var(--violet,#5b4be0); box-shadow:0 0 0 2px rgba(91,75,224,.12); }
    .msgr__seg-opt.is-on b{ color:var(--violet7,#4636c4); }
    .msgr__bcast{ flex:1 1 auto; display:flex; flex-direction:column; gap:.6rem; padding:.8rem; min-height:0; }
    .msgr__bcast-body{ flex:1 1 auto; resize:none; min-height:90px; padding:.6rem .75rem; border:1px solid #e2e0ea; border-radius:12px; font-size:.9rem; font-family:inherit; line-height:1.45; color:#1c1830; outline:none; }
    .msgr__bcast-body:focus{ border-color:var(--violet,#5b4be0); box-shadow:0 0 0 3px rgba(91,75,224,.12); }
    .msgr__bcast-send{ flex:none; padding:.62rem; border:0; border-radius:10px; background:var(--violet,#5b4be0); color:#fff; font-size:.9rem; font-weight:700; cursor:pointer; }
    .msgr__bcast-send:hover{ background:var(--violet7,#4636c4); }
    .msgr__bcast-send:disabled{ opacity:.55; cursor:default; }
    .msgr-conv{ display:flex; align-items:center; gap:.6rem; width:100%; text-align:left; padding:.65rem .8rem; border:0; border-bottom:1px solid #f2f1f7; background:transparent; cursor:pointer; }
    .msgr-conv:hover{ background:#faf9fe; }
    .msgr-conv__body{ flex:1 1 auto; min-width:0; }
    .msgr-conv__top{ display:flex; align-items:baseline; justify-content:space-between; gap:.4rem; }
    .msgr-conv__name{ font-size:.88rem; font-weight:600; color:#1c1830; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .msgr-conv.is-unread .msgr-conv__name{ font-weight:700; }
    .msgr-conv__time{ flex:none; font-size:.7rem; color:#9a97a8; }
    .msgr-conv__handle{ display:block; font-size:.74rem; color:#9a97a8; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .msgr-conv__prev{ display:block; font-size:.8rem; color:#8a8797; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .msgr-conv.is-unread .msgr-conv__prev{ color:#1c1830; }
    .msgr-conv__unread{ flex:none; min-width:18px; height:18px; padding:0 5px; border-radius:9px; background:var(--violet,#5b4be0); color:#fff; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
    .msgr-av{ flex:none; width:36px; height:36px; border-radius:50%; background:#e9e7f4 center/cover no-repeat; display:inline-block; }
    .msgr-av--i{ display:inline-flex; align-items:center; justify-content:center; color:var(--violet7,#4636c4); font-weight:700; font-size:.9rem; }
    .msgr__thread{ flex:1 1 auto; display:flex; flex-direction:column; min-height:0; }
    .msgr__scroll{ flex:1 1 auto; overflow-y:auto; padding:.9rem; display:flex; flex-direction:column; gap:.45rem; background:#fafafc; }
    .msgr-hint{ margin:auto; color:#9a97a8; font-size:.86rem; }
    .msgr-bubble{ max-width:80%; padding:.5rem .75rem; border-radius:14px; }
    .msgr-bubble__body{ display:block; font-size:.9rem; line-height:1.4; word-break:break-word; }
    .msgr-bubble__time{ display:block; font-size:.66rem; margin-top:.15rem; opacity:.7; }
    .msgr-bubble--mine{ align-self:flex-end; background:var(--violet,#5b4be0); color:#fff; border-bottom-right-radius:4px; }
    .msgr-bubble--theirs{ align-self:flex-start; background:#fff; color:#1c1830; border:1px solid #ececf3; border-bottom-left-radius:4px; }
    .msgr__compose{ flex:none; display:flex; align-items:flex-end; gap:.45rem; padding:.6rem .7rem; border-top:1px solid #eee; background:#fff; }
    .msgr__input{ flex:1 1 auto; resize:none; max-height:120px; padding:.5rem .75rem; border:1px solid #e2e0ea; border-radius:16px; font-size:.9rem; font-family:inherit; line-height:1.4; outline:none; }
    .msgr__input:focus{ border-color:var(--violet,#5b4be0); box-shadow:0 0 0 3px rgba(91,75,224,.12); }
    .msgr__send{ flex:none; width:38px; height:38px; border:0; border-radius:50%; background:var(--violet,#5b4be0); color:#fff; font-size:.95rem; cursor:pointer; }
    .msgr__send:hover{ background:var(--violet7,#4636c4); }
    .msgr__send:disabled{ opacity:.6; cursor:default; }
    @media (max-width:480px){ .msgr{ right:14px; bottom:14px; } .msgr__panel{ width:calc(100vw - 28px); height:70vh; } }
    </style>

    <script>
    (function () {
        if (typeof window.jQuery === 'undefined' || typeof window.ApiDataSvc === 'undefined') { return; }
        var $ = window.jQuery, active = null;
        var IS_CREATOR = <?php echo Permissions::has_role('Creator') ? 'true' : 'false'; ?>;
        var $panel = $('#msgrPanel'), $inbox = $('#msgrInbox'), $thread = $('#msgrThread'),
            $scroll = $('#msgrScroll'), $title = $('#msgrTitle'), $back = $('#msgrBack'), $count = $('#msgrCount'),
            $newview = $('#msgrNewView'), $newbtn = $('#msgrNew'), $people = $('#msgrPeople'), $search = $('#msgrSearch'),
            $modes = $('#msgrModes'), $direct = $('#msgrDirect'), $bcast = $('#msgrBcast'),
            $bcastBody = $('#msgrBcastBody'), $bcastSend = $('#msgrBcastSend'), $bcastN = $('#msgrBcastN');
        var bseg = 'all', bcounts = { all: 0, followers: 0, subscribers: 0 };

        function esc(s){ var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML; }
        function ftime(iso){ if(!iso){ return ''; } var d = new Date(String(iso).replace(' ','T')+'Z'); return isNaN(d) ? '' : d.toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}); }
        function ini(n){ return (String(n||'?').trim().charAt(0)||'?').toUpperCase(); }
        function av(a,n){ return a ? '<span class="msgr-av" style="background-image:url(\''+esc(a)+'\')"></span>' : '<span class="msgr-av msgr-av--i">'+esc(ini(n))+'</span>'; }

        function badge(){ ApiDataSvc.apiCall('post','message_unread_count',{},function(r){ var o=null; try{o=JSON.parse(r);}catch(e){} var n=(o&&o.count)?o.count:0; if(n>0){ $count.text(n>99?'99+':n).prop('hidden',false); } else { $count.prop('hidden',true); } }); }
        function showInbox(){ active=null; $thread.prop('hidden',true); $newview.prop('hidden',true); $inbox.prop('hidden',false); $back.prop('hidden',true); $newbtn.prop('hidden',false); $title.text('Messages'); loadInbox(); }
        function showThread(){ $inbox.prop('hidden',true); $newview.prop('hidden',true); $thread.prop('hidden',false); $back.prop('hidden',false); $newbtn.prop('hidden',true); }
        function showNew(){ active=null; $inbox.prop('hidden',true); $thread.prop('hidden',true); $newview.prop('hidden',false); $back.prop('hidden',false); $newbtn.prop('hidden',true); $title.text('New message'); $modes.prop('hidden',!IS_CREATOR); setMode('direct'); }
        function setMode(m){
            $modes.find('.msgr__mode').each(function(){ $(this).toggleClass('is-on', $(this).data('mode')===m); });
            if(m==='broadcast'){ $direct.prop('hidden',true); $bcast.prop('hidden',false); $title.text('Broadcast'); loadCounts(); setTimeout(function(){ $bcastBody.trigger('focus'); },50); }
            else { $bcast.prop('hidden',true); $direct.prop('hidden',false); $title.text('New message'); $search.val(''); loadPeople(''); setTimeout(function(){ $search.trigger('focus'); },50); }
        }
        function loadCounts(){
            ApiDataSvc.apiCall('post','broadcast_info',{},function(r){ var o=null; try{o=JSON.parse(r);}catch(e){} if(o&&o.success){ bcounts=o.counts; $('#msgrSegAll').text(o.counts.all); $('#msgrSegFollowers').text(o.counts.followers); $('#msgrSegSubscribers').text(o.counts.subscribers); updateBcastSend(); } });
        }
        function setSeg(s){ bseg=s; $('#msgrSeg .msgr__seg-opt').each(function(){ $(this).toggleClass('is-on', $(this).data('seg')===s); }); updateBcastSend(); }
        function updateBcastSend(){ var n=bcounts[bseg]||0; $bcastN.text(n); $bcastSend.prop('disabled', n<=0 || ($bcastBody.val()||'').trim()===''); }

        function loadInbox(){ ApiDataSvc.apiCall('post','message_inbox',{},function(r){ var o=null; try{o=JSON.parse(r);}catch(e){} if(o&&o.success){ renderInbox(o.conversations); } }); }
        function renderInbox(list){
            $inbox.find('.msgr-conv').remove();
            if(!list.length){ $('#msgrEmpty').prop('hidden',false); return; }
            $('#msgrEmpty').prop('hidden',true);
            list.forEach(function(c){
                var cb = c.other_is_creator ? ' <i class="fa-solid fa-circle-check msgr-badge" title="Creator"></i>' : '';
                var pv = (c.last_mine?'You: ':'')+(c.preview||'');
                var un = c.unread>0 ? '<span class="msgr-conv__unread">'+c.unread+'</span>' : '';
                $inbox.append('<button type="button" class="msgr-conv'+(c.unread>0?' is-unread':'')+'" data-id="'+c.id+'" data-name="'+esc(c.other_name)+'" data-handle="'+esc(c.other_handle)+'" data-avatar="'+esc(c.other_avatar)+'" data-creator="'+(c.other_is_creator?1:0)+'">'
                    + av(c.other_avatar,c.other_name)
                    + '<span class="msgr-conv__body"><span class="msgr-conv__top"><span class="msgr-conv__name">'+esc(c.other_name)+cb+'</span><span class="msgr-conv__time">'+ftime(c.last_at)+'</span></span>'
                    + '<span class="msgr-conv__handle">@'+esc(c.other_handle)+'</span><span class="msgr-conv__prev">'+esc(pv)+'</span></span>'+un+'</button>');
            });
        }
        function loadPeople(q){
            ApiDataSvc.apiCall('post','message_people',{q:q||''},function(r){ var o=null; try{o=JSON.parse(r);}catch(e){} if(o&&o.success){ renderPeople(o.people, o.is_search); } });
        }
        function renderPeople(list, isSearch){
            var h = $people.empty();
            if(!list.length){ h.html('<p class="msgr__empty">'+(isSearch?'No one matches that search.':'Follow or subscribe to a creator — or gain a follower — to start a conversation.')+'</p>'); return; }
            list.forEach(function(c){
                var cb = c.is_creator ? ' <i class="fa-solid fa-circle-check msgr-badge" title="Creator"></i>' : '';
                h.append('<button type="button" class="msgr-conv" data-pid="'+c.id+'" data-name="'+esc(c.name)+'" data-handle="'+esc(c.handle)+'" data-avatar="'+esc(c.avatar)+'" data-creator="'+(c.is_creator?1:0)+'">'
                    + av(c.avatar,c.name)
                    + '<span class="msgr-conv__body"><span class="msgr-conv__top"><span class="msgr-conv__name">'+esc(c.name)+cb+'</span></span>'
                    + '<span class="msgr-conv__handle">@'+esc(c.handle)+'</span></span></button>');
            });
        }
        function openWith(p){
            showThread(); setTitle(p);
            ApiDataSvc.apiCall('post','message_open',{to_creator:p.id},function(r){
                var o=null; try{o=JSON.parse(r);}catch(e){}
                if(!o||!o.success){ if(o&&o.need_login){ window.location='/'; return; } if(window.toastr){ toastr.error(o?o.message:'Could not open conversation'); } showInbox(); return; }
                active=o.conversation_id; setTitle(o.other); renderMsgs(o.messages); badge();
                setTimeout(function(){ $('#msgrInput').trigger('focus'); },50);
            });
        }
        function setTitle(p){
            if(!p){ $title.text('Conversation'); return; }
            var cb = p.is_creator ? ' <i class="fa-solid fa-circle-check msgr-badge" title="Creator"></i>' : '';
            var sub = (p.handle?'@'+esc(p.handle):'')+(p.handle?' · ':'')+(p.is_creator?'Creator':'Member');
            var inner = '<span class="msgr-peer__name">'+esc(p.name)+cb+'</span><span class="msgr-peer__sub">'+sub+'</span>';
            $title.html((p.is_creator && p.handle) ? '<a class="msgr-peer" href="/@'+encodeURIComponent(p.handle)+'" target="_blank" rel="noopener">'+inner+'</a>' : '<span class="msgr-peer">'+inner+'</span>');
        }
        function openThread(id, peer){
            active=id; showThread(); setTitle(peer);
            ApiDataSvc.apiCall('post','message_thread',{conversation_id:id},function(r){ var o=null; try{o=JSON.parse(r);}catch(e){} if(!o||!o.success){ return; } setTitle(o.other); renderMsgs(o.messages); badge(); });
        }
        function renderMsgs(m){ var $s=$scroll.empty(); if(!m.length){ $s.html('<div class="msgr-hint">No messages yet. Say hello.</div>'); return; } m.forEach(addMsg); $s.scrollTop($s[0].scrollHeight); }
        function addMsg(m){ $scroll.append('<div class="msgr-bubble '+(m.mine?'msgr-bubble--mine':'msgr-bubble--theirs')+'"><span class="msgr-bubble__body">'+esc(m.body).replace(/\n/g,'<br>')+'</span><span class="msgr-bubble__time">'+ftime(m.created_at)+'</span></div>'); $scroll.scrollTop($scroll[0].scrollHeight); }

        $('#msgrToggle').on('click', function(){ if($panel.prop('hidden')){ $panel.prop('hidden',false); showInbox(); badge(); } else { $panel.prop('hidden',true); } });
        $('#msgrClose').on('click', function(){ $panel.prop('hidden',true); });
        $('#msgrBack').on('click', showInbox);
        $newbtn.on('click', showNew);
        $inbox.on('click','.msgr-conv', function(){ var $b=$(this); openThread(parseInt($b.data('id'),10), {name:$b.data('name'),handle:$b.data('handle'),avatar:$b.data('avatar'),is_creator:String($b.data('creator'))==='1'}); });
        $people.on('click','.msgr-conv', function(){ var $b=$(this); openWith({id:parseInt($b.data('pid'),10),name:$b.data('name'),handle:$b.data('handle'),avatar:$b.data('avatar'),is_creator:String($b.data('creator'))==='1'}); });
        var searchT=null;
        $search.on('input', function(){ var v=(this.value||'').trim(); clearTimeout(searchT); searchT=setTimeout(function(){ loadPeople(v); }, 250); });
        $modes.on('click','.msgr__mode', function(){ setMode($(this).data('mode')); });
        $('#msgrSeg').on('click','.msgr__seg-opt', function(){ setSeg($(this).data('seg')); });
        $bcastBody.on('input', updateBcastSend);
        $('#msgrBcastForm').on('submit', function(e){
            e.preventDefault();
            var body=($bcastBody.val()||'').trim(); if(body===''||(bcounts[bseg]||0)<=0){ return; }
            $bcastSend.prop('disabled',true);
            ApiDataSvc.apiCall('post','broadcast_send',{segment:bseg,body:body},function(r){
                var o=null; try{o=JSON.parse(r);}catch(e){}
                if(!o||!o.success){ $bcastSend.prop('disabled',false); if(o&&o.need_login){ window.location='/'; return; } if(window.toastr){ toastr.error(o?o.message:'Could not send broadcast'); } return; }
                $bcastBody.val('');
                if(window.toastr){ toastr.success('Broadcast sent to '+o.count+(o.count===1?' person':' people')); }
                showInbox(); badge();
            });
        });
        $('#msgrCompose').on('submit', function(e){
            e.preventDefault();
            var body=($('#msgrInput').val()||'').trim(); if(body===''||!active){ return; }
            $('#msgrSend').prop('disabled',true);
            ApiDataSvc.apiCall('post','message_send',{conversation_id:active,body:body},function(r){
                $('#msgrSend').prop('disabled',false);
                var o=null; try{o=JSON.parse(r);}catch(e){}
                if(!o||!o.success){ if(o&&o.need_login){ window.location='/'; return; } if(window.toastr){ toastr.error(o?o.message:'Could not send'); } return; }
                $('#msgrInput').val('').css('height','auto'); addMsg(o.sent);
            });
        });
        $('#msgrInput').on('keydown', function(e){ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); $('#msgrCompose').submit(); } })
            .on('input', function(){ this.style.height='auto'; this.style.height=Math.min(this.scrollHeight,120)+'px'; });

        badge();
        setInterval(function(){ badge(); if(!$panel.prop('hidden')){ if(active){ ApiDataSvc.apiCall('post','message_thread',{conversation_id:active},function(r){ var o=null; try{o=JSON.parse(r);}catch(e){} if(o&&o.success){ renderMsgs(o.messages); } }); } else { loadInbox(); } } }, 12000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
