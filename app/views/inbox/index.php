<link rel="stylesheet" href="/css/inbox.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/inbox.css'); ?>">
<div class="ibx" id="ibx" data-init="<?php echo htmlspecialchars((string) $this->init, ENT_QUOTES, 'UTF-8'); ?>">

    <aside class="ibx__list" id="ibxList">
        <header class="ibx__listhead">
            <h1 class="ibx__title">Inbox</h1>
            <div class="ibx__listactions">
                <?php if ($this->is_creator): ?>
                <button type="button" class="ibx__ico" id="ibxBroadcast" aria-label="Broadcast" title="Broadcast"><i class="fa-solid fa-bullhorn"></i></button>
                <?php endif; ?>
                <button type="button" class="ibx__ico ibx__ico--primary" id="ibxNew" aria-label="New Message" title="New Message"><i class="fa-solid fa-pen-to-square"></i></button>
            </div>
        </header>
        <div class="ibx__search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="ibxSearch" placeholder="Search" autocomplete="off" maxlength="60" aria-label="Search conversations">
        </div>
        <div class="ibx__tabs" role="tablist">
            <button type="button" class="ibx__tab is-on" data-filter="all" role="tab">All</button>
            <button type="button" class="ibx__tab" data-filter="unread" role="tab">Unread <span id="ibxUnreadN"></span></button>
        </div>
        <div class="ibx__convs" id="ibxConvs"><p class="ibx__note">Loading…</p></div>
    </aside>

    <section class="ibx__thread" id="ibxThread">
        <div class="ibx__blank" id="ibxBlank">
            <i class="fa-regular fa-comments"></i>
            <p>Pick a conversation, or start a new one.</p>
        </div>
        <div class="ibx__pane" id="ibxPane" hidden>
            <header class="ibx__peer">
                <button type="button" class="ibx__ico ibx__back" id="ibxBack" aria-label="Back to Conversations"><i class="fa-solid fa-arrow-left"></i></button>
                <span class="ibx__av" id="ibxPeerAv"></span>
                <div class="ibx__peertext">
                    <span class="ibx__peername" id="ibxPeerName"></span>
                    <span class="ibx__peersub" id="ibxPeerSub"></span>
                </div>
                <a class="ibx__ico" id="ibxPeerProfile" href="#" target="_blank" rel="noopener" aria-label="View Profile" title="View Profile" hidden><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                <button type="button" class="ibx__ico ibx__del" id="ibxConvDelete" aria-label="Delete Conversation" title="Delete Conversation"><i class="fa-regular fa-trash-can"></i></button>
                <button type="button" class="ibx__ico ibx__block" id="ibxPeerBlock" data-block-user="0" aria-label="Block" title="Block" hidden><i class="fa-solid fa-ban"></i></button>
                <?php if ($this->is_creator): ?>
                <button type="button" class="ibx__ico ibx__ctxtoggle" id="ibxCtxToggle" aria-label="About This Fan" title="About This Fan" hidden><i class="fa-solid fa-circle-info"></i></button>
                <?php endif; ?>
            </header>
            <div class="ibx__scroll" id="ibxScroll"></div>
            <div class="ibx__attach" id="ibxAttach" hidden></div>
            <form class="ibx__compose" id="ibxCompose">
                <?php if ($this->is_creator): ?>
                <button type="button" class="ibx__addbtn" id="ibxAttachBtn" hidden><i class="fa-solid fa-photo-film"></i><span>Add Content</span></button>
                <?php endif; ?>
                <textarea class="ibx__input" id="ibxInput" rows="1" placeholder="Write a message" maxlength="2000" aria-label="Message"></textarea>
                <button type="submit" class="ibx__send" id="ibxSend"><span id="ibxSendLabel">Send</span></button>
            </form>
        </div>
    </section>

    <?php if ($this->is_creator): ?>
    <aside class="ibx__ctx" id="ibxCtx" hidden>
        <div class="ibx__ctxhead"><span>About</span><button type="button" class="ibx__ico" id="ibxCtxClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="ibx__ctxbody" id="ibxCtxBody"></div>
    </aside>
    <?php endif; ?>
</div>

<!-- People picker: start a conversation -->
<div class="modal fade ibx-modal" id="ibxPeopleModal" tabindex="-1" aria-labelledby="ibxPeopleTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title" id="ibxPeopleTitle">New Message</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="ibx__search ibx__search--modal"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="ibxPeopleSearch" placeholder="Search people" autocomplete="off" maxlength="60" aria-label="Search people"></div>
                <div class="ibx__people" id="ibxPeople"></div>
            </div>
        </div>
    </div>
</div>

<?php if ($this->is_creator): ?>
<!-- Library picker: attach media to a message -->
<div class="modal fade ibx-modal" id="ibxPickerModal" tabindex="-1" aria-labelledby="ibxPickerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title" id="ibxPickerTitle">Attach Media</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="ibx__search ibx__search--modal"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="ibxPickSearch" placeholder="Search your library" autocomplete="off" maxlength="60" aria-label="Search your library"></div>
                <div class="ibx__pickgrid" id="ibxPickGrid"></div>
            </div>
            <div class="modal-footer"><span class="ibx__pickcount" id="ibxPickCount">Nothing selected</span><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="ibxPickDone">Done</button></div>
        </div>
    </div>
</div>

<!-- Broadcast -->
<div class="modal fade ibx-modal" id="ibxBcastModal" tabindex="-1" aria-labelledby="ibxBcastTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title" id="ibxBcastTitle">Broadcast</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <form id="ibxBcastForm">
                <div class="modal-body">
                    <span class="ibx__label">Send to</span>
                    <div class="ibx__segs" id="ibxSegs" role="group" aria-label="Send to"></div>
                    <textarea class="form-control ibx__bcastbody" id="ibxBcastBody" rows="4" placeholder="Write a message to your audience" maxlength="2000" aria-label="Message"></textarea>
                    <div class="ibx__attach ibx__attach--bcast" id="ibxBcastAttach" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ibx__addbtn" id="ibxBcastAttachBtn"><i class="fa-solid fa-photo-film"></i><span>Add Content</span></button>
                    <span class="ibx__grow"></span>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="ibxBcastSend" disabled>Send</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="ibx__lightbox" id="ibxLightbox" hidden>
    <button type="button" class="ibx__lightbox-close" id="ibxLightboxClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="ibx__lightbox-body" id="ibxLightboxBody"></div>
</div>

<script src="/js/inbox.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/inbox.js'); ?>"></script>
