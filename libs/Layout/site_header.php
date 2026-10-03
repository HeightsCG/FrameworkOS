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
    <script src="/js/csrf-retry.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/csrf-retry.js'); ?>"></script>
    <script src="/js/site.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/site.js'); ?>"></script>
</head>
<body class="app-shell<?php echo UserSession::impersonating() ? ' is-impersonating' : ''; ?>">
<?php if (UserSession::impersonating()): $imp_h = htmlspecialchars((string) Session::get('u_name'), ENT_QUOTES, 'UTF-8'); ?>
    <div class="imp-bar" role="status">
        <span class="imp-bar__text"><i class="fa-solid fa-user-secret" aria-hidden="true"></i> You&rsquo;re signed in as <b>@<?php echo $imp_h; ?></b>. Password, payment and payout changes are turned off.</span>
        <button type="button" class="imp-bar__btn" id="impReturn">Return to Admin</button>
    </div>
    <script>
    document.getElementById('impReturn').addEventListener('click', function () {
        var b = this; b.disabled = true; b.textContent = 'Returning…';
        ApiDataSvc.apiCall('post', 'impersonate_stop', {}, function (r) {
            var o = null; try { o = JSON.parse(r); } catch (e) {}
            window.location.href = (o && o.redirect) ? o.redirect : '/admin';
        });
    });
    </script>
<?php endif; ?>

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
            <?php if (Permissions::can_act_as_creator() || !Permissions::is_team_member()): /* Free accounts see the creator pages too; each opens behind an upgrade cover (Plan::COVERS) */ ?>
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
                    <a href="/support" class="app-account-menu__item" role="menuitem"><i class="fa-solid fa-life-ring"></i> Support</a>
                    <span class="app-account-menu__sep"></span>
                    <a href="#" class="app-account-menu__item app-account-menu__item--danger app-logout" role="menuitem"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
                </div>
            </div>
        </header>

<?php $plan_cover = Plan::cover($this->controller); ?>
<?php if ($plan_cover): ?>
        <div class="plan-lock" role="dialog" aria-modal="false" aria-labelledby="plan_lock_title">
            <div class="plan-lock__card">
                <div class="plan-lock__icon"><i class="fa-solid <?php echo $plan_cover['icon']; ?>" aria-hidden="true"></i></div>
                <h2 class="plan-lock__title" id="plan_lock_title"><?php echo htmlspecialchars($plan_cover['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="plan-lock__text"><?php echo htmlspecialchars($plan_cover['text'], ENT_QUOTES, 'UTF-8'); ?></p>
                <a href="/account/billing" class="plan-lock__btn">Choose a Plan</a>
                <p class="plan-lock__note">Included with Creator and Studio.</p>
            </div>
        </div>
<?php endif; ?>
        <div class="app-content<?php echo $plan_cover ? ' app-content--covered' : ''; ?>"<?php echo $plan_cover ? ' inert aria-hidden="true"' : ''; ?>>
<?php
// Creator onboarding widget: floats bottom-right on every page (not on /setup) until every
// required step is done or skipped, or the creator hides it. Collapsed/open is remembered client-side.
$setup = (strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/setup') === 0) ? null : SetupService::card();
if ($setup):
    $sw_e    = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $sw_pct  = $setup['required_total'] > 0 ? (int) round($setup['required_done'] / $setup['required_total'] * 100) : 0;
    $sw_done = !empty($setup['complete']);
?>
            <aside class="setup-widget" id="setupWidget" data-open="0" aria-label="Studio setup progress">
                <button type="button" class="setup-widget__pill" data-setup-toggle aria-expanded="false" aria-controls="setupWidgetPanel">
                    <span class="setup-widget__ring" style="--pct:<?php echo $sw_pct; ?>"><span><?php echo (int) $setup['required_done']; ?>/<?php echo (int) $setup['required_total']; ?></span></span>
                    <span class="setup-widget__pilltext"><?php echo $sw_done ? 'You\'re all set' : 'Set up your studio'; ?></span>
                    <i class="fa-solid fa-chevron-up setup-widget__chev" aria-hidden="true"></i>
                </button>
                <div class="setup-widget__panel" id="setupWidgetPanel" hidden>
                    <div class="setup-widget__head">
                        <div>
                            <h2 class="setup-widget__title"><?php echo $sw_done ? 'You\'re all set' : 'Set up your studio'; ?></h2>
                            <span class="setup-widget__count"><?php echo $sw_done ? 'All ' . (int) $setup['required_total'] . ' steps done' : (int) $setup['required_done'] . ' of ' . (int) $setup['required_total'] . ' steps done'; ?></span>
                        </div>
                        <div class="setup-widget__headbtns">
                            <button type="button" class="setup-widget__iconbtn" data-setup-toggle aria-label="Collapse"><i class="fa-solid fa-minus"></i></button>
                            <button type="button" class="setup-widget__iconbtn" data-setup-dismiss aria-label="Hide the setup checklist" title="Hide the setup checklist"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    </div>
                    <span class="setup-widget__track"><span class="setup-widget__fill" style="width:<?php echo $sw_pct; ?>%"></span></span>
                    <ol class="setup-widget__list">
                        <?php foreach ($setup['steps'] as $st): if (!empty($st['skipped'])) { continue; } ?>
                        <li class="setup-widget__step<?php echo $st['done'] ? ' is-done' : ''; ?><?php echo !empty($st['optional']) ? ' is-optional' : ''; ?>" data-step="<?php echo $sw_e($st['key']); ?>">
                            <span class="setup-widget__mark" aria-hidden="true"><?php echo $st['done'] ? '<i class="fa-solid fa-check"></i>' : ''; ?></span>
                            <a class="setup-widget__name setup-widget__link" href="<?php echo $sw_e($st['url']); ?>" title="<?php echo $st['done'] ? 'Done. Open this page again' : $sw_e($st['text']); ?>"><?php echo $sw_e($st['title']); ?><?php echo !empty($st['optional']) ? ' <small>Optional</small>' : ''; ?></a>
                            <?php if (!$st['done']): ?>
                            <button type="button" class="setup-widget__skip" data-setup-skip="<?php echo $sw_e($st['key']); ?>" aria-label="Skip this step" title="Skip this step"><i class="fa-solid fa-xmark"></i></button>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                    <div class="setup-widget__foot">
                        <a class="setup-widget__all" href="/setup">View All Steps</a>
                        <?php if ($sw_done): ?><button type="button" class="btn btn-primary btn-sm setup-widget__done" data-setup-dismiss data-setup-complete="1">Done</button><?php endif; ?>
                    </div>
                </div>
            </aside>
<?php endif; ?>
