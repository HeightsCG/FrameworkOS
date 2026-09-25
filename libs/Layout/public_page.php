<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/images/android-chrome-192x192.png">
<?php echo SeoMeta::head($public_meta); ?>
    <?php echo CSRF::meta(); ?>
    <link rel="alternate" type="application/rss+xml" title="<?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> guides" href="/blog/feed.xml">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/landing.css'); ?>">
    <link rel="stylesheet" href="/css/public.css?v=<?php echo @filemtime(Main::app_path().'/public/css/public.css'); ?>">
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/sx.css?v=<?php echo @filemtime(Main::app_path().'/public/css/sx.css'); ?>">
<?php include __DIR__ . '/auth_head.php'; ?>
</head>
<body class="ld pub">
    <a class="ld-skip" href="#pub_main">Skip to content</a>
<?php include __DIR__ . '/public_nav.php'; ?>

<?php if (!empty($public_meta['sections'])): ?>
    <main class="sx-main" id="pub_main">
<?php require $public_view_file; ?>
<?php if (empty($public_meta['no_band'])) { echo Sections::cta(isset($cta_title) ? (string) $cta_title : 'Start selling from one page.', isset($cta_text) ? (string) $cta_text : 'Memberships, pay-per-view, services and events, with payouts to your bank.', empty($cta_no_pricing)); } ?>
    </main>
<?php else: ?>
    <main class="pub-main" id="pub_main">
        <div class="ld-wrap pub-wrap">
<?php require $public_view_file; ?>
        </div>
    </main>
<?php endif; ?>

<?php include __DIR__ . '/public_footer.php'; ?>
<?php include __DIR__ . '/auth_modal.php'; ?>
    <script defer src="/js/landing.js?v=<?php echo @filemtime(Main::app_path().'/public/js/landing.js'); ?>"></script>
</body>
</html>
