<?php
/**
 * One alternatives list page (/onlyfans-alternatives, /fanvue-alternatives), from PagesController::alternatives_page().
 * Locals: $p (the filled page row with 'us' first), $faq.
 */
$e = function ($s) { return Sections::e($s); }; $site = Main::site_name();
$signup = '/?auth=register&role=creator';
$rows = array(array($p['us']['name'], $p['us']['kind'], true));
foreach ($p['others'] as $o) { $rows[] = array($o['name'], $o['kind']); }
echo Sections::panel_hero(array('photo' => 'alternatives', 'variant' => 'panel', 
    'title'   => $p['title'],
    'lead'    => $p['intro'],
    'buttons' => array(array('Start Free', $signup, 'primary', 'register'), array('Compare All Platforms', '/best-creator-monetization-platforms', 'secondary')),
    'panel'   => Sections::pane('On this list', count($rows) . ' platforms, in the order below.', Sections::pane_rows(array_slice($rows, 0, 6), count($rows) > 6 ? '<p class="hx__p hx__p--foot">And ' . (count($rows) - 6) . ' more, listed below.</p>' : '')),   // a long list would push the heading off the first screen
));

// The numbered list: us first (we build it and say so), then each platform with a paragraph written for this page's reader.
echo Sections::open('white', 'Which ' . $p['name'] . ' alternatives are worth considering?', 'We build ' . $site . ', so it leads this list of ' . $p['name'] . ' alternatives, and we say so. Every other entry is written for a creator leaving ' . $p['name'] . ', and the points under it come from that platform\'s own pages, linked beside each one or in its comparison.');
$cards = array(array('title' => '1. ' . $p['us']['name'], 'text' => $p['us']['text'], 'points' => $p['us']['points'], 'note' => 'Best for ' . $p['us']['best_for'], 'link' => array('See All Features', '/features')));
$n = 2;
foreach ($p['others'] as $o) {
    // The paragraph is written for this page's reader (AlternativesPages); the points under it are the platform's sourced strengths, shared data from its compare record.
    $card = array('title' => ($n++) . '. ' . $o['name'], 'text' => $o['text']);
    $rec = $o['compare'] !== '' ? (PagesController::COMPETITORS[$o['compare']] ?? null) : null;
    if ($rec) { $card['points'] = array_map(function ($s) { $s = trim((string) $s); return preg_match('/[.!?]$/', $s) ? $s : $s . '.'; }, (array) ($rec['strengths'] ?? array())); }
    if ($o['compare'] !== '') { $card['link'] = array('Compare ' . $site . ' with ' . $o['name'], '/compare/' . $o['compare']); }
    // A platform with no compare page carries its own sourced record (AlternativesPages::RECORDS): each point links to the platform's page it was read from.
    $own = AlternativesPages::RECORDS[$o['name']] ?? null;
    if (!$rec && $own) {
        $card['points_html'] = array_map(function ($f) use ($e) { return $e($f[0]) . ' <a class="sx-src" href="' . $e($f[1]) . '" rel="noopener" target="_blank">source</a>'; }, (array) $own['facts']);
    }
    $cards[] = $card;
}
echo '<div class="alt-list">' . Sections::cards($cards, 2) . '</div>';
$alt_checked = ''; foreach ($p['others'] as $o) { if (isset(AlternativesPages::RECORDS[$o['name']])) { $alt_checked = max($alt_checked, (string) AlternativesPages::RECORDS[$o['name']]['checked']); } if ($o['compare'] !== '' && isset(PagesController::COMPETITORS[$o['compare']])) { $alt_checked = max($alt_checked, (string) (PagesController::COMPETITORS[$o['compare']]['checked'] ?? '')); } }   // the newest date a listed platform's facts were checked
echo '<p class="sx-note">' . ($alt_checked !== '' ? 'The platforms on this ' . $e($p['name']) . ' list were last checked on ' . $e(date('F j, Y', strtotime($alt_checked))) . ' by the ' . $e($site) . ' team. ' : '') . 'The sourced, side-by-side table is in <a href="/compare/' . $e(strtolower($p['name'])) . '">' . $e($site . ' vs ' . $p['name']) . '</a>.</p>';
echo Sections::close();

// This page's own prose (choosing, leaving, what changes, a worked example). The fee table itself lives on the compare page and
// the best-of page, so the list pages do not repeat it; the links line and the sources are the ones written for this platform.
$alt_key = strtolower(preg_replace('/[^a-z]/i', '', (string) $p['name']));
echo CompareProse::render(CompareProse::alternatives($alt_key), 'alt');
$alt_n = array_search($alt_key, array_keys(AlternativesPages::PAGES), true);
echo Sections::related_guides((string) $p['path'], 'alt', 3, (int) $alt_n, $alt_key);   // this platform's own article first, then a different turn of the pool on each list page
echo CompareProse::links_line($alt_key);
if (!empty($faq)) { echo Sections::faq($faq); }

// The closing band comes from the layout (outside the page's <article>); the Start Free button keeps the creator role.
$cta_title = 'Try it alongside ' . $p['name'] . '.';
$cta_text = 'Build your page, publish to your socials and keep your ' . $p['name'] . ' page live while your fans move.';
$cta_signup = $signup;
