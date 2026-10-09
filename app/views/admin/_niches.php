<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $niches = (array) ($this->niches ?? array()); $niche_n = (array) ($this->niche_counts ?? array()); ?>
<section class="adm-sec adm-panel" data-panel="niches">
    <div class="adm-sec__head">
        <span class="adm-sec__meta"><?php echo count($niches); ?> niches</span>
        <button type="button" class="adm-btn adm-btn--ok" id="admNicheNew"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Niche</button>
    </div>
    <?php if (empty($niches)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-tags"></i></span><p class="adm-empty__t">No Niches</p><p class="adm-empty__x">Niches are the categories of the Creator Directory and the choices in creator Settings.</p></div>
    <?php else: ?>
    <div class="adm-table adm-table--niches">
        <div class="adm-table__head"><span>Niche</span><span class="adm-r">Creators</span><span>Status</span><span></span></div>
        <div class="adm-table__body">
            <?php $last = count($niches) - 1; foreach (array_values($niches) as $i => $n): $nj = array('id' => (int) $n['id'], 'slug' => (string) $n['slug'], 'name' => (string) $n['name'], 'active' => (int) $n['active']); ?>
            <div class="adm-crow adm-nrow" data-niche="<?php echo $e(json_encode($nj)); ?>">
                <div class="adm-ucell"><a class="adm-crow__title" href="/creators/<?php echo $e($n['slug']); ?>" target="_blank" rel="noopener"><?php echo $e($n['name']); ?></a><span class="adm-crow__sub">/creators/<?php echo $e($n['slug']); ?></span></div>
                <div class="adm-ucell adm-ucell--muted adm-r"><?php echo number_format((int) ($niche_n[$n['slug']] ?? 0)); ?></div>
                <div class="adm-ucell"><span class="adm-tag <?php echo !empty($n['active']) ? 'adm-tag--bundle' : 'adm-tag--off'; ?>"><?php echo !empty($n['active']) ? 'Live' : 'Off'; ?></span></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Niche actions', array(
                    array('text' => 'Rename', 'attrs' => 'data-niche-action="rename"'),
                    $i > 0 ? array('text' => 'Move Up', 'attrs' => 'data-niche-action="up"') : null,
                    $i < $last ? array('text' => 'Move Down', 'attrs' => 'data-niche-action="down"') : null,
                    array('text' => !empty($n['active']) ? 'Turn Off' : 'Turn On', 'attrs' => 'data-niche-action="toggle"', 'danger' => !empty($n['active'])),
                )); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade ai-modal" id="admNicheModal" tabindex="-1" aria-labelledby="admNicheTitle" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title ai-modal__title" id="admNicheTitle">New Niche</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <form id="admNicheForm" autocomplete="off" onsubmit="return false;">
                    <input type="hidden" id="admNicheId" value="0">
                    <div class="ai-field"><label class="ai-label" for="admNicheName">Name</label><input type="text" class="form-control" id="admNicheName" maxlength="60" placeholder="Photography"><p class="ai-error" data-err="name" role="alert" hidden></p></div>
                    <div class="ai-field" id="admNicheSlugField"><label class="ai-label" for="admNicheSlug">URL Slug</label><input type="text" class="form-control" id="admNicheSlug" maxlength="40" placeholder="photography"><p class="ai-error" data-err="slug" role="alert" hidden></p></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="admNicheSave">Save Niche</button></div>
        </div></div>
    </div>
</section>
