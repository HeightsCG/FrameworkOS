<link rel="stylesheet" href="/css/feed.css?v=<?php echo @filemtime(Main::app_path().'/public/css/feed.css'); ?>">
<div class="feed">
    <header class="feed__head">
        <h1 class="feed__title">Discover</h1>
        <p class="feed__sub">The latest from creators across the studio.</p>
    </header>

    <!-- loading -->
    <div class="feed__loading" id="feed_loading">
        <span class="feed__spinner" role="status" aria-label="Loading"></span>
        <span>Loading the feed&hellip;</span>
    </div>

    <!-- error -->
    <div class="feed__state" id="feed_error" hidden>
        <i class="fa-solid fa-triangle-exclamation feed__state-icon"></i>
        <h2 class="feed__state-title">We couldn't load the feed</h2>
        <p class="feed__state-text">Something went wrong. Give it another try.</p>
        <button type="button" class="btn btn-primary feed__state-btn" id="feed_retry">Try again</button>
    </div>

    <!-- empty -->
    <div class="feed__state" id="feed_empty" hidden>
        <i class="fa-solid fa-compass feed__state-icon"></i>
        <h2 class="feed__state-title">Nothing published yet</h2>
        <p class="feed__state-text">When creators publish content, it shows up here. Check back soon &mdash; or start creating your own.</p>
    </div>

    <!-- grid (filled by feed.js) -->
    <div class="feed__grid" id="feed_grid" hidden></div>

    <!-- infinite scroll: inline loader + sentinel the observer watches -->
    <div class="feed__infload" id="feed_inf_load" hidden><span class="feed__spinner"></span> <span>Loading more&hellip;</span></div>
    <div class="feed__sentinel" id="feed_sentinel" aria-hidden="true"></div>
    <div class="feed__end" id="feed_end" hidden>You're all caught up.</div>
</div>

<!-- new-content alert -->
<button type="button" class="feed__newpill" id="feed_new_pill" hidden>
    <i class="fa-solid fa-arrow-up"></i> <span id="feed_new_pill_text">New posts</span>
</button>

<!-- full-post lightbox -->
<div class="feed-plb" id="feed_lightbox" hidden>
    <button type="button" class="feed-plb__close" id="feed_lb_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="feed-plb__inner" id="feed_lb_inner"></div>
</div>

<script src="/js/feed.js?v=<?php echo @filemtime(Main::app_path().'/public/js/feed.js'); ?>"></script>
