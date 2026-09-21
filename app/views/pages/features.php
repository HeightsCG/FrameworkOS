<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<p class="pub-eyebrow">Creator platform</p>
<h1 class="pub-h1">Everything a creator sells, from one page.</h1>
<p class="pub-lead"><?php echo $e(Main::site_name()); ?> is an online creator platform: one public page with memberships, pay-per-view posts, bundles, events, services and tracked links, a studio that publishes to your socials, and payouts through Stripe. You keep your audience and your content.</p>

<h2 class="pub-h2" id="page">Your public page</h2>
<p class="pub-p">Fans land on <strong>yourhandle</strong> at our domain. It shows your posts, membership tiers, services, events and links, with your brand colors. Free posts are open to everyone; subscriber and pay-per-view posts show blurred with a lock until a fan joins or unlocks.</p>

<h2 class="pub-h2" id="memberships">Memberships and tiers</h2>
<p class="pub-p">Create as many tiers as you like, each with its own price, billing interval, trial and perks. Every post can target one tier or several, so a "Supporter" post and an "Inner circle" post live on the same page. Discount codes and free trials are built in.</p>

<h2 class="pub-h2" id="ppv">Pay-per-view, bundles, services and events</h2>
<p class="pub-p">Sell a single post for a set price, group library media into a bundle, take bookings for a service, or sell seats to an event. Fans pay with a credit wallet, so a $7 unlock is one tap, and you earn the net amount in your balance.</p>

<h2 class="pub-h2" id="studio">The studio</h2>
<p class="pub-p">Upload once. Write the caption with AI in your brand voice, choose who can see it, and publish to your page and to X, Instagram, TikTok, Facebook, LinkedIn, Pinterest, YouTube, Threads and Bluesky at the same time. Schedule ahead, run automations, and read engagement from every platform in one analytics view.</p>

<h2 class="pub-h2" id="inbox">Inbox with AI replies</h2>
<p class="pub-p">Fan messages arrive in one inbox. AI drafts replies in your voice and waits for your approval until you let it send on its own. Welcome messages go out automatically to new followers and subscribers, with media and a price if you want.</p>

<h2 class="pub-h2" id="payouts">Payouts and ownership</h2>
<p class="pub-p">Payouts run through Stripe Connect to your own bank account. Your subscriber list, content and brand are yours to export. The platform fee is a flat percentage that falls as your plan grows; see <a href="/pricing">pricing</a>.</p>

<h2 class="pub-h2">Included on every plan</h2>
<ul class="pub-list"><?php foreach (PlanTiers::INCLUDED as $i): ?><li><?php echo $i; ?></li><?php endforeach; ?></ul>

<h2 class="pub-h2">Questions</h2>
<ul class="pub-faq">
<?php foreach ($faq as $qa): ?><li><h3><?php echo $e($qa['q']); ?></h3><p><?php echo $e($qa['a']); ?></p></li><?php endforeach; ?>
</ul>

<div class="pub-cta"><span class="pub-cta__text">Create your page in a few minutes.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
