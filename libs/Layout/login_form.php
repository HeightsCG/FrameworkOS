<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo CSRF::meta(); ?>
    <?php
        $seo_site  = htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8');
        $seo_base  = htmlspecialchars(Main::get_base_domain(), ENT_QUOTES, 'UTF-8');
        $seo_title = $seo_site . ': Creator Monetization Platform';   // search keywords in the title; the slogan stays in the page
        $seo_desc  = $seo_site . ' brings your profiles, content, subscriptions, payouts, and revenue into one simple workspace.';
    ?>
    <title><?php echo $seo_title; ?></title>
    <meta name="description" content="<?php echo $seo_desc; ?>">
    <link rel="canonical" href="<?php echo $seo_base; ?>/">
    <link rel="sitemap" type="application/xml" href="/sitemap.xml">
    <meta name="theme-color" content="#050505">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo $seo_site; ?>">
    <meta property="og:title" content="<?php echo $seo_title; ?>">
    <meta property="og:description" content="<?php echo $seo_desc; ?>">
    <meta property="og:url" content="<?php echo $seo_base; ?>/">
    <meta property="og:image" content="<?php echo htmlspecialchars(SeoMeta::default_image(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="en_US">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $seo_title; ?>">
    <meta name="twitter:description" content="<?php echo $seo_desc; ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars(SeoMeta::default_image(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/images/android-chrome-192x192.png">
    <?php
        $seo_base_raw = Main::get_base_domain();
        $seo_ld = [
            '@context' => 'https://schema.org',
            '@graph' => [
                array('@id' => $seo_base_raw . '/#org', 'logo' => $seo_base_raw . '/images/android-chrome-512x512.png') + SeoMeta::org(),   // same Organization (and sameAs) as every other page
                [
                    '@type' => 'WebSite',
                    '@id' => $seo_base_raw . '/#website',
                    'name' => Main::site_name(),
                    'url' => $seo_base_raw . '/',
                    'publisher' => ['@id' => $seo_base_raw . '/#org'],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $seo_base_raw . '/#webpage',
                    'url' => $seo_base_raw . '/',
                    'name' => Main::site_name() . ': Create. Share. Earn.',
                    'description' => Main::site_name() . ' brings your profiles, content, subscriptions, payouts, and revenue into one simple workspace.',
                    'isPartOf' => ['@id' => $seo_base_raw . '/#website'],
                    'primaryImageOfPage' => SeoMeta::default_image(),
                ],
                [
                    '@type' => 'SoftwareApplication',
                    'name' => Main::site_name(),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web',
                    'url' => $seo_base_raw . '/',
                    'image' => SeoMeta::default_image(),
                    'description' => 'A creator platform: one public page at your handle with content, memberships, pay-per-view posts, content bundles, events, services, and tracked links. Publish, schedule, or automate posts; fans pay with credits; creators cash earnings out to their bank.',
                    'offers' => PagesController::plan_offers(),
                ],
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => [
                        ['@type' => 'Question', 'name' => 'Who can see my content?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'You choose per post: Everyone, Subscribers, or Pay-per-view at a price you set.']],
                        ['@type' => 'Question', 'name' => 'How do fans pay?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Memberships bill on your schedule; everything else uses credits.']],
                        ['@type' => 'Question', 'name' => 'How do I get paid?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Earnings collect as credits, net of your plan\'s fee. Cash out to your bank anytime.']],
                        ['@type' => 'Question', 'name' => 'Can people follow me for free?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes. Free follows, plus an optional free membership tier.']],
                        ['@type' => 'Question', 'name' => 'What do the plans cost?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => PagesController::plan_cost_answer()]],
                    ],
                ],
            ],
        ];
    ?>
    <script type="application/ld+json"><?php echo json_encode($seo_ld, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/landing.css'); ?>">
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/sx.css?v=<?php echo @filemtime(Main::app_path().'/public/css/sx.css'); ?>">
<?php include __DIR__ . '/auth_head.php'; ?>
</head>
<body class="ld">

    <div class="ld-grain" aria-hidden="true"></div>
    <a class="ld-skip" href="#ld_main">Skip to Content</a>

<?php include __DIR__ . '/public_nav.php'; ?>

    <main id="ld_main" class="sx-main">
<?php include __DIR__ . '/home_body.php'; ?>
    </main>

<?php include __DIR__ . '/public_footer.php'; ?>

<?php include __DIR__ . '/auth_modal.php'; ?>

    <script defer src="/js/landing.js?v=<?php echo @filemtime(Main::app_path().'/public/js/landing.js'); ?>"></script>
</body>
</html>
