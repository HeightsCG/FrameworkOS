<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<p class="pub-eyebrow">Guides</p>
<h1 class="pub-h1">Guides for creators</h1>
<p class="pub-lead">Practical, specific writing on selling memberships, pay-per-view, bundles, services and events, and on running it all from one page.</p>

<?php if (empty($articles)): ?>
<p class="pub-p">New guides are on the way. Start with the product guides below.</p>
<?php else: ?>
<div class="pub-cards">
    <?php foreach ($articles as $a): ?>
    <a class="pub-card" href="/blog/<?php echo $e($a['slug']); ?>">
        <span class="pub-card__meta"><?php echo $e(date('M j, Y', strtotime($a['published_at'] . ' UTC'))); ?> · <?php echo (int) $a['reading_minutes']; ?> min read</span>
        <span class="pub-card__title"><?php echo $e($a['title']); ?></span>
        <span class="pub-card__x"><?php echo $e($a['excerpt'] ?: $a['meta_description']); ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="pub-pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="ld-btn ld-btn--quiet" href="/blog<?php echo $page > 2 ? '?page=' . ($page - 1) : ''; ?>">Newer</a><?php endif; ?>
    <span class="pub-pager__n">Page <?php echo (int) $page; ?> of <?php echo (int) $pages; ?></span>
    <?php if ($page < $pages): ?><a class="ld-btn ld-btn--quiet" href="/blog?page=<?php echo $page + 1; ?>">Older</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<h2 class="pub-h2">Product guides</h2>
<ul class="pub-list">
    <?php foreach ($guides as $g): ?><li><a href="<?php echo $e($g['path']); ?>"><?php echo $e($g['title']); ?></a> — <?php echo $e($g['description']); ?></li><?php endforeach; ?>
</ul>

<div class="pub-cta"><span class="pub-cta__text">Sell from one page.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
