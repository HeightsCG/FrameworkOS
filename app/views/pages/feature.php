<?php
/**
 * One feature page under /features/<slug>, rendered from FeaturePages::PAGES.
 * Locals: $page (the config row), $slug, $siblings (related feature pages, each with its 'path', for the cards at the bottom).
 */
$site = Main::site_name();
$hero = (array) ($page['hero'] ?? array());
$look = isset($look) && is_array($look) ? $look : (FeaturePages::LOOK[$slug] ?? array());   // photo, hero layout and the row visual (site-wide redesign 2026-10-15); /for pages pass NichePages::LOOK
echo Sections::panel_hero(array(
    'title'   => $hero['title'] ?? $page['title'],
    'lead'    => $hero['lead'] ?? ($page['description'] ?? ''),
    'photo'   => (string) ($look['photo'] ?? ''),
    'variant' => (string) ($look['variant'] ?? 'split'),
    'note'    => !empty($page['plan_note']) ? Sections::plan_note() : '',
    'buttons' => array(
        array('Start Free', '/?auth=register', 'primary', 'register'),   // becomes the trial offer in Sections::buttons()
        array('See Pricing', '/pricing', 'secondary'),
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
    echo Sections::open('white', (string) ($page['rows_title'] ?? 'What do you get with ' . (string) ($page['nav_title'] ?? 'it') . '?'));   // a question heading: the answer is the first row below
    $rows = array_values((array) $page['rows']);
    $visual = FeaturePages::visual_from($look);   // a schematic or a photo set (FeaturePages::LOOK): its row is lifted out and shown beside it, full width
    $vi = (int) ($look['row'] ?? 0);
    if ($visual !== '' && isset($rows[$vi])) {
        $r = $rows[$vi]; array_splice($rows, $vi, 1);
        echo '<div class="sx-split sx-split--feature"><div class="sx-split__copy"><h3 class="sx-h3">' . Sections::e(Sections::tc($r['title'])) . '</h3>' . Sections::paragraphs((string) $r['text'], 'sx-p')
            . (!empty($r['points']) ? Sections::checks((array) $r['points']) : '') . (!empty($r['link']) ? '<a class="sx-more" href="' . Sections::e($r['link'][1]) . '">' . Sections::e($r['link'][0]) . '</a>' : '')
            . '</div>' . $visual . '</div>';
    }
    if (!empty($rows)) { echo Sections::rows($rows); }
    echo Sections::tool_line((string) ($path ?? ''));   // the free tool that goes with this feature, when there is one
    echo Sections::close();
}

// "How we compare" (NichePages 'compare'): what the creator gets here, for visitors weighing other tools
if (!empty($page['compare']['points'])) {
    $cmp = (array) $page['compare'];
    echo Sections::open('alt', (string) ($cmp['title'] ?? ''), (string) ($cmp['lead'] ?? ''));
    echo Sections::checks((array) $cmp['points'], 2);
    foreach ((array) ($cmp['links'] ?? array()) as $cl) { echo '<a class="sx-more" href="' . Sections::e($cl[1]) . '">' . Sections::e($cl[0]) . '</a> '; }
    echo Sections::close();
}

// How to set it up, in order; what the fan sees; what each plan includes for this feature (SEO audit 2026-10-12: these pages were thin).
if (!empty($page['steps'])) {
    $st = (array) $page['steps']; $last = count($st) - 1;
    echo Sections::open('alt', (string) ($page['steps_title'] ?? 'How do you set up ' . (string) ($page['nav_title'] ?? 'it') . '?'));
    echo '<ol class="fx-steps' . (count($st) === 3 ? ' fx-steps--3' : '') . '">';
    foreach ($st as $si => $s) { echo '<li' . ($si === $last ? ' class="is-last"' : '') . '><h3>' . Sections::e(($si + 1) . '. ' . $s[0]) . '</h3><p>' . Sections::e($s[1]) . '</p></li>'; }
    echo '</ol>' . Sections::close();
}
if (!empty($page['fan_side'])) {
    echo Sections::prose('white', (string) ($page['fan_side_title'] ?? 'What does the fan see?'), array((string) $page['fan_side']));
}
if (!empty($page['limits'])) {
    $tiers = PagesController::selling_tiers(); usort($tiers, function ($a, $b) { return (int) $a['rank'] <=> (int) $b['rank']; });
    $labels = array(); foreach (PlanTiers::ROWS as $r) { $labels[$r['key']] = $r['label']; }
    $head = array(''); foreach ($tiers as $t) { $head[] = Sections::e($t['name']); }
    $trows = array();
    foreach ((array) $page['limits'] as $lk) {
        if (isset($labels[$lk])) { $tr = array(Sections::e($labels[$lk])); foreach ($tiers as $t) { $tr[] = Sections::e(PlanTiers::fmt_tier_limit($t, $lk)); } $trows[] = $tr; }
        elseif (isset(PlanTiers::FEATURES[$lk])) { $tr = array(PlanTiers::FEATURES[$lk]); foreach ($tiers as $t) { $tr[] = PlanTiers::has_feature($t, $lk) ? 'Included' : '&mdash;'; } $trows[] = $tr; }
    }
    if (count($trows) >= 2 && !empty($tiers)) {   // one row is not a table: a single limit is already stated in the rows above
        echo Sections::open('alt', 'What does each plan include for ' . (string) ($page['nav_title'] ?? 'it') . '?', 'Plan prices and every other limit are on the pricing page.');
        echo Sections::table($head, $trows, -1);
        echo '<p class="sx-p"><a href="/pricing">See All Plans</a></p>';
        echo Sections::close();
    }
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

echo Sections::related_guides((string) ($path ?? ''), 'white');   // the articles that belong under this page
if (!empty($page['faq'])) { echo Sections::faq((array) $page['faq']); }
