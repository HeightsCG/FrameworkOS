<?php
/**
 * /author/dr-glauber: the author page behind every article byline. Name, role, a bio and an experience section that are
 * STUBS until Daniel fills them (no invented facts), and the list of published articles. No first name, no photo, no location.
 */
$site = Main::site_name(); $name = SeoMeta::AUTHOR_NAME; $e = function ($s) { return Sections::e($s); };
echo Sections::panel_hero(array(
    'title'   => $name,
    'lead'    => SeoMeta::AUTHOR_ROLE . '. Every article on the ' . $site . ' blog is reviewed by the team ' . $name . ' leads before it is published.',
    'buttons' => array(array('Read the Blog', '/blog', 'ghost'), array('About ' . $site, '/about', 'ghost')),
));
echo Sections::open('white', 'About ' . $name);
echo '<p class="sx-p">' . $e($name . ' leads the ' . $site . ' team, the people who build the platform and answer its support tickets.') . '</p>';
echo '<p class="sx-p sx-muted">Bio: to be written by the author.</p>';   // stub, flagged: never invented
echo Sections::close();
echo Sections::open('alt', 'Experience');
echo '<p class="sx-p sx-muted">Experience: to be written by the author.</p>';   // stub, flagged: never invented
echo Sections::close();
echo Sections::open('white', 'Articles', count($recent) . ' published article' . (count($recent) === 1 ? '' : 's') . ', newest first.');
if (empty($recent)) { echo '<p class="sx-p">No articles published yet.</p>'; }
else {
    echo '<ul class="sx-checks sx-checks--2">';
    foreach ($recent as $r) {
        $when = !empty($r['published_at']) ? date('M j, Y', strtotime($r['published_at'] . ' UTC')) : '';
        echo '<li>' . Sections::icon('check', 16) . '<span><a href="/blog/' . $e($r['slug']) . '">' . $e($r['title']) . '</a>' . ($when !== '' ? ' <span class="sx-muted">' . $e($when) . '</span>' : '') . '</span></li>';
    }
    echo '</ul>';
}
echo Sections::close();
