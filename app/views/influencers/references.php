<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); ?>


    <div class="inf-angles__bar" id="inf_ang_bar" hidden>
        <div class="inf-angles__go">
            <span class="inf-wiz__meta" id="inf_ang_cost"></span>
            <button type="button" class="btn btn-primary" id="inf_ang_generate"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Angle Set</button>
        </div>
    </div>

    <div class="inf-state-loading" id="inf_ang_loading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading references…</div>
    <div class="inf-state-error" id="inf_ang_error" hidden>Could not load her references. <button type="button" class="inf-link" id="inf_ang_retry">Try Again</button></div>
    <div class="inf-empty" id="inf_ang_noref" hidden>
        <span class="inf-empty__ic"><i class="fa-regular fa-id-badge"></i></span>
        <h2 class="inf-empty__title">No Reference Image Yet</h2>
        <p class="inf-empty__text">Angles are made from her reference image.</p>
        <a href="/influencers/create/<?php echo (int) $infl['id']; ?>" class="btn btn-primary">Open Settings</a>
    </div>
    <div class="inf-angles" id="inf_angles" hidden></div>

    <div class="inf-lightbox" id="inf_lightbox" hidden><img id="inf_lightbox_img" alt=""><button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>

<?php require __DIR__ . '/_bottom.php'; ?>
