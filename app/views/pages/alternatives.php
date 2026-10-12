<?php
/**
 * One alternatives list page (/onlyfans-alternatives, /fanvue-alternatives), from PagesController::alternatives_page().
 * Locals: $p (the filled page row with 'us' first), $faq.
 */
$e = function ($s) { return Sections::e($s); }; $site = Main::site_name();
$signup = '/?auth=register&role=creator';
$rows = array(array($p['us']['name'], $p['us']['kind'], true));
foreach ($p['others'] as $o) { $rows[] = array($o['name'], $o['kind']); }
echo Sections::panel_hero(array(
    'title'   => $p['title'],
    'lead'    => $p['intro'],
    'buttons' => array(array('Start Free', $signup, 'primary', 'register'), array('Compare All Platforms', '/best-creator-monetization-platforms', 'secondary')),
    'panel'   => Sections::pane('On this list', count($rows) . ' platforms, in the order below.', Sections::pane_rows($rows)),
));

// The numbered list: us first (we build it and say so), then each platform with its kind of platform and who it suits.
echo Sections::open('white', 'Which ' . $p['name'] . ' alternatives are worth considering?', 'We build ' . $site . ', so it is first, and we say so. Each other platform is described from the same sourced record as its comparison page. Prices change, so check each platform\'s own page for current numbers.');
$cards = array(array('title' => '1. ' . $p['us']['name'], 'text' => $p['us']['text'], 'points' => $p['us']['points'], 'note' => 'Best for: ' . $p['us']['best_for'], 'link' => array('See All Features', '/features')));
$n = 2;
foreach ($p['others'] as $o) {
    $card = array('title' => ($n++) . '. ' . $o['name'], 'text' => $o['text'], 'note' => 'Best for: ' . $o['best_for']);
    $rec = $o['compare'] !== '' ? (PagesController::COMPETITORS[$o['compare']] ?? null) : null;
    if ($rec) {   // what the platform is, what it does well and who it suits, from the record its compare page is built on (no prices here: they sit in the dated fee table below)
        $card['text'] = (string) (Sections::cap((string) $rec['summary'], 2)[0] ?? $o['text']);
        $card['points'] = array_map(function ($s) { $s = trim((string) $s); return preg_match('/[.!?]$/', $s) ? $s : $s . '.'; }, (array) ($rec['strengths'] ?? array()));
        $card['note'] = 'Best for: ' . (string) ($rec['best_for'] ?? $o['best_for']);
    }
    if ($o['compare'] !== '') { $card['link'] = array('Compare ' . $site . ' with ' . $o['name'], '/compare/' . $o['compare']); }
    $cards[] = $card;
}
echo '<div class="alt-list">' . Sections::cards($cards, 2) . '</div>';
$alt_checked = ''; foreach ($p['others'] as $o) { if ($o['compare'] !== '' && isset(PagesController::COMPETITORS[$o['compare']])) { $alt_checked = max($alt_checked, (string) (PagesController::COMPETITORS[$o['compare']]['checked'] ?? '')); } }   // the newest date a listed platform's facts were checked
echo '<p class="sx-note">' . ($alt_checked !== '' ? 'Platform details last checked on ' . $e(date('F j, Y', strtotime($alt_checked))) . ' by the ' . $e($site) . ' team. ' : '') . $e($p['us']['note']) . ' For a sourced, side-by-side look, read <a href="/compare/' . $e(strtolower($p['name'])) . '">' . $e($site . ' vs ' . $p['name']) . '</a>.</p>';
echo Sections::close();

// Worked fee example across the platforms on this list that have a sourced rate (the ones with a /compare page), then payout terms beside the platform the reader is leaving.
$compare_slugs = array_values(array_filter(array_map(function ($o) { return (string) $o['compare']; }, $p['others'])));
echo CompareProse::render(CompareProse::alternatives(strtolower($p['name'])), 'alt');   // this page's own prose: choosing, leaving, what changes, a computed scenario
$alt_key = strtolower(preg_replace('/[^a-z]/i', '', (string) $p['name']));
echo PagesController::fee_example_section(isset(PagesController::COMPETITORS[$alt_key]) ? array($alt_key) : $compare_slugs, 'white', array(1000, 5000), '', $alt_key);   // only the platform the reader is leaving, beside our plans: the all-platforms table lives on the best-of page, so the list pages do not repeat it
echo Sections::related_guides((string) $p['path'], 'alt', 3);
echo CompareProse::links_line();
if (!empty($faq)) { echo Sections::faq($faq); }

// The closing band comes from the layout (outside the page's <article>); the Start Free button keeps the creator role.
$cta_title = 'Try it alongside ' . $p['name'] . '.';
$cta_text = 'Build your page, publish to your socials and keep your ' . $p['name'] . ' page live while your fans move.';
$cta_signup = $signup;
