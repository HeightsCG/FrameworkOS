<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<?php if (!empty($preview) && ($a['status'] ?? '') !== 'published'): ?><p class="pub-note pub-note--preview">Preview — this article is not published.</p><?php endif; ?>
<article class="pub-post">
    <p class="pub-eyebrow"><a href="/blog">Guides</a></p>
    <h1 class="pub-h1"><?php echo $e($a['title']); ?></h1>
    <p class="pub-post__meta"><?php echo $e(SeoMeta::site()); ?> team · <?php echo $e(date('M j, Y', strtotime(($a['published_at'] ?: $a['created_at']) . ' UTC'))); ?> · <?php echo (int) $a['reading_minutes']; ?> min read</p>
    <?php if (trim((string) $a['excerpt']) !== ''): ?><p class="pub-lead"><?php echo $e($a['excerpt']); ?></p><?php endif; ?>
    <div class="pub-body"><?php echo $a['body_html']; ?></div>
    <?php if (!empty($faq)): ?>
    <h2 class="pub-h2">Questions</h2>
    <ul class="pub-faq"><?php foreach ($faq as $f): if (!is_array($f) || trim((string) ($f['q'] ?? '')) === '' || trim((string) ($f['a'] ?? '')) === '') { continue; } ?><li><h3><?php echo $e($f['q'] ?? ''); ?></h3><p><?php echo $e($f['a'] ?? ''); ?></p></li><?php endforeach; ?></ul>
    <?php endif; ?>
</article>
<?php if (!empty($related)): ?>
<h2 class="pub-h2">Keep reading</h2>
<div class="pub-cards pub-cards--3">
    <?php foreach ($related as $r): ?>
    <a class="pub-card" href="/blog/<?php echo $e($r['slug']); ?>"><span class="pub-card__title"><?php echo $e($r['title']); ?></span><span class="pub-card__x"><?php echo $e($r['excerpt'] ?: $r['meta_description']); ?></span></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="pub-cta"><span class="pub-cta__text">Put it into practice.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
