<?php
/**
 * /author/dr-glauber: the author page behind every article byline (Daniel's text, 2026-10-12). Name, role, bio, experience
 * and the list of published articles. No first name, no photo, no location. Linked only from the byline.
 */
$site = Main::site_name(); $name = SeoMeta::AUTHOR_NAME; $e = function ($s) { return Sections::e($s); };
echo Sections::panel_hero(array(
    'title'   => $name,
    'lead'    => SeoMeta::AUTHOR_ROLE . '. Every article on the ' . $site . ' blog is reviewed by the team ' . $name . ' leads before it is published.',
    'buttons' => array(array('Read the Blog', '/blog', 'ghost'), array('About ' . $site, '/about', 'ghost')),
));
echo Sections::open('white', 'About ' . $name);
foreach (SeoMeta::AUTHOR_BIO as $p) { echo '<p class="sx-p">' . $e($p) . '</p>'; }
echo Sections::close();
echo Sections::open('alt', 'Experience');
echo '<ul class="sx-checks">';
foreach (SeoMeta::AUTHOR_EXPERIENCE as $line) { echo '<li>' . Sections::icon('check', 16) . '<span>' . $e($line) . '</span></li>'; }
echo '</ul>';
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
