<?php
/**
 * Creator directory (/creators, /creators/<niche>). Vars: $creators, $featured, $counts, $cats, $show_counts, $cat, $label,
 * $page, $pages, $total, $listed, $all, $base, $sort, $q, $q_on, $link (page number => URL keeping sort and search).
 */
$e = function ($s) { return Sections::e($s); };
$site = Main::site_name();

echo Sections::hero(array(
    'title' => $cat === '' ? 'Creator Directory' : Sections::tc($label . ' creators'),
    'lead'  => $cat === ''
        ? 'The creator directory is the list of creators on ' . $site . ' who chose to be listed. Follow them, join a membership, or book a service from their page.'
        : 'The ' . strtolower($label) . ' directory is the list of ' . strtolower($label) . ' creators on ' . $site . ' who chose to be listed. Follow them, join a membership, or book a service from their page.',
));

echo Sections::open('white');
// Search (name, handle, bio) and sort; both stay in the URL so results can be shared.
$sorts = array_keys(DirectoryService::SORTS);
$keep  = function ($path) use ($sort, $sorts, $q, $q_on) { return PagesController::directory_url($path, 1, $sort === $sorts[0] ? '' : $sort, $q_on ? $q : ''); };
if ($listed > 0) {
    echo '<div class="dir-bar">';
    echo '<form class="dir-search" role="search" method="get" action="' . $e($base) . '">';
    echo '<label class="visually-hidden" for="dirQ">Search creators</label>';
    echo '<span class="dir-search__ic" aria-hidden="true">' . Sections::icon('search', 16) . '</span>';
    echo '<input class="dir-search__in" type="search" id="dirQ" name="q" value="' . $e($q) . '" minlength="2" maxlength="80" placeholder="Name, handle or bio" autocomplete="off">';
    if ($sort !== $sorts[0]) { echo '<input type="hidden" name="sort" value="' . $e($sort) . '">'; }
    echo '<button class="dir-search__btn" type="submit">Search</button>';
    echo '</form>';
    echo '<nav class="dir-sort" aria-label="Sort">';
    foreach (DirectoryService::SORTS as $sk => $sl) {
        $href = PagesController::directory_url($base, 1, $sk === $sorts[0] ? '' : $sk, $q_on ? $q : '');
        echo '<a class="dir-sort__a' . ($sk === $sort ? ' is-on' : '') . '" href="' . $e($href) . '"' . ($sk === $sort ? ' aria-current="true"' : '') . ' rel="nofollow">' . $e($sl) . '</a>';
    }
    echo '</nav></div>';
}

// Category chips: only categories that have listed creators, so no chip leads to an empty page.
if (!empty($counts)) {
    echo '<nav class="dir-cats" aria-label="Categories">';
    // chip numbers only once the directory is big enough to quote (PagesController::DIRECTORY_COUNT_MIN).
    $n = function ($c) use ($show_counts) { return $show_counts ? ' <span>' . (int) $c . '</span>' : ''; };
    echo '<a class="dir-cat' . ($cat === '' ? ' is-on' : '') . '" href="' . $e($keep('/creators')) . '"' . ($cat === '' ? ' aria-current="page"' : '') . '>All' . $n($all) . '</a>';
    foreach ($cats as $slug => $name) {
        if (empty($counts[$slug])) { continue; }
        echo '<a class="dir-cat' . ($cat === $slug ? ' is-on' : '') . '" href="' . $e($keep('/creators/' . $slug)) . '"' . ($cat === $slug ? ' aria-current="page"' : '') . '>' . $e($name) . $n($counts[$slug]) . '</a>';
    }
    echo '</nav>';
}

// One grid of creator cards (the featured row and the list share it).
$grid = function (array $rows) use ($e, $cats) {
    echo '<ul class="dir-grid">';
    foreach ($rows as $c) {
        $name = trim((string) $c['display_name']) !== '' ? (string) $c['display_name'] : (string) $c['u_name'];
        $bio  = trim(preg_replace('/\s+/', ' ', (string) $c['bio']));
        if (mb_strlen($bio) > 110) { $bio = rtrim(mb_substr($bio, 0, 107)) . '…'; }
        $fol  = (int) $c['followers'];
        echo '<li class="dir-card"><a class="dir-card__link" href="/@' . $e(rawurlencode((string) $c['u_name'])) . '">';
        echo '<img class="dir-card__av" src="' . $e(trim((string) ($c['avatar_webp_url'] ?? '')) !== '' ? $c['avatar_webp_url'] : $c['avatar_url']) . '" alt="' . $e($name) . '" width="64" height="64" loading="lazy">';
        echo '<span class="dir-card__body"><span class="dir-card__name">' . $e($name) . (!empty($c['verified']) ? ' <span class="dir-card__ver" title="Verified">' . Sections::icon('check', 12) . '<span class="visually-hidden">Verified</span></span>' : '') . '</span>';
        echo '<span class="dir-card__handle">@' . $e($c['u_name']) . '</span>';
        if ($bio !== '') { echo '<span class="dir-card__bio">' . $e($bio) . '</span>'; }
        $unit = (string) ($c['min_price_interval'] ?? '') === 'year' ? 'year' : ((string) ($c['min_price_interval'] ?? '') === 'week' ? 'week' : 'month');
        echo '<span class="dir-card__price">' . ((int) ($c['min_price_cents'] ?? 0) > 0 ? 'From $' . number_format(((int) $c['min_price_cents']) / 100, 2) . '/' . $unit : 'Free to follow') . '</span>';
        $niche = (string) ($cats[$c['directory_category']] ?? '');   // empty when the niche was turned off
        echo '<span class="dir-card__meta">' . ($niche !== '' ? $e($niche) . ' · ' : '') . number_format($fol) . ' follower' . ($fol === 1 ? '' : 's') . '</span></span>';
        echo '</a></li>';
    }
    echo '</ul>';
};

if (!empty($featured)) {
    echo '<h2 class="dir-h">Featured Creators</h2>';
    $grid($featured);
    echo '<h2 class="dir-h">' . $e($cat === '' ? 'All Creators' : Sections::tc('All ' . $label . ' creators')) . '</h2>';
}
if ($q_on && !empty($creators)) {
    echo '<p class="dir-found" role="status">' . number_format($total) . ' creator' . ($total === 1 ? '' : 's') . ' matching "' . $e($q) . '"</p>';
}
if (empty($creators)) {
    if ($q_on) {
        echo '<div class="dir-empty"><h2 class="dir-empty__t">No creators match "' . $e($q) . '"</h2><p class="dir-empty__p"><a class="dir-pages__a" href="' . $e(PagesController::directory_url($base, 1, $sort === $sorts[0] ? '' : $sort, '')) . '">Clear Search</a></p></div>';
    } else {
        echo '<div class="dir-empty"><h2 class="dir-empty__t">No creators listed here yet</h2><p class="dir-empty__p">Creators appear once they turn on the directory in their profile settings.</p></div>';
    }
} else {
    $grid($creators);
    if ($pages > 1) {
        echo '<nav class="dir-pages" aria-label="Pages">';
        if ($page > 1) { echo '<a class="dir-pages__a" href="' . $e($link($page - 1)) . '" rel="prev">Previous</a>'; }
        echo '<span class="dir-pages__n">Page ' . (int) $page . ' of ' . (int) $pages . '</span>';
        if ($page < $pages) { echo '<a class="dir-pages__a" href="' . $e($link($page + 1)) . '" rel="next">Next</a>'; }
        echo '</nav>';
    }
}
echo Sections::close();

$cta_title = 'Are you a creator?';
$cta_text  = 'Set up your page, then turn on the directory in your profile settings to be listed here.';
