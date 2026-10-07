<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; ?>

    <?php /* Scenes: the creator's own scenes (a new account starts with its own copies of the starter scenes). A card opens the
             editor, where the scene is saved, run with this influencer as the subject (four variants to rate) and deleted.
             The grid scrolls inside the canvas so the page itself does not scroll, like the other Generate Images pages. */ ?>
    <div class="inf-cv" id="inf_scenes_page" data-cv>
        <div class="inf-scene__bar" id="infSceneBar">
            <div class="inf-scene__tools">
                <?php echo Tutorials::button('26'); ?>
            </div>
        </div>
        <div class="inf-cv__body">
            <section class="inf-cv__canvas" aria-label="Scenes">
                <div class="inf-state-loading" id="infSceneLoading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading scenes…</div>
                <div class="inf-state-error" id="infSceneError" hidden>
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <p>Could not load scenes.</p>
                    <button type="button" class="btn btn-secondary" id="infSceneRetry">Try Again</button>
                </div>
                <div class="inf-scenes" id="infScenes" hidden></div>
            </section>
        </div>
    </div>

    <!-- the scene editor: fields, Generate (saves, then runs with this influencer), results, Delete -->
    <div class="modal fade ai-modal" id="infSceneEdit" tabindex="-1" aria-labelledby="infSceneEditTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title ai-modal__title" id="infSceneEditTitle">New Scene</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <form class="inf-sedit" id="infSceneEditForm" autocomplete="off" onsubmit="return false;">
                    <input type="hidden" id="infSceneEditId" value="0">
                    <div class="ai-field"><label class="ai-label" for="infSceneEditName">Title</label><input type="text" class="form-control" id="infSceneEditName" maxlength="120" placeholder="Rooftop Cafe"><p class="ai-error" data-err="title" role="alert" hidden></p></div>
                    <div class="ai-field"><label class="ai-label" for="infSceneEditPrompt">Base Prompt</label><textarea class="form-control" id="infSceneEditPrompt" rows="5" maxlength="4000" placeholder="photo of a {subject} at a rooftop cafe at golden hour, iced coffee in hand"></textarea><p class="ai-error" data-err="base_prompt" role="alert" hidden></p></div>
                    <div class="ai-field"><div class="ai-label" id="infSceneEditAspectLabel">Shape</div><div class="inf-seg" id="infSceneEditAspect" role="group" aria-labelledby="infSceneEditAspectLabel"></div><p class="ai-error" data-err="default_aspect" role="alert" hidden></p></div>
                    <div class="inf-sedit__foot">
                        <div class="inf-sedit__thumb">
                            <div class="ai-label" id="infSceneEditThumbLabel">Thumbnail</div>
                            <div class="inf-sedit__image" role="group" aria-labelledby="infSceneEditThumbLabel">
                                <button type="button" class="inf-sedit__pick" id="infSceneEditThumbBtn" aria-label="Choose Image" title="Choose Image"><img id="infSceneEditThumbImg" alt="" hidden><i class="fa-regular fa-image" aria-hidden="true"></i></button>
                                <input type="file" id="infSceneEditThumb" accept="image/jpeg,image/png,image/webp" hidden>
                            </div>
                        </div>
                        <label class="form-check form-switch inf-sedit__switch" for="infSceneEditAdult"><input class="form-check-input" type="checkbox" id="infSceneEditAdult"> <span>Adult</span></label>
                    </div>
                </form>
                <p class="ai-error" id="infSceneErr" role="alert" hidden></p>
                <div class="inf-srun__grid" id="infSceneGrid" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="inf-sedit__delete me-auto" id="infSceneEditDelete" hidden>Delete</button>
                <span class="ai-cost" id="infSceneCost" role="status"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-secondary" id="infSceneEditSave">Save Scene</button>
                <button type="button" class="btn btn-primary" id="infSceneGo">Generate 4 Variants</button>
            </div>
        </div></div>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
