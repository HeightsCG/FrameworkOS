<link rel="stylesheet" href="/css/audience.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/audience.css'); ?>">
<?php
$aud_tz = (string) ($this->timezone ?? 'UTC');
$fmt_since = function ($utc) use ($aud_tz) {
    if ((string) $utc === '') { return ''; }
    try {
        $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($aud_tz ?: 'UTC'));
        return $d->format('M j, Y');
    } catch (\Throwable $e) { return ''; }
};
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$ini = function ($n) { $n = trim((string) $n); return $n === '' ? '?' : mb_strtoupper(mb_substr($n, 0, 1)); };
$dollars = function ($credits) { return Price::credits((int) $credits); };   // everything inside is credits
$counts   = $this->counts;
$audience = $this->audience;
$revenue  = 0;
foreach ($audience as $f) { $revenue += (int) $f['spend_credits']; }
?>
<div class="aud">
    <header class="aud__head">
        <h1 class="aud__title">Audience</h1>
        <p class="aud__sub">Everyone connected to you — followers, subscribers, and buyers.</p>
    </header>

<?php if (empty($audience)): ?>
    <div class="aud__empty">
        <span class="aud__empty-icon"><i class="fa-solid fa-users"></i></span>
        <h2 class="aud__empty-title">No Audience Yet</h2>
        <p class="aud__empty-text">When people follow you, subscribe, or buy your content, they'll show up here — ready to tag, note, and message.</p>
    </div>
<?php else: ?>
    <div class="aud__toolbar">
        <div class="aud__filters" id="audFilters">
            <button type="button" class="aud__chip is-on" data-seg="all">All <b><?php echo (int) $counts['all']; ?></b></button>
            <button type="button" class="aud__chip" data-seg="followers">Followers <b><?php echo (int) $counts['followers']; ?></b></button>
            <button type="button" class="aud__chip" data-seg="subscribers">Subscribers <b><?php echo (int) $counts['subscribers']; ?></b></button>
            <button type="button" class="aud__chip" data-seg="buyers">Buyers <b><?php echo (int) $counts['buyers']; ?></b></button>
        </div>
        <div class="aud__search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="audSearch" placeholder="Search name or @handle" autocomplete="off" maxlength="60">
        </div>
    </div>

    <div class="aud-table">
        <div class="aud-table__cap">
            <span class="aud-table__cap-l"><i class="fa-solid fa-arrow-down-wide-short"></i> Top spenders first</span>
            <span class="aud-table__cap-r"><b><?php echo $dollars($revenue); ?></b> lifetime revenue</span>
        </div>
        <div class="aud-table__head">
            <span>Member</span>
            <span>Relationship</span>
            <span class="aud-r">Spent</span>
            <span>Member since</span>
            <span></span>
        </div>
        <div class="aud-table__body" id="audList">
            <?php foreach ($audience as $f):
                $base   = mb_strtolower($f['name'] . ' @' . $f['handle']);
                $search = trim($base . ' ' . mb_strtolower(implode(' ', $f['tags'])));
                $since  = $f['subscribed_at'] ?: ($f['followed_at'] ?: $f['last_at']);
            ?>
            <div class="aud-row" data-fan="<?php echo (int) $f['id']; ?>"
                 data-follower="<?php echo $f['is_follower'] ? 1 : 0; ?>"
                 data-subscriber="<?php echo $f['is_subscriber'] ? 1 : 0; ?>"
                 data-buyer="<?php echo $f['is_buyer'] ? 1 : 0; ?>"
                 data-search-base="<?php echo $e($base); ?>"
                 data-search="<?php echo $e($search); ?>">
                <div class="aud-row__main">
                    <div class="aud-cell aud-cell--member">
                        <?php if ($f['avatar'] !== ''): ?>
                            <span class="aud-av" style="background-image:url('<?php echo $e($f['avatar']); ?>')"></span>
                        <?php else: ?>
                            <span class="aud-av aud-av--i"><?php echo $e($ini($f['name'])); ?></span>
                        <?php endif; ?>
                        <span class="aud-member">
                            <span class="aud-member__name"><?php echo $e($f['name']); ?><?php if ($f['is_creator']): ?> <i class="fa-solid fa-circle-check aud-member__creator" title="Creator"></i><?php endif; ?></span>
                            <span class="aud-member__handle">@<?php echo $e($f['handle']); ?></span>
                        </span>
                    </div>
                    <div class="aud-cell aud-cell--rel">
                        <?php if ($f['is_follower']): ?><span class="aud-badge"><i class="fa-solid fa-user-plus"></i> Follower</span><?php endif; ?>
                        <?php if ($f['is_subscriber']): ?><span class="aud-badge aud-badge--sub"><i class="fa-solid fa-heart"></i> Subscriber</span><?php endif; ?>
                        <?php if ($f['is_buyer']): ?><span class="aud-badge aud-badge--buy"><i class="fa-solid fa-bag-shopping"></i> Buyer</span><?php endif; ?>
                    </div>
                    <div class="aud-cell aud-cell--spend aud-r">
                        <?php if ($f['spend_credits'] > 0): ?>
                            <span class="aud-spend"><?php echo $dollars($f['spend_credits']); ?></span>
                            <span class="aud-spend__meta"><?php echo (int) $f['purchases']; ?> <?php echo ((int) $f['purchases'] === 1 ? 'purchase' : 'purchases'); ?></span>
                        <?php else: ?>
                            <span class="aud-spend__meta">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="aud-cell aud-cell--since">
                        <?php echo $since ? $e($fmt_since($since)) : '<span class="aud-spend__meta">—</span>'; ?>
                    </div>
                    <div class="aud-cell aud-cell--actions">
                        <button type="button" class="aud-btn aud-btn--msg" data-msg="<?php echo (int) $f['id']; ?>"><i class="fa-solid fa-comment-dots"></i> Message</button>
                        <button type="button" class="aud-btn aud-btn--icon aud-btn--block" data-block-user="<?php echo (int) $f['id']; ?>" data-block-name="<?php echo $e($f['handle'] !== '' ? '@' . $f['handle'] : $f['name']); ?>" aria-label="Block" title="Block"><i class="fa-solid fa-ban"></i></button>
                        <button type="button" class="aud-btn aud-btn--icon" data-expand aria-label="Details"><i class="fa-solid fa-chevron-down"></i></button>
                    </div>
                </div>
                <div class="aud-row__detail" hidden>
                    <div class="aud-field">
                        <label class="aud-field__label">Tags</label>
                        <div class="aud-tags" data-tags>
                            <?php foreach ($f['tags'] as $t): ?>
                                <span class="aud-tag" data-tag="<?php echo $e($t); ?>"><?php echo $e($t); ?><button type="button" class="aud-tag__x" aria-label="Remove tag"><i class="fa-solid fa-xmark"></i></button></span>
                            <?php endforeach; ?>
                            <input type="text" class="aud-tag__input" placeholder="Add tag" autocomplete="off" maxlength="40">
                        </div>
                    </div>
                    <div class="aud-field">
                        <label class="aud-field__label">Private note</label>
                        <textarea class="aud-note" placeholder="Only you can see this — e.g. VIP, met at meetup, prefers Orlando tips" maxlength="2000"><?php echo $e($f['note']); ?></textarea>
                        <button type="button" class="aud-btn aud-btn--save" data-note-save>Save Note</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <p class="aud__none" id="audNone" hidden>No one matches those filters.</p>
        </div>
    </div>
<?php endif; ?>
</div>

<script src="/js/audience.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/audience.js'); ?>"></script>
