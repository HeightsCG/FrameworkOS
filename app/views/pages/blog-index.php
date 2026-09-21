<?php
/** Blog index: title + search on one row, then every post as one dense full-width row (bl-*), built for hundreds of posts. */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$when = function ($utc) { return date('M j, Y', strtotime($utc . ' UTC')); };
$iso = function ($utc) { return gmdate('Y-m-d', strtotime($utc . ' UTC')); };
$starter = null; foreach ($guides as $g) { if ($g['path'] === '/monetize-your-content') { $starter = $g; } }

?>
<?php
$topic_links = ''; foreach (array('Pricing', 'Memberships', 'Pay-per-view', 'Payouts', 'Bundles', 'Services', 'Events', 'Social') as $tp) { $topic_links .= '<a class="hx__chip" href="/blog?q=' . $e(rawurlencode(strtolower($tp))) . '">' . $e($tp) . '</a>'; }
$search_form = '<form class="bl-search" action="/blog" method="get" role="search"><label class="bl-search__label" for="bl_q">Search posts</label>'
    . '<svg class="bl-search__ic" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
    . '<input class="bl-search__input" type="search" id="bl_q" name="q" value="' . $e($q) . '" placeholder="Pricing, payouts, pay-per-view" maxlength="80" autocomplete="off">'
    . '<button class="sx-btn sx-btn--primary bl-search__btn" type="submit">Search</button></form>';
?>
<header class="ld-wrap sx__in bl-top">
    <h1 class="bl-top__title"><?php echo $e(BlogController::NAME); ?></h1>
    <p class="bl-top__lead">Plain answers about pricing and selling memberships, pay-per-view posts, bundles, services and events.</p>
    <?php echo $search_form; ?>
    <div class="hx__chips hx__chips--links bl-top__topics"><?php echo $topic_links; ?></div>
</header>
<?php
?>
<div class="ld-wrap sx__in bl">

<?php if ($q !== ''): ?>
    <div class="bl-results">
        <p class="bl-results__n"><?php echo $total === 0 ? 'No posts match' : ($total === 1 ? '1 post matches' : ((int) $total . ' posts match')); ?> <strong>&ldquo;<?php echo $e($q); ?>&rdquo;</strong></p>
        <a class="bl-results__clear" href="/blog">Clear Search</a>
    </div>
    <?php if ($total === 0): ?><p class="bl-empty">Try a shorter or different word, or browse <a href="/blog">all posts</a>.</p><?php endif; ?>
<?php elseif (empty($articles) && $starter): ?>
    <p class="bl-empty">New posts are on the way. Start with <a href="<?php echo $e($starter['path']); ?>">how creators get paid, and how to price each way</a>.</p>
<?php endif; ?>



<?php if (!empty($articles)): ?>
    <ol class="bl-list">
        <?php foreach ($articles as $a): ?>
        <li class="bl-item">
            <a class="bl-item__link" href="/blog/<?php echo $e($a['slug']); ?>">
                <span class="bl-item__meta"><span class="bl-topic bl-item__topic"><?php echo $e(BlogController::topic($a)); ?></span><time class="bl-item__date" datetime="<?php echo $e($iso($a['published_at'])); ?>"><?php echo $e($when($a['published_at'])); ?></time></span>
                <span class="bl-item__main"><span class="bl-item__title"><?php echo $e($a['title']); ?></span><span class="bl-item__x"><?php echo $e($a['excerpt'] ?: $a['meta_description']); ?></span></span>
                <span class="bl-item__read"><?php echo (int) $a['reading_minutes']; ?> min</span>
            </a>
        </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if ($pages > 1): ?>
    <nav class="bl-pager" aria-label="More posts">
        <?php $qs = function ($n) use ($q) { $p = array(); if ($q !== '') { $p['q'] = $q; } if ($n > 1) { $p['page'] = $n; } return '/blog' . (empty($p) ? '' : '?' . http_build_query($p)); }; ?>
        <?php if ($page > 1): ?><a class="sx-btn sx-btn--secondary" href="<?php echo $e($qs($page - 1)); ?>"><?php echo $q !== '' ? 'Previous' : 'Newer Posts'; ?></a><?php endif; ?>
        <span class="bl-pager__n">Page <?php echo (int) $page; ?> of <?php echo (int) $pages; ?></span>
        <?php if ($page < $pages): ?><a class="sx-btn sx-btn--secondary" href="<?php echo $e($qs($page + 1)); ?>"><?php echo $q !== '' ? 'More Results' : 'Older Posts'; ?></a><?php endif; ?>
    </nav>
<?php endif; ?>
</div>
