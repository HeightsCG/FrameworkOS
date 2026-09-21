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
    <link rel="alternate" type="application/rss+xml" title="<?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> guides" href="/blog/feed.xml">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/landing.css'); ?>">
    <link rel="stylesheet" href="/css/public.css?v=<?php echo @filemtime(Main::app_path().'/public/css/public.css'); ?>">
</head>
<body class="ld pub">
    <a class="ld-skip" href="#pub_main">Skip to content</a>
    <header class="ld-nav">
        <div class="ld-wrap ld-nav__inner">
            <a class="ld-brand" href="/">
                <span class="ld-brand__mark" aria-hidden="true"></span>
                <span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <nav class="ld-nav__links" aria-label="Site">
                <a href="/features">Features</a>
                <a href="/pricing">Pricing</a>
                <a href="/blog"><?php echo htmlspecialchars(BlogController::NAME, ENT_QUOTES, 'UTF-8'); ?></a>
            </nav>
            <div class="ld-nav__actions">
                <a class="ld-btn ld-btn--quiet" href="/?auth=login">Sign In</a>
                <a class="ld-btn ld-btn--primary" href="/?auth=register">Create <span class="ld-hide-sm">Your </span>Account</a>
            </div>
        </div>
    </header>

    <main class="pub-main" id="pub_main">
        <div class="ld-wrap pub-wrap">
<?php require $public_view_file; ?>
<?php if (empty($public_meta['no_guides']) && !empty($public_meta['guides'])): $gx = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
            <section class="gd-more" aria-labelledby="gd_more_h">
                <h2 class="gd-h2" id="gd_more_h">From the <?php echo $gx(strtolower(BlogController::NAME)); ?></h2>
                <ol class="gd-list">
                <?php foreach ($public_meta['guides'] as $g): $gi = trim((string) ($g['cover_image_url'] ?? '')); ?>
                    <li class="gd-row<?php echo $gi === '' ? ' gd-row--noimg' : ''; ?>">
                        <?php if ($gi !== ''): ?><a class="gd-row__img" href="/blog/<?php echo $gx($g['slug']); ?>" tabindex="-1" aria-hidden="true"><img src="<?php echo $gx($gi); ?>" alt="" loading="lazy" width="160" height="120"></a><?php endif; ?>
                        <div class="gd-row__body">
                            <p class="gd-row__topic"><?php echo $gx(BlogController::topic($g)); ?></p>
                            <h3 class="gd-row__title"><a href="/blog/<?php echo $gx($g['slug']); ?>"><?php echo $gx($g['title']); ?></a></h3>
                            <p class="gd-row__x"><?php echo $gx($g['excerpt'] ?: $g['meta_description']); ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ol>
            </section>
<?php endif; ?>
<?php if (empty($public_meta['no_band'])): ?>
            <section class="gd-band">
                <h2 class="gd-band__title"><?php echo htmlspecialchars(isset($cta_title) ? (string) $cta_title : 'Put it into practice on one page.', ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="gd-band__x">Memberships, pay-per-view, services and events, with payouts to your bank.</p>
                <a class="ld-btn ld-btn--onviolet" href="/?auth=register">Create Your Account</a>
            </section>
<?php endif; ?>
        </div>
    </main>

<?php include __DIR__ . '/public_footer.php'; ?>
</body>
</html>
