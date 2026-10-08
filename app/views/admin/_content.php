<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<section class="adm-sec adm-panel" data-panel="content">
    <div class="adm-subtabs" role="tablist" aria-label="Content">
        <button type="button" class="adm-subtab is-active" role="tab" aria-selected="true" data-sub="published">Published <b><?php echo count($this->seo_published); ?></b></button>
        <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-sub="keywords">Keyword Queue <b><?php echo count($this->seo_keywords); ?></b></button>
        <button type="button" class="adm-subtab" role="tab" aria-selected="false" data-sub="review">Unpublished <b><?php echo count($this->seo_review); ?></b></button>
    </div>
    <div class="adm-subpanel" data-sub="review" hidden>
    <div class="adm-sec__head">
        <h2 class="adm-sec__title">Unpublished</h2>
    </div>
    <?php if (empty($this->seo_review)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-newspaper"></i></span><p class="adm-empty__t">No Unpublished Articles</p></div>
    <?php else: ?>
    <div class="adm-table adm-table--content">
        <div class="adm-table__head"><span>Article</span><span>Keyword</span><span>Updated</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($this->seo_review as $a): ?>
            <div class="adm-crow" data-article="<?php echo (int) $a['id']; ?>">
                <div class="adm-ucell"><a class="adm-crow__title" href="/admin/article/<?php echo (int) $a['id']; ?>"><?php echo $e($a['title']); ?></a><span class="adm-crow__sub">/blog/<?php echo $e($a['slug']); ?> · <?php echo (int) $a['reading_minutes']; ?> min</span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($a['target_keyword']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e(date('M j, H:i', strtotime($a['updated_at'] . ' UTC'))); ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Article actions', array(array('text' => 'Edit', 'href' => '/admin/article/' . (int) $a['id']))); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    </div>
    <div class="adm-subpanel" data-sub="keywords" hidden>
    <div class="adm-sec__head">
        <h2 class="adm-sec__title">Keyword Queue</h2>
        <form class="adm-kwadd" id="admKwAdd">
            <input type="text" name="keyword" placeholder="Keyword" maxlength="160" required>
            <input type="number" name="volume" placeholder="Volume" min="0">
            <select name="difficulty"><option value="easy">Easy</option><option value="doable" selected>Doable</option><option value="hard">Hard</option></select>
            <select name="cluster" required><option value="">Cluster</option><?php foreach (SeoDrafter::CLUSTERS as $ck => $cd): ?><option value="<?php echo $e($ck); ?>"><?php echo $e($cd['label']); ?></option><?php endforeach; ?></select>
            <input type="number" name="priority" placeholder="Priority" value="100" min="1">
            <button type="submit" class="adm-btn adm-btn--ok">Add Keyword</button>
        </form>
    </div>
    <div class="adm-table adm-table--keywords">
        <div class="adm-table__head"><span>Keyword</span><span>Volume</span><span>Difficulty</span><span>Priority</span><span>Status</span><span></span></div>
        <div class="adm-table__body" id="admKeywords">
            <?php foreach ($this->seo_keywords as $k): ?>
            <div class="adm-krow" data-keyword="<?php echo (int) $k['id']; ?>" data-status="<?php echo $e($k['status']); ?>">
                <div class="adm-ucell"><?php echo $e($k['keyword']); ?><?php if ($k['article_slug']): ?><a class="adm-crow__sub" href="/admin/article/<?php echo (int) $k['article_id']; ?>"><?php echo $e($k['article_title']); ?></a><?php endif; ?><?php if ($k['last_error']): ?><span class="adm-crow__err" title="<?php echo $e($k['last_error']); ?>">Last draft failed</span><?php endif; ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $k['volume'] === null ? '—' : number_format((int) $k['volume']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e(ucfirst($k['difficulty'])); ?></div>
                <div class="adm-ucell"><input class="adm-kprio" type="number" value="<?php echo (int) $k['priority']; ?>" min="1" aria-label="Priority"></div>
                <div class="adm-ucell"><span class="adm-tag adm-tag--<?php echo $e($k['status']); ?>"><?php echo $e(ucfirst($k['status'])); ?></span></div>
                <div class="adm-ucell adm-ucell--act"><?php
                    $kw_items = $k['status'] === 'queued' ? array(array('text' => 'Draft now', 'attrs' => 'data-kw-action="draft"'), array('text' => 'Skip', 'attrs' => 'data-kw-action="skip"'))
                              : (in_array($k['status'], array('skipped', 'drafting'), true) ? array(array('text' => 'Requeue', 'attrs' => 'data-kw-action="requeue"')) : array());
                    echo adm_row_menu('Keyword actions', $kw_items); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    </div>
    <div class="adm-subpanel" data-sub="published">
    <div class="adm-sec__head">
        <h2 class="adm-sec__title">Published</h2>
    </div>
    <div class="adm-table adm-table--content">
        <div class="adm-table__head"><span>Article</span><span>Keyword</span><span>Views</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($this->seo_published as $a): ?>
            <div class="adm-crow" data-article="<?php echo (int) $a['id']; ?>">
                <div class="adm-ucell"><a class="adm-crow__title" href="/blog/<?php echo $e($a['slug']); ?>" target="_blank" rel="noopener"><?php echo $e($a['title']); ?></a><span class="adm-crow__sub"><?php echo $e(date('M j, Y', strtotime($a['published_at'] . ' UTC'))); ?></span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($a['target_keyword']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo number_format((int) $a['views']); ?></div>
                <div class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Article actions', array(array('text' => 'Edit', 'href' => '/admin/article/' . (int) $a['id']), array('text' => 'Unpublish…', 'attrs' => 'data-art-action="unpublish"'))); ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($this->seo_published)): ?><p class="adm__none">Nothing published yet.</p><?php endif; ?>
        </div>
    </div>
    </div>
</section>
