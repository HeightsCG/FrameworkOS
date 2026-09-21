<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$when = function ($utc) { return date('M j, Y', strtotime($utc . ' UTC')); };
// Page 1 leads with the newest guide; with no articles yet, the lead is the product guide on getting paid.
$lead = null; $rest = $articles;
if ($page === 1 && !empty($articles)) { $lead = array_shift($rest); }
$starter = null; $start_here = array();
foreach ($guides as $g) { if ($g['path'] === '/monetize-your-content') { $starter = $g; } else { $start_here[] = $g; } }
?>
<div class="blg">
    <header class="blg-head">
        <h1 class="blg-title">Guides for creators</h1>
        <p class="blg-lead">Plain answers about pricing and selling memberships, pay-per-view posts, bundles, services and events.</p>
    </header>

<?php if ($page === 1): ?>
    <section class="blg-row" aria-labelledby="blg_newest">
        <h2 class="blg-label" id="blg_newest"><?php echo $lead ? 'Newest' : 'Start here'; ?></h2>
        <div class="blg-feature">
        <?php if ($lead): ?>
            <a class="blg-feature__title" href="/blog/<?php echo $e($lead['slug']); ?>"><?php echo $e($lead['title']); ?></a>
            <p class="blg-feature__x"><?php echo $e($lead['excerpt'] ?: $lead['meta_description']); ?></p>
            <div class="blg-feature__foot">
                <span class="blg-meta"><time datetime="<?php echo $e(gmdate('Y-m-d', strtotime($lead['published_at'] . ' UTC'))); ?>"><?php echo $e($when($lead['published_at'])); ?></time><span><?php echo (int) $lead['reading_minutes']; ?>-minute read</span></span>
                <a class="blg-read" href="/blog/<?php echo $e($lead['slug']); ?>" tabindex="-1" aria-hidden="true">Read the Guide</a>
            </div>
        <?php elseif ($starter): ?>
            <a class="blg-feature__title" href="<?php echo $e($starter['path']); ?>">How creators get paid, and how to price each way</a>
            <p class="blg-feature__x"><?php echo $e($starter['description']); ?></p>
            <div class="blg-feature__foot">
                <a class="blg-read" href="<?php echo $e($starter['path']); ?>" tabindex="-1" aria-hidden="true">Read the Guide</a>
            </div>
        <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($rest)): ?>
    <section class="blg-row" aria-labelledby="blg_all">
        <h2 class="blg-label" id="blg_all"><?php echo $page === 1 ? 'All guides' : 'Page ' . (int) $page; ?></h2>
        <ol class="blg-list">
            <?php foreach ($rest as $a): ?>
            <li class="blg-item">
                <a class="blg-item__title" href="/blog/<?php echo $e($a['slug']); ?>"><?php echo $e($a['title']); ?></a>
                <time class="blg-item__date" datetime="<?php echo $e(gmdate('Y-m-d', strtotime($a['published_at'] . ' UTC'))); ?>"><?php echo $e($when($a['published_at'])); ?></time>
                <p class="blg-item__x"><?php echo $e($a['excerpt'] ?: $a['meta_description']); ?></p>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($pages > 1): ?>
        <nav class="blg-pager" aria-label="More guides">
            <?php if ($page > 1): ?><a class="ld-btn ld-btn--quiet" href="/blog<?php echo $page > 2 ? '?page=' . ($page - 1) : ''; ?>">Newer Guides</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="ld-btn ld-btn--quiet" href="/blog?page=<?php echo $page + 1; ?>">Older Guides</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>
<?php elseif ($page === 1 && $pages > 1): ?>
    <nav class="blg-pager blg-pager--solo" aria-label="More guides"><a class="ld-btn ld-btn--quiet" href="/blog?page=2">Older Guides</a></nav>
<?php endif; ?>

<?php $side = ($lead === null && $page === 1) ? $start_here : $guides; if (!empty($side)): ?>
    <section class="blg-row" aria-labelledby="blg_start">
        <h2 class="blg-label" id="blg_start"><?php echo ($lead === null && $page === 1) ? 'Compare platforms' : 'Start here'; ?></h2>
        <ul class="blg-list blg-list--plain">
            <?php foreach ($side as $g): ?>
            <li class="blg-item">
                <a class="blg-item__title" href="<?php echo $e($g['path']); ?>"><?php echo $e($g['title']); ?></a>
                <p class="blg-item__x"><?php echo $e($g['description']); ?></p>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

    <div class="pub-cta blg-cta"><span class="pub-cta__text">Sell memberships, pay-per-view, services and events from one page.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
</div>
