<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$when = function ($utc) { return date('M j, Y', strtotime($utc . ' UTC')); };
// Topic shown on each cover, from the article's target keyword.
$topic = function ($a) {
    $k = strtolower((string) ($a['target_keyword'] ?? '') . ' ' . (string) ($a['title'] ?? ''));
    $map = array('pay-per-view' => 'Pay-per-view', 'ppv' => 'Pay-per-view', 'link in bio' => 'Link in bio', 'payout' => 'Payouts', 'bundle' => 'Bundles',
                 'service' => 'Services', 'event' => 'Events', 'price' => 'Pricing', 'pricing' => 'Pricing', 'tier' => 'Memberships', 'membership' => 'Memberships',
                 'subscription' => 'Memberships', 'cross-post' => 'Social', 'social' => 'Social', 'ai ' => 'AI', 'onlyfans' => 'Platforms', 'fanvue' => 'Platforms', 'monetiz' => 'Getting paid');
    foreach ($map as $needle => $label) { if (strpos($k, $needle) !== false) { return $label; } }
    return 'Guide';
};
$lead = null; $rest = $articles;
if ($page === 1 && !empty($articles)) { $lead = array_shift($rest); }
$starter = null; foreach ($guides as $g) { if ($g['path'] === '/monetize-your-content') { $starter = $g; } }
?>
<div class="gd">
    <section class="gd-hero">
        <h1 class="gd-hero__title"><?php echo $e(BlogController::NAME); ?></h1>
        <p class="gd-hero__lead">Plain answers about pricing and selling memberships, pay-per-view posts, bundles, services and events.</p>
        <nav class="gd-topics" aria-label="Product guides">
            <?php foreach ($guides as $g): ?><a class="gd-topic" href="<?php echo $e($g['path']); ?>"><?php echo $e($g['title']); ?></a><?php endforeach; ?>
        </nav>
    </section>

<?php if ($page === 1 && ($lead || $starter)): ?>
    <?php if ($lead) { $href = '/blog/' . $lead['slug']; $t = $lead['title']; $x = $lead['excerpt'] ?: $lead['meta_description']; $tp = $topic($lead); }
          else { $href = $starter['path']; $t = 'How creators get paid, and how to price each way'; $x = $starter['description']; $tp = 'Getting paid'; } ?>
    <article class="gd-feature">
        <a class="gd-cover gd-cover--lg" href="<?php echo $e($href); ?>" tabindex="-1" aria-hidden="true">
            <span class="gd-cover__topic"><?php echo $e($tp); ?></span>
            <?php if ($lead): ?><span class="gd-cover__foot"><?php echo (int) $lead['reading_minutes']; ?>-minute read</span><?php endif; ?>
        </a>
        <div class="gd-feature__body">
            <p class="gd-kicker"><?php echo $lead ? 'Latest post' : 'Start here'; ?></p>
            <h2 class="gd-feature__title"><a href="<?php echo $e($href); ?>"><?php echo $e($t); ?></a></h2>
            <p class="gd-feature__x"><?php echo $e($x); ?></p>
            <div class="gd-feature__foot">
                <?php if ($lead): ?><time class="gd-date" datetime="<?php echo $e(gmdate('Y-m-d', strtotime($lead['published_at'] . ' UTC'))); ?>"><?php echo $e($when($lead['published_at'])); ?></time><?php endif; ?>
                <a class="gd-read" href="<?php echo $e($href); ?>">Read Post</a>
            </div>
        </div>
    </article>
<?php endif; ?>

<?php if (!empty($rest)): ?>
    <h2 class="gd-h2"><?php echo $page === 1 ? 'More posts' : 'Posts, page ' . (int) $page; ?></h2>
    <div class="gd-grid">
        <?php $i = 0; foreach ($rest as $a): $i++; ?>
        <article class="gd-card">
            <a class="gd-cover gd-cover--v<?php echo ($i % 3) + 1; ?>" href="/blog/<?php echo $e($a['slug']); ?>" tabindex="-1" aria-hidden="true"><span class="gd-cover__topic"><?php echo $e($topic($a)); ?></span></a>
            <h3 class="gd-card__title"><a href="/blog/<?php echo $e($a['slug']); ?>"><?php echo $e($a['title']); ?></a></h3>
            <p class="gd-card__x"><?php echo $e($a['excerpt'] ?: $a['meta_description']); ?></p>
            <p class="gd-date"><time datetime="<?php echo $e(gmdate('Y-m-d', strtotime($a['published_at'] . ' UTC'))); ?>"><?php echo $e($when($a['published_at'])); ?></time>, <?php echo (int) $a['reading_minutes']; ?>-minute read</p>
        </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($pages > 1): ?>
    <nav class="gd-pager" aria-label="More posts">
        <?php if ($page > 1): ?><a class="gd-read" href="/blog<?php echo $page > 2 ? '?page=' . ($page - 1) : ''; ?>">Newer Posts</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="gd-read" href="/blog?page=<?php echo $page + 1; ?>">Older Posts</a><?php endif; ?>
    </nav>
<?php endif; ?>

    <section class="gd-band">
        <h2 class="gd-band__title">Put it into practice on one page.</h2>
        <p class="gd-band__x">Memberships, pay-per-view, services and events, with payouts to your bank.</p>
        <a class="ld-btn ld-btn--onviolet" href="/?auth=register">Create Your Account</a>
    </section>
</div>
