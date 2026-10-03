<?php
/** Top menu shared by the landing page (login_form.php) and every public page (public_page.php). */
$nav_path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$nav_items = array(array('/features', 'Features'), array('/pricing', 'Pricing'), array('/blog', BlogController::NAME));
?>
    <header class="ld-nav">
        <div class="ld-wrap ld-nav__inner">
            <a class="ld-brand" href="/">
                <span class="ld-brand__mark" aria-hidden="true"></span>
                <span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <nav class="ld-nav__links" id="ld_nav_links" aria-label="Site">
<?php foreach ($nav_items as $ni): $on = ($nav_path === $ni[0] || strpos($nav_path, $ni[0] . '/') === 0); ?>
                <a href="<?php echo $ni[0]; ?>"<?php echo $on ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo htmlspecialchars($ni[1], ENT_QUOTES, 'UTF-8'); ?></a>
<?php endforeach; ?>
                <button type="button" class="ld-nav__signin" data-auth="login">Sign In</button>
            </nav>
            <div class="ld-nav__actions">
                <button type="button" class="ld-btn ld-btn--quiet" data-auth="login">Sign In</button>
                <button type="button" class="ld-btn ld-btn--primary ld-nav__cta" data-auth="register">Get Started</button>
                <button type="button" class="ld-nav__toggle" id="ld_nav_toggle" aria-label="Menu" aria-expanded="false" aria-controls="ld_nav_links">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
    </header>
