<link rel="stylesheet" href="/css/purchases.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/purchases.css'); ?>">
<div class="pur">
    <header class="pur__head">
        <h1 class="pur__title">Purchases</h1>
        <p class="pur__sub">What you&rsquo;ve bought: pay-per-view posts, bundles, messages, event tickets and bookings. One-time purchases, separate from your subscriptions.</p>
    </header>

    <?php if (empty($this->purchases)): ?>
    <div class="pur__empty">
        <i class="fa-solid fa-bag-shopping pur__empty-icon"></i>
        <p class="pur__empty-title">No Purchases Yet</p>
        <p class="pur__empty-text">Unlock a post, bundle or message, or buy a ticket or booking, and it&rsquo;ll show up here.</p>
    </div>
    <?php else: ?>
    <div class="pur__list">
        <?php foreach ($this->purchases as $pur): ?>
        <div class="pur-card">
            <div class="pur-card__head">
                <div class="pur-card__meta">
                    <span class="pur-card__badge pur-card__badge--<?php echo htmlspecialchars((string) $pur['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo array('bundle' => 'Bundle', 'message' => 'Message', 'event' => 'Event', 'service' => 'Service')[$pur['type']] ?? 'Pay-per-view'; ?></span>
                    <span class="pur-card__title"><?php echo htmlspecialchars((string) $pur['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="pur-card__price"><?php echo Price::credits((int) $pur['price']); ?> &middot; <?php echo htmlspecialchars(date('M j, Y', strtotime((string) $pur['purchased_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="pur-card__side">
                    <a class="pur-card__creator" href="/@<?php echo htmlspecialchars(rawurlencode((string) $pur['handle']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string) $pur['creator'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php if (count($pur['media']) === 1 && $pur['media'][0]['download'] !== ''): ?>
                    <a class="pur-dl" href="<?php echo htmlspecialchars((string) $pur['media'][0]['download'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-download" aria-hidden="true"></i> Download</a>
                    <?php elseif (count($pur['media']) > 1): ?>
                    <a class="pur-dl" href="/purchases/download/<?php echo htmlspecialchars(str_replace(':', '/', (string) $pur['key']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-download" aria-hidden="true"></i> Download All</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($pur['media'])): ?>
            <div class="pur-media">
                <?php foreach ($pur['media'] as $m): ?>
                <button type="button" class="pur-media__item" data-full="<?php echo htmlspecialchars((string) $m['url'], ENT_QUOTES, 'UTF-8'); ?>" data-download="<?php echo htmlspecialchars((string) $m['download'], ENT_QUOTES, 'UTF-8'); ?>" data-type="<?php echo htmlspecialchars((string) $m['type'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $m['thumb'] !== '' ? ' style="background-image:url(\'' . htmlspecialchars((string) $m['thumb'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>>
                    <?php if ($m['type'] === 'video'): ?><i class="fa-solid fa-play pur-media__vid"></i><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($pur['link'])): ?>
            <a class="pur-card__link" href="<?php echo htmlspecialchars((string) $pur['link'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $pur['link_label'], ENT_QUOTES, 'UTF-8'); ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            <?php else: ?>
            <p class="pur-card__gone">This content is no longer available.</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="pur-lb" id="purLb" hidden>
    <button type="button" class="pur-lb__close" id="purLbClose" aria-label="Close">&times;</button>
    <a class="pur-lb__dl" id="purLbDl" href="#" hidden><i class="fa-solid fa-download" aria-hidden="true"></i> Download</a>
    <div class="pur-lb__stage" id="purLbStage"></div>
</div>
<script>
(function () {
    var lb = document.getElementById('purLb');
    if (!lb) { return; }
    var stage = document.getElementById('purLbStage');
    var dl = document.getElementById('purLbDl');
    function open(full, type, download) {
        if (!full) { return; }
        dl.hidden = !download; dl.setAttribute('href', download || '#');
        stage.innerHTML = (type === 'video')
            ? '<video class="pur-lb__media" src="' + full + '" controls autoplay playsinline></video>'
            : '<img class="pur-lb__media" src="' + full + '" alt="">';
        lb.hidden = false; document.body.style.overflow = 'hidden';
    }
    function close() { lb.hidden = true; stage.innerHTML = ''; document.body.style.overflow = ''; }
    document.querySelectorAll('.pur-media__item').forEach(function (b) {
        b.addEventListener('click', function () { open(b.getAttribute('data-full'), b.getAttribute('data-type'), b.getAttribute('data-download')); });
    });
    document.getElementById('purLbClose').addEventListener('click', close);
    lb.addEventListener('click', function (e) { if (e.target === lb) { close(); } });
    document.addEventListener('keydown', function (e) { if (!lb.hidden && e.key === 'Escape') { close(); } });
})();
</script>
