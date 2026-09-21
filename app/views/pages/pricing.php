<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<header class="gd-hero">
    <nav class="gd-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><span>Pricing</span></nav>
    <h1 class="gd-hero__title">Three plans. The fee falls as you grow.</h1>
    <p class="gd-hero__lead">Monthly, cancel anytime. Every plan includes the whole platform and unlimited fans, tiers and social connections. The rows below are the only things that differ.</p>
</header>
<div class="gd-prose">

<div class="pub-plans">
<?php foreach ($rows as $r): $t = $r['tier']; ?>
    <div class="pub-plan<?php echo !empty($t['recommended']) ? ' pub-plan--featured' : ''; ?>">
        <div class="pub-plan__name"><?php echo $e($t['name']); ?></div>
        <p class="pub-plan__tag"><?php echo $e($t['tagline']); ?></p>
        <div class="pub-plan__price"><?php if ($r['amount'] !== null): ?>$<?php echo number_format($r['amount'] / 100, ($r['amount'] % 100 === 0) ? 0 : 2); ?> <small>/ month</small><?php else: ?><small>See plans in your account</small><?php endif; ?></div>
        <dl>
        <?php foreach (PlanTiers::ROWS as $row): ?>
            <div><dt><?php echo $e($row['label']); ?></dt><dd><?php echo $e(PlanTiers::fmt_limit($row['key'], $t['limits'][$row['key']] ?? 0)); ?></dd></div>
        <?php endforeach; ?>
        </dl>
        <div class="pub-plan__cta"><a class="<?php echo !empty($t['recommended']) ? 'ld-btn ld-btn--primary' : 'gd-read'; ?>" href="/?auth=register">Start with <?php echo $e($t['name']); ?></a></div>
    </div>
<?php endforeach; ?>
</div>
<p class="pub-note">Included on every plan: <?php echo implode(' · ', PlanTiers::INCLUDED); ?>. Stripe card-processing fees are separate from the take rate.</p>

<section class="faq" aria-labelledby="gd_faq_h">
    <h2 class="faq__title" id="gd_faq_h">Frequently asked questions</h2>
    <div class="faq__list">
        <?php foreach ($faq as $qa): ?><details class="faq__item"><summary class="faq__q"><?php echo $e($qa['q']); ?><svg class="faq__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="faq__a"><?php echo $e($qa['a']); ?></div></details><?php endforeach; ?>
    </div>
</section>

</div>
<?php $cta_title = 'Pick a plan after you sign up. Nothing to pay until then.'; ?>
