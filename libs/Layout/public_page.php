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
        <div class="ld-wrap pub-wrap<?php echo !empty($public_meta['wide']) ? ' pub-wrap--wide' : ''; ?>">
<?php require $public_view_file; ?>
<?php if (empty($public_meta['no_guides']) && !empty($public_meta['guides'])): ?>
            <aside class="pub-guides" aria-labelledby="pub_guides_h">
                <p class="pub-guides__h" id="pub_guides_h">From the <?php echo htmlspecialchars(strtolower(BlogController::NAME), ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="pub-cards pub-cards--3">
                    <?php foreach ($public_meta['guides'] as $g): ?>
                    <a class="pub-card" href="/blog/<?php echo htmlspecialchars($g['slug'], ENT_QUOTES, 'UTF-8'); ?>"><span class="pub-card__title"><?php echo htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8'); ?></span><span class="pub-card__x"><?php echo htmlspecialchars($g['excerpt'] ?: $g['meta_description'], ENT_QUOTES, 'UTF-8'); ?></span></a>
                    <?php endforeach; ?>
                </div>
            </aside>
<?php endif; ?>
        </div>
    </main>

    <footer class="ld-foot">
        <div class="ld-wrap ld-foot__inner">
            <span class="ld-brand"><span class="ld-brand__mark" aria-hidden="true"></span><span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span></span>
            <nav class="pub-foot__links" aria-label="Footer">
                <a href="/features">Features</a><a href="/pricing">Pricing</a><?php foreach (PagesController::COMPETITORS as $slug => $c): ?><a href="/compare/<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">vs <?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></a><?php endforeach; ?><a href="/best-creator-monetization-platforms">Best platforms</a><a href="/llms.txt">llms.txt</a>
            </nav>
            <span class="ld-foot__note">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </footer>
</body>
</html>
