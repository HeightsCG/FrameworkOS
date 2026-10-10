<?php
$e = function ($s) { return Sections::e($s); };
?>
<section class="sx sx--hero sx--pricing"><div class="ld-wrap sx__in">
    <h1 class="sx-hero__title">Join Free. Sell When You're Ready.</h1>
<?php $sell = array_map(function ($t) { return $t['name']; }, PagesController::selling_tiers()); $words = array(2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five'); ?>
    <p class="sx-hero__lead"><?php echo $e(Main::site_name()); ?> pricing is <?php echo $words[count($rows)] ?? count($rows); ?> plans: a Free account for fans, and <?php echo $e(implode(' and ', $sell)); ?> for creators who sell. Everyone starts with a Free account, no card needed: follow creators, join memberships, unlock posts and buy tickets and bookings. To sell, upgrade to Creator or Studio. Both include your page, memberships, pay-per-view, publishing and payouts, and the platform fee is <?php echo htmlspecialchars(PagesController::fee_short(), ENT_QUOTES, 'UTF-8'); ?>.</p>
    <p class="sx-hero__lead"><?php echo $e(PagesController::plan_roles_sentence()); ?><?php $be = PagesController::breakeven_sentence(); if ($be !== ''): ?> <?php echo $e($be); ?><?php endif; ?></p>
<?php
    // Founding offer (Founding::SPOTS, first month free, the Studio fee locked on Creator): shown while spots remain.
    $founding_left = isset($founding_left) ? (int) $founding_left : 0;
    $f_plan = PlanTiers::get(Founding::PLAN); $f_from = PlanTiers::get(Founding::FEE_FROM);
?>
<?php if ($founding_left > 0 && $f_plan && $f_from): ?>
<p class="sx-plans__offer">Founding creators: first month free and the <?php echo $e(Founding::fee_label()); ?> <?php echo $e($f_from['name']); ?> fee locked on the <?php echo $e($f_plan['name']); ?> plan. <?php echo number_format(Founding::spots()); ?> spots, <?php echo number_format($founding_left); ?> left. <a href="/founding">Claim a spot</a></p>
<?php endif; ?>
<div class="sx-plans">
<?php foreach ($rows as $r): $t = $r['tier']; $hi = !empty($t['recommended']); ?>
    <article class="sx-plan<?php echo $hi ? ' sx-plan--hi' : ''; ?>">
        <?php if ($hi): ?><span class="sx-plan__tag">Most popular</span><?php endif; ?>
        <h2 class="sx-plan__name"><?php echo $e($t['name']); ?><?php $role = PagesController::plan_role((string) $t['key']); if ($role !== ''): ?><span class="sx-plan__role"><?php echo $e($role); ?></span><?php endif; ?></h2>
        <p class="sx-plan__line"><?php echo $e($t['tagline']); ?></p>
        <div class="sx-plan__price"><?php if ($r['amount'] !== null): ?>$<?php echo number_format($r['amount'] / 100, ($r['amount'] % 100 === 0) ? 0 : 2); ?> <small>/ month</small><?php else: ?><small>Shown when you sign up</small><?php endif; ?></div>
        <?php if ($founding_left > 0 && $t['key'] === Founding::PLAN): ?><span class="sx-plan__tag sx-plan__tag--inline">First month free for founding creators.</span><?php endif; ?>
        <?php if ($t['key'] === PlanTiers::FREE_KEY): ?>
        <p class="sx-plan__keep">Upgrade to Creator or Studio to sell</p>
        <dl><?php foreach (PlanTiers::FREE_INCLUDES as $inc): ?><div><dt><?php echo $e($inc); ?></dt><dd>Included</dd></div><?php endforeach; ?></dl>
        <?php else: ?>
        <p class="sx-plan__keep">Keep <?php echo 100 - (int) $t['limits']['fee_percent']; ?>% of every sale</p>
        <?php $vs = PagesController::fee_vs_onlyfans($t); if ($vs !== ''): ?><p class="sx-plan__vs"><?php echo $e($vs); ?></p><?php endif; ?>
        <dl><?php foreach (PlanTiers::ROWS as $row): ?><div><dt><?php echo $e($row['label']); ?></dt><dd><?php echo $e(PlanTiers::fmt_tier_limit($t, $row['key'])); ?></dd></div><?php endforeach; ?><?php foreach (PlanTiers::FEATURES as $fk => $fl): ?><div><dt><?php echo $fl; ?></dt><dd><?php echo PlanTiers::has_feature($t, $fk) ? 'Included' : '&mdash;'; ?></dd></div><?php endforeach; ?></dl>
        <?php endif; ?>
        <?php foreach (PlanTiers::addons_for($t['key']) as $ad): ?><p class="sx-plan__addon">Add AI influencers for $<?php echo (int) $ad['price']; ?>/month each, up to <?php echo (int) $ad['max']; ?> more.</p><?php endforeach; ?>
        <div class="sx-plan__cta"><a class="sx-btn <?php echo $hi ? 'sx-btn--primary' : 'sx-btn--secondary'; ?>" href="<?php echo ($t['key'] === PlanTiers::FREE_KEY) ? '/?auth=register' : $e('/?auth=register&plan=' . rawurlencode((string) $t['key']) . '&role=creator'); ?>" data-auth="register">Start with <?php echo $e($t['name']); ?></a></div>
    </article>
<?php endforeach; ?>
</div>
<?php
    // What AI credits buy, from the same price list Billing shows (Plan::ai_price_list), so the page can't go stale.
    $vids = array(); foreach (Plan::ai_price_list() as $pr) { if (strpos($pr['label'], 'Video') === 0) { $vids[] = (int) $pr['credits']; } }
?>
<p class="sx-note">On Creator and Studio, more AI credits can be bought any time: $1 buys <?php echo (int) PlanTiers::AI_CREDITS_PER_DOLLAR; ?> AI credits. An AI image uses <?php echo number_format(Plan::ai_price('image')); ?><?php echo $vids ? ', a video ' . number_format(min($vids)) . ' to ' . number_format(max($vids)) . ' depending on style and length' : ''; ?>. Captions and training are included. Card-processing fees are separate from the platform take rate.</p>
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
    foreach ($rows as $r) { $tr[] = ($r['tier']['key'] === PlanTiers::FREE_KEY) ? '&mdash;' : $e(PlanTiers::fmt_tier_limit($r['tier'], $row['key'])); }   // Free sells nothing
    $trows[] = $tr;
}
foreach (PlanTiers::FEATURES as $fk => $fl) {   // per-plan switches (inbox AI replies)
    $tr = array($fl);
    foreach ($rows as $r) { $tr[] = PlanTiers::has_feature($r['tier'], $fk) ? $check : '&mdash;'; }
    $trows[] = $tr;
}
foreach (PlanTiers::addons() as $ad) {
    $tr = array($e($ad['name'] . ' add-on'));
    foreach ($rows as $r) { $tr[] = in_array($r['tier']['key'], (array) $ad['plans'], true) ? '$' . (int) $ad['price'] . ' / month each' : '&mdash;'; }
    $trows[] = $tr;
}
$every = array('Public page at your handle', 'Unlimited membership tiers', 'Pay-per-view posts', 'Bundles', 'Services and events', 'Paid messages', 'Promo codes and free trials',
    'Nine social networks, unlimited connections', 'Fanvue cross-posting', 'AI captions', 'AI images (paid with AI credits)', 'Welcome and trigger messages', 'Buy AI credits any time', 'Broadcasts to audience segments',
    'Audience list with tags and notes', 'Analytics and exports', 'Claude connector', 'Payouts to your bank');
foreach ($every as $label) { $tr = array($e($label)); foreach ($rows as $r) { $tr[] = ($r['tier']['key'] === PlanTiers::FREE_KEY) ? '&mdash;' : $check; } $trows[] = $tr; }
echo Sections::open('white', 'Compare plans', 'Free is the account everyone starts with. Creator and Studio are for selling: they differ in fee, limits and AI tools, and include everything below the limits.');
echo '<div class="pt">' . Sections::table($head, $trows, $hi) . '</div>';
echo Sections::close();

echo Sections::faq($faq, 'white');
$cta_title = 'Start with Free.';
$cta_text = 'Create your free account. When you want to sell, pick Creator or Studio in Billing.';
$cta_no_pricing = true;
