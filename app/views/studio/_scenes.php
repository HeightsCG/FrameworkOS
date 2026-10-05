<?php /* Content Studio, Scenes tab: the admin-managed scene template library. Filled by studio-ai.js on first open. */ ?>
        <!-- ============ SCENES ============ -->
        <div class="tab-pane fade" id="csPaneScenes" role="tabpanel">
            <div class="cs-toolbar" id="csSceneBar" hidden>
                <div class="cs-scene__cats" id="csSceneCats" role="group" aria-label="Category"></div>
                <?php echo Tutorials::button('26', 'data-push'); ?>
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
                <p class="cs-empty__text">Scene templates will appear here as they are added.</p>
            </div>
            <div class="cs-scenes" id="csScenes" hidden></div>
        </div>
