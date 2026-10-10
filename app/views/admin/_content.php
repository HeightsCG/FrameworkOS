<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<section class="adm-box adm-panel" data-panel="content">
    <header class="adm-box__h">
        <h2 class="adm-box__t">Articles</h2>
        <div class="adm-seg adm-subtabs" role="tablist" aria-label="Articles">
            <button type="button" class="adm-seg__b adm-subtab is-active is-on" role="tab" aria-selected="true" data-sub="published">Published <b><?php echo count($this->seo_published); ?></b></button>
            <button type="button" class="adm-seg__b adm-subtab" role="tab" aria-selected="false" data-sub="keywords">Keyword queue <b><?php echo count($this->seo_keywords); ?></b></button>
            <button type="button" class="adm-seg__b adm-subtab" role="tab" aria-selected="false" data-sub="review">Unpublished <b><?php echo count($this->seo_review); ?></b></button>
        </div>
    </header>

    <div class="adm-subpanel" data-sub="review" hidden>
    <?php if (empty($this->seo_review)): ?>
        <?php echo adm_empty('No unpublished articles', 'fa-newspaper'); ?>
    <?php else: ?>
    <table class="adm-t" data-sortable>
        <thead><tr><th data-sort="text">Article</th><th data-sort="text">Keyword</th><th data-sort="text" data-sorted="desc">Updated</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($this->seo_review as $a): ?>
            <tr class="adm-crow adm-t__link" data-article="<?php echo (int) $a['id']; ?>" data-href="/admin/article/<?php echo (int) $a['id']; ?>">
                <td class="adm-t__main"><a href="/admin/article/<?php echo (int) $a['id']; ?>"><?php echo $e($a['title']); ?></a><span class="adm-t__sub">/blog/<?php echo $e($a['slug']); ?> · <?php echo $e(ucfirst($a['status'])); ?></span></td>
                <td class="adm-t__muted"><?php echo $e($a['target_keyword']); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($a['updated_at']); ?>"><?php echo $e($fmt($a['updated_at'], true)); ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Article actions', array(array('text' => 'Edit', 'href' => '/admin/article/' . (int) $a['id']))); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>

    <div class="adm-subpanel" data-sub="keywords" hidden>
    <form class="adm-kwadd" id="admKwAdd">
        <input type="text" name="keyword" placeholder="Keyword" maxlength="160" required>
        <input type="number" name="volume" placeholder="Volume" min="0">
        <select name="difficulty"><option value="easy">Easy</option><option value="doable" selected>Doable</option><option value="hard">Hard</option></select>
        <select name="cluster" required><option value="">Cluster</option><?php foreach (SeoDrafter::CLUSTERS as $ck => $cd): ?><option value="<?php echo $e($ck); ?>"><?php echo $e($cd['label']); ?></option><?php endforeach; ?></select>
        <input type="number" name="priority" placeholder="Priority" value="100" min="1">
        <button type="submit" class="adm-btn">Add Keyword</button>
    </form>
    <table class="adm-t" id="admKeywordsT" data-sortable data-pager>
        <thead><tr><th data-sort="text">Keyword</th><th class="adm-r" data-sort="num">Volume</th><th data-sort="text">Difficulty</th><th>Priority</th><th data-sort="text">Status</th><th></th></tr></thead>
        <tbody id="admKeywords">
        <?php foreach ($this->seo_keywords as $k): ?>
            <tr class="adm-krow" data-keyword="<?php echo (int) $k['id']; ?>" data-status="<?php echo $e($k['status']); ?>">
                <td class="adm-t__main"><?php echo $e($k['keyword']); ?><?php if ($k['article_slug']): ?><a class="adm-t__sub" href="/admin/article/<?php echo (int) $k['article_id']; ?>"><?php echo $e($k['article_title']); ?></a><?php endif; ?></td>
                <td class="adm-r adm-t__num adm-t__muted" data-value="<?php echo (int) $k['volume']; ?>"><?php echo $k['volume'] === null ? '—' : number_format((int) $k['volume']); ?></td>
                <td class="adm-t__muted"><?php echo $e(ucfirst($k['difficulty'])); ?></td>
                <td><input class="adm-kprio" type="number" value="<?php echo (int) $k['priority']; ?>" min="1" aria-label="Priority"></td>
                <td><?php echo adm_pill(ucfirst($k['status'])); ?></td>
                <td class="adm-t__act"><?php
                    $kw_items = $k['status'] === 'queued' ? array(array('text' => 'Draft now', 'attrs' => 'data-kw-action="draft"'), array('text' => 'Skip', 'attrs' => 'data-kw-action="skip"'))
                              : (in_array($k['status'], array('skipped', 'drafting'), true) ? array(array('text' => 'Requeue', 'attrs' => 'data-kw-action="requeue"')) : array());
                    echo adm_row_menu('Keyword actions', $kw_items); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="adm-subpanel" data-sub="published">
    <?php if (empty($this->seo_published)): ?>
        <?php echo adm_empty('Nothing published yet', 'fa-newspaper'); ?>
    <?php else: ?>
    <table class="adm-t" id="admPublished" data-sortable data-pager>
        <thead><tr><th data-sort="text">Article</th><th data-sort="text">Keyword</th><th class="adm-r" data-sort="num">Views</th><th data-sort="text">Published</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($this->seo_published as $a): ?>
            <tr class="adm-crow adm-t__link" data-article="<?php echo (int) $a['id']; ?>" data-href="/admin/article/<?php echo (int) $a['id']; ?>">
                <td class="adm-t__main"><a href="/admin/article/<?php echo (int) $a['id']; ?>"><?php echo $e($a['title']); ?></a><span class="adm-t__sub">/blog/<?php echo $e($a['slug']); ?></span></td>
                <td class="adm-t__muted"><?php echo $e($a['target_keyword']); ?></td>
                <td class="adm-r adm-t__num adm-t__muted" data-value="<?php echo (int) $a['views']; ?>"><?php echo number_format((int) $a['views']); ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($a['published_at'] ?? $a['updated_at']); ?>"><?php echo $e($fmt($a['published_at'] ?? $a['updated_at'])); ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Article actions', array(array('text' => 'View on blog', 'href' => '/blog/' . $a['slug'], 'attrs' => 'target="_blank" rel="noopener"'), array('text' => 'Edit', 'href' => '/admin/article/' . (int) $a['id']), array('text' => 'Unpublish…', 'attrs' => 'data-art-action="unpublish"', 'danger' => true))); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>
</section>
