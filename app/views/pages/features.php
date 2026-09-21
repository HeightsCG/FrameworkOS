<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$icon = function ($paths) { return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>'; };
$features = array(
    array('id' => 'page', 'icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>', 'title' => 'Your public page', 'text' => 'One page at your handle that sells everything you make.',
          'points' => array('Posts, tiers, services, events and links in one place', 'Your brand colors', 'Locked posts show blurred until a fan joins or unlocks')),
    array('id' => 'memberships', 'icon' => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>', 'title' => 'Memberships and tiers', 'text' => 'As many tiers as you want, each priced your way.',
          'points' => array('Own price, billing interval, trial and perks per tier', 'Target a post at one tier or several', 'Discount codes and free trials built in')),
    array('id' => 'ppv', 'icon' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 'title' => 'Pay-per-view, bundles, services, events', 'text' => 'Sell single pieces alongside your memberships.',
          'points' => array('Price any post, bundle library media', 'Take bookings and sell event seats', 'Fans pay from a credit wallet in one tap')),
    array('id' => 'studio', 'icon' => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>', 'title' => 'The studio', 'text' => 'Upload once, publish everywhere.',
          'points' => array('AI captions in your brand voice', 'Posts to your page and 9 social networks at once', 'Scheduling, automations and one analytics view')),
    array('id' => 'inbox', 'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 'title' => 'Inbox with AI replies', 'text' => 'Every fan message in one place, answered in your voice.',
          'points' => array('AI drafts replies for your approval, or sends them', 'Automatic welcome messages for new fans', 'Attach media and a price to any message')),
    array('id' => 'payouts', 'icon' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>', 'title' => 'Payouts and ownership', 'text' => 'Your money and your audience stay yours.',
          'points' => array('Stripe Connect payouts to your bank', 'Export your subscribers, content and brand any time', 'A flat fee that falls as your plan grows')),
);
?>
<header class="gd-hero">
    <nav class="gd-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><span>Features</span></nav>
    <h1 class="gd-hero__title">Everything a creator sells, from one page.</h1>
    <p class="gd-hero__lead"><?php echo $e(Main::site_name()); ?> is an online creator platform: one public page with memberships, pay-per-view posts, bundles, events, services and tracked links, a studio that publishes to your socials, and payouts through Stripe. You keep your audience and your content.</p>
    <div class="gd-hero__acts"><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a><a class="gd-read" href="/pricing">See Pricing</a></div>
</header>

<section class="ft-grid" aria-label="Features">
<?php foreach ($features as $f): ?>
    <article class="ft-card" id="<?php echo $e($f['id']); ?>">
        <span class="ft-card__icon"><?php echo $icon($f['icon']); ?></span>
        <h2 class="ft-card__title"><?php echo $e($f['title']); ?></h2>
        <p class="ft-card__text"><?php echo $e($f['text']); ?></p>
        <ul class="ft-checks"><?php foreach ($f['points'] as $pt): ?><li><?php echo $e($pt); ?></li><?php endforeach; ?></ul>
    </article>
<?php endforeach; ?>
</section>

<section class="ft-included" aria-labelledby="ft_inc_h">
    <h2 class="gd-h2" id="ft_inc_h">Included on every plan</h2>
    <ul class="ft-checks ft-checks--cols"><?php foreach (PlanTiers::INCLUDED as $i): ?><li><?php echo $i; ?></li><?php endforeach; ?></ul>
</section>

<div class="gd-prose">
<section class="faq" aria-labelledby="gd_faq_h">
    <h2 class="faq__title" id="gd_faq_h">Frequently asked questions</h2>
    <div class="faq__list">
        <?php foreach ($faq as $qa): ?><details class="faq__item"><summary class="faq__q"><?php echo $e($qa['q']); ?><svg class="faq__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="faq__a"><?php echo $e($qa['a']); ?></div></details><?php endforeach; ?>
    </div>
</section>
</div>
<?php $cta_title = 'Create your page in a few minutes.'; ?>
