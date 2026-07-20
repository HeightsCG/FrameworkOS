<link rel="stylesheet" href="/css/feed.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/feed.css'); ?>">
<?php
/**
 * Home — discovery feed. Cards come from IndexController via FeedModel.
 * A card links to the creator's profile, where content is consumed / unlocked.
 * Non-entitled cards carry only a blurred cover + a lock badge.
 */
$fmt_num = function ($n) { return number_format((int) $n); };
$cards   = (array) ($this->cards ?? array());
?>
<div class="feed">
    <header class="feed__head">
        <h1 class="feed__title">Discover</h1>
        <p class="feed__sub">The latest from creators across the studio.</p>
    </header>

<?php if (empty($cards)): ?>
    <div class="feed__empty">
        <i class="fa-solid fa-compass feed__empty-icon"></i>
        <h2 class="feed__empty-title">Nothing published yet</h2>
        <p class="feed__empty-text">When creators publish content, it shows up here. Check back soon &mdash; or start creating your own.</p>
    </div>
<?php else: ?>
    <div class="feed__grid">
        <?php foreach ($cards as $c):
            $cap      = trim((string) $c['caption']);
            $has_cap  = $cap !== '';
            $avatar   = (string) $c['avatar'];
            $author   = (string) $c['author'];
            $initial  = strtoupper(mb_substr(ltrim($author, '@'), 0, 1));
            $locked   = empty($c['entitled']);
        ?>
        <a class="feed-card" href="<?php echo htmlspecialchars($c['profile_url'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="feed-card__media">
                <?php if (!empty($c['cover'])): ?>
                    <img class="feed-card__img<?php echo $locked ? ' feed-card__img--locked' : ''; ?>" src="<?php echo htmlspecialchars($c['cover'], ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                <?php else: ?>
                    <div class="feed-card__ph"><i class="fa-regular fa-image"></i></div>
                <?php endif; ?>

                <?php if (!empty($c['is_video'])): ?>
                    <span class="feed-card__glyph"><i class="fa-solid fa-play"></i></span>
                <?php elseif ((int) $c['media_count'] > 1): ?>
                    <span class="feed-card__glyph"><i class="fa-solid fa-layer-group"></i></span>
                <?php endif; ?>

                <?php if ($locked): ?>
                    <div class="feed-card__lock">
                        <span class="feed-card__lock-icon"><i class="fa-solid fa-lock"></i></span>
                        <?php if ($c['audience'] === 'ppv'): ?>
                            <span class="feed-card__lock-tag">Unlock &middot; <?php echo $fmt_num($c['ppv_price_credits']); ?> cr</span>
                        <?php else: ?>
                            <span class="feed-card__lock-tag">Subscribers only</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="feed-card__body">
                <div class="feed-card__author">
                    <?php if ($avatar !== ''): ?>
                        <img class="feed-card__avatar" src="<?php echo htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                    <?php else: ?>
                        <span class="feed-card__avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <span class="feed-card__name"><?php echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>

                <p class="feed-card__caption<?php echo $has_cap ? '' : ' feed-card__caption--empty'; ?>">
                    <?php echo $has_cap ? htmlspecialchars(mb_substr($cap, 0, 140), ENT_QUOTES, 'UTF-8') : 'Untitled'; ?>
                </p>

                <div class="feed-card__meta">
                    <span><i class="fa-regular fa-eye"></i><?php echo $fmt_num($c['views']); ?></span>
                    <span class="<?php echo !empty($c['liked']) ? 'liked' : ''; ?>"><i class="fa-<?php echo !empty($c['liked']) ? 'solid' : 'regular'; ?> fa-heart"></i><?php echo $fmt_num($c['likes']); ?></span>
                    <span><i class="fa-regular fa-comment"></i><?php echo $fmt_num($c['comments']); ?></span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
