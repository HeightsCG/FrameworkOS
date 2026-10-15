<?php
/**
 * /integrations/<slug>: one platform (IntegrationPages::PAGES). No logo here: brand marks stay on the index grid.
 * Locals: $page, $slug, $faq, $steps.
 */
$site = Main::site_name();
$name = (string) $page['name'];
?>
<link rel="stylesheet" href="/css/growth.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/growth.css'); ?>">
<?php
$trial = Sections::trial_link();
echo Sections::panel_hero(array(
    'title'   => $name . ' + ' . $site,
    'lead'    => $page['lead'],
    'buttons' => array(array($trial[0], $trial[1], 'primary', $trial[2]), array('All Integrations', '/integrations', 'ghost')),
));

echo Sections::open('white', 'What you can do with ' . $name);
echo Sections::checks((array) $page['can'], 2);
echo Sections::close();

echo Sections::open('alt', 'How it works');
echo '<ol class="fx-steps fx-steps--3">';
$last = count($steps) - 1;
foreach ($steps as $i => $s) { echo '<li' . ($i === $last ? ' class="is-last"' : '') . '><h3>' . Sections::e(($i + 1) . '. ' . $s[0]) . '</h3><p>' . Sections::e($s[1]) . '</p></li>'; }
echo '</ol>';
echo Sections::close();

// the free tools that go with this platform, then the other integrations
$cards = array();
foreach ((array) ($page['tools'] ?? array()) as $t_slug) {
    if (!isset(ToolsController::TOOLS[$t_slug])) { continue; }
    $t = ToolsController::TOOLS[$t_slug];
    $cards[] = array('icon' => 'sparkles', 'title' => $t['nav_title'], 'text' => $t['description'], 'link' => array('Open ' . $t['nav_title'], '/tools/' . $t_slug));
}
if (!empty($cards)) {
    echo Sections::open('white', 'Free tools for ' . $name);
    echo Sections::cards($cards, count($cards) === 2 ? 2 : 3);
    echo Sections::close();
}

echo Sections::faq($faq, 'alt');

echo Sections::open('white', 'Other integrations');
echo '<p class="gi-others">';
foreach (IntegrationPages::PAGES as $o_slug => $o) { if ($o_slug !== $slug) { echo '<a class="hx__chip" href="/integrations/' . Sections::e($o_slug) . '">' . Sections::e($o['name']) . '</a>'; } }
echo '</p>';
echo Sections::close();

echo Sections::trial_cta('Post to ' . $name . ' from your page.', 'Connect ' . $name . ' and your next post goes out to your page and ' . $name . ' together.');
