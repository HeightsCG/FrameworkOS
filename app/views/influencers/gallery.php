<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); ?>


    <div class="inf-gal__bar">
        <div class="inf-chips" id="inf_gal_roles" role="group" aria-label="Filter">
            <button type="button" class="inf-chip is-on" data-role="">All</button>
            <button type="button" class="inf-chip" data-role="generated">Generated</button>
            <button type="button" class="inf-chip" data-role="video">Videos</button>
            <button type="button" class="inf-chip" data-role="enhanced">Enhanced</button>
            <button type="button" class="inf-chip" data-role="training">Training set</button>
            <button type="button" class="inf-chip" data-role="upload">Uploads</button>
        </div>
    </div>

    <div class="inf-state-loading" id="inf_gal_loading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading gallery…</div>
    <div class="inf-empty" id="inf_gal_empty" hidden>
        <span class="inf-empty__ic"><i class="fa-regular fa-images"></i></span>
        <h2 class="inf-empty__title">Nothing Here Yet</h2>
        <p class="inf-empty__text">Generate an image or a video and it lands here.</p>
        <a href="/influencers/images/<?php echo (int) $infl['id']; ?>" class="btn btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate images</a>
    </div>
    <div class="inf-gal" id="inf_gal" hidden></div>

    <div class="inf-lightbox" id="inf_lightbox" hidden>
        <div class="inf-lightbox__body">
            <img id="inf_lightbox_img" alt="" hidden>
            <video id="inf_lightbox_video" controls playsinline hidden></video>
        </div>
        <div class="inf-lightbox__bar">
            <span class="inf-lightbox__meta" id="inf_lightbox_meta"></span>
            <button type="button" class="btn btn-secondary btn-sm" id="inf_lightbox_edit"><i class="fa-solid fa-pen"></i> Edit</button>
            <button type="button" class="btn btn-secondary btn-sm" id="inf_lightbox_frame" hidden><i class="fa-regular fa-image"></i> Export Frame</button>
            <button type="button" class="btn btn-secondary btn-sm" id="inf_lightbox_download"><i class="fa-solid fa-download"></i> Download</button>
            <button type="button" class="btn btn-secondary btn-sm" id="inf_lightbox_post"><i class="fa-solid fa-feather-pointed"></i> Use In Post</button>
            <button type="button" class="btn btn-secondary btn-sm" id="inf_lightbox_message"><i class="fa-solid fa-comment-dots"></i> Send In Message</button>
            <button type="button" class="btn btn-secondary btn-sm inf-btn--danger" id="inf_lightbox_delete"><i class="fa-regular fa-trash-can"></i> Delete</button>
        </div>
        <button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
