<?php
/** Top menu shared by the landing page (login_form.php) and every public page (public_page.php). Grouped panels from SiteNav (2026-10-15), behaviour in public/js/nav.js. */
$nav_path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
// the creator profile has no auth dialog: it sets $pub_links (base = main site, '' or absolute; login; register; cta 'outline'
// when the page has its own primary action) and gets plain links
$nav_links = (isset($pub_links) && is_array($pub_links)) ? $pub_links : null;
$nav_base  = $nav_links ? (string) $nav_links['base'] : '';
$nav_e     = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
// Founding offer bar above the nav on every public page while spots remain (Daniel 2026-10-12). Not on creator profiles: those are the creator's page, not ours.
$nav_offer = false;
if (!$nav_links) { try { $nav_offer = Founding::remaining_cached() > 0; } catch (\Throwable $ex) { error_log('[nav] founding: ' . $ex->getMessage()); } }
?>
<?php if ($nav_offer): ?>
    <aside class="fb" aria-label="Founding offer"><p class="fb__in"><span class="fb__text"><?php echo (int) Founding::TRIAL_DAYS; ?> days free on <?php echo $nav_e(Founding::plan_names()); ?>.</span> <?php $nav_claim = Founding::claim_link(); ?><a href="<?php echo $nav_e($nav_claim[0]); ?>" rel="nofollow"<?php echo $nav_claim[1] !== '' ? ' data-auth="' . $nav_e($nav_claim[1]) . '"' : ''; ?>>Claim Your Spot <span aria-hidden="true">&rarr;</span></a></p></aside>
<?php endif; ?>
    <header class="ld-nav">
        <div class="ld-wrap ld-nav__inner">
            <a class="ld-brand" href="<?php echo $nav_e($nav_base . '/'); ?>">
                <img class="ld-brand__logo" src="/images/logo/logo-mark.svg" width="28" height="28" alt="">
                <span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <nav class="mn" id="ld_nav_links" aria-label="Site">
<?php $nav_on = SiteNav::group_of($nav_path); $nav_chev = '<svg class="mn__chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'; ?>
<?php foreach (SiteNav::GROUPS as $ng_key => $ng): if ($ng_key === 'resources'): ?>
                <a class="mn__top<?php echo $nav_on === 'pricing' ? ' is-active' : ''; ?>" href="<?php echo $nav_e($nav_base . '/pricing'); ?>"<?php echo $nav_path === '/pricing' ? ' aria-current="page"' : ''; ?> data-track="nav_click" data-track-label="Pricing">Pricing</a>
<?php endif; ?>
                <div class="mn__g" data-mn="<?php echo $nav_e($ng_key); ?>">
                    <button type="button" class="mn__top<?php echo $nav_on === $ng_key ? ' is-active' : ''; ?>" aria-expanded="false" aria-controls="mn_p_<?php echo $nav_e($ng_key); ?>"><?php echo $nav_e($ng['label']); ?><?php echo $nav_chev; ?></button>
                    <div class="mn__panel" id="mn_p_<?php echo $nav_e($ng_key); ?>">
                        <div class="ld-wrap mn__in">
                            <ul class="mn__grid mn__grid--<?php echo count($ng['items']) > 5 ? 'wide' : 'narrow'; ?>">
<?php foreach ($ng['items'] as $it): $it_on = ($nav_path === $it[0]); ?>
                                <li><a class="mn__item<?php echo $it_on ? ' is-active' : ''; ?>" href="<?php echo $nav_e($nav_base . $it[0]); ?>"<?php echo $it_on ? ' aria-current="page"' : ''; ?> data-track="nav_click" data-track-label="<?php echo $nav_e($it[1]); ?>"><span class="mn__ic"><?php echo Sections::icon($it[3], 20); ?></span><span class="mn__tx"><b><?php echo $nav_e($it[1]); ?></b><small><?php echo $nav_e($it[2]); ?></small></span></a></li>
<?php endforeach; ?>
                            </ul>
<?php if (!empty($ng['foot'])): ?>
                            <a class="mn__foot" href="<?php echo $nav_e($nav_base . $ng['foot'][0]); ?>" data-track="nav_click" data-track-label="<?php echo $nav_e($ng['foot'][1]); ?>"><?php echo $nav_e($ng['foot'][1]); ?><?php echo Sections::icon('arrow-right', 16); ?></a>
<?php endif; ?>
                        </div>
                    </div>
                </div>
<?php endforeach; ?>
<?php if ($nav_links): ?>
                <a class="ld-nav__signin" href="<?php echo $nav_e($nav_links['login']); ?>" rel="nofollow">Sign In</a>
<?php else: ?>
                <button type="button" class="ld-nav__signin" data-auth="login">Sign In</button>
<?php endif; ?>
            </nav>
            <div class="ld-nav__actions">
<?php if ($nav_links): ?>
                <a class="ld-btn ld-btn--quiet" href="<?php echo $nav_e($nav_links['login']); ?>" rel="nofollow">Sign In</a>
                <a class="ld-btn ld-nav__cta<?php echo (string) ($nav_links['cta'] ?? '') === 'outline' ? ' ld-nav__cta--outline' : ' ld-btn--primary'; ?>" href="<?php echo $nav_e($nav_links['register']); ?>" rel="nofollow">Get Started</a>
<?php else: ?>
                <button type="button" class="ld-btn ld-btn--quiet" data-auth="login">Sign In</button>
                <button type="button" class="ld-btn ld-btn--primary ld-nav__cta" data-auth="register" data-track="nav_click" data-track-label="Get Started">Get Started</button>
<?php endif; ?>
                <button type="button" class="ld-nav__toggle" id="ld_nav_toggle" aria-label="Menu" aria-expanded="false" aria-controls="ld_nav_links">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
    </header>
    <script defer src="/js/nav.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/nav.js'); ?>"></script>
