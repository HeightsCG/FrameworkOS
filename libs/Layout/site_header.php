<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/images/android-chrome-192x192.png">
    <meta name="robots" content="noindex, nofollow">
    <?php echo CSRF::meta(); ?>
    <title><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="/css/site.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/site.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>/* Back-compat for the SweetAlert v1 call in api.data.js. */ window.sweetAlert = window.sweetAlert || function (t, x, i) { return window.Swal ? Swal.fire(t, x, i) : null; };</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://kit.fontawesome.com/0b1fb50c1a.js" crossorigin="anonymous"></script>
    <script src="/js/api.data.js"></script>
    <script src="/js/site.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/site.js'); ?>"></script>
</head>
<body class="app-shell">

    <aside class="app-sidebar">
        <a href="/" class="app-brand">
            <span class="app-brand__mark"></span>
            <span class="app-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <button type="button" class="app-navtoggle" id="appNavToggle" aria-label="Menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
        <nav class="app-nav">
            <span class="app-nav__label">Menu</span>
            <a href="/" class="app-nav-item<?php echo ($this->controller === 'index' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-house"></i> Home</a>
            <a href="/inbox" class="app-nav-item<?php echo ($this->controller === 'inbox' ? ' app-nav-item-active' : ''); ?>"><i class="fa-regular fa-comment-dots"></i> Inbox <span class="app-nav__badge" id="appInboxBadge" hidden></span></a>
            <?php if (Permissions::can_act_as_creator()): ?>
            <a href="/dashboard" class="app-nav-item<?php echo ($this->controller === 'dashboard' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-chart-line"></i> Analytics</a>
            <a href="/studio" class="app-nav-item<?php echo ($this->controller === 'studio' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-photo-film"></i> Content Studio</a>
            <a href="/influencers" class="app-nav-item<?php echo ($this->controller === 'influencers' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-user-astronaut"></i> Influencers</a>
            <a href="/audience" class="app-nav-item<?php echo ($this->controller === 'audience' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-users"></i> Audience</a>
            <?php if (Permissions::team_allows('manage')): ?>
            <a href="/events" class="app-nav-item<?php echo ($this->controller === 'events' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-calendar-days"></i> Events</a>
            <a href="/services" class="app-nav-item<?php echo ($this->controller === 'services' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-briefcase"></i> Services</a>
            <?php endif; ?>
            <?php endif; ?>
            <a href="/purchases" class="app-nav-item<?php echo ($this->controller === 'purchases' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-bag-shopping"></i> Purchases</a>
            <?php if (Permissions::is_admin()): ?>
            <a href="/admin" class="app-nav-item<?php echo ($this->controller === 'admin' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-shield-halved"></i> Admin</a>
            <?php endif; ?>
        </nav>
        <span class="app-sidebar__spacer"></span>
        <a href="#" class="app-signout app-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
        <span class="app-copy">&copy; <?php echo htmlspecialchars(date('Y'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="app-search">
                <input type="search" class="form-control" id="app_search" name="app_search_q" placeholder="Search creators and content&hellip;" aria-label="Search"
                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                       data-1p-ignore="true" data-lpignore="true" data-bwignore data-form-type="other">
                <div class="app-search__panel" id="appSearchPanel" hidden></div>
            </div>
            <span class="app-topbar__spacer"></span>
            <div class="app-notif" id="appNotif">
                <button type="button" class="app-notif__btn" id="notifBtn" aria-label="Notifications" aria-haspopup="true">
                    <i class="fa-regular fa-bell"></i>
                    <span class="app-notif__badge" id="notifBadge" hidden></span>
                </button>
                <div class="app-notif__panel" id="notifPanel" hidden>
                    <div class="app-notif__head"><span class="app-notif__title">Notifications</span><button type="button" class="app-notif__mark" id="notifMarkAll">Mark all read</button></div>
                    <div class="app-notif__list" id="notifList"></div>
                </div>
            </div>
            <div class="app-acct">
                <button type="button" class="app-account" id="acctBtn" aria-haspopup="true" aria-expanded="false">
                    <span class="app-avatar"><?php echo htmlspecialchars(substr(Session::get('first_name'), 0, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="app-account__name"><?php echo htmlspecialchars(Session::get('first_name') . ' ' . Session::get('last_name'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <i class="fa-solid fa-chevron-down app-account__chev"></i>
                </button>
                <div class="app-account-menu" id="acctMenu" role="menu">
                    <div class="app-account-menu__head">
                        <span class="app-avatar"><?php echo htmlspecialchars(substr(Session::get('first_name'), 0, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>
                            <span class="app-account-menu__nm d-block"><?php echo htmlspecialchars(Session::get('first_name') . ' ' . Session::get('last_name'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="app-account-menu__em d-block"><?php echo htmlspecialchars(Session::get('user_email'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                    </div>
                    <a href="/account/billing" class="app-account-menu__item" role="menuitem"><i class="fa-solid fa-credit-card"></i> Billing</a>
                    <?php if (Permissions::is_owner_creator()): ?>
                    <a href="/account/users" class="app-account-menu__item" role="menuitem"><i class="fa-solid fa-users"></i> Users</a>
                    <?php endif; ?>
                    <a href="/account/settings" class="app-account-menu__item" role="menuitem"><i class="fa-solid fa-gear"></i> Settings</a>
                    <span class="app-account-menu__sep"></span>
                    <a href="#" class="app-account-menu__item app-account-menu__item--danger app-logout" role="menuitem"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
                </div>
            </div>
        </header>

        <div class="app-content">
<?php
// Creator onboarding card: every page, until all required steps are done or the creator hides it.
$setup_card = (strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/setup') === 0) ? null : SetupService::card();
if ($setup_card && !empty($setup_card['next'])):
    $sc_next = $setup_card['next'];
    $sc_pct  = $setup_card['required_total'] > 0 ? (int) round($setup_card['required_done'] / $setup_card['required_total'] * 100) : 0;
?>
            <section class="setup-card" id="setupCard" aria-label="Studio setup progress">
                <div class="setup-card__main">
                    <div class="setup-card__head">
                        <h2 class="setup-card__title">Set up your studio</h2>
                        <span class="setup-card__count"><?php echo (int) $setup_card['required_done']; ?> of <?php echo (int) $setup_card['required_total']; ?> steps done</span>
                    </div>
                    <span class="setup-card__track"><span class="setup-card__fill" style="width:<?php echo $sc_pct; ?>%"></span></span>
                    <p class="setup-card__next">Next: <?php echo htmlspecialchars($sc_next['title'], ENT_QUOTES, 'UTF-8'); ?>. <?php echo htmlspecialchars($sc_next['text'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="setup-card__actions">
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars($sc_next['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sc_next['cta'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <a class="btn btn-secondary" href="/setup">View All Steps</a>
                </div>
                <button type="button" class="setup-card__close" data-setup-dismiss aria-label="Hide the setup checklist" title="Hide the setup checklist"><i class="fa-solid fa-xmark"></i></button>
            </section>
<?php endif; ?>
