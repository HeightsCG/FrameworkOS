<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$day = gmdate('Y-m-d', strtotime(($a['published_at'] ?: $a['created_at']) . ' UTC'));
?>
<div class="gd gd--post">
<?php if (!empty($preview) && ($a['status'] ?? '') !== 'published'): ?><p class="pub-note pub-note--preview">Preview. This article is not published.</p><?php endif; ?>
    <?php $cover = trim((string) ($a['cover_image_url'] ?? '')); ?>
    <header class="gd-hero gd-posthead<?php echo $cover !== '' ? ' gd-posthead--cover' : ''; ?>">
        <div class="gd-posthead__text">
        <nav class="gd-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><a href="/blog"><?php echo $e(BlogController::NAME); ?></a><span aria-hidden="true">/</span><span class="gd-crumbs__here" aria-current="page"><?php echo $e($a['title']); ?></span></nav>
        <h1 class="gd-posthead__title"><?php echo $e($a['title']); ?></h1>
        <?php if (trim((string) $a['excerpt']) !== ''): ?><p class="gd-hero__lead"><?php echo $e($a['excerpt']); ?></p><?php endif; ?>
        <p class="gd-posthead__meta"><span><?php echo $e(trim((string) ($a['author'] ?? '')) !== '' ? $a['author'] : SeoMeta::site() . ' team'); ?></span><time datetime="<?php echo $e($day); ?>"><?php echo $e(date('M j, Y', strtotime($day))); ?></time><span><?php echo (int) $a['reading_minutes']; ?>-minute read</span></p>
        </div>
        <?php $cover_webp = trim((string) ($a['cover_webp_url'] ?? '')); /* webp copies (PublicThumbService), else the original */ ?>
        <?php if ($cover_webp !== ''): ?><img class="gd-posthead__img" src="<?php echo $e($cover_webp); ?>" srcset="<?php echo $e(PublicThumbService::cover_small($cover_webp)); ?> 480w, <?php echo $e($cover_webp); ?> 960w" sizes="(max-width: 900px) 100vw, 340px" alt="<?php echo $e($a['title']); ?>" width="960" height="720" fetchpriority="high">
        <?php elseif ($cover !== ''): ?><img class="gd-posthead__img" src="<?php echo $e($cover); ?>" alt="<?php echo $e($a['title']); ?>" width="1024" height="768" fetchpriority="high"><?php endif; ?>
    </header>

    <div class="gd-post">
        <article class="gd-post__main">
            <div class="pub-body gd-body"><?php echo $body; ?></div>
            <?php echo SeoDrafter::cta_html((int) ($a['id'] ?? 0)); ?>
            <?php if (!empty($faq)): ?>
            <section class="faq" aria-labelledby="gd_faq_h">
    <h2 class="faq__title" id="gd_faq_h">Frequently asked questions</h2>
    <div class="faq__list">
        <?php foreach ($faq as $f): ?><details class="faq__item"><summary class="faq__q"><?php echo $e($f['q']); ?><svg class="faq__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="faq__a"><?php echo $e($f['a']); ?></div></details><?php endforeach; ?>
    </div>
</section>
            <?php endif; ?>
        </article>
        <?php if (count($toc) > 1): ?>
        <aside class="gd-toc" aria-labelledby="gd_toc_h">
            <p class="gd-toc__h" id="gd_toc_h">On this page</p>
            <ol class="gd-toc__list"><?php foreach ($toc as $t): ?><li><a href="#<?php echo $e($t['id']); ?>"><?php echo $e($t['text']); ?></a></li><?php endforeach; ?><?php if (!empty($faq)): ?><li><a href="#gd_faq_h">Questions</a></li><?php endif; ?></ol>
        </aside>
        <?php endif; ?>
    </div>

<?php if (!empty($related)): ?>
    <h2 class="gd-h2">Keep reading</h2>
    <ol class="gd-list">
        <?php foreach ($related as $r): $img = trim((string) ($r['cover_image_url'] ?? '')); ?>
        <li class="gd-row<?php echo $img === '' ? ' gd-row--noimg' : ''; ?>">
            <?php if ($img !== ''): ?><a class="gd-row__img" href="/blog/<?php echo $e($r['slug']); ?>" tabindex="-1" aria-hidden="true"><img src="<?php echo $e(trim((string) ($r['cover_webp_url'] ?? '')) !== '' ? PublicThumbService::cover_small($r['cover_webp_url']) : $img); ?>" alt="<?php echo $e($r['title']); ?>" loading="lazy" decoding="async" width="160" height="120"></a><?php endif; ?>
            <div class="gd-row__body">
                <p class="gd-row__topic"><?php echo $e(BlogController::topic($r)); ?></p>
                <h3 class="gd-row__title"><a href="/blog/<?php echo $e($r['slug']); ?>"><?php echo $e($r['title']); ?></a></h3>
                <p class="gd-row__x"><?php echo $e($r['excerpt'] ?: $r['meta_description']); ?></p>
                <p class="gd-date"><time datetime="<?php echo $e(gmdate('Y-m-d', strtotime($r['published_at'] . ' UTC'))); ?>"><?php echo $e(date('M j, Y', strtotime($r['published_at'] . ' UTC'))); ?></time>, <?php echo (int) $r['reading_minutes']; ?>-minute read</p>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
</div>
