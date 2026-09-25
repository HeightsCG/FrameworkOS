<?php
/**
 * One feature page under /features/<slug>, rendered from FeaturePages::PAGES.
 * Locals: $page (the config row), $slug, $siblings (the other feature pages, for the footer links).
 */
$site = Main::site_name();
$hero = (array) ($page['hero'] ?? array());
echo Sections::panel_hero(array(
    'title'   => $hero['title'] ?? $page['title'],
    'lead'    => $hero['lead'] ?? ($page['description'] ?? ''),
    'buttons' => array(
        array('Get Started', '/?auth=register', 'primary', 'register'),
        array('See Pricing', '/pricing', 'ghost'),
    ),
));

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
        $cards[] = array('icon' => 'layers', 'title' => $s['title'], 'text' => $s['description'], 'link' => array('Explore ' . ucwords((string) ($s['nav_title'] ?? $s['title'])), '/features/' . $s_slug));   // descriptive anchor text, not "Read More"
    }
    $cards[] = array('icon' => 'list', 'title' => 'Everything else', 'text' => 'The full feature list: your page, ways to get paid, publishing, the inbox and analytics.', 'link' => array('See All Features', '/features'));
    echo Sections::open('alt', 'More of the platform');
    echo Sections::cards($cards, 3);
    echo Sections::close();
}

if (!empty($page['faq'])) { echo Sections::faq((array) $page['faq']); }
