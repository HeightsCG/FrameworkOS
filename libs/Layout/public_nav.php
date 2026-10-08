<?php
/** Top menu shared by the landing page (login_form.php) and every public page (public_page.php). */
$nav_path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$nav_items = array(array('/features', 'Features'), array('/pricing', 'Pricing'), array('/blog', BlogController::NAME));
// the creator profile has no auth dialog: it sets $pub_links (base = main site, '' or absolute; login; register; cta 'outline'
// when the page has its own primary action) and gets plain links
$nav_links = (isset($pub_links) && is_array($pub_links)) ? $pub_links : null;
$nav_base  = $nav_links ? (string) $nav_links['base'] : '';
$nav_e     = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
    <header class="ld-nav">
        <div class="ld-wrap ld-nav__inner">
            <a class="ld-brand" href="<?php echo $nav_e($nav_base . '/'); ?>">
                <span class="ld-brand__mark" aria-hidden="true"></span>
                <span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <nav class="ld-nav__links" id="ld_nav_links" aria-label="Site">
<?php foreach ($nav_items as $ni): $on = ($nav_path === $ni[0] || strpos($nav_path, $ni[0] . '/') === 0); ?>
                <a href="<?php echo $nav_e($nav_base . $ni[0]); ?>"<?php echo $on ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo htmlspecialchars($ni[1], ENT_QUOTES, 'UTF-8'); ?></a>
<?php endforeach; ?>
<?php if ($nav_links): ?>
                <a class="ld-nav__signin" href="<?php echo $nav_e($nav_links['login']); ?>">Sign In</a>
<?php else: ?>
                <button type="button" class="ld-nav__signin" data-auth="login">Sign In</button>
<?php endif; ?>
            </nav>
            <div class="ld-nav__actions">
<?php if ($nav_links): ?>
                <a class="ld-btn ld-btn--quiet" href="<?php echo $nav_e($nav_links['login']); ?>">Sign In</a>
                <a class="ld-btn ld-nav__cta<?php echo (string) ($nav_links['cta'] ?? '') === 'outline' ? ' ld-nav__cta--outline' : ' ld-btn--primary'; ?>" href="<?php echo $nav_e($nav_links['register']); ?>">Get Started</a>
<?php else: ?>
                <button type="button" class="ld-btn ld-btn--quiet" data-auth="login">Sign In</button>
                <button type="button" class="ld-btn ld-btn--primary ld-nav__cta" data-auth="register">Get Started</button>
<?php endif; ?>
                <button type="button" class="ld-nav__toggle" id="ld_nav_toggle" aria-label="Menu" aria-expanded="false" aria-controls="ld_nav_links">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
    </header>
