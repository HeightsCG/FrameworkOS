<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php $niches = (array) ($this->niches ?? array()); $niche_n = (array) ($this->niche_counts ?? array()); ?>
<section class="adm-box adm-panel" data-panel="niches">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Niches</h2>
        <div class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search niche" aria-label="Search niches" data-search-for="admNiches"></div>
        <button type="button" class="adm-btn adm-btn--primary adm-box__end" id="admNicheNew">New Niche</button>
    </header>
    <?php if (empty($niches)): ?>
        <?php echo adm_empty('No niches yet', 'fa-tag'); ?>
    <?php else: ?>
    <table class="adm-t" id="admNiches">
        <thead><tr><th>Niche</th><th class="adm-r">Creators</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php $last = count($niches) - 1; foreach (array_values($niches) as $i => $n): $nj = array('id' => (int) $n['id'], 'slug' => (string) $n['slug'], 'name' => (string) $n['name'], 'active' => (int) $n['active']); ?>
            <tr class="adm-crow adm-nrow" data-niche="<?php echo $e(json_encode($nj)); ?>">
                <td class="adm-t__main"><a href="/creators/<?php echo $e($n['slug']); ?>" target="_blank" rel="noopener"><?php echo $e($n['name']); ?></a><span class="adm-t__sub">/creators/<?php echo $e($n['slug']); ?></span></td>
                <td class="adm-r adm-t__num adm-t__muted"><?php echo number_format((int) ($niche_n[$n['slug']] ?? 0)); ?></td>
                <td><?php echo adm_pill(!empty($n['active']) ? 'Live' : 'Off', !empty($n['active']) ? 'ok' : 'gray'); ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Niche actions', array(
                    array('text' => 'Rename', 'attrs' => 'data-niche-action="rename"'),
                    $i > 0 ? array('text' => 'Move Up', 'attrs' => 'data-niche-action="up"') : null,
                    $i < $last ? array('text' => 'Move Down', 'attrs' => 'data-niche-action="down"') : null,
                    array('text' => !empty($n['active']) ? 'Turn Off' : 'Turn On', 'attrs' => 'data-niche-action="toggle"', 'danger' => !empty($n['active'])),
                )); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admNichesNone" hidden>No matching niches.</p>
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
