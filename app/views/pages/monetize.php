<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $site = Main::site_name(); ?>
<p class="pub-eyebrow">Guide</p>
<h1 class="pub-h1">How to monetize your content</h1>
<p class="pub-lead">Five ways creators get paid, what each one is good for, and how to price it. Everything here works on <?php echo $e($site); ?>, and most of it works anywhere.</p>

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

<h2 class="pub-h2">Questions</h2>
<ul class="pub-faq"><?php foreach ($faq as $qa): ?><li><h3><?php echo $e($qa['q']); ?></h3><p><?php echo $e($qa['a']); ?></p></li><?php endforeach; ?></ul>

<div class="pub-cta"><span class="pub-cta__text">Set up your tiers in an afternoon.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
