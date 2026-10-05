<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<link rel="stylesheet" href="/css/clip-editor.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/clip-editor.css'); ?>">
<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$pid = (int) ($this->project_id ?? 0);
$focus_labels = array('draft' => 'Draft', 'rendering' => 'Exporting', 'done' => 'Exported', 'failed' => 'Export Failed');
?>
<?php if ($pid === 0): $projects = (array) ($this->projects ?? array()); ?>
<div class="ev">
    <header class="ev__head">
        <div>
            <a class="ce-back" href="/studio"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Content Studio</a>
            <h1 class="ev__title">Edits</h1>
            <p class="ev__sub">Join clips and stills, add text, images and audio, and export one finished video.</p>
        </div>
        <div class="ce-headtools"><?php echo Tutorials::button('33'); ?>
            <button type="button" class="ev-btn ev-btn--primary" id="ceNew"><i class="fa-solid fa-plus"></i> New Edit</button></div>
    </header>
    <?php if (empty($projects)): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-solid fa-scissors"></i></span>
        <h2 class="ev-empty__t">No Edits Yet</h2>
        <p class="ev-empty__x">Start an edit to join your clips into one video.</p>
    </div>
    <?php else: ?>
    <div class="ev-table ce-list">
        <div class="ev-table__head"><span>Edit</span><span>Clips</span><span>Length</span><span>Shape</span><span>Status</span></div>
        <div class="ev-table__body">
            <?php foreach ($projects as $p): ?>
            <a class="ev-row" href="/studio/edit/<?php echo (int) $p['id']; ?>">
                <span class="ev-cell ev-cell--title ce-list__name"><span class="ce-list__thumb"><?php if ($p['thumb_url'] !== ''): ?><img src="<?php echo $e($p['thumb_url']); ?>" alt=""><?php else: ?><i class="fa-solid fa-film" aria-hidden="true"></i><?php endif; ?></span><?php echo $e($p['name']); ?></span>
                <span class="ev-cell ev-cell--muted"><?php echo (int) $p['clips']; ?></span>
                <span class="ev-cell ev-cell--muted"><?php echo (int) $p['duration'] > 0 ? (int) $p['duration'] . ' sec' : '—'; ?></span>
                <span class="ev-cell ev-cell--muted"><?php echo $e($p['aspect']); ?></span>
                <span class="ev-cell"><span class="ev-status ev-status--<?php echo $p['status'] === 'done' ? 'on' : ($p['status'] === 'failed' ? 'off' : 'draft'); ?>"><?php echo $e($focus_labels[$p['status']] ?? 'Draft'); ?></span></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="ce" id="ce" data-project="<?php echo $pid; ?>" data-can-export="<?php echo !empty($this->can_export) ? '1' : '0'; ?>">
    <header class="ce__head">
        <div class="ce__title">
            <a class="ce-back" href="/studio/edit"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Edits</a>
            <label class="visually-hidden" for="ceName">Edit Name</label>
            <input type="text" class="form-control ce__name" id="ceName" maxlength="160" placeholder="Edit name">
        </div>
        <div class="ce__tools">
            <span class="ce__saved" id="ceSaved" role="status"></span>
            <div class="cs-seg ce-seg" id="ceAspect" role="group" aria-label="Shape">
                <button type="button" class="cs-seg__opt" data-value="9:16" aria-pressed="false"><i class="cs-ratio" style="--rw:9;--rh:16" aria-hidden="true"></i><span>9:16</span></button>
                <button type="button" class="cs-seg__opt" data-value="3:4" aria-pressed="false"><i class="cs-ratio" style="--rw:3;--rh:4" aria-hidden="true"></i><span>3:4</span></button>
            </div>
            <div class="dropdown">
                <button type="button" class="ce-more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                <ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item ce-danger" id="ceDelete">Delete Edit</button></li></ul>
            </div>
            <button type="button" class="btn btn-primary" id="ceExport" disabled><i class="fa-solid fa-file-export" aria-hidden="true"></i> Export</button>
        </div>
    </header>

    <div class="ce-loading" id="ceLoading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading the edit…</div>
    <div class="ce-error" id="ceError" hidden><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><p>We couldn't load this edit.</p><button type="button" class="btn btn-secondary btn-sm" id="ceRetry">Try Again</button></div>

    <div class="ce__body" id="ceBody" hidden>
        <div class="ce__left">
            <div class="ce-stage" id="ceStage" data-shape="9:16">
                <div class="ce-stage__empty" id="ceStageEmpty"><i class="fa-solid fa-film" aria-hidden="true"></i><p>Add a clip to start.</p></div>
                <div class="ce-stage__media" id="ceStageMedia"></div>
                <div class="ce-stage__layer" id="ceStageLayer" aria-hidden="true"></div>
            </div>
            <p class="ce-total" id="ceTotal"></p>
            <ul class="ce-problems" id="ceProblems" hidden></ul>
            <div class="ce-export" id="ceExportBox" hidden></div>
        </div>

        <div class="ce__right">
            <section class="ce-sec">
                <div class="ce-sec__head"><h2 class="ce-sec__h">Clips</h2><button type="button" class="ce-link" id="ceAddClip"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Clip Or Image</button></div>
                <ol class="ce-rows" id="ceClips"></ol>
                <p class="ce-none" id="ceClipsNone">No clips yet.</p>
            </section>
            <section class="ce-sec">
                <div class="ce-sec__head"><h2 class="ce-sec__h">Text</h2><button type="button" class="ce-link" id="ceAddText"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Text</button></div>
                <ol class="ce-rows" id="ceTexts"></ol>
                <p class="ce-none" id="ceTextsNone">No text overlays.</p>
            </section>
            <section class="ce-sec">
                <div class="ce-sec__head"><h2 class="ce-sec__h">Image Overlays</h2><button type="button" class="ce-link" id="ceAddOverlay"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add PNG</button></div>
                <ol class="ce-rows" id="ceOverlays"></ol>
                <p class="ce-none" id="ceOverlaysNone">No image overlays.</p>
            </section>
            <section class="ce-sec">
                <div class="ce-sec__head"><h2 class="ce-sec__h">Audio Track</h2><button type="button" class="ce-link" id="ceAddAudio"><i class="fa-solid fa-plus" aria-hidden="true"></i> Choose Audio</button></div>
                <div id="ceAudio"></div>
                <p class="ce-none" id="ceAudioNone">No audio track.</p>
            </section>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<?php endif; ?>
<script src="/js/ai-tools.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/ai-tools.js'); ?>"></script>
<script src="/js/clip-editor.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/clip-editor.js'); ?>"></script>
