<?php
/** Site footer shared by the landing page (login_form.php) and every public page (public_page.php). */
$fl = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$site = Main::site_name();
// the creator profile passes $pub_links (see public_nav.php): links go to the main site, auth links are plain links
$foot_links = (isset($pub_links) && is_array($pub_links)) ? $pub_links : null;
$fb = $foot_links ? $fl((string) $foot_links['base']) : '';
?>
    <footer class="ld-foot sf">
        <div class="ld-wrap sf__top">
            <div class="sf__brand">
                <a class="ld-brand" href="<?php echo $fb; ?>/"><span class="ld-brand__mark" aria-hidden="true"></span><span class="ld-brand__name"><?php echo $fl($site); ?></span></a>
                <p class="sf__blurb"><?php echo $fl(SeoMeta::brand_description(!$foot_links)); ?></p>
                <?php if (!empty(SeoMeta::SOCIAL_PROFILES)): ?>
                <p class="sf__social"><?php foreach (SeoMeta::SOCIAL_PROFILES as $s_label => $s_url): ?><a href="<?php echo $fl($s_url); ?>" rel="me noopener" target="_blank"><?php echo $fl($s_label); ?></a><?php endforeach; ?></p>
                <?php endif; ?>
            </div>
            <nav class="sf__cols" aria-label="Footer">
                <div class="sf__col">
                    <h2 class="sf__h">Product</h2>
                    <a href="<?php echo $fb; ?>/features">Features</a><?php foreach (FeaturePages::FOOTER_PRODUCT as $f_slug): ?><a href="<?php echo $fb; ?>/features/<?php echo $fl($f_slug); ?>"><?php echo $fl(FeaturePages::PAGES[$f_slug]['nav_title']); ?></a><?php endforeach; ?><a href="<?php echo $fb; ?>/pricing">Pricing</a><a href="<?php echo $fb; ?>/creators">Creator directory</a><a href="<?php echo $fb; ?>/blog"><?php echo $fl(BlogController::NAME); ?></a>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">AI</h2>
                    <?php foreach (FeaturePages::FOOTER_AI as $f_slug): ?><a href="<?php echo $fb; ?>/features/<?php echo $fl($f_slug); ?>"><?php echo $fl(FeaturePages::PAGES[$f_slug]['nav_title']); ?></a><?php endforeach; ?><?php foreach (FeaturePages::ROOT as $f_slug => $f): ?><a href="<?php echo $fb; ?>/<?php echo $fl($f_slug); ?>"><?php echo $fl($f['nav_title']); ?></a><?php endforeach; ?>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">Compare</h2>
                    <?php foreach (PagesController::COMPETITORS as $slug => $c): ?><a href="<?php echo $fb; ?>/compare/<?php echo $fl($slug); ?>"><?php echo $fl($c['name']); ?> alternative</a><?php endforeach; ?><a href="<?php echo $fb; ?>/best-creator-monetization-platforms">Best creator platforms</a>
                    <?php foreach (array_keys(AlternativesPages::PAGES) as $alt): ?><a href="<?php echo $fb; ?>/<?php echo $fl($alt); ?>-alternatives">All <?php echo $fl(AlternativesPages::PAGES[$alt]['name']); ?> Alternatives</a><?php endforeach; ?>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">Learn</h2>
                    <a href="<?php echo $fb; ?>/monetize-your-content">Monetize your content</a><a href="<?php echo $fb; ?>/blog/feed.xml">RSS feed</a>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">Company</h2>
                    <a href="<?php echo $fb; ?>/about">About</a><a href="<?php echo $fb; ?>/contact">Contact</a><a href="<?php echo $fb; ?>/terms">Terms of Service</a><a href="<?php echo $fb; ?>/privacy">Privacy Policy</a>
<?php /* the account links live here too: five columns fit the footer grid, six wrap */ if ($foot_links): ?>
                    <a href="<?php echo $fl($foot_links['login']); ?>">Sign in</a><a href="<?php echo $fl($foot_links['register']); ?>">Create an account</a>
<?php else: ?>
                    <a href="/?auth=login" data-auth="login">Sign in</a><a href="/?auth=register" data-auth="register">Create an account</a>
<?php endif; ?>
                </div>
            </nav>
        </div>
        <div class="ld-wrap sf__bottom">
            <span>&copy; <?php echo date('Y'); ?> <?php echo $fl($site); ?></span>
        </div>
    </footer>
