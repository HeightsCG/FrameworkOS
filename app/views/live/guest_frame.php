<?php /* CLS Video standalone page: a visitor without an account (site banner + the call), or anyone on a creator's own domain (the call only). $c = the call. */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <?php echo CSRF::meta(); ?>
    <title><?php echo htmlspecialchars($c['title'] . ' · ' . Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css">
    <link rel="stylesheet" href="/css/site.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/site.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/api.data.js"></script>
    <script src="/js/csrf-retry.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/csrf-retry.js'); ?>"></script>
    <style>body.lv-guest{ background:var(--bg); } .lv-guest__main{ padding:1.5rem 16px 2rem; } .lv-guest .lv{ height:calc(100vh - 64px - 3.5rem); max-width:1152px; } .lv-guest--own .lv{ height:calc(100vh - 3.5rem); }</style>
</head>
<body class="lv-guest<?php echo !empty($c['own_domain']) ? ' lv-guest--own' : ''; ?>">
<?php if (empty($c['own_domain'])) { include Main::app_path() . '/libs/Layout/guest_bar.php'; } /* not on a creator's own domain: their brand */ ?>
<main class="lv-guest__main">
<?php require __DIR__ . '/_room.php'; ?>
</main>
</body>
</html>
