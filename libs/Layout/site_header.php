<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        <nav class="app-nav">
            <span class="app-nav__label">Menu</span>
            <a href="/" class="app-nav-item<?php echo ($this->controller === 'index' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-house"></i> Home</a>
            <?php if (Permissions::has_role('Creator')): ?>
            <a href="/dashboard" class="app-nav-item<?php echo ($this->controller === 'dashboard' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-chart-line"></i> Analytics</a>
            <a href="/studio" class="app-nav-item<?php echo ($this->controller === 'studio' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-photo-film"></i> Content Studio</a>
            <a href="/audience" class="app-nav-item<?php echo ($this->controller === 'audience' ? ' app-nav-item-active' : ''); ?>"><i class="fa-solid fa-users"></i> Audience</a>
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
                <input type="search" class="form-control" id="app_search" placeholder="Search creators and content&hellip;" autocomplete="off" aria-label="Search">
                <div class="app-search__panel" id="appSearchPanel" hidden></div>
            </div>
            <span class="app-topbar__spacer"></span>
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
                    <a href="/account/users" class="app-account-menu__item" role="menuitem"><i class="fa-solid fa-users"></i> Users</a>
                    <a href="/account/settings" class="app-account-menu__item" role="menuitem"><i class="fa-solid fa-gear"></i> Settings</a>
                    <span class="app-account-menu__sep"></span>
                    <a href="#" class="app-account-menu__item app-account-menu__item--danger app-logout" role="menuitem"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
                </div>
            </div>
        </header>

        <div class="app-content">
