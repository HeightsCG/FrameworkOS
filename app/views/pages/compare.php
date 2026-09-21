<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $us = PagesController::our_facts(); $site = Main::site_name();
$rows = array('fee' => 'Platform fee', 'payout' => 'Payouts', 'content' => 'What you can sell', 'socials' => 'Social publishing', 'ai' => 'AI tools', 'ownership' => 'Your audience');
$has_reported = false; foreach ($rows as $k => $label) { if (!empty($c[$k]['reported'])) { $has_reported = true; break; } } ?>
<header class="gd-hero">
    <nav class="gd-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><span><?php echo $e($c['name']); ?> alternative</span></nav>
    <h1 class="gd-hero__title"><?php echo $e($site); ?> vs <?php echo $e($c['name']); ?></h1>
    <p class="gd-hero__lead"><?php echo $e($c['summary']); ?> <?php echo $e($site); ?> is built for creators who want one page for everything they sell and a studio that publishes everywhere. Here is how the two compare, with sources.</p>
</header>
<div class="gd-prose">

<table class="pub-table pub-table--compare">
    <thead><tr><th></th><th><?php echo $e($site); ?></th><th><?php echo $e($c['name']); ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $k => $label): ?>
        <tr><td><?php echo $e($label); ?></td><td><?php echo $e($us[$k]); ?></td><td><?php echo $e($c[$k]['value']); ?> <a class="pub-src" href="<?php echo $e($c[$k]['source']); ?>" rel="nofollow noopener" target="_blank">source</a><?php if (!empty($c[$k]['reported'])): ?> <span class="pub-src">reported</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="pub-note"><?php echo $e($c['name']); ?> details checked on <?php echo $e(date('F j, Y', strtotime($c['checked']))); ?> from the linked pages. If something has changed, tell us and we will update it.<?php if ($has_reported): ?> <?php echo $e($c['name']); ?> blocks automated access to its help pages, so figures marked "reported" come from published third-party reporting linked in the table.<?php endif; ?></p>

<h2 class="pub-h2">When <?php echo $e($c['name']); ?> is the better fit</h2>
<p class="pub-p"><?php echo $c['name'] === 'OnlyFans' ? 'If most of your audience already pays on OnlyFans and you do not plan to sell services, events or bundles, staying there avoids moving anyone.' : 'If you only sell subscriptions and messages and want the lowest setup effort, Fanvue does that well.'; ?></p>

<h2 class="pub-h2">When <?php echo $e($site); ?> is the better fit</h2>
<ul class="pub-list">
    <li>You post to several social networks and want one studio that publishes to all of them and reports engagement back.</li>
    <li>You sell more than subscriptions: pay-per-view posts, bundles, services, events and tracked links from one page.</li>
    <li>You want AI to draft captions and DM replies in your voice, with your approval.</li>
    <li>You want a fee that falls as you grow rather than a flat rate. See <a href="/pricing">pricing</a>.</li>
</ul>

<h2 class="pub-h2">Moving over</h2>
<p class="pub-p">Keep your <?php echo $e($c['name']); ?> page live while you set up. Publish to both from the studio, put your new page in every bio, and let fans move at their own pace. Memberships and pay-per-view work from day one; payouts start as soon as Stripe finishes verifying you.</p>

</div>
<?php $cta_title = 'Try it alongside ' . $c['name'] . '.'; ?>
