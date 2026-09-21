<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$when = function ($utc) { return date('M j, Y', strtotime($utc . ' UTC')); };
$topic = function ($a) { return BlogController::topic($a); };
$starter = null; foreach ($guides as $g) { if ($g['path'] === '/monetize-your-content') { $starter = $g; } }
?>
<div class="gd">
    <section class="gd-hero">
        <h1 class="gd-hero__title"><?php echo $e(BlogController::NAME); ?></h1>
        <p class="gd-hero__lead">Plain answers about pricing and selling memberships, pay-per-view posts, bundles, services and events.</p>
        <form class="gd-search" action="/blog" method="get" role="search">
            <label class="gd-search__label" for="gd_q">Search posts</label>
            <input class="gd-search__input" type="search" id="gd_q" name="q" value="<?php echo $e($q); ?>" placeholder="Pricing, payouts, pay-per-view" maxlength="80" autocomplete="off">
            <button class="gd-read" type="submit">Search</button>
        </form>
    </section>

<?php if ($q !== ''): ?>
    <div class="gd-results">
        <p class="gd-results__n"><?php echo $total === 0 ? 'No posts match' : ($total === 1 ? '1 post matches' : ((int) $total . ' posts match')); ?> <strong>&ldquo;<?php echo $e($q); ?>&rdquo;</strong></p>
        <a class="gd-results__clear" href="/blog">Clear Search</a>
    </div>
    <?php if ($total === 0): ?><p class="gd-empty">Try a shorter or different word, or browse <a href="/blog">all posts</a>.</p><?php endif; ?>
<?php elseif (empty($articles) && $starter): ?>
    <p class="gd-empty">New posts are on the way. Start with <a href="<?php echo $e($starter['path']); ?>">how creators get paid, and how to price each way</a>.</p>
<?php endif; ?>

<?php if (!empty($articles)): ?>
    <ol class="gd-list">
        <?php foreach ($articles as $a): $img = trim((string) ($a['cover_image_url'] ?? '')); ?>
        <li class="gd-row<?php echo $img === '' ? ' gd-row--noimg' : ''; ?>">
            <?php if ($img !== ''): ?><a class="gd-row__img" href="/blog/<?php echo $e($a['slug']); ?>" tabindex="-1" aria-hidden="true"><img src="<?php echo $e($img); ?>" alt="" loading="lazy" width="160" height="120"></a><?php endif; ?>
            <div class="gd-row__body">
                <p class="gd-row__topic"><?php echo $e($topic($a)); ?></p>
                <h2 class="gd-row__title"><a href="/blog/<?php echo $e($a['slug']); ?>"><?php echo $e($a['title']); ?></a></h2>
                <p class="gd-row__x"><?php echo $e($a['excerpt'] ?: $a['meta_description']); ?></p>
                <p class="gd-date"><time datetime="<?php echo $e(gmdate('Y-m-d', strtotime($a['published_at'] . ' UTC'))); ?>"><?php echo $e($when($a['published_at'])); ?></time>, <?php echo (int) $a['reading_minutes']; ?>-minute read</p>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if ($pages > 1): ?>
    <nav class="gd-pager" aria-label="More posts">
        <?php $qs = function ($n) use ($q) { $p = array(); if ($q !== '') { $p['q'] = $q; } if ($n > 1) { $p['page'] = $n; } return '/blog' . (empty($p) ? '' : '?' . http_build_query($p)); }; ?>
        <?php if ($page > 1): ?><a class="gd-read" href="<?php echo $e($qs($page - 1)); ?>"><?php echo $q !== '' ? 'Previous' : 'Newer Posts'; ?></a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="gd-read" href="<?php echo $e($qs($page + 1)); ?>"><?php echo $q !== '' ? 'More Results' : 'Older Posts'; ?></a><?php endif; ?>
    </nav>
<?php endif; ?>
</div>
