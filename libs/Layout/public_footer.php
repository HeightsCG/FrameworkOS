<?php
/** Site footer shared by the landing page (login_form.php) and every public page (public_page.php). */
$fl = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$site = Main::site_name();
?>
    <footer class="ld-foot sf">
        <div class="ld-wrap sf__top">
            <div class="sf__brand">
                <a class="ld-brand" href="/"><span class="ld-brand__mark" aria-hidden="true"></span><span class="ld-brand__name"><?php echo $fl($site); ?></span></a>
                <p class="sf__blurb">One page for your memberships, pay-per-view posts, services and events, with a studio that publishes everywhere and payouts to your bank.</p>
            </div>
            <nav class="sf__cols" aria-label="Footer">
                <div class="sf__col">
                    <h2 class="sf__h">Product</h2>
                    <a href="/features">Features</a><a href="/pricing">Pricing</a><a href="/blog"><?php echo $fl(BlogController::NAME); ?></a>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">Compare</h2>
                    <?php foreach (PagesController::COMPETITORS as $slug => $c): ?><a href="/compare/<?php echo $fl($slug); ?>"><?php echo $fl($site); ?> vs <?php echo $fl($c['name']); ?></a><?php endforeach; ?><a href="/best-creator-monetization-platforms">Best creator platforms</a>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">Learn</h2>
                    <a href="/monetize-your-content">Monetize your content</a><a href="/blog/feed.xml">RSS feed</a>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">Legal</h2>
                    <a href="/terms">Terms of Service</a><a href="/privacy">Privacy Policy</a>
                </div>
                <div class="sf__col">
                    <h2 class="sf__h">Account</h2>
                    <a href="/?auth=login" data-auth="login">Sign in</a><a href="/?auth=register" data-auth="register">Create an account</a>
                </div>
            </nav>
        </div>
        <div class="ld-wrap sf__bottom">
            <span>&copy; <?php echo date('Y'); ?> <?php echo $fl($site); ?></span>
        </div>
    </footer>
