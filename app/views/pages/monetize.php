<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $site = Main::site_name(); ?>
<header class="gd-hero">
    <nav class="gd-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><span>Guide</span></nav>
    <h1 class="gd-hero__title">How to monetize your content</h1>
    <p class="gd-hero__lead">Five ways creators get paid, what each one is good for, and how to price it. Everything here works on <?php echo $e($site); ?>, and most of it works anywhere.</p>
</header>
<div class="gd-prose">

<h2 class="pub-h2" id="memberships">1. Memberships</h2>
<p class="pub-p">A monthly price for ongoing access. Start with two tiers, not five: an entry tier priced where a fan says yes without thinking, and a higher tier for the people who want more of you. Put most posts on the entry tier and save a few for the top. Raise prices for new members only.</p>
<h2 class="pub-h2" id="pay-per-view">2. Pay-per-view posts</h2>
<p class="pub-p">One post, one price, for everyone including members. Best for your strongest single pieces. Price by effort and scarcity, not length, and never lower than your entry tier's monthly price divided by four; otherwise members feel penalised.</p>
<h2 class="pub-h2" id="bundles">3. Bundles</h2>
<p class="pub-p">Group past media into a set at a discount to the sum of the parts. Bundles turn your back catalogue into a product and are the easiest upsell after someone unlocks a single post.</p>
<h2 class="pub-h2" id="services">4. Services</h2>
<p class="pub-p">Custom content, shout-outs, coaching, reviews. Fixed price, clear scope, a delivery window you can keep. Services are where a small audience earns the most per fan.</p>
<h2 class="pub-h2" id="events">5. Events</h2>
<p class="pub-p">Live sessions, Q&amp;As, watch parties, workshops. Sell seats ahead of time, cap the room, and record it for a bundle afterwards.</p>

<h2 class="pub-h2">Pricing in one paragraph</h2>
<p class="pub-p">Entry tier: the price of a coffee where most of your fans live. Top tier: three to five times that. Pay-per-view: a quarter of the entry tier or more. Services: your hourly worth times the time it really takes, then add a third. Start higher than feels comfortable; you can add a discount code, you cannot easily raise a price.</p>

<h2 class="pub-h2">Getting fans to the page</h2>
<p class="pub-p">Publish everywhere and point back to one place. A studio that posts to your socials and your page at once, with the paid version behind the lock, does the promotion for you every time you publish. See how <a href="/features">the studio</a> handles it.</p>

<section class="faq" aria-labelledby="gd_faq_h">
    <h2 class="faq__title" id="gd_faq_h">Frequently asked questions</h2>
    <div class="faq__list">
        <?php foreach ($faq as $qa): ?><details class="faq__item"><summary class="faq__q"><?php echo $e($qa['q']); ?><svg class="faq__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="faq__a"><?php echo $e($qa['a']); ?></div></details><?php endforeach; ?>
    </div>
</section>

</div>
<?php $cta_title = 'Set up your tiers in an afternoon.'; ?>
