<?php
/**
 * One feature page under /features/<slug>, rendered from FeaturePages::PAGES.
 * Locals: $page (the config row), $slug, $siblings (related feature pages, each with its 'path', for the cards at the bottom).
 */
$site = Main::site_name();
$hero = (array) ($page['hero'] ?? array());
echo Sections::panel_hero(array(
    'title'   => $hero['title'] ?? $page['title'],
    'lead'    => $hero['lead'] ?? ($page['description'] ?? ''),
    'buttons' => array(
        array('Start Free', '/?auth=register', 'primary', 'register'),
        array('See Pricing', '/pricing', 'ghost'),
    ),
));
// screenshot under the hero: /features/<slug> pages only (root keyword pages have no placement); nothing at all while Screenshots is off
$shot = isset(FeaturePages::PAGES[$slug]) ? Screenshots::img('feature-' . $slug) : '';
if ($shot !== '') { echo Sections::open('white') . $shot . Sections::close(); }

// optional video: 'video' => '<youtube id>' renders a click-to-play embed (poster first, nothing loads from YouTube until a click)
$video = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($page['video'] ?? ''));
if ($video !== '') {
    $v_title = Sections::tc((string) ($hero['title'] ?? $page['title']));
    $v_doc = '<style>*{margin:0}html,body{height:100%;background:#050505}a{display:block;height:100%;position:relative}img{width:100%;height:100%;object-fit:cover}span{position:absolute;top:50%;left:50%;width:68px;height:48px;margin:-24px 0 0 -34px;border-radius:8px;background:#CD4C00}span:after{content:"";position:absolute;top:14px;left:27px;border-style:solid;border-width:10px 0 10px 17px;border-color:transparent transparent transparent #fff}</style>'
        . '<a href="https://www.youtube-nocookie.com/embed/' . $video . '?autoplay=1&rel=0"><img src="https://i.ytimg.com/vi/' . $video . '/hqdefault.jpg" alt="' . Sections::e($v_title) . '"><span></span></a>';
    echo Sections::open('white');
    echo '<div class="fx-video"><iframe srcdoc="' . Sections::e($v_doc) . '" title="' . Sections::e($v_title) . '" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>';
    echo Sections::close();
}

if (!empty($page['rows'])) {
    echo Sections::open('white', 'What you get');
    echo Sections::rows((array) $page['rows']);
    echo Sections::close();
}

if (!empty($page['cards'])) {
    echo Sections::open('alt');
    echo Sections::cards((array) $page['cards'], 3);
    echo Sections::close();
}

if (!empty($siblings)) {
    $cards = array();
    foreach ($siblings as $s_slug => $s) {
        $cards[] = array('icon' => 'layers', 'title' => $s['title'], 'text' => $s['description'], 'link' => array('Explore ' . Sections::tc((string) ($s['nav_title'] ?? $s['title'])), (string) ($s['path'] ?? '/features/' . $s_slug)));   // descriptive anchor text, not "Read More"
    }
    $cards[] = array('icon' => 'list', 'title' => 'Everything else', 'text' => 'The full feature list: your page, ways to get paid, publishing, the inbox and analytics.', 'link' => array('See All Features', '/features'));
    echo Sections::open('alt', 'Explore more features');
    echo Sections::cards($cards, count($cards) % 4 === 0 ? 4 : 3);   // related pages + "Everything else" fill whole rows
    echo Sections::close();
}

if (!empty($page['faq'])) { echo Sections::faq((array) $page['faq']); }
