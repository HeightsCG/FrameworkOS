<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/heycatch.php'; ?>
<?php include __DIR__ . '/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo CSRF::meta(); ?>
    <?php
        $seo_site  = htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8');
        $seo_base  = htmlspecialchars(Main::get_base_domain(), ENT_QUOTES, 'UTF-8');
        $seo_title = $seo_site . ': Creator Monetization Platform';   // search keywords in the title; the slogan stays in the page
        $seo_desc  = htmlspecialchars(SeoMeta::brand_tagline(), ENT_QUOTES, 'UTF-8');
    ?>
    <title><?php echo $seo_title; ?></title>
    <meta name="description" content="<?php echo $seo_desc; ?>">
    <link rel="canonical" href="<?php echo $seo_base; ?>/">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="alternate" type="text/markdown" href="<?php echo $seo_base; ?>/index.md">
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
<?php if (SeoMeta::x_handle() !== ''): ?>    <meta name="twitter:site" content="<?php echo htmlspecialchars(SeoMeta::x_handle(), ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>    <meta name="twitter:title" content="<?php echo $seo_title; ?>">
    <meta name="twitter:description" content="<?php echo $seo_desc; ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars(SeoMeta::default_image(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/logo/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/logo/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/logo/apple-touch-icon.png">
    <?php
        $seo_base_raw = Main::get_base_domain();
        $seo_software = PagesController::software_app('/', Main::site_name(), SeoMeta::brand_description());   // @id /#software, offers from the plans, publisher by reference
        $seo_ld = [
            '@context' => 'https://schema.org',
            '@graph' => [
                SeoMeta::org(),       // the one Organization node (@id /#org); every other page points at it
                SeoMeta::website(),   // the one WebSite node (@id /#website)
                [
                    '@type' => 'WebPage',
                    '@id' => $seo_base_raw . '/#webpage',
                    'url' => $seo_base_raw . '/',
                    'name' => Main::site_name() . ': Creator Monetization Platform',   // names the buyer, same as the page title (HeyCatch D1.1)
                    'description' => SeoMeta::brand_tagline(),
                    'inLanguage' => 'en-US',
                    'isPartOf' => SeoMeta::website_ref(),
                    'publisher' => SeoMeta::org_ref(),
                    'about' => ['@id' => $seo_software['@id']],
                    'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => strtok(SeoMeta::default_image(), '?')],
                ],
                $seo_software,
                SeoMeta::faq(PagesController::home_faq()),   // the same questions the page shows
            ],
        ];
        foreach ($seo_ld['@graph'] as $gi => $gn) { unset($seo_ld['@graph'][$gi]['@context']); }
    ?>
    <script type="application/ld+json"><?php echo json_encode($seo_ld, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/landing.css'); ?>">
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/sx.css?v=<?php echo @filemtime(Main::app_path().'/public/css/sx.css'); ?>">
    <link rel="stylesheet" href="/css/home.css?v=<?php echo @filemtime(Main::app_path().'/public/css/home.css'); ?>">
    <link rel="preload" as="image" type="image/webp" imagesrcset="/images/home/hero-640.webp 640w, /images/home/hero-960.webp 960w, /images/home/hero-1152.webp 1152w" imagesizes="(max-width: 760px) 100vw, 55vw" fetchpriority="high">
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
