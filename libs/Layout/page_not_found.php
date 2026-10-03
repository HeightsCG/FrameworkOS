<?php
/** 404 page (Errors::page_not_found). Standalone: it can be reached before any layout has loaded, so it brings its own
 *  minimal styles. If a page already started printing, stay silent rather than nest a second document inside it. */
if (headers_sent()) { return; }
$nf_site = htmlspecialchars((string) Main::site_name(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <title>Page Not Found &middot; <?php echo $nf_site; ?></title>
    <style>
        @font-face{ font-family:"Inter"; src:url("/fonts/inter-latin-var.woff2") format("woff2"); font-weight:100 900; font-display:swap; }
        html{ -webkit-text-size-adjust:100%; }
        body{ margin:0; min-height:100vh; min-height:100dvh; display:flex; align-items:center; justify-content:center; padding:24px 16px; box-sizing:border-box;
              background:#050505; color:#F4F4F5; font-family:"Inter", system-ui, sans-serif; }
        .nf{ width:100%; max-width:440px; }
        .nf__mark{ position:relative; display:block; width:40px; height:40px; border-radius:10px; background:#FF6A13; margin-bottom:32px; }
        .nf__mark::before{ content:""; position:absolute; inset:10px; border-radius:50%; border:2px solid #fff; border-right-color:transparent; transform:rotate(-42deg); }
        .nf__mark::after{ content:""; position:absolute; top:9px; right:9px; width:5px; height:5px; border-radius:50%; background:#fff; }
        .nf__code{ margin:0 0 8px; font-size:14px; font-weight:600; letter-spacing:.08em; color:#FF6A13; }
        .nf__title{ margin:0 0 12px; font-size:32px; line-height:1.1; font-weight:800; letter-spacing:-.03em; }
        .nf__text{ margin:0 0 32px; font-size:16px; line-height:1.6; color:#A1A1AA; }
        .nf__btn{ display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; height:48px; padding:0 24px; border-radius:8px; background:#FF6A13; color:#111;
                  font-size:16px; font-weight:700; text-decoration:none; transition:background .15s; }
        .nf__btn:hover{ background:#FF8A45; }
        .nf__btn:active{ background:#E85A06; }
        .nf__btn:focus-visible{ outline:2px solid #fff; outline-offset:3px; }
        @media (max-width:480px){ .nf__title{ font-size:28px; } .nf__btn{ width:100%; } }
    </style>
</head>
<body>
    <main class="nf">
        <a class="nf__mark" href="/" aria-label="<?php echo $nf_site; ?>"></a>
        <p class="nf__code">404</p>
        <h1 class="nf__title">Page Not Found</h1>
        <p class="nf__text">This page doesn't exist or has moved.</p>
        <a class="nf__btn" href="/">Go Home</a>
    </main>
</body>
</html>
