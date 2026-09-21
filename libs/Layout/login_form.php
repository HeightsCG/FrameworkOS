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
        $seo_title = $seo_site . ': Create. Share. Earn.';
        $seo_desc  = $seo_site . ' brings your profiles, content, subscriptions, payouts, and revenue into one simple workspace.';
    ?>
    <title><?php echo $seo_title; ?></title>
    <meta name="description" content="<?php echo $seo_desc; ?>">
    <link rel="canonical" href="<?php echo $seo_base; ?>/">
    <link rel="sitemap" type="application/xml" href="/sitemap.xml">
    <meta name="theme-color" content="#eef0f4">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo $seo_site; ?>">
    <meta property="og:title" content="<?php echo $seo_title; ?>">
    <meta property="og:description" content="<?php echo $seo_desc; ?>">
    <meta property="og:url" content="<?php echo $seo_base; ?>/">
    <meta property="og:image" content="<?php echo $seo_base; ?>/images/og-image.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="en_US">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $seo_title; ?>">
    <meta name="twitter:description" content="<?php echo $seo_desc; ?>">
    <meta name="twitter:image" content="<?php echo $seo_base; ?>/images/og-image.png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/images/android-chrome-192x192.png">
    <?php
        $seo_base_raw = Main::get_base_domain();
        $seo_ld = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $seo_base_raw . '/#org',
                    'name' => Main::site_name(),
                    'url' => $seo_base_raw . '/',
                    'logo' => $seo_base_raw . '/images/android-chrome-512x512.png',
                ],
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
                    'primaryImageOfPage' => $seo_base_raw . '/images/og-image.png',
                ],
                [
                    '@type' => 'SoftwareApplication',
                    'name' => Main::site_name(),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web',
                    'url' => $seo_base_raw . '/',
                    'description' => 'A creator platform: one public page at your handle with content, memberships, pay-per-view posts, content bundles, events, services, and tracked links. Publish, schedule, or automate posts; fans pay with credits; creators cash earnings out to their bank.',
                ],
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => [
                        ['@type' => 'Question', 'name' => 'Who can see my content?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'You choose per post: Everyone, Subscribers, or Pay-per-view at a price you set.']],
                        ['@type' => 'Question', 'name' => 'How do fans pay?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Memberships bill on your schedule; everything else uses credits.']],
                        ['@type' => 'Question', 'name' => 'How do I get paid?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Earnings collect as credits, net of your plan\'s fee. Cash out to your bank anytime.']],
                        ['@type' => 'Question', 'name' => 'Can people follow me for free?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes. Free follows, plus an optional free membership tier.']],
                        ['@type' => 'Question', 'name' => 'What do the plans cost?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Pricing is shown at checkout; the platform fee drops as you move up.']],
                    ],
                ],
            ],
        ];
    ?>
    <script type="application/ld+json"><?php echo json_encode($seo_ld, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/landing.css'); ?>">
    <!-- Bootstrap + toastr are only needed by the auth dialog: load them without blocking first paint. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css"></noscript>
    <script defer src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script defer src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path().'/public/js/api.data.js'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
    $(document).ready(function() {

        $('#login_form').show();
        $('#forgot_form').hide();
        $('#register_form').hide();

        function showRegister() {
            $('#login_form').hide();
            $('#forgot_form').hide();
            $('#register_form').show();
        }

        $('#do_login').on('click', function() {

            if ($("#u_name").val() === '') {
                toastr.error('Username or email is required');
                return;
            }

            if ($("#p_word").val() === '') {
                toastr.error('Password is required');
                return;
            }

            ApiDataSvc.apiCall('post', 'login', {
                u_name: $('#u_name').val(),
                p_word: $('#p_word').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success && obj.mfa_required) {
                    startMfa(obj.methods || {});
                } else if (obj.success) {
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : '/';
                } else if (obj.unverified) {
                    show_unverified($('#u_name').val());
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        // Unconfirmed email: surface a resend option using whatever identifier they signed in with.
        var resend_identifier = '';
        function show_unverified(identifier) {
            resend_identifier = identifier;
            toastr.warning('Please verify your email before signing in.');
            $('#resend_verify_wrap').show();
        }
        $('#do_resend_verify').on('click', function() {
            ApiDataSvc.apiCall('post', 'resend_verification', { u_name: resend_identifier }, function(data) {
                var obj = null;
                try { obj = JSON.parse(data); } catch (e) { obj = null; }
                if (obj && obj.success) { toastr.success(obj.message); }
                else { toastr.error(obj ? obj.message : 'Something went wrong.'); }
            });
        });

        var mfaMethods = { totp: false, email: false };

        function startMfa(methods) {
            mfaMethods = methods;
            $('#login_form').hide();
            $('#forgot_form').hide();
            $('#register_form').hide();
            $('#mfa_form').show();
            setMfaMethod(mfaMethods.totp ? 'totp' : 'email');
        }

        function setMfaMethod(method) {
            $('#mfa_method').val(method);
            $('#mfa_code').val('').focus();

            if (method === 'totp') {
                $('#mfa_help').text('Enter the 6-digit code from your authenticator app.');
            } else if (method === 'email') {
                $('#mfa_help').text('We emailed you a verification code. Enter it below.');
            } else {
                $('#mfa_help').text('Enter one of your saved backup codes.');
            }

            // Alternate-method links, contextual to the current method.
            $('#mfa_use_email').toggle(method !== 'email' && !!mfaMethods.email);
            $('#mfa_resend_email').toggle(method === 'email');
            $('#mfa_use_totp').toggle(method !== 'totp' && !!mfaMethods.totp);
            $('#mfa_use_backup').toggle(method !== 'backup');
        }

        $('#do_mfa_verify').on('click', function() {
            var code = ($('#mfa_code').val() || '').trim();
            if (code === '') { toastr.error('Enter your verification code'); return; }
            ApiDataSvc.apiCall('post', 'mfa_verify', {
                method: $('#mfa_method').val(),
                code:   code
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : '/';
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $('#mfa_use_email').on('click', function() {
            ApiDataSvc.apiCall('post', 'mfa_send_login_code', {}, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) { toastr.success(obj.message); setMfaMethod('email'); }
                else { toastr.error(obj.message); }
            });
        });

        $('#mfa_resend_email').on('click', function() {
            ApiDataSvc.apiCall('post', 'mfa_send_login_code', {}, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) { toastr.success(obj.message); } else { toastr.error(obj.message); }
            });
        });

        $('#mfa_use_totp').on('click', function() { setMfaMethod('totp'); });
        $('#mfa_use_backup').on('click', function() { setMfaMethod('backup'); });

        $(document).on('keydown', '#mfa_code', function(e) {
            if (e.keyCode === 13) { $('#do_mfa_verify').trigger('click'); }
        });

        $('#forgot_password').on('click', function() {
            $('#login_form').hide();
            $('#register_form').hide();
            $('#forgot_form').show();
        });

        $('#register').on('click', function(e) { e.preventDefault(); showRegister(); });

        $('.show_login').on('click', function() {
            $('#forgot_form').hide();
            $('#register_form').hide();
            $('#login_form').show();
        });

        $('#do_forgot').on('click', function() {

            if ($("#forgot_u_name").val() === '') {
                toastr.error('Username or email is required');
                return;
            }

            ApiDataSvc.apiCall('post', 'forgot', {
                u_name: $('#forgot_u_name').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    toastr.success(obj.message);
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $('#do_register').on('click', function() {

            if ($("#register_first_name").val() === '') {
                toastr.error('First name is required');
                return;
            }

            if ($("#register_last_name").val() === '') {
                toastr.error('Last name is required');
                return;
            }

            if ($("#register_user_email").val() === '') {
                toastr.error('Email is required');
                return;
            }

            if ($("#register_p_word").val() === '') {
                toastr.error('Password is required');
                return;
            }

            if ($("#register_p_word").val() !== $("#register_p_word_confirm").val()) {
                toastr.error('Passwords do not match');
                return;
            }

            if ($("#register_p_word").val().length < 8) {
                toastr.error('Password must be at least 8 characters');
                return;
            }

            if (!/[A-Z]/.test($("#register_p_word").val())) {
                toastr.error('Password must include an uppercase letter');
                return;
            }

            if (!/[a-z]/.test($("#register_p_word").val())) {
                toastr.error('Password must include a lowercase letter');
                return;
            }

            if (!/[0-9]/.test($("#register_p_word").val())) {
                toastr.error('Password must include a number');
                return;
            }

            if (!/[^A-Za-z0-9]/.test($("#register_p_word").val())) {
                toastr.error('Password must include a symbol');
                return;
            }

            ApiDataSvc.apiCall('post', 'register', {
                first_name: $('#register_first_name').val(),
                last_name:  $('#register_last_name').val(),
                user_email: $('#register_user_email').val(),
                p_word:     $('#register_p_word').val(),
                p_word_confirm: $('#register_p_word_confirm').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    // Account created but not yet usable: send them to sign in with a heads-up to verify.
                    $('#register_form').hide();
                    $('#forgot_form').hide();
                    $('#login_form').show();
                    toastr.success(obj.message);
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $(document).on('keydown', '#u_name, #p_word', function(e) {
            if (e.keyCode === 13) {
                $("#do_login").trigger('click');
            }
        });

        $(document).on('keydown', '#forgot_u_name', function(e) {
            if (e.keyCode === 13) {
                $("#do_forgot").trigger('click');
            }
        });

        $(document).on('keydown', '#register_first_name, #register_last_name, #register_user_email, #register_p_word', function(e) {
            if (e.keyCode === 13) {
                $("#do_register").trigger('click');
            }
        });

    });
    });
    </script>
</head>
<body class="ld">

    <div class="ld-grain" aria-hidden="true"></div>
    <a class="ld-skip" href="#ld_main">Skip to Content</a>

    <header class="ld-nav">
        <div class="ld-wrap ld-nav__inner">
            <a class="ld-brand" href="/">
                <span class="ld-brand__mark" aria-hidden="true"></span>
                <span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <nav class="ld-nav__links" aria-label="Site">
                <a href="/features">Features</a>
                <a href="/pricing">Pricing</a>
                <a href="/blog">Guides</a>
            </nav>
            <div class="ld-nav__actions">
                <button type="button" class="ld-btn ld-btn--quiet" data-auth="login">Sign In</button>
                <button type="button" class="ld-btn ld-btn--primary" data-auth="register">Create <span class="ld-hide-sm">Your </span>Account</button>
            </div>
        </div>
    </header>

    <main id="ld_main">

        <!-- ============ Kinetic opening ============ -->
        <section class="ld-kx" aria-label="Introduction">
            <div class="ld-kx__atmo" aria-hidden="true"></div>
            <span class="ld-kx__refract" aria-hidden="true"></span>
            <span class="ld-kx__refract ld-kx__refract--b" aria-hidden="true"></span>

            <div class="ld-wrap ld-kx__stage">
                <h1 class="ld-kx__type" aria-label="Create. Share. Earn.">
                    <span class="ld-kx__line ld-kx__line--create" aria-hidden="true"><span class="ld-kx__frag"><span class="ld-mag">C</span></span><span class="ld-kx__frag"><span class="ld-mag">R</span></span><span class="ld-kx__frag"><span class="ld-mag">E</span></span><span class="ld-kx__frag"><span class="ld-mag">A</span></span><span class="ld-kx__frag"><span class="ld-mag">T</span></span><span class="ld-kx__frag"><span class="ld-mag">E</span></span><span class="ld-kx__frag"><span class="ld-mag">.</span></span></span>
                    <span class="ld-kx__line ld-kx__line--share" aria-hidden="true">SHARE.</span>
                    <span class="ld-kx__earnrow" aria-hidden="true">
                        <span class="ld-kx__beam"></span>
                        <span class="ld-kx__bloom"></span>
                        <span class="ld-kx__line ld-kx__line--earn">EARN.</span>
                    </span>
                </h1>
                <p class="ld-kx__tag">Keep It All Connected.</p>
                <div class="ld-kx__foot">
                    <p class="ld-kx__value"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> is a creator platform for monetizing your content: memberships, pay-per-view posts, and tracked links on one public page, with payouts to your bank.</p>
                </div>
            </div>
        </section>

        <!-- ============ Presence ============ -->
        <section class="ld-act ld-act--presence" aria-labelledby="ld_h_presence">
            <span class="ld-ghost" aria-hidden="true">@</span>
            <svg class="ld-net" viewBox="0 0 560 440" aria-hidden="true">
                <g stroke="rgba(91,75,224,.3)" stroke-width="1">
                    <line x1="70" y1="70" x2="290" y2="230"/>
                    <line x1="480" y1="50" x2="290" y2="230"/>
                    <line x1="520" y1="240" x2="290" y2="230"/>
                    <line x1="440" y1="400" x2="290" y2="230"/>
                    <line x1="120" y1="380" x2="290" y2="230"/>
                    <line x1="30" y1="230" x2="290" y2="230"/>
                    <line x1="70" y1="70" x2="30" y2="230"/>
                    <line x1="480" y1="50" x2="520" y2="240"/>
                    <line x1="120" y1="380" x2="440" y2="400"/>
                </g>
                <g fill="#8273f8">
                    <circle cx="70" cy="70" r="6"/>
                    <circle cx="480" cy="50" r="4.5"/>
                    <circle cx="520" cy="240" r="6"/>
                    <circle cx="440" cy="400" r="4.5"/>
                    <circle cx="120" cy="380" r="5"/>
                    <circle cx="30" cy="230" r="4"/>
                </g>
                <circle cx="290" cy="230" r="18" fill="rgba(91,75,224,.18)"/>
                <circle cx="290" cy="230" r="9" fill="#5b4be0"/>
            </svg>
            <div class="ld-wrap">
                <div class="ld-act__body ld-reveal">
                    <span class="ld-eyebrow">Presence</span>
                    <h2 class="ld-h" id="ld_h_presence">Your page lives at <em>your handle.</em></h2>
                    <p class="ld-lede">One page carries everything you are. Every click tracked.</p>
                    <p class="ld-runline" aria-label="Content, Membership, Services, Events, About, Links">
                        <span>Content</span><span class="ld-dot">&middot;</span><span>Membership</span><span class="ld-dot">&middot;</span><span>Services</span><span class="ld-dot">&middot;</span><span>Events</span><span class="ld-dot">&middot;</span><span>About</span><span class="ld-dot">&middot;</span><span>Links</span>
                    </p>
                </div>
            </div>
        </section>

        <!-- ============ Publish ============ -->
        <section class="ld-act ld-act--alt" aria-labelledby="ld_h_publish">
            <div class="ld-wrap">
                <div class="ld-reveal">
                    <span class="ld-eyebrow">Publish</span>
                    <h2 class="ld-h" id="ld_h_publish">Upload once, <em>use everywhere.</em></h2>
                    <p class="ld-lede">Draft it, schedule it, publish now, or let an automation post for you.</p>
                </div>
                <div class="ld-ladder ld-reveal ld-reveal--late">
                    <div class="ld-ladder__step">
                        <span class="ld-ladder__orb ld-ladder__orb--draft" aria-hidden="true"></span>
                        <span class="ld-ladder__word">Draft</span>
                        <span class="ld-ladder__note">Work in progress, visible only to you.</span>
                        <small class="ld-ladder__sub ld-lede" style="margin:0;font-size:.9rem;">Work in progress, visible only to you.</small>
                    </div>
                    <div class="ld-ladder__step">
                        <span class="ld-ladder__orb ld-ladder__orb--sched" aria-hidden="true"></span>
                        <span class="ld-ladder__word">Scheduled</span>
                        <span class="ld-ladder__note">Queued for the moment you choose.</span>
                        <small class="ld-ladder__sub ld-lede" style="margin:0;font-size:.9rem;">Queued for the moment you choose.</small>
                    </div>
                    <div class="ld-ladder__step ld-ladder__step--live">
                        <span class="ld-ladder__orb ld-ladder__orb--live" aria-hidden="true"></span>
                        <span class="ld-ladder__word">Published</span>
                        <span class="ld-ladder__note">Live on your page, on your schedule or automatically.</span>
                        <small class="ld-ladder__sub ld-lede" style="margin:0;font-size:.9rem;">Live on your page, on your schedule or automatically.</small>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ Access ============ -->
        <section class="ld-act" aria-labelledby="ld_h_access">
            <div class="ld-wrap">
                <div class="ld-reveal">
                    <span class="ld-eyebrow">Access</span>
                    <h2 class="ld-h" id="ld_h_access">You decide <em>who sees every post.</em></h2>
                </div>
                <div class="ld-access ld-reveal ld-reveal--late">
                    <div class="ld-access__row">
                        <span class="ld-access__ap ld-access__ap--open" aria-hidden="true"></span>
                        <span class="ld-access__word">Everyone</span>
                        <span class="ld-access__note">Anyone can see it.</span>
                        <small class="ld-access__sub ld-lede" style="margin:0;font-size:.9rem;">Anyone can see it.</small>
                    </div>
                    <div class="ld-access__row">
                        <span class="ld-access__ap ld-access__ap--member" aria-hidden="true"></span>
                        <span class="ld-access__word">Subscribers</span>
                        <span class="ld-access__note">Members only.</span>
                        <small class="ld-access__sub ld-lede" style="margin:0;font-size:.9rem;">Members only.</small>
                    </div>
                    <div class="ld-access__row ld-access__row--ppv">
                        <span class="ld-access__ap ld-access__ap--lock" aria-hidden="true"></span>
                        <span class="ld-access__word">Pay-per-view</span>
                        <span class="ld-access__note">Unlock to view. You set the price.</span>
                        <small class="ld-access__sub ld-lede" style="margin:0;font-size:.9rem;">Unlock to view. You set the price.</small>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ Earn (violet field) ============ -->
        <section class="ld-band" aria-labelledby="ld_h_earn">
            <div class="ld-band__art" aria-hidden="true">
                <span class="ld-coin ld-coin--a"></span>
                <span class="ld-coin ld-coin--b"></span>
                <span class="ld-coin ld-coin--c"></span>
                <span class="ld-coin ld-coin--d"></span>
                <svg class="ld-band__wave" viewBox="0 0 1200 180" preserveAspectRatio="none">
                    <path d="M0,110 C150,40 300,160 450,100 S750,30 900,100 S1120,150 1200,90" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                    <path d="M0,140 C180,80 330,180 480,130 S780,60 930,130 S1140,170 1200,120" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="1.5"/>
                    <path d="M0,180 L0,120 C180,70 360,170 540,120 S900,60 1200,110 L1200,180 Z" fill="rgba(255,255,255,.07)"/>
                </svg>
            </div>
            <div class="ld-wrap ld-band__inner">
                <div class="ld-reveal">
                    <span class="ld-eyebrow">Earn</span>
                    <h2 class="ld-h" id="ld_h_earn">Made to be paid.</h2>
                    <p class="ld-lede">Every way you earn, on the page fans already visit.</p>
                </div>
                <div class="ld-band__facts ld-reveal ld-reveal--late">
                    <div class="ld-fact">
                        <span class="ld-fact__n">Memberships</span>
                        <span class="ld-fact__t">Free or paid tiers, trials, discount codes.</span>
                    </div>
                    <div class="ld-fact">
                        <span class="ld-fact__n">Unlocks</span>
                        <span class="ld-fact__t">Price a post, or bundle content at one price.</span>
                    </div>
                    <div class="ld-fact">
                        <span class="ld-fact__n">Payouts</span>
                        <span class="ld-fact__t">Fans pay with credits. You cash out to your bank.</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ Plans ============ -->
        <section class="ld-act ld-act--alt" id="pricing" aria-labelledby="ld_h_plans">
            <div class="ld-wrap">
                <div class="ld-reveal">
                    <span class="ld-eyebrow">Plans</span>
                    <h2 class="ld-h" id="ld_h_plans">The fee falls <em>as you grow.</em></h2>
                    <p class="ld-lede">Everything included on every plan. What changes is the room you get and the cut you keep.</p>
                </div>
                <div class="ld-tiers ld-reveal ld-reveal--late">
                    <article class="ld-tier">
                        <h3 class="ld-tier__name">Creator</h3>
                        <p class="ld-tier__tag">Go solo, get paid.</p>
                        <p class="ld-tier__fee ld-tier__fee--ghost">10<span>%</span></p>
                        <span class="ld-tier__feelabel">platform fee</span>
                        <div class="ld-tier__keep">
                            <span class="ld-tier__keep-label">You keep 90%</span>
                            <span class="ld-tier__keep-bar"><span class="ld-tier__keep-fill" style="--keep:90%;"></span></span>
                        </div>
                        <ul class="ld-tier__list">
                            <li><strong>1</strong> seat, just you</li>
                            <li><strong>1</strong> creator profile</li>
                            <li><strong>3</strong> connected socials</li>
                            <li><strong>50&nbsp;GB</strong> storage</li>
                        </ul>
                    </article>
                    <article class="ld-tier ld-tier--reco">
                        <h3 class="ld-tier__name">Pro<span class="ld-tier__flag">Recommended</span></h3>
                        <p class="ld-tier__tag">Scale your solo brand.</p>
                        <p class="ld-tier__fee ld-tier__fee--violet">5<span>%</span></p>
                        <span class="ld-tier__feelabel">platform fee</span>
                        <div class="ld-tier__keep">
                            <span class="ld-tier__keep-label">You keep 95%</span>
                            <span class="ld-tier__keep-bar"><span class="ld-tier__keep-fill" style="--keep:95%;"></span></span>
                        </div>
                        <ul class="ld-tier__list">
                            <li><strong>3</strong> seats + collaborator roles</li>
                            <li><strong>10</strong> connected socials</li>
                            <li>Tiers, bundles, trials &amp; promo codes</li>
                            <li>AI tools &amp; brand kit</li>
                            <li><strong>500&nbsp;GB</strong> storage</li>
                        </ul>
                    </article>
                    <article class="ld-tier">
                        <h3 class="ld-tier__name">Studio</h3>
                        <p class="ld-tier__tag">Run a team or agency.</p>
                        <p class="ld-tier__fee">2<span>%</span></p>
                        <span class="ld-tier__feelabel">platform fee</span>
                        <div class="ld-tier__keep">
                            <span class="ld-tier__keep-label">You keep 98%</span>
                            <span class="ld-tier__keep-bar"><span class="ld-tier__keep-fill" style="--keep:98%;"></span></span>
                        </div>
                        <ul class="ld-tier__list">
                            <li><strong>10</strong> seats with roles</li>
                            <li>Up to <strong>10</strong> creator profiles</li>
                            <li><strong>50+</strong> connected socials</li>
                            <li>Approval workflows</li>
                            <li>Agency analytics + exports</li>
                            <li><strong>2&nbsp;TB</strong> storage</li>
                        </ul>
                    </article>
                </div>
                <p class="ld-tiers__note ld-reveal ld-reveal--later">Plan pricing is shown at checkout from your account&rsquo;s billing page.</p>
            </div>
        </section>

        <!-- ============ Trust ============ -->
        <section class="ld-act ld-act--trust" aria-labelledby="ld_h_trust">
            <div class="ld-wrap">
                <div class="ld-reveal">
                    <span class="ld-eyebrow">Trust</span>
                    <h2 class="ld-h" id="ld_h_trust">Verified &amp; Secure, <em>by default.</em></h2>
                </div>
                <div class="ld-trust">
                    <div class="ld-trust__item ld-reveal">
                        <span class="ld-trust__sig ld-trust__sig--seal" aria-hidden="true"></span>
                        <span class="ld-trust__k">Email Verification</span>
                        <span class="ld-trust__t">Verified before first sign-in.</span>
                    </div>
                    <div class="ld-trust__item ld-reveal ld-reveal--late">
                        <span class="ld-trust__sig ld-trust__sig--dual" aria-hidden="true"></span>
                        <span class="ld-trust__k">Two-Factor Authentication</span>
                        <span class="ld-trust__t">Authenticator, email &amp; backup codes.</span>
                    </div>
                    <div class="ld-trust__item ld-reveal ld-reveal--later">
                        <span class="ld-trust__sig ld-trust__sig--badge" aria-hidden="true"></span>
                        <span class="ld-trust__k">Verified Creators</span>
                        <span class="ld-trust__t">A badge that shows it&rsquo;s really you.</span>
                    </div>
                    <div class="ld-trust__item ld-reveal ld-reveal--last">
                        <span class="ld-trust__sig ld-trust__sig--watch" aria-hidden="true"></span>
                        <span class="ld-trust__k">Reporting &amp; Moderation</span>
                        <span class="ld-trust__t">Anything can be reported &amp; reviewed.</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ FAQ ============ -->
        <section class="ld-act ld-act--faq" aria-labelledby="ld_h_faq">
            <span class="ld-ghost ld-ghost--q" aria-hidden="true">?</span>
            <div class="ld-wrap">
                <div class="ld-faqgrid">
                    <div class="ld-reveal">
                        <span class="ld-eyebrow">FAQ</span>
                        <h2 class="ld-h" id="ld_h_faq">Good<br>Questions.</h2>
                    </div>
                    <div class="ld-faq ld-reveal ld-reveal--late">
                        <details>
                            <summary><span class="ld-faq__n" aria-hidden="true">01</span><span class="ld-faq__q">Who can see my content?</span></summary>
                            <p class="ld-faq__a">You choose per post: Everyone, Subscribers, or Pay-per-view at a price you set.</p>
                        </details>
                        <details>
                            <summary><span class="ld-faq__n" aria-hidden="true">02</span><span class="ld-faq__q">How do fans pay?</span></summary>
                            <p class="ld-faq__a">Memberships bill on your schedule; everything else uses credits.</p>
                        </details>
                        <details>
                            <summary><span class="ld-faq__n" aria-hidden="true">03</span><span class="ld-faq__q">How do I get paid?</span></summary>
                            <p class="ld-faq__a">Earnings collect as credits, net of your plan&rsquo;s fee. Cash out to your bank anytime.</p>
                        </details>
                        <details>
                            <summary><span class="ld-faq__n" aria-hidden="true">04</span><span class="ld-faq__q">Can people follow me for free?</span></summary>
                            <p class="ld-faq__a">Yes. Free follows, plus an optional free membership tier.</p>
                        </details>
                        <details>
                            <summary><span class="ld-faq__n" aria-hidden="true">05</span><span class="ld-faq__q">What do the plans cost?</span></summary>
                            <p class="ld-faq__a">Pricing is shown at checkout; the platform fee drops as you move up.</p>
                        </details>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ Final conversion ============ -->
        <section class="ld-fin" aria-labelledby="ld_h_fin">
            <div class="ld-fin__atmo" aria-hidden="true"></div>
            <span class="ld-fin__rays" aria-hidden="true"></span>
            <div class="ld-wrap ld-fin__inner ld-reveal">
                <h2 class="ld-fin__words" id="ld_h_fin">Create. Share. <em>Earn.</em></h2>
                <p class="ld-fin__tag">Keep It All Connected.</p>
            </div>
        </section>

    </main>

    <footer class="ld-foot">
        <div class="ld-wrap ld-foot__inner">
            <span class="ld-brand">
                <span class="ld-brand__mark" aria-hidden="true"></span>
                <span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
            <span class="ld-foot__note">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </footer>

    <div class="ld-auth" id="ld_auth" role="dialog" aria-modal="true" aria-label="Sign in or create account" hidden>
        <div class="ld-auth__veil"></div>
        <div class="ld-auth__dialog">
            <button type="button" class="ld-auth__close" aria-label="Close">&#10005;</button>
            <span class="ld-brand__mark ld-auth__mark" aria-hidden="true"></span>

            <div id="login_form">
                <h2 class="cos-form-title">Sign In</h2>
                <p class="cos-help">Enter your username or email and password to sign in.</p>
                <div class="form-floating mb-3">
                    <input type="text" id="u_name" class="form-control" placeholder="Username or email" autocomplete="username">
                    <label for="u_name">Username or email</label>
                </div>
                <div class="form-floating mb-3">
                    <input type="password" id="p_word" class="form-control" placeholder="Password" autocomplete="current-password">
                    <label for="p_word">Password</label>
                </div>
                <button type="button" id="do_login" class="cos-submit">Sign In</button>
                <div id="resend_verify_wrap" style="display:none; margin-top:.9rem; text-align:center;">
                    <span class="cos-help" style="margin:0;">Didn&rsquo;t get the email? </span>
                    <button type="button" id="do_resend_verify" class="cos-link accent">Resend Verification</button>
                </div>
                <div class="cos-links">
                    <button type="button" id="forgot_password" class="cos-link">Forgot Password?</button>
                    <button type="button" id="register" class="cos-link accent">Create Account</button>
                </div>
            </div>

            <div id="mfa_form" style="display:none;">
                <h2 class="cos-form-title">Verify It's You</h2>
                <p class="cos-help" id="mfa_help">Enter your verification code to finish signing in.</p>
                <div class="form-floating mb-3">
                    <input type="text" id="mfa_code" class="form-control" placeholder="Verification code" inputmode="numeric" autocomplete="one-time-code">
                    <label for="mfa_code">Verification code</label>
                </div>
                <input type="hidden" id="mfa_method" value="">
                <button type="button" id="do_mfa_verify" class="cos-submit">Verify</button>
                <div class="cos-links" style="flex-wrap:wrap; gap:.75rem;">
                    <button type="button" id="mfa_use_email" class="cos-link" style="display:none;">Email me a code</button>
                    <button type="button" id="mfa_resend_email" class="cos-link" style="display:none;">Resend code</button>
                    <button type="button" id="mfa_use_totp" class="cos-link" style="display:none;">Use authenticator app</button>
                    <button type="button" id="mfa_use_backup" class="cos-link">Use a backup code</button>
                </div>
            </div>

            <div id="forgot_form" style="display:none;">
                <h2 class="cos-form-title">Reset Password</h2>
                <p class="cos-help">Enter your username or email and we&rsquo;ll email a reset link.</p>
                <div class="form-floating mb-3">
                    <input type="text" id="forgot_u_name" class="form-control" placeholder="Username or email" autocomplete="username">
                    <label for="forgot_u_name">Username or email</label>
                </div>
                <button type="button" id="do_forgot" class="cos-submit">Send Reset Link</button>
                <div class="cos-links">
                    <button type="button" class="cos-link show_login">Back to Sign In</button>
                </div>
            </div>

            <div id="register_form" style="display:none;">
                <h2 class="cos-form-title">Create Account</h2>
                <p class="cos-help">Set up your <?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> account.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" id="register_first_name" class="form-control" placeholder="First name" autocomplete="given-name">
                            <label for="register_first_name">First name</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" id="register_last_name" class="form-control" placeholder="Last name" autocomplete="family-name">
                            <label for="register_last_name">Last name</label>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-floating">
                            <input type="email" id="register_user_email" class="form-control" placeholder="Email" autocomplete="email">
                            <label for="register_user_email">Email</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="password" id="register_p_word" class="form-control" placeholder="Password" autocomplete="new-password">
                            <label for="register_p_word">Password</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="password" id="register_p_word_confirm" class="form-control" placeholder="Confirm password" autocomplete="new-password">
                            <label for="register_p_word_confirm">Confirm password</label>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <button type="button" id="do_register" class="cos-submit">Create Account</button>
                    </div>
                    <div class="col-md-12">
                        <div class="cos-links">
                            <button type="button" class="cos-link show_login">Back to Sign In</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script defer src="/js/landing.js?v=<?php echo @filemtime(Main::app_path().'/public/js/landing.js'); ?>"></script>
</body>
</html>
