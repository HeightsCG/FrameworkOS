<?php
/** Creator directory (/creators, /creators/<category>). Vars: $creators, $counts, $show_counts, $cat, $label, $page, $pages, $total, $base. */
$e = function ($s) { return Sections::e($s); };
$site = Main::site_name();

echo Sections::hero(array(
    'title' => $cat === '' ? 'Creator directory' : $label . ' creators',
    'lead'  => $cat === ''
        ? 'Creators on ' . $site . ' who chose to be listed. Follow them, join a membership, or book a service from their page.'
        : 'The ' . strtolower($label) . ' creators on ' . $site . ' who chose to be listed. Follow them, join a membership, or book a service from their page.',
));

echo Sections::open('white');
// Category chips: only categories that have listed creators, so no chip leads to an empty page.
if (!empty($counts)) {
    echo '<nav class="dir-cats" aria-label="Categories">';
    // chip numbers only once the directory is big enough to quote (PagesController::DIRECTORY_COUNT_MIN).
    $n = function ($c) use ($show_counts) { return $show_counts ? ' <span>' . (int) $c . '</span>' : ''; };
    echo '<a class="dir-cat' . ($cat === '' ? ' is-on' : '') . '" href="/creators"' . ($cat === '' ? ' aria-current="page"' : '') . '>All' . $n(array_sum($counts)) . '</a>';
    foreach (DirectoryService::CATEGORIES as $slug => $name) {
        if (empty($counts[$slug])) { continue; }
        echo '<a class="dir-cat' . ($cat === $slug ? ' is-on' : '') . '" href="/creators/' . $e($slug) . '"' . ($cat === $slug ? ' aria-current="page"' : '') . '>' . $e($name) . $n($counts[$slug]) . '</a>';
    }
    echo '</nav>';
}

if (empty($creators)) {
    echo '<div class="dir-empty"><h2 class="dir-empty__t">No creators listed here yet</h2><p class="dir-empty__p">Creators appear once they turn on the directory in their profile settings.</p></div>';
} else {
    echo '<ul class="dir-grid">';
    foreach ($creators as $c) {
        $name = trim((string) $c['display_name']) !== '' ? (string) $c['display_name'] : (string) $c['u_name'];
        $bio  = trim(preg_replace('/\s+/', ' ', (string) $c['bio']));
        if (mb_strlen($bio) > 110) { $bio = rtrim(mb_substr($bio, 0, 107)) . '…'; }
        $fol  = (int) $c['followers'];
        echo '<li class="dir-card"><a class="dir-card__link" href="/@' . $e(rawurlencode((string) $c['u_name'])) . '">';
        echo '<img class="dir-card__av" src="' . $e($c['avatar_url']) . '" alt="' . $e($name) . '" width="64" height="64" loading="lazy">';
        echo '<span class="dir-card__body"><span class="dir-card__name">' . $e($name) . (!empty($c['verified']) ? ' <span class="dir-card__ver" title="Verified">' . Sections::icon('check', 12) . '<span class="visually-hidden">Verified</span></span>' : '') . '</span>';
        echo '<span class="dir-card__handle">@' . $e($c['u_name']) . '</span>';
        if ($bio !== '') { echo '<span class="dir-card__bio">' . $e($bio) . '</span>'; }
        echo '<span class="dir-card__meta">' . $e(DirectoryService::CATEGORIES[$c['directory_category']] ?? '') . ' · ' . number_format($fol) . ' follower' . ($fol === 1 ? '' : 's') . '</span></span>';
        echo '</a></li>';
    }
    echo '</ul>';
    if ($pages > 1) {
        echo '<nav class="dir-pages" aria-label="Pages">';
        if ($page > 1) { echo '<a class="dir-pages__a" href="' . $e($base . ($page > 2 ? '?page=' . ($page - 1) : '')) . '" rel="prev">Previous</a>'; }
        echo '<span class="dir-pages__n">Page ' . (int) $page . ' of ' . (int) $pages . '</span>';
        if ($page < $pages) { echo '<a class="dir-pages__a" href="' . $e($base . '?page=' . ($page + 1)) . '" rel="next">Next</a>'; }
        echo '</nav>';
    }
}
echo Sections::close();

$cta_title = 'Are you a creator?';
$cta_text  = 'Set up your page, then turn on the directory in your profile settings to be listed here.';
