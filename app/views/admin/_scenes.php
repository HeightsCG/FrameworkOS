<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $scenes = (array) ($this->scenes ?? array()); ?>
<section class="adm-sec adm-panel" data-panel="scenes">
    <div class="adm-sec__head">
        <h2 class="adm-sec__title">Scene Templates</h2>
        <button type="button" class="adm-btn adm-btn--ok" id="admSceneNew"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Scene</button>
    </div>
    <?php if (empty($scenes)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-panorama"></i></span><p class="adm-empty__t">No Scene Templates</p><p class="adm-empty__x">Creators see templates in Influencers, Generate Images, Scenes.</p></div>
    <?php else: ?>
    <div class="adm-table adm-table--scenes">
        <div class="adm-table__head"><span>Scene</span><span>Shape</span><span>Rating</span><span>Status</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($scenes as $s): $j = SceneTemplates::json($s, true); ?>
            <div class="adm-crow adm-srow" data-scene="<?php echo $e(json_encode($j)); ?>">
                <div class="adm-ucell adm-srow__main">
                    <span class="adm-srow__thumb"><?php if ($j['thumb_url'] !== ''): ?><img src="<?php echo $e($j['thumb_url']); ?>" alt=""><?php else: ?><i class="fa-regular fa-image" aria-hidden="true"></i><?php endif; ?></span>
                    <span><span class="adm-crow__title"><?php echo $e($j['title']); ?></span><span class="adm-crow__sub"><?php echo $e($j['category'] !== '' ? $j['category'] : 'No category'); ?></span></span>
                </div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($j['default_aspect']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><i class="fa-regular fa-thumbs-up" aria-hidden="true"></i> <?php echo (int) $j['ups']; ?> <span class="adm-srow__sep">·</span> <i class="fa-regular fa-thumbs-down" aria-hidden="true"></i> <?php echo (int) $j['downs']; ?></div>
                <div class="adm-ucell"><span class="adm-tag <?php echo $j['is_active'] ? 'adm-tag--bundle' : 'adm-tag--off'; ?>"><?php echo $j['is_active'] ? 'Live' : 'Off'; ?></span><?php if ($j['is_adult']): ?> <span class="adm-tag adm-tag--flag">Adult</span><?php endif; ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Scene actions', array(
                    array('text' => 'Edit', 'attrs' => 'data-scene-action="edit"'),
                    array('text' => $j['is_active'] ? 'Turn Off' : 'Turn On', 'attrs' => 'data-scene-action="toggle"'),
                    array('text' => 'Delete', 'attrs' => 'data-scene-action="delete"', 'danger' => true),
                )); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade ai-modal" id="admSceneModal" tabindex="-1" aria-labelledby="admSceneTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title ai-modal__title" id="admSceneTitle">New Scene</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <form class="adm-scene" id="admSceneForm" autocomplete="off" onsubmit="return false;">
                    <input type="hidden" id="admSceneId" value="0">
                    <div class="adm-scene__thumb">
                        <div class="ai-label">Thumbnail</div>
                        <button type="button" class="adm-scene__pick" id="admSceneThumbBtn" aria-label="Choose a thumbnail"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose Image</span></button>
                        <input type="file" id="admSceneThumb" accept="image/jpeg,image/png,image/webp" hidden>
                    </div>
                    <div class="adm-scene__fields">
                        <div class="ai-field"><label class="ai-label" for="admSceneName">Title</label><input type="text" class="form-control" id="admSceneName" maxlength="120" placeholder="Rooftop Cafe"><p class="ai-error" data-err="title" role="alert" hidden></p></div>
                        <div class="adm-scene__row">
                            <div class="ai-field"><label class="ai-label" for="admSceneCat">Category</label><input type="text" class="form-control" id="admSceneCat" maxlength="60" placeholder="Lifestyle" list="admSceneCats">
                                <datalist id="admSceneCats"><?php foreach (array_unique(array_filter(array_map(function ($s) { return (string) $s['category']; }, $scenes))) as $c): ?><option value="<?php echo $e($c); ?>"><?php endforeach; ?></datalist></div>
                            <div class="ai-field"><label class="ai-label" for="admSceneAspect">Default Shape</label><select class="form-select" id="admSceneAspect"><?php foreach (Aspect::options() as $ao): ?><option value="<?php echo $ao['key']; ?>"><?php echo $ao['name'] . ' ' . $ao['label']; ?></option><?php endforeach; ?></select><p class="ai-error" data-err="default_aspect" role="alert" hidden></p></div>
                            <div class="ai-field"><label class="ai-label" for="admSceneSort">Sort Order</label><input type="number" class="form-control" id="admSceneSort" value="0" step="1"></div>
                        </div>
                        <div class="ai-field"><label class="ai-label" for="admScenePrompt">Base Prompt</label><textarea class="form-control" id="admScenePrompt" rows="5" maxlength="4000" placeholder="photo of a {subject} at a rooftop cafe at golden hour, iced coffee in hand"></textarea><p class="ai-error" data-err="base_prompt" role="alert" hidden></p></div>
                        <div class="adm-scene__switches">
                            <label class="form-check form-switch m-0" for="admSceneActive"><input class="form-check-input" type="checkbox" id="admSceneActive" checked> <span>Live</span></label>
                            <label class="form-check form-switch m-0" for="admSceneAdult"><input class="form-check-input" type="checkbox" id="admSceneAdult"> <span>Adult</span></label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer"><span class="ai-cost" id="admSceneState" role="status"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="admSceneSave">Save Scene</button></div>
        </div></div>
    </div>
</section>
