<?php
/* Top banner for signed-out visitors on a public event page and on a CLS Video guest call: the brand on the left,
   Log In / Register on the right. Both come back to this page afterwards (?next=). Not shown on a creator's own domain. */
$gb_next = CustomDomains::safe_path($_SERVER['REQUEST_URI'] ?? '/');
$gb_h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<link rel="stylesheet" href="/css/guest-bar.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/guest-bar.css'); ?>">
<header class="gbar">
    <a class="gbar__brand" href="/">
        <span class="gbar__mark" aria-hidden="true"></span>
        <span class="gbar__name"><?php echo $gb_h(Main::site_name()); ?></span>
    </a>
    <nav class="gbar__actions" aria-label="Account">
        <a class="gbar__btn" href="/?auth=login&amp;next=<?php echo $gb_h(rawurlencode($gb_next)); ?>">Log In</a>
        <a class="gbar__btn gbar__btn--primary" href="/?auth=register&amp;next=<?php echo $gb_h(rawurlencode($gb_next)); ?>">Register</a>
    </nav>
</header>
