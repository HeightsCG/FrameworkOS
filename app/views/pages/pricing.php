<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<p class="pub-eyebrow">Pricing</p>
<h1 class="pub-h1">Three plans. The fee falls as you grow.</h1>
<p class="pub-lead">Monthly, cancel anytime. Every plan includes the whole platform and unlimited fans, tiers and social connections. The rows below are the only things that differ.</p>

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
    </div>
<?php endforeach; ?>
</div>
<p class="pub-note">Included on every plan: <?php echo implode(' · ', PlanTiers::INCLUDED); ?>. Stripe card-processing fees are separate from the take rate.</p>

<h2 class="pub-h2">Questions</h2>
<ul class="pub-faq"><?php foreach ($faq as $qa): ?><li><h3><?php echo $e($qa['q']); ?></h3><p><?php echo $e($qa['a']); ?></p></li><?php endforeach; ?></ul>

<div class="pub-cta"><span class="pub-cta__text">Pick a plan after you sign up. Nothing to pay until then.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
