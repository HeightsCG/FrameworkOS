<?php /* Content Studio, Scenes tab: the creator's own scenes beside the platform scene library. Filled by studio-ai.js on first open. */ ?>
        <!-- ============ SCENES ============ -->
        <div class="tab-pane fade" id="csPaneScenes" role="tabpanel">
            <div class="cs-toolbar" id="csSceneBar" hidden>
                <div class="cs-scene__cats" id="csSceneCats" role="group" aria-label="Category"></div>
                <div class="cs-scene__tools">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="csSceneNew"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Scene</button>
                    <?php echo Tutorials::button('26', 'data-push'); ?>
                </div>
            </div>
            <div class="cs-loading" id="csSceneLoading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading scenes…</div>
            <div class="cs-error" id="csSceneError" hidden>
                <i class="fa-solid fa-circle-exclamation"></i>
                <p>We couldn't load the scenes.</p>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="csSceneRetry">Try Again</button>
            </div>
            <div class="cs-empty" id="csSceneEmpty" hidden>
                <div class="cs-empty__icon"><i class="fa-solid fa-panorama"></i></div>
                <h2 class="cs-empty__title">No Scenes Yet</h2>
                <p class="cs-empty__text">Save a scene of your own and run it with any of your influencers.</p>
                <button type="button" class="btn btn-secondary" id="csSceneNewEmpty"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Scene</button>
            </div>
            <div class="cs-scenes" id="csScenes" hidden></div>

            <!-- create / edit one of the creator's own scenes -->
            <div class="modal fade ai-modal" id="csSceneEdit" tabindex="-1" aria-labelledby="csSceneEditTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg"><div class="modal-content">
                    <div class="modal-header"><h2 class="modal-title ai-modal__title" id="csSceneEditTitle">New Scene</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <form class="cs-sedit" id="csSceneEditForm" autocomplete="off" onsubmit="return false;">
                            <input type="hidden" id="csSceneEditId" value="0">
                            <div class="ai-field"><label class="ai-label" for="csSceneEditName">Title</label><input type="text" class="form-control" id="csSceneEditName" maxlength="120" placeholder="Rooftop Cafe"><p class="ai-error" data-err="title" role="alert" hidden></p></div>
                            <div class="cs-sedit__row">
                                <div class="ai-field"><label class="ai-label" for="csSceneEditCat">Category</label><input type="text" class="form-control" id="csSceneEditCat" maxlength="60" placeholder="Lifestyle" list="csSceneEditCats"><datalist id="csSceneEditCats"></datalist></div>
                                <div class="ai-field"><label class="ai-label" for="csSceneEditAspect">Default Shape</label><select class="form-select" id="csSceneEditAspect"><?php foreach (Aspect::options() as $ao): ?><option value="<?php echo $ao['key']; ?>"><?php echo $ao['name'] . ' ' . $ao['label']; ?></option><?php endforeach; ?></select><p class="ai-error" data-err="default_aspect" role="alert" hidden></p></div>
                            </div>
                            <div class="ai-field"><label class="ai-label" for="csSceneEditPrompt">Base Prompt</label><textarea class="form-control" id="csSceneEditPrompt" rows="5" maxlength="4000" placeholder="photo of a {subject} at a rooftop cafe at golden hour, iced coffee in hand"></textarea><p class="ai-error" data-err="base_prompt" role="alert" hidden></p></div>
                            <div class="cs-sedit__foot">
                                <div class="cs-sedit__thumb">
                                    <div class="ai-label" id="csSceneEditThumbLabel">Thumbnail</div>
                                    <div class="cs-sedit__image" role="group" aria-labelledby="csSceneEditThumbLabel">
                                        <button type="button" class="cs-sedit__pick" id="csSceneEditThumbBtn" aria-label="Choose Image"><img id="csSceneEditThumbImg" alt="" hidden><i class="fa-regular fa-image" aria-hidden="true"></i></button>
                                        <button type="button" class="btn btn-secondary btn-sm" id="csSceneEditThumbChoose">Choose Image</button>
                                        <input type="file" id="csSceneEditThumb" accept="image/jpeg,image/png,image/webp" hidden>
                                    </div>
                                </div>
                                <label class="form-check form-switch cs-sedit__switch" for="csSceneEditAdult"><input class="form-check-input" type="checkbox" id="csSceneEditAdult"> <span>Adult</span></label>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer"><span class="ai-cost" id="csSceneEditState" role="status"></span>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="csSceneEditSave">Save Scene</button></div>
                </div></div>
            </div>
        </div>
