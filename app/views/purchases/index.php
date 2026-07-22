<link rel="stylesheet" href="/css/purchases.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/purchases.css'); ?>">
<div class="pur">
    <header class="pur__head">
        <h1 class="pur__title">Purchases</h1>
        <p class="pur__sub">Content you&rsquo;ve bought &mdash; pay-per-view unlocks and bundles. One-time purchases, separate from your subscriptions.</p>
    </header>

    <?php if (empty($this->purchases)): ?>
    <div class="pur__empty">
        <i class="fa-solid fa-bag-shopping pur__empty-icon"></i>
        <p class="pur__empty-title">No purchases yet</p>
        <p class="pur__empty-text">Unlock a pay-per-view post or buy a content bundle and it&rsquo;ll show up here.</p>
    </div>
    <?php else: ?>
    <div class="pur__list">
        <?php foreach ($this->purchases as $pur): ?>
        <div class="pur-card">
            <div class="pur-card__head">
                <div class="pur-card__meta">
                    <span class="pur-card__badge pur-card__badge--<?php echo htmlspecialchars((string) $pur['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo $pur['type'] === 'bundle' ? 'Bundle' : 'Pay-per-view'; ?></span>
                    <span class="pur-card__title"><?php echo htmlspecialchars((string) $pur['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <a class="pur-card__creator" href="/@<?php echo htmlspecialchars(rawurlencode((string) $pur['handle']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string) $pur['creator'], ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
            <?php if (!empty($pur['media'])): ?>
            <div class="pur-media">
                <?php foreach ($pur['media'] as $m): ?>
                <a class="pur-media__item" href="<?php echo htmlspecialchars((string) $m['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"<?php echo $m['thumb'] !== '' ? ' style="background-image:url(\'' . htmlspecialchars((string) $m['thumb'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>>
                    <?php if ($m['type'] === 'video'): ?><i class="fa-solid fa-play pur-media__vid"></i><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="pur-card__gone">This content is no longer available.</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
