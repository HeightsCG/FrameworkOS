<?php
/**
 * /start: the TikTok ads landing page (PagesController::startAction, images and platforms in StartPage). A standalone
 * document, mobile first: most visitors arrive on a phone inside TikTok's in-app browser.
 *
 * Signup happens in a sheet on this page (email + password, posted to /api/register); Google is offered only outside
 * in-app browsers, where it works. Tracking, in order: landing_view, cta_click, signup_started, signup_completed (GA,
 * through CLSTrack), and the TikTok pixel's ViewContent on load and CompleteRegistration once the account exists, sent
 * with the same event_id as the server's Events API copy (TikTokEventJob) so TikTok counts it once.
 * The ad's utm_* tags and ttclid go into the cls_ad cookie, which registerAction stores on the account (ad_* columns).
 * Locals: $meta, $signed_in, $trial.
 */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$site = Main::site_name();
$hero = StartPage::HERO;
$days = (int) Founding::TRIAL_DAYS;
$cta  = $trial ? 'Build yours free for ' . $days . ' days' : 'Build yours now';
// signed in: straight to the plan tab; signed out: the signup sheet on this page
$cta_href = $signed_in ? ('/account/billing?tab=plan&plan=creator' . ($trial ? '&founding=1' : '')) : '#signup';
$cta_attr = function ($where) use ($e, $signed_in) { return ' data-st-cta="' . $e($where) . '"' . ($signed_in ? '' : ' aria-haspopup="dialog" aria-controls="st_sheet"'); };
$faq = array(
    array('Is she really AI?', 'Yes. The woman on this page does not exist: every photo of her is AI-generated. Your character is made the same way, and when you post to TikTok or YouTube, ' . $site . ' turns on their AI-content label for you.'),
    array('Do I need design skills?', 'No. You describe her in plain words, like her age, hair and style, pick the face you like, and that face is locked in for every photo and video you make after.'),
    array('Which platforms?', 'LinkedIn, Bluesky, X, Facebook, Instagram, Threads, TikTok, YouTube, Pinterest and Fanvue. Connect your accounts once, then post to any of them from one place, right away or on a schedule.'),
    $trial
        ? array('What happens after ' . $days . ' days?', 'The trial is the Creator plan, free for the first ' . $days . ' days. You add a card when you start it and nothing is charged during the trial. If you keep it, the plan renews monthly from then on. Cancel before the trial ends and you pay nothing.')
        : array('How do I start?', 'Create your account, then pick the Creator plan in Billing. AI characters, photos and videos come with it.'),
    array('Can I cancel anytime?', 'Yes, from Billing in your account. Your plan stays on until the end of the period you are in, then it stops. Nothing else is charged.'),
);
$icon_close = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
$icon_plus  = '<svg class="st-faq__ic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/><path class="st-faq__v" d="M12 5v14"/></svg>';
$icon_mail  = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
?><!DOCTYPE html>
<html lang="en">
<head>
    <script>window.CLS_TAG_DELAY = 800;   /* the analytics tags load 800ms after the load event, so they never compete with the hero's first paint; calls queue meanwhile */</script>
<?php include Main::lib_path() . '/Layout/heycatch.php'; ?>
<?php include Main::lib_path() . '/Layout/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#050505">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/logo/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/logo/apple-touch-icon.png">
<?php echo SeoMeta::head($meta); ?>
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <style><?php echo @file_get_contents(Main::app_path() . '/public/css/start.css'); ?></style>
</head>
<body class="st">

<main>
    <section class="st-hero" id="st_hero">
        <div class="st-hero__media">
            <video class="st-hero__video" id="st_hero_video" poster="<?php echo $e($hero['poster']); ?>" width="<?php echo (int) $hero['w']; ?>" height="<?php echo (int) $hero['h']; ?>" autoplay muted loop playsinline webkit-playsinline disablepictureinpicture disableremoteplayback preload="none" aria-label="<?php echo $e($hero['label']); ?>" data-src-sm="<?php echo $e($hero['video_sm']); ?>" data-src="<?php echo $e($hero['video']); ?>"></video>
            <p class="st-tag">AI-generated</p>
        </div>
<?php /* wider screens: the same face in every scene drifts behind the video (lazy: phones never show it, so never load it) */
$wall = array_values(array_filter(StartPage::GALLERY, function ($g) { return $g[2] === 'img'; }));
$cols = array(array($wall[0], $wall[3], $wall[6]), array($wall[1], $wall[4], $wall[7]), array($wall[2], $wall[5]));
?>
        <div class="st-wall" aria-hidden="true">
<?php foreach ($cols as $ci => $col): ?>
            <div class="st-wall__col st-wall__col--<?php echo $ci + 1; ?>"><div class="st-wall__track">
<?php for ($rep = 0; $rep < 2; $rep++) { foreach ($col as $g) { echo '                <img src="' . $e(StartPage::img($g[0], 320)) . '" width="320" height="411" alt="" loading="lazy" decoding="async">' . "\n"; } } ?>
            </div></div>
<?php endforeach; ?>
        </div>
        <div class="st-hero__shade" aria-hidden="true"></div>
        <p class="st-brand"><img src="/images/logo/logo-mark.svg" width="24" height="24" alt=""><span><?php echo $e($site); ?></span></p>
        <div class="st-hero__copy">
            <h1 class="st-h1">She's not real. You can make your own.</h1>
            <p class="st-sub st-hero__sub">Design her face once. Make her photos and videos. Post her everywhere.</p>
            <a class="st-btn st-btn--primary" href="<?php echo $e($cta_href); ?>"<?php echo $cta_attr('hero'); ?>><?php echo $e($cta); ?></a>
            <p class="st-fine">Takes about a minute to start.</p>
        </div>
    </section>

    <section class="st-sec st-how" aria-labelledby="st_how">
        <div class="st-wrap">
            <h2 class="st-h2" id="st_how">How It Works</h2>
            <ol class="st-steps" id="st_steps">
                <li class="st-step">
                    <div class="st-step__out st-type" aria-hidden="true"><span class="st-type__label">Describe her</span><?php $chars = preg_split('//u', StartPage::PROMPT, -1, PREG_SPLIT_NO_EMPTY); ?><p class="st-type__text" style="--n:<?php echo count($chars); ?>"><?php
                        foreach ($chars as $i => $ch) { echo '<span style="--i:' . $i . '">' . $e($ch) . '</span>'; }
                    ?><i class="st-type__caret"></i></p></div>
                    <h3 class="st-step__h">1. Describe her</h3>
                    <p class="st-step__p">Write her look in plain words: age, hair, style.</p>
                </li>
                <li class="st-step">
                    <div class="st-step__out st-face"><img data-src="<?php echo $e(StartPage::FACE['src']); ?>" width="<?php echo (int) StartPage::FACE['w']; ?>" height="<?php echo (int) StartPage::FACE['h']; ?>" alt="<?php echo $e(StartPage::FACE['alt']); ?>" loading="lazy" decoding="async"><span class="st-face__chip"><?php echo Sections::icon('lock', 14); ?>Face locked</span></div>
                    <h3 class="st-step__h">2. Lock in her face</h3>
                    <p class="st-step__p">Pick the face you like. Every photo and video after uses it.</p>
                </li>
                <li class="st-step">
                    <div class="st-step__out st-logos" aria-label="The ten platforms">
<?php foreach (StartPage::PLATFORMS as $i => $slug): $logo = IntegrationPages::logo($slug, 22); $name = (string) (IntegrationPages::PAGES[$slug]['name'] ?? ucfirst($slug)); ?>
                        <span class="st-logos__i" style="--i:<?php echo $i; ?>" title="<?php echo $e($name); ?>"><?php echo $logo !== '' ? $logo . '<span class="st-sr">' . $e($name) . '</span>' : '<b>' . $e($name) . '</b>'; ?></span>
<?php endforeach; ?>
                    </div>
                    <h3 class="st-step__h">3. Post her everywhere</h3>
                    <p class="st-step__p">Schedule and post to 10 platforms from one place.</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="st-sec st-galsec" aria-labelledby="st_gal">
        <div class="st-wrap">
            <h2 class="st-h2" id="st_gal">Same face. Every shot.</h2>
        </div>
        <div class="st-gal" id="st_gal_strip">
            <ul class="st-gal__track" aria-label="Example photos and clips of Aria, one AI character">
<?php for ($rep = 0; $rep < 2; $rep++): foreach (StartPage::GALLERY as $g): $dup = $rep === 1 ? ' class="is-dup" aria-hidden="true"' : ''; ?>
                <li<?php echo $dup; ?>><?php if ($g[2] === 'clip'): ?><video class="st-clip" data-src="/landing/<?php echo $e($g[0]); ?>.mp4" data-poster="/landing/<?php echo $e($g[0]); ?>.jpg" width="<?php echo (int) StartPage::CLIP_W; ?>" height="<?php echo (int) StartPage::CLIP_H; ?>" muted loop playsinline webkit-playsinline preload="none" aria-label="<?php echo $e($g[1]); ?>"></video><?php else: ?><img data-src="<?php echo $e(StartPage::img($g[0], 320)); ?>" data-srcset="<?php echo $e(StartPage::srcset($g[0])); ?>" sizes="(min-width: 900px) 280px, 62vw" width="<?php echo (int) StartPage::IMG_W; ?>" height="<?php echo (int) StartPage::IMG_H; ?>" alt="<?php echo $rep ? '' : $e($g[1]); ?>" loading="lazy" decoding="async"><?php endif; ?></li>
<?php endforeach; endfor; ?>
            </ul>
        </div>
        <div class="st-wrap st-galsec__foot">
            <p class="st-note">One AI character, ten shots. Example images and clips.</p>
            <a class="st-btn st-btn--primary" href="<?php echo $e($cta_href); ?>"<?php echo $cta_attr('gallery'); ?>><?php echo $e($cta); ?></a>
        </div>
    </section>

    <section class="st-sec st-sec--split" aria-labelledby="st_faq">
        <div class="st-wrap">
            <h2 class="st-h2" id="st_faq">Questions</h2>
            <div class="st-faq">
<?php foreach ($faq as $f): ?>
                <details><summary><span><?php echo $e($f[0]); ?></span><?php echo $icon_plus; ?></summary><p><?php echo $e($f[1]); ?></p></details>
<?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="st-sec st-end" id="st_end" aria-labelledby="st_end_h">
        <div class="st-wrap st-end__in">
            <img class="st-end__img" data-src="<?php echo $e(StartPage::img('gallery-4')); ?>" data-srcset="<?php echo $e(StartPage::srcset('gallery-4')); ?>" sizes="(min-width: 900px) 440px, 100vw" width="<?php echo (int) StartPage::IMG_W; ?>" height="<?php echo (int) StartPage::IMG_H; ?>" alt="Aria on a rooftop at night with the city behind her" loading="lazy" decoding="async">
            <div class="st-end__copy">
                <h2 class="st-h2 st-end__h" id="st_end_h">Make Your Own Today</h2>
                <p class="st-sub st-end__p">Design her face once. Make her photos and videos. Post her everywhere.</p>
                <a class="st-btn st-btn--primary" href="<?php echo $e($cta_href); ?>"<?php echo $cta_attr('bottom'); ?>><?php echo $e($cta); ?></a>
                <p class="st-fine">Takes about a minute to start.</p>
            </div>
        </div>
    </section>
</main>

<footer class="st-foot">
    <div class="st-wrap"><a href="/terms" target="_blank" rel="noopener">Terms</a><a href="/privacy" target="_blank" rel="noopener">Privacy</a></div>
</footer>

<div class="st-sticky" id="st_sticky" aria-hidden="true">
    <a class="st-btn st-btn--primary" href="<?php echo $e($cta_href); ?>" tabindex="-1"<?php echo $cta_attr('sticky'); ?>><?php echo $e($cta); ?></a>
</div>

<?php if (!$signed_in): ?>
<div class="st-sheet" id="st_sheet" role="dialog" aria-modal="true" aria-labelledby="st_sheet_h" hidden>
    <div class="st-sheet__veil" data-st-close></div>
    <div class="st-sheet__panel">
        <button type="button" class="st-sheet__close" data-st-close aria-label="Close"><?php echo $icon_close; ?></button>

        <form class="st-form" id="st_form" novalidate>
            <h2 class="st-sheet__h" id="st_sheet_h">Create Your Account</h2>
            <label class="st-label" for="st_email">Email</label>
            <input class="st-input" id="st_email" name="user_email" type="email" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false" placeholder="you@example.com" required>
            <label class="st-label" for="st_pw">Password</label>
            <div class="st-pw">
                <input class="st-input" id="st_pw" name="p_word" type="password" autocomplete="new-password" placeholder="At least 8 characters" required>
                <button type="button" class="st-pw__show" id="st_pw_show" aria-controls="st_pw" aria-pressed="false">Show</button>
            </div>
            <div class="st-hp" aria-hidden="true"><input type="text" name="company" id="st_company" tabindex="-1" autocomplete="off"></div>
            <p class="st-error" id="st_error" role="alert" hidden></p>
            <button class="st-btn st-btn--primary st-submit" id="st_submit" type="submit">Create Account</button>
            <p class="st-legal">By creating an account you agree to the <a href="/terms" target="_blank" rel="noopener">Terms</a> and <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</p>

<?php if (GoogleAuth::configured()): ?>
            <div class="st-alt" id="st_google" hidden>
                <div class="st-or"><span>or</span></div>
                <a class="st-btn st-btn--secondary st-google" href="/account/google_start" rel="nofollow" data-st-google>
                    <svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    <span>Continue With Google</span>
                </a>
            </div>
            <div class="st-alt" id="st_inapp" hidden>
                <div class="st-or"><span>or</span></div>
                <p class="st-inapp__p">Open in your browser to use Google.</p>
                <button type="button" class="st-btn st-btn--secondary" id="st_copy">Copy Link</button>
            </div>
<?php endif; ?>
            <p class="st-signin">Already have an account? <a href="/?auth=login">Sign In</a></p>
        </form>

        <div class="st-done" id="st_done" hidden tabindex="-1">
            <span class="st-done__ic"><?php echo $icon_mail; ?></span>
            <h2 class="st-sheet__h">Check Your Email</h2>
            <p class="st-done__p">We sent a link to <b id="st_done_email"></b>. Open it to verify your account<?php echo $trial ? ' and start your ' . $days . '-day trial' : ''; ?>. It works in any browser.</p>
            <p class="st-done__p st-done__warn" id="st_done_warn" hidden></p>
            <button type="button" class="st-btn st-btn--secondary" id="st_resend">Resend Email</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var trial = <?php echo $trial ? 'true' : 'false'; ?>, signed_in = <?php echo $signed_in ? 'true' : 'false'; ?>;
    var track = function (n, p) { if (window.CLSTrack) { window.CLSTrack(n, p || {}); } };
    var tt = function () { try { if (window.ttq) { window.ttq.track.apply(window.ttq, arguments); } } catch (e) {} };
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    var set_cookie = function (k, v, days) { document.cookie = k + '=' + encodeURIComponent(v) + '; path=/; max-age=' + (days * 86400) + '; SameSite=Lax' + secure; };

    // The ad touch: utm_* and ttclid from this URL, kept 30 days for the account (UsersModel::record_ad_touch). Last ad landing wins.
    var q = new URLSearchParams(location.search), ad = {};
    [['s', 'utm_source'], ['m', 'utm_medium'], ['c', 'utm_campaign'], ['n', 'utm_content'], ['t', 'utm_term'], ['tt', 'ttclid']].forEach(function (k) { var v = q.get(k[1]); if (v) { ad[k[0]] = v.slice(0, k[0] === 'tt' ? 500 : 150); } });
    if (Object.keys(ad).length) { ad.l = (location.pathname + location.search).slice(0, 255); ad.at = new Date().toISOString(); set_cookie('cls_ad', JSON.stringify(ad), 30); }

    track('landing_view', { page: '/start', utm_source: ad.s || '', utm_campaign: ad.c || '', utm_content: ad.n || '' });
    tt('ViewContent', { content_type: 'product', content_id: 'start', content_name: 'AI influencer landing page' });

    // In-app browsers (TikTok, Instagram, Facebook): Google blocks sign-in inside them, so it is replaced by "open in your browser".
    var ua = navigator.userAgent || '';
    var inapp = /musical_ly|Bytedance|TikTok|trill_|Instagram|FBAN|FBAV|FB_IAB|FBIOS|FB4A|FBAN\/Messenger/i.test(ua);
    var g = document.getElementById('st_google'), ia = document.getElementById('st_inapp');
    if (g && ia) { (inapp ? ia : g).hidden = false; }

    // The hero video: muted + playsinline autoplay (TikTok's and Instagram's in-app browsers allow it); play() again in case the
    // attribute was ignored, and after the tab comes back. If a phone refuses (low-power mode), the poster stays.
    // The file is attached just after the poster has painted, so the poster is the first paint and the video never delays it.
    // Phones get the 540-wide encode, wider screens the 720-wide one.
    var hv = document.getElementById('st_hero_video');
    if (hv) {
        hv.muted = true; hv.defaultMuted = true;
        var go = function () { var r = hv.play(); if (r && r.catch) { r.catch(function () {}); } };
        var attach = function () {
            hv.src = hv.getAttribute(window.matchMedia('(min-width: 900px)').matches ? 'data-src' : 'data-src-sm');
            hv.preload = 'auto'; go();
        };
        requestAnimationFrame(function () { requestAnimationFrame(attach); });
        document.addEventListener('visibilitychange', function () { if (!document.hidden && hv.src) { go(); } });
    }

    // Everything below the fold loads only when it comes within 400px of the screen (data-src / data-srcset / data-poster):
    // native lazy loading starts far earlier on a phone connection and competes with the hero.
    var late = [].slice.call(document.querySelectorAll('img[data-src], video[data-poster]'));
    var fill = function (el) {
        if (el.hasAttribute('data-poster')) { el.poster = el.getAttribute('data-poster'); el.removeAttribute('data-poster'); return; }
        if (el.hasAttribute('data-srcset')) { el.srcset = el.getAttribute('data-srcset'); el.removeAttribute('data-srcset'); }
        el.src = el.getAttribute('data-src'); el.removeAttribute('data-src');
    };
    if ('IntersectionObserver' in window) {
        var lo = new IntersectionObserver(function (en) { en.forEach(function (x) { if (x.isIntersecting) { fill(x.target); lo.unobserve(x.target); } }); }, { rootMargin: '400px' });
        late.forEach(function (el) { lo.observe(el); });
    } else { late.forEach(fill); }

    // The steps animate (typing, logos arriving) once they are on screen; the gallery clips load and play only while visible.
    var steps = document.getElementById('st_steps');
    if ('IntersectionObserver' in window) {
        if (steps) {   // each step on its own, so on a phone it plays when it reaches the screen
            var so = new IntersectionObserver(function (en) { en.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('is-in'); so.unobserve(x.target); } }); }, { threshold: .5 });
            [].forEach.call(steps.querySelectorAll('.st-step'), function (li) { so.observe(li); });
        }
        var co = new IntersectionObserver(function (en) {
            en.forEach(function (x) {
                var v = x.target;
                if (x.isIntersecting) { if (!v.src) { v.src = v.getAttribute('data-src'); } v.muted = true; var r = v.play(); if (r && r.catch) { r.catch(function () {}); } }
                else if (v.src) { v.pause(); }
            });
        }, { rootMargin: '200px' });
        [].forEach.call(document.querySelectorAll('.st-clip'), function (v) { co.observe(v); });
    } else {
        if (steps) { [].forEach.call(steps.querySelectorAll('.st-step'), function (li) { li.classList.add('is-in'); }); }
        [].forEach.call(document.querySelectorAll('.st-clip'), function (v) { v.src = v.getAttribute('data-src'); v.play(); });
    }

    // Sticky CTA on phones once the hero's button has scrolled away; hidden again over the closing CTA.
    var sticky = document.getElementById('st_sticky'), hero_out = false, end_in = false;
    var paint = function () { var on = hero_out && !end_in && !document.body.classList.contains('st-open'); sticky.classList.toggle('is-on', on); sticky.setAttribute('aria-hidden', on ? 'false' : 'true'); sticky.querySelector('a').tabIndex = on ? 0 : -1; };
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (en) { hero_out = !en[0].isIntersecting; paint(); }).observe(document.querySelector('.st-hero .st-btn'));
        new IntersectionObserver(function (en) { end_in = en[0].isIntersecting; paint(); }).observe(document.getElementById('st_end'));
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('[data-st-cta]') : null;
        if (!a) { return; }
        track('cta_click', { position: a.getAttribute('data-st-cta'), page: '/start' });
        if (!signed_in) { e.preventDefault(); open_sheet(a); }
    });

    if (signed_in) { return; }

    var sheet = document.getElementById('st_sheet'), form = document.getElementById('st_form'), done = document.getElementById('st_done');
    var email = document.getElementById('st_email'), pw = document.getElementById('st_pw'), err = document.getElementById('st_error'), btn = document.getElementById('st_submit');
    var opener = null, token = '', started = false, busy = false, done_email = '';

    // The request token: this page can be served from cache without a session, so it is fetched when the sheet opens.
    var get_token = function () {
        if (token) { return Promise.resolve(token); }
        return fetch('/csrf-token', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (o) { token = o.csrf_token || ''; return token; });
    };
    var post = function (action, data, retried) {
        return get_token().then(function (t) {
            var body = new URLSearchParams(data); body.set('csrf_token', t);
            return fetch('/api/' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: body.toString() });
        }).then(function (r) { return r.json(); }).then(function (o) {
            if (o && o.csrf_expired && !retried) { token = o.csrf_token || ''; return post(action, data, true); }
            return o;
        });
    };

    function open_sheet(from) {
        opener = from || null;
        sheet.hidden = false;
        document.body.classList.add('st-open'); paint();
        requestAnimationFrame(function () { sheet.classList.add('is-in'); });
        get_token().catch(function () {});
        setTimeout(function () { (form.hidden ? done : email).focus({ preventScroll: true }); }, 60);
    }
    function close_sheet() {
        sheet.classList.remove('is-in');
        document.body.classList.remove('st-open'); paint();
        setTimeout(function () { sheet.hidden = true; }, 200);
        if (opener && opener.focus) { opener.focus({ preventScroll: true }); }
    }
    sheet.addEventListener('click', function (e) { if (e.target.closest('[data-st-close]')) { close_sheet(); } });
    document.addEventListener('keydown', function (e) {
        if (sheet.hidden) { return; }
        if (e.key === 'Escape') { close_sheet(); return; }
        if (e.key === 'Tab') {   // keep focus inside the sheet
            var f = [].slice.call(sheet.querySelectorAll('a[href], button, input:not([tabindex="-1"]), [tabindex="0"]')).filter(function (x) { return x.offsetParent !== null; });
            if (!f.length) { return; }
            if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        }
    });

    var start = function () { if (!started) { started = true; track('signup_started', { method: 'email', page: '/start' }); } };
    email.addEventListener('focus', start); pw.addEventListener('focus', start);

    var show = document.getElementById('st_pw_show');
    show.addEventListener('click', function () { var on = pw.type === 'password'; pw.type = on ? 'text' : 'password'; show.textContent = on ? 'Hide' : 'Show'; show.setAttribute('aria-pressed', on ? 'true' : 'false'); pw.focus(); });

    var fail = function (msg, field) {
        err.textContent = msg; err.hidden = false;
        [email, pw].forEach(function (x) { x.classList.toggle('is-bad', x === field); x.setAttribute('aria-invalid', x === field ? 'true' : 'false'); });
        if (field) { field.focus(); }
    };
    var pw_problem = function (p) {
        if (p.length < 8) { return 'Use at least 8 characters for your password.'; }
        if (!/[A-Z]/.test(p)) { return 'Add an uppercase letter to your password.'; }
        if (!/[a-z]/.test(p)) { return 'Add a lowercase letter to your password.'; }
        if (!/[0-9]/.test(p)) { return 'Add a number to your password.'; }
        if (!/[^A-Za-z0-9]/.test(p)) { return 'Add a symbol, like ! or #, to your password.'; }
        return '';
    };

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) { return; }
        start();
        var em = email.value.trim(), p = pw.value;
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { fail('Enter a valid email address.', email); return; }
        var pp = pw_problem(p); if (pp) { fail(pp, pw); return; }
        err.hidden = true; [email, pw].forEach(function (x) { x.classList.remove('is-bad'); x.setAttribute('aria-invalid', 'false'); });
        if (trial) { set_cookie('cls_founding', '1', 30); }   // the trial offer, read on the billing plan tab in this browser
        busy = true; btn.disabled = true; btn.classList.add('is-busy'); btn.textContent = 'Creating Account…';
        post('register', {
            user_email: em, p_word: p, p_word_confirm: p, company: document.getElementById('st_company').value,
            plan: 'creator', role: 'creator', ref: '', founding: trial ? '1' : '', 'return': '/setup?plan=creator'
        }).then(function (o) {
            busy = false; btn.disabled = false; btn.classList.remove('is-busy'); btn.textContent = 'Create Account';
            if (!o || !o.success) { fail((o && o.message) || 'Something went wrong. Try again.', /email/i.test((o && o.message) || '') ? email : null); return; }
            track('signup_completed', { method: 'email', page: '/start' });
            track('sign_up', { method: 'email' });   // GA's standard sign-up event, as on every other signup
            if (window.CLSTikTokRegistration) { window.CLSTikTokRegistration(o.tt_registration); }   // TikTok identify + CompleteRegistration, once, with the server's event id
            done_email = em;
            document.getElementById('st_done_email').textContent = em;
            var warn = document.getElementById('st_done_warn');
            if (o.email_failed) { warn.textContent = 'The email did not go out. Use Resend Email to try again.'; warn.hidden = false; }
            form.hidden = true; done.hidden = false; done.focus({ preventScroll: true });
        }).catch(function () {
            busy = false; btn.disabled = false; btn.classList.remove('is-busy'); btn.textContent = 'Create Account';
            fail('Could not reach the server. Check your connection and try again.', null);
        });
    });

    var resend = document.getElementById('st_resend');
    resend.addEventListener('click', function () {
        resend.disabled = true;
        post('resend_verification', { u_name: done_email }).then(function () { resend.textContent = 'Email Sent'; setTimeout(function () { resend.textContent = 'Resend Email'; resend.disabled = false; }, 30000); })
            .catch(function () { resend.disabled = false; });
    });

    // Google, outside in-app browsers: the creator role and the trial ride along in the cookies the Google sign-up reads.
    var gl = document.querySelector('[data-st-google]');
    if (gl) { gl.addEventListener('click', function () { track('signup_started', { method: 'google', page: '/start' }); set_cookie('cls_signup', JSON.stringify({ plan: 'creator', role: 'creator' }), 30); if (trial) { set_cookie('cls_founding', '1', 30); } }); }

    var copy = document.getElementById('st_copy');
    if (copy) {
        copy.addEventListener('click', function () {
            var url = location.href, ok = function () { copy.textContent = 'Link Copied'; setTimeout(function () { copy.textContent = 'Copy Link'; }, 2000); };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(url).then(ok, legacy); } else { legacy(); }
            function legacy() { var t = document.createElement('textarea'); t.value = url; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); ok(); } catch (x) {} document.body.removeChild(t); }
        });
    }
})();
</script>
</body>
</html>
