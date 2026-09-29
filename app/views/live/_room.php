<?php
/* CLS Video call page (event or booking). Everything live happens in /js/live.js; this is the frame and its states. */
$c = isset($c) ? $c : $this->call;   // guest_frame.php passes $c directly
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<link rel="stylesheet" href="/css/live.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/live.css'); ?>">
<div class="lv" id="lv" data-kind="<?php echo $h($c['kind']); ?>" data-id="<?php echo (int) $c['id']; ?>" data-host="<?php echo $c['is_host'] ? 1 : 0; ?>"
     data-opens="<?php echo (int) $c['opens_at']; ?>" data-back="<?php echo $h($c['back']); ?>" data-me="<?php echo $h($c['me_name']); ?>"
     data-guest="<?php echo !empty($c['guest']) ? 1 : 0; ?>" data-needs-pw="<?php echo !empty($c['needs_password']) ? 1 : 0; ?>">

    <header class="lv__head">
        <a class="lv__back" href="<?php echo $h($c['back']); ?>" aria-label="Back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
        <div class="lv__titles">
            <h1 class="lv__title"><?php echo $h($c['title']); ?></h1>
            <p class="lv__sub"><?php echo $h($c['when']); ?><?php if ($c['host_name'] !== ''): ?> · Hosted by <?php echo $h($c['host_name']); ?><?php endif; ?></p>
        </div>
        <div class="lv__meta">
            <?php if ($c['is_host']): ?><span class="lv__chip">Host</span><?php endif; ?>
            <span class="lv__timer" id="lvTimer" hidden>00:00</span>
        </div>
    </header>

    <!-- Lobby: check camera and mic, then join -->
    <section class="lv-lobby" id="lvLobby">
        <div class="lv-lobby__preview">
            <div class="lv-pv" id="lvPreview">
                <video id="lvPreviewVideo" autoplay muted playsinline></video>
                <div class="lv-pv__off" id="lvPreviewOff" hidden><span class="lv-avatar" id="lvPreviewAvatar"></span><span>Camera is off</span></div>
                <div class="lv-pv__level" aria-hidden="true"><span id="lvLevel"></span></div>
            </div>
            <div class="lv-pv__controls">
                <button type="button" class="lv-ctl" id="lvPvMic" aria-pressed="true" aria-label="Microphone"><i class="fa-solid fa-microphone" aria-hidden="true"></i></button>
                <button type="button" class="lv-ctl" id="lvPvCam" aria-pressed="true" aria-label="Camera"><i class="fa-solid fa-video" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="lv-lobby__panel">
            <h2 class="lv-lobby__h">Ready to join?</h2>
            <?php if (!empty($c['open']) && !$c['is_host']): ?><p class="lv-lobby__p">This call is open to everyone. No account or registration needed.</p><?php endif; ?>
            <?php if (!empty($c['guest'])): ?>
            <div class="lv-field">
                <label class="lv-field__label" for="lvName">Your Name</label>
                <input type="text" class="form-control" id="lvName" maxlength="40" autocomplete="name" placeholder="Jane Smith">
            </div>
            <?php endif; ?>
            <?php if (!empty($c['needs_password'])): ?>
            <div class="lv-field">
                <label class="lv-field__label" for="lvPw">Call Password</label>
                <input type="password" class="form-control" id="lvPw" maxlength="64" autocomplete="off">
            </div>
            <?php endif; ?>
            <?php if ($c['is_host'] && ($c['password'] ?? '') !== ''): ?>
            <p class="lv-lobby__pw"><i class="fa-solid fa-lock" aria-hidden="true"></i> Call password: <b><?php echo $h($c['password']); ?></b></p>
            <?php endif; ?>
            <div class="lv-field">
                <label class="lv-field__label" for="lvMicSel">Microphone</label>
                <select class="form-select" id="lvMicSel"></select>
            </div>
            <div class="lv-field">
                <label class="lv-field__label" for="lvCamSel">Camera</label>
                <select class="form-select" id="lvCamSel"></select>
            </div>
            <div class="lv-note lv-note--wait" id="lvWait" role="status" hidden></div>
            <div class="lv-note" id="lvNote" role="status" hidden></div>
            <button type="button" class="btn btn-primary lv-join" id="lvJoin" disabled>Join Call</button>
            <button type="button" class="lv-link" id="lvRetryDevices" hidden>Try Camera and Microphone Again</button>
        </div>
    </section>

    <!-- In the call -->
    <section class="lv-call" id="lvCall" hidden>
        <div class="lv-call__main">
            <div class="lv-banner" id="lvBanner" role="status" hidden></div>
            <div class="lv-stage" id="lvStage">
                <div class="lv-feature" id="lvFeature" hidden></div>
                <div class="lv-grid" id="lvGrid" data-count="0"></div>
            </div>
            <div class="lv-bar" role="toolbar" aria-label="Call controls">
                <button type="button" class="lv-ctl" id="lvMic" aria-pressed="true" aria-label="Microphone" title="Microphone"><i class="fa-solid fa-microphone" aria-hidden="true"></i></button>
                <button type="button" class="lv-ctl" id="lvCam" aria-pressed="true" aria-label="Camera" title="Camera"><i class="fa-solid fa-video" aria-hidden="true"></i></button>
                <?php if (empty($c['guest'])): /* visitors without an account can't share their screen */ ?><button type="button" class="lv-ctl lv-ctl--wide-hide" id="lvShare" aria-pressed="false" aria-label="Share Screen" title="Share Screen"><i class="fa-solid fa-display" aria-hidden="true"></i></button><?php endif; ?>
                <?php if ($c['is_host']): ?>
                <button type="button" class="lv-ctl" id="lvPeople" aria-pressed="false" aria-label="People" title="People"><i class="fa-solid fa-user-group" aria-hidden="true"></i><span class="lv-ctl__count" id="lvCount">1</span></button>
                <button type="button" class="lv-ctl" id="lvMuteAll" aria-label="Mute Everyone" title="Mute Everyone"><i class="fa-solid fa-microphone-lines-slash" aria-hidden="true"></i></button>
                <?php endif; ?>
                <button type="button" class="lv-ctl lv-ctl--leave" id="lvLeave" aria-label="Leave Call" title="Leave Call"><i class="fa-solid fa-phone-slash" aria-hidden="true"></i></button>
            </div>
        </div>
        <?php if ($c['is_host']): ?>
        <aside class="lv-people" id="lvPeoplePanel" hidden aria-label="People in the call">
            <header class="lv-people__head"><h2 class="lv-people__h">People</h2><button type="button" class="lv-people__close" id="lvPeopleClose" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
            <ul class="lv-people__list" id="lvPeopleList"></ul>
        </aside>
        <?php endif; ?>
    </section>

    <!-- After leaving (or being removed / the call closing) -->
    <section class="lv-end" id="lvEnd" hidden>
        <div class="lv-end__box">
            <span class="lv-end__ic"><i class="fa-solid fa-phone-slash" aria-hidden="true"></i></span>
            <h2 class="lv-end__h" id="lvEndTitle">You left the call</h2>
            <p class="lv-end__p" id="lvEndText"></p>
            <div class="lv-end__actions">
                <button type="button" class="btn btn-primary" id="lvRejoin">Rejoin</button>
                <a class="btn btn-secondary" href="<?php echo $h($c['back']); ?>">Back</a>
            </div>
        </div>
    </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.js"></script>
<script src="/js/live.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/live.js'); ?>"></script>
