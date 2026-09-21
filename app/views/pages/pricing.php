<?php
$e = function ($s) { return Sections::e($s); };
?>
<section class="sx sx--hero sx--pricing"><div class="ld-wrap sx__in">
    <h1 class="sx-hero__title">Three plans. The fee falls as you grow.</h1>
    <p class="sx-hero__lead">Monthly, cancel anytime. Every plan includes the whole platform and unlimited fans, tiers and social connections. Only the rows in the cards differ.</p>
<?php
?>
<div class="sx-plans">
<?php foreach ($rows as $r): $t = $r['tier']; $hi = !empty($t['recommended']); ?>
    <article class="sx-plan<?php echo $hi ? ' sx-plan--hi' : ''; ?>">
        <?php if ($hi): ?><span class="sx-plan__tag">Most popular</span><?php endif; ?>
        <h2 class="sx-plan__name"><?php echo $e($t['name']); ?></h2>
        <p class="sx-plan__line"><?php echo $e($t['tagline']); ?></p>
        <div class="sx-plan__price"><?php if ($r['amount'] !== null): ?>$<?php echo number_format($r['amount'] / 100, ($r['amount'] % 100 === 0) ? 0 : 2); ?> <small>/ month</small><?php else: ?><small>Shown when you sign up</small><?php endif; ?></div>
        <p class="sx-plan__keep">Keep <?php echo 100 - (int) $t['limits']['fee_percent']; ?>% of every sale</p>
        <dl><?php foreach (PlanTiers::ROWS as $row): ?><div><dt><?php echo $e($row['label']); ?></dt><dd><?php echo $e(PlanTiers::fmt_limit($row['key'], $t['limits'][$row['key']] ?? 0)); ?></dd></div><?php endforeach; ?></dl>
        <div class="sx-plan__cta"><a class="sx-btn <?php echo $hi ? 'sx-btn--primary' : 'sx-btn--secondary'; ?>" href="/?auth=register" data-auth="register">Start with <?php echo $e($t['name']); ?></a></div>
    </article>
<?php endforeach; ?>
</div>
<p class="sx-note">Stripe card-processing fees are separate from the platform take rate.</p>
<?php
echo Sections::close();

/* Full comparison: limits from PlanTiers::ROWS, then everything every plan includes. */
$check = '<svg class="sx-ic pt-check" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="Included"><path d="M20 6 9 17l-5-5"/></svg>';
$head = array('');
$hi = -1;
foreach ($rows as $i => $r) { $head[] = $r['tier']['name']; if (!empty($r['tier']['recommended'])) { $hi = $i + 1; } }
$trows = array();
$price_row = array($e('Price'));
foreach ($rows as $r) { $price_row[] = $r['amount'] !== null ? '$' . $e(number_format($r['amount'] / 100, ($r['amount'] % 100 === 0) ? 0 : 2)) . ' / month' : 'At checkout'; }
$trows[] = $price_row;
foreach (PlanTiers::ROWS as $row) {
    $tr = array($e($row['label']));
    foreach ($rows as $r) { $tr[] = $e(PlanTiers::fmt_limit($row['key'], $r['tier']['limits'][$row['key']] ?? 0)); }
    $trows[] = $tr;
}
$every = array('Public page at your handle', 'Unlimited membership tiers', 'Pay-per-view posts', 'Bundles', 'Services and events', 'Paid messages', 'Promo codes and free trials',
    'Nine social networks, unlimited connections', 'Fanvue cross-posting', 'AI captions and brand images', 'Inbox AI replies and welcome messages', 'Broadcasts to audience segments',
    'Audience list with tags and notes', 'Analytics and exports', 'Claude connector', 'Payouts to your bank');
foreach ($every as $label) { $tr = array($e($label)); foreach ($rows as $r) { $tr[] = $check; } $trows[] = $tr; }
echo Sections::open('white', 'Compare plans', 'Every plan includes the whole platform. Plans differ only in fee, seats and limits.');
echo '<div class="pt">' . Sections::table($head, $trows, $hi) . '</div>';
echo Sections::close();

echo Sections::faq($faq, 'white');
$cta_title = 'Pick a plan after you sign up.';
$cta_text = 'Create your account first. Nothing to pay until you choose a plan.';
$cta_no_pricing = true;
