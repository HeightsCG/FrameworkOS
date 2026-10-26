<?php
/**
 * /press: boilerplate (50 and 150 words), logo downloads, brand colors, product facts, the press contact and the
 * "Link to us" badges. No founder name, no figures about earnings or fees. Logos: public/images/logo/,
 * badges: public/images/badges/. The copy buttons are wired by public/js/free-tools.js (data-copy-from).
 */
$e = function ($s) { return Sections::e($s); };
$site = Main::site_name();
$base = SeoMeta::base();
$host = preg_replace('#^https?://#', '', $base);
$company = 'Heights Consulting Group LLC';
$b50  = $site . ' is a creator platform for selling from one public page: memberships, pay-per-view posts, bundles, services and live events. Its studio publishes each post to ten platforms, and its AI tools create consistent AI influencers with photos, video and voice. It is built by ' . $company . '.';
$b150 = array($site . ' is a creator platform that puts everything a creator sells on one public page. Every account starts free. Fans can follow for free, join a membership, unlock single posts, buy bundles, book services and get seats at live events, and creators are paid out to their bank. Fans pay with a credit wallet for single purchases and by card for memberships.',   // two paragraphs: none longer than 6 sentences (HeyCatch page clarity)
    'From one editor, creators publish each post to their page and to LinkedIn, Bluesky, X, Facebook, Instagram, Threads, TikTok, YouTube, Pinterest and Fanvue, now or on a schedule. The AI studio creates consistent AI influencers with photos, video and voice, and labels AI content on the platforms that ask for it. An inbox with an AI reply assistant, analytics and custom domains round out the platform. Creators can download their content and data at any time. ' . $site . ' is built by ' . $company . '.');
$platforms = array(); foreach (IntegrationPages::PAGES as $p) { $platforms[] = $p['name']; }
$trial_open = false; try { $trial_open = Founding::remaining_cached() > 0; } catch (\Throwable $ex) { error_log('[press] founding: ' . $ex->getMessage()); }
$logos = array(
    array('Logo mark', 'For app icons, avatars and small spaces.', 'is-dark', '/images/logo/logo-mark.svg', 256, 256, array(array('SVG', '/images/logo/logo-mark.svg'), array('PNG 512', '/images/logo/logo-mark-512.png'), array('PNG 1024', '/images/logo/logo-mark-1024.png'))),
    array('Logo for dark backgrounds', 'White wordmark with the orange mark.', 'is-dark', '/images/logo/logo-dark-bg.svg', 407, 64, array(array('SVG', '/images/logo/logo-dark-bg.svg'), array('PNG 1600', '/images/logo/logo-dark-bg.png'))),
    array('Logo for light backgrounds', 'Black wordmark with the orange mark.', 'is-light', '/images/logo/logo-light-bg.svg', 407, 64, array(array('SVG', '/images/logo/logo-light-bg.svg'), array('PNG 1600', '/images/logo/logo-light-bg.png'))),
);
$colors = array(array('Orange', '#FF6A13', 'The brand color and primary actions.'), array('Deep orange', '#E85A06', 'Hover and pressed states.'), array('Black', '#050505', 'Backgrounds.'), array('Dark grey', '#161618', 'Panels and cards.'), array('Off-white', '#F5F5F4', 'Text on dark backgrounds.'));
$badges = array(
    array('built-with-light', 'Built with ' . $site, 'Light'), array('built-with-dark', 'Built with ' . $site, 'Dark'),
    array('works-with-light', 'Works with ' . $site, 'Light'), array('works-with-dark', 'Works with ' . $site, 'Dark'),
);
?>
<link rel="stylesheet" href="/css/tools.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/tools.css'); ?>">
<link rel="stylesheet" href="/css/growth.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/growth.css'); ?>">
<?php
echo Sections::panel_hero(array('photo' => 'press', 'variant' => 'editorial', 
    'title'   => 'Press kit',
    'lead'    => 'Everything you need to write about ' . $site . ' or link to it: a short and a long description, logos, brand colors, product facts and link badges.',
    'buttons' => array(array('Download Logos', '#logos', 'secondary'), array('Get a Link Badge', '#link-to-us', 'ghost')),
));

echo Sections::open('white', 'About ' . $site, 'Use either description as it is. Both are written to be quoted.', 'boilerplate');
?>
<div class="gp-copy">
    <div class="gp-copy__item">
        <div class="gp-copy__head"><h3 class="sx-h3"><?php echo $e(Sections::tc("Short description")); ?></h3><span class="gp-copy__count">50 words</span></div>
        <p class="gp-copy__text" id="gp_b50"><?php echo $e($b50); ?></p>
        <button class="sx-btn sx-btn--secondary" type="button" data-copy-from="gp_b50">Copy Text</button>
    </div>
    <div class="gp-copy__item">
        <div class="gp-copy__head"><h3 class="sx-h3"><?php echo $e(Sections::tc("Long description")); ?></h3><span class="gp-copy__count">150 words</span></div>
        <div class="gp-copy__text" id="gp_b150"><p><?php echo $e($b150[0]); ?></p>

<p><?php echo $e($b150[1]); ?></p></div><?php /* the blank line between is a text node, so Copy Text keeps the paragraph break */ ?>
        <button class="sx-btn sx-btn--secondary" type="button" data-copy-from="gp_b150">Copy Text</button>
    </div>
</div>
<?php
echo Sections::close();

echo Sections::open('alt', 'Logos', 'Keep the mark and wordmark together as supplied, with clear space around them. Do not recolor or stretch them.', 'logos');
echo '<div class="gp-logos">';
foreach ($logos as $l) {
    echo '<div class="gp-logo"><div class="gp-logo__preview ' . $e($l[2]) . '"><img src="' . $e($l[3]) . '" alt="' . $e($site . ' ' . strtolower($l[0])) . '" width="' . (int) $l[4] . '" height="' . (int) $l[5] . '" loading="lazy"></div>'
        . '<h3 class="gp-logo__name">' . $e(Sections::tc($l[0])) . '</h3><p class="gp-logo__text">' . $e($l[1]) . '</p><p class="gp-logo__dl">';
    foreach ($l[6] as $d) { echo '<a class="hx__chip" href="' . $e($d[1]) . '" download>' . $e($d[0]) . '</a>'; }
    echo '</p></div>';
}
echo '</div>';
echo Sections::close();

echo Sections::open('white', 'Brand colors and type', 'The interface typeface is Inter.', 'colors');
echo '<ul class="gp-colors">';
foreach ($colors as $c) {
    echo '<li class="gp-color"><span class="gp-color__chip" style="background:' . $e($c[1]) . '"></span><b>' . $e($c[0]) . '</b><code>' . $e($c[1]) . '</code><small>' . $e($c[2]) . '</small></li>';
}
echo '</ul>';
echo Sections::close();

echo Sections::open('alt', 'Product facts', '', 'facts');
?>
<dl class="gp-facts">
    <div><dt>Product</dt><dd><?php echo $e($site); ?>, a creator platform for selling from one public page</dd></div>
    <div><dt>Website</dt><dd><a href="<?php echo $e($base); ?>/"><?php echo $e($host); ?></a></dd></div>
    <div><dt>Company</dt><dd><?php echo $e($company); ?></dd></div>
    <div><dt>Publishes to</dt><dd><?php echo $e(implode(', ', $platforms)); ?></dd></div>
    <div><dt>Ways to sell</dt><dd>Memberships, pay-per-view posts, bundles, services and live events</dd></div>
    <div><dt>AI tools</dt><dd>Consistent AI influencers with photos, video and voice, an AI reply assistant for the inbox, and AI labels on the platforms that ask for them</dd></div>
    <div><dt>Also included</dt><dd>Link in bio, analytics, custom domains and payouts to your bank</dd></div>
<?php if ($trial_open): ?>
    <div><dt>Free trial</dt><dd><?php echo (int) Founding::TRIAL_DAYS; ?> days free on <?php echo $e(Founding::plan_names()); ?> for founding creators</dd></div>
<?php endif; ?>
<?php if (!empty(SeoMeta::SOCIAL_PROFILES)): ?>
    <div><dt>Profiles</dt><dd class="gp-facts__links"><?php foreach (SeoMeta::SOCIAL_PROFILES as $s_label => $s_url): ?><a href="<?php echo $e($s_url); ?>" rel="noopener" target="_blank"><?php echo $e($s_label); ?></a><?php endforeach; ?></dd></div>
<?php endif; ?>
</dl>
<?php
echo Sections::close();

echo Sections::open('white', 'Press contact', '', 'contact');
echo '<p class="sx-p">For interviews, product questions and partnerships, email <a href="mailto:' . $e(PagesController::PRESS_CONTACT) . '">' . $e(PagesController::PRESS_CONTACT) . '</a>.</p>';
echo Sections::close();

echo Sections::open('alt', 'Link to us', 'Using ' . $site . ' or building on it? Add a badge to your site. Copy the code and paste it where you want the badge.', 'link-to-us');
echo '<div class="gp-badges">';
foreach ($badges as $i => $b) {
    $src = $base . '/images/badges/' . $b[0] . '.svg';
    $code = '<a href="' . $base . '/"><img src="' . $src . '" alt="' . $b[1] . '" width="200" height="44"></a>';
    echo '<div class="gp-badge"><div class="gp-badge__preview ' . ($b[2] === 'Dark' ? 'is-light' : 'is-dark') . '">' . $code . '</div>'
        . '<p class="gp-badge__name">' . $e($b[1]) . ', ' . strtolower($b[2]) . '</p>'
        . '<textarea class="ct-input ct-input--area tl-embed__code" id="gp_badge_' . $i . '" rows="3" readonly aria-label="' . $e('Code for the ' . strtolower($b[2]) . ' ' . $b[1] . ' badge') . '">' . $e($code) . '</textarea>'
        . '<button class="sx-btn sx-btn--secondary" type="button" data-copy-from="gp_badge_' . $i . '">Copy Code</button></div>';
}
echo '</div>';
echo Sections::close();
?>
<script defer src="/js/free-tools.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/free-tools.js'); ?>"></script>
