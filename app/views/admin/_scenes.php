<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php $scenes = (array) ($this->scenes ?? array()); ?>
<section class="adm-box adm-panel" data-panel="scenes">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Scene templates</h2>
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search scene or category" aria-label="Search scenes" data-search-for="admScenes"></div>
        <div class="adm-seg" role="tablist" aria-label="Status" data-filter-for="admScenes" data-filter-attr="data-live">
            <button type="button" role="tab" class="adm-seg__b is-on" aria-selected="true" data-filter="all">All</button>
            <button type="button" role="tab" class="adm-seg__b" aria-selected="false" data-filter="live">Live</button>
            <button type="button" role="tab" class="adm-seg__b" aria-selected="false" data-filter="off">Off</button>
            <button type="button" role="tab" class="adm-seg__b" aria-selected="false" data-filter="adult">Adult</button>
        </div>
        <button type="button" class="adm-btn adm-btn--primary adm-box__end" id="admSceneNew">New Scene</button>
    </header>
    <?php if (empty($scenes)): ?>
        <?php echo adm_empty('No scene templates yet', 'fa-image'); ?>
    <?php else: ?>
    <table class="adm-t" id="admScenes" data-sortable>
        <thead><tr><th data-sort="text">Scene</th><th data-sort="text">Category</th><th>Shape</th><th class="adm-r" data-sort="num">Rating</th><th data-sort="text">Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($scenes as $s): $j = SceneTemplates::json($s, true); ?>
            <tr class="adm-crow adm-srow" data-scene="<?php echo $e(json_encode($j)); ?>" data-live="<?php echo $j['is_active'] ? 'live' : 'off'; ?><?php echo $j['is_adult'] ? ' adult' : ''; ?>">
                <td class="adm-t__main"><span class="adm-t__thumb"><?php if ($j['thumb_url'] !== ''): ?><img src="<?php echo $e($j['thumb_url']); ?>" alt=""><?php endif; ?></span><?php echo $e($j['title']); ?></td>
                <td class="adm-t__muted"><?php echo $e($j['category'] !== '' ? $j['category'] : '—'); ?></td>
                <td class="adm-t__muted"><?php echo $e($j['default_aspect']); ?></td>
                <td class="adm-r adm-t__num adm-t__muted" data-value="<?php echo (int) $j['ups'] - (int) $j['downs']; ?>"><?php echo (int) $j['ups']; ?> up · <?php echo (int) $j['downs']; ?> down</td>
                <td><?php echo adm_pill($j['is_active'] ? 'Live' : 'Off', $j['is_active'] ? 'ok' : 'gray'); ?><?php if ($j['is_adult']): ?> <?php echo adm_pill('Adult', 'bad'); ?><?php endif; ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Scene actions', array(
                    array('text' => 'Edit', 'attrs' => 'data-scene-action="edit"'),
                    array('text' => $j['is_active'] ? 'Turn Off' : 'Turn On', 'attrs' => 'data-scene-action="toggle"'),
                    array('text' => 'Delete', 'attrs' => 'data-scene-action="delete"', 'danger' => true),
                )); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admScenesNone" hidden>No matching scenes.</p>
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
                                <datalist id="admSceneCats"><?php foreach (array_unique(array_filter(array_map(function ($s) { return (string) $s['category']; }, $scenes))) as $c): ?><option value="<?php echo $e($c); ?>"></option><?php endforeach; ?></datalist></div>
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
