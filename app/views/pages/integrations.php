<?php
/**
 * /integrations: the ten platforms the studio publishes to (IntegrationPages::PAGES), each a tile with its own mark
 * (small, the index only) linking to /integrations/<slug>. Locals: $faq.
 */
$site = Main::site_name();
?>
<link rel="stylesheet" href="/css/growth.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/growth.css'); ?>">
<?php
echo Sections::panel_hero(array('photo' => 'integrations', 'variant' => 'full', 
    'title' => 'Integrations',
    'lead'  => 'Write a post once and send it to ten platforms. Connect your accounts in Settings, tick the ones you want on each post, and publish now or on a schedule.',
));

echo Sections::open('white', 'Ten platforms, one editor', 'Social networks get the public version of each post. Fanvue gets the whole post.');
echo '<ul class="gi-grid">';
foreach (IntegrationPages::PAGES as $slug => $p) {
    $mark = IntegrationPages::logo($slug, 24);
    echo '<li><a class="gi-tile" href="/integrations/' . Sections::e($slug) . '">'
        . ($mark !== '' ? '<span class="gi-tile__mark">' . $mark . '</span>' : '<span class="gi-tile__mark gi-tile__mark--text" aria-hidden="true">' . Sections::e(mb_substr($p['name'], 0, 1)) . '</span>')
        . '<span class="gi-tile__name">' . Sections::e($p['name']) . '</span>'
        . '<span class="gi-tile__text">' . Sections::e($p['lead']) . '</span>'
        . '</a></li>';
}
echo '</ul>';
echo Sections::close();

echo Sections::open('alt', 'How publishing works');
echo '<ol class="fx-steps fx-steps--3">'
    . '<li><h3>1. Connect your accounts</h3><p>Open Settings, then Integrations, and connect each platform with its own sign-in.</p></li>'
    . '<li><h3>2. Write the post once</h3><p>Create your post in the editor and tick the platforms under Distribution.</p></li>'
    . '<li class="is-last"><h3>3. Publish or schedule</h3><p>Publish now or pick a time. Your page and every platform you ticked update together.</p></li>'
    . '</ol>';
echo Sections::close();

echo Sections::open('white', 'Free tools for your profiles');
echo Sections::cards(array(
    array('icon' => 'sparkles', 'title' => ToolsController::TOOLS['ai-influencer-bio-generator']['nav_title'], 'text' => 'Bios sized for Instagram, TikTok, X, Threads and Bluesky, with a character count for each.', 'link' => array('Write Bios', '/tools/ai-influencer-bio-generator')),
    array('icon' => 'shield', 'title' => ToolsController::TOOLS['ai-disclosure-caption-checker']['nav_title'], 'text' => 'Check that a caption discloses AI content clearly before you post it.', 'link' => array('Check a Caption', '/tools/ai-disclosure-caption-checker')),
    array('icon' => 'users', 'title' => ToolsController::TOOLS['ai-influencer-name-generator']['nav_title'], 'text' => 'Twenty name ideas for a new character, from your niche and the vibe you want.', 'link' => array('Find Names', '/tools/ai-influencer-name-generator')),
), 3);
echo Sections::close();

echo Sections::faq($faq, 'alt');
echo Sections::trial_cta('Publish everywhere from one page.', 'Connect your platforms and send your next post to all of them at once.');
