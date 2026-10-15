<?php
$e = function ($s) { return Sections::e($s); };
?>
<section class="sx sx--hero sx--pricing"><div class="ld-wrap sx__in">
    <h1 class="sx-hero__title sx-hero__title--plain">Pricing</h1>
    <p class="sx-hero__lead"><?php echo $e(Main::site_name()); ?> is one page for your memberships, pay-per-view posts, bundles, services and events, with a studio that publishes everywhere and payouts to your bank. Everyone joins free, and creators can build their page before they pay. When you’re ready to sell, pick Creator or Studio, billed monthly, cancel any time.</p>
<?php
    // Founding offer (first TRIAL_DAYS free on Creator; no fee lock since 2026-10-12): one line on the Creator card while spots remain.
    $founding_left = isset($founding_left) ? (int) $founding_left : 0;
    $f_plan = PlanTiers::get(Founding::PLAN); $f_from = PlanTiers::get(Founding::FEE_FROM);
    // Card order: Free, Creator, Studio on desktop; phones put the paid plans first (CSS order by plan key).
?>
<?php if ($founding_left > 0 && $f_plan && $f_from): ?>
<p class="sx-plans__offer">Founding creators: <?php echo $e(Founding::free_label()); ?> on <?php echo $e(Founding::plan_names()); ?>. <?php $f_claim = Founding::claim_link(); ?><a href="<?php echo $e($f_claim[0]); ?>"<?php echo $f_claim[1] !== '' ? ' data-auth="' . $e($f_claim[1]) . '"' : ''; ?>>Claim a spot</a></p><?php /* across the top of the cards, no spot counts (Daniel 2026-10-12) */ ?>
<?php endif; ?>
<div class="sx-plans" id="sx_plans">
<?php foreach ($rows as $r): $t = $r['tier']; $hi = !empty($t['recommended']); $is_free = ($t['key'] === PlanTiers::FREE_KEY); ?>
    <div class="sx-plan sx-plan--<?php echo $e(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $t['key']))); ?><?php echo $hi ? ' sx-plan--hi' : ''; ?>">
        <h2 class="sx-plan__name"><?php echo $e($t['name']); ?></h2><?php $role = PagesController::plan_role((string) $t['key']); if ($role !== ''): ?><p class="sx-plan__role"><?php echo $e($role); ?></p><?php endif; ?>
        <div class="sx-plan__price"><?php if ($is_free): ?>$0<?php elseif ($r['amount'] !== null): ?>$<?php echo number_format($r['amount'] / 100, ($r['amount'] % 100 === 0) ? 0 : 2); ?> <small>/ month</small><?php else: ?><small>Shown when you sign up</small><?php endif; ?></div>
        <div class="sx-plan__cta">
            <a class="sx-btn <?php echo $hi ? 'sx-btn--primary' : 'sx-btn--secondary'; ?>" href="<?php echo $is_free ? '/?auth=register' : $e('/?auth=register&plan=' . rawurlencode((string) $t['key']) . '&role=creator'); ?>" rel="nofollow" data-auth="register">Start Now</a>
        </div>
        <div class="sx-plan__body">
        <?php if ($is_free): ?>
        <dl><?php foreach (array_merge(PlanTiers::FREE_BUILDS, PlanTiers::FREE_INCLUDES) as $inc): ?><div><dt><?php echo $e($inc); ?></dt><dd>Included</dd></div><?php endforeach; ?></dl>
        <?php else: ?>
        <dl><?php foreach (PlanTiers::ROWS as $row): ?><div><dt><?php echo $e($row['label']); ?></dt><dd><?php echo $e(PlanTiers::fmt_tier_limit($t, $row['key'])); ?></dd></div><?php endforeach; ?><?php foreach (PlanTiers::FEATURES as $fk => $fl): ?><div><dt><?php echo $fl; ?></dt><dd><?php echo PlanTiers::has_feature($t, $fk) ? 'Included' : '&mdash;'; ?></dd></div><?php endforeach; ?></dl>
        <?php endif; ?>
        <?php foreach (PlanTiers::addons_for($t['key']) as $ad): ?><p class="sx-plan__addon">Add AI influencers for $<?php echo (int) $ad['price']; ?>/month each, up to <?php echo (int) $ad['max']; ?> more.</p><?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php
    // What AI credits buy, from the same price list Billing shows (Plan::ai_price_list), so the page can't go stale.
    $vids = array(); foreach (Plan::ai_price_list() as $pr) { if (strpos($pr['label'], 'Video') === 0) { $vids[] = (int) $pr['credits']; } }
?>
<?php if (Proof::product_hunt_url() !== ''): ?><p class="sx-proof"><a class="sx-proof__a" href="<?php echo $e(Proof::product_hunt_url()); ?>" rel="noopener" target="_blank">See <?php echo $e(Main::site_name()); ?> on Product Hunt</a><?php if (Proof::launch_label() !== ''): ?> <span class="sx-proof__badge"><?php echo $e(Proof::launch_label()); ?></span><?php endif; ?></p><?php endif; ?>
<p class="sx-note">On Creator and Studio, more AI credits can be bought any time: $1 buys <?php echo (int) PlanTiers::AI_CREDITS_PER_DOLLAR; ?> AI credits. An AI image uses <?php echo number_format(Plan::ai_price('image')); ?><?php echo $vids ? ', a video ' . number_format(min($vids)) . ' to ' . number_format(max($vids)) . ' depending on style and length' : ''; ?>. Captions and training are included. Card-processing fees are separate from the platform take rate.</p>
<?php
echo Sections::close();

/* What a paid plan unlocks, beside a schematic of the page's offers (Sections::ui, invented values, never a screenshot). */
echo Sections::open('alt', 'What do you set up once you upgrade?');
echo Sections::rows(array(
    array('title' => 'Tiers, codes and bundles in one place',
          'text' => 'Memberships, discount codes and content bundles live on one Settings screen. Set a tier\'s price and billing interval, switch it on when it is ready, and add a code for a launch. Every sale lands in your balance net of your plan\'s platform fee, and you cash out to your bank from ' . Price::PAYOUT_MIN_LABEL . '.',
          'points' => array('As many tiers as you want, each with its own price, interval and trial', 'Promo codes with a cap or an expiry', 'Bundles built from media already in your library'),
          'link' => array('See memberships', '/features/memberships'),
          'media_html' => Sections::ui('offers')),
));
echo Sections::close();

/* Full comparison: limits from PlanTiers::ROWS, then everything every plan includes. */
$check = '<svg class="sx-ic pt-check" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Included"><path d="M20 6 9 17l-5-5"/></svg><span class="sx-vh">Included</span>';   // the hidden word is what text extractors and screen readers read
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
    'Ten platforms, unlimited connections', 'Fanvue cross-posting', 'AI captions', 'AI images (paid with AI credits)', 'Welcome and trigger messages', 'Buy AI credits any time', 'Broadcasts to audience segments',
    'Audience list with tags and notes', 'Analytics and exports', 'Claude connector', 'Payouts to your bank');
foreach ($every as $label) { $tr = array($e($label)); foreach ($rows as $r) { $tr[] = ($r['tier']['key'] === PlanTiers::FREE_KEY) ? '&mdash;' : $check; } $trows[] = $tr; }
// Teams and agencies: what Studio adds for people who run accounts with help (SEO audit 2026-10-12: no entry point for that reader).
$st = PlanTiers::get('studio');
if ($st) {
    echo Sections::prose('white', 'Which plan fits a team or an agency?', array(
        'The ' . $st['name'] . ' plan is the one built for a team. It includes ' . PlanTiers::fmt_tier_limit($st, 'seats') . ' team seats, so collaborators work on your account with their own sign-in and never share your password. It also covers ' . PlanTiers::fmt_tier_limit($st, 'influencers') . ' AI influencers and custom domains.',
        'Every sale made by anyone on the team lands in the account owner\'s balance. The tools a team uses day to day are set out in [AI tools for running creator accounts](/ai-ofm-tools).',
    ));
}

echo Sections::open('alt', 'Compare plans', PagesController::FREE_LINE . ' Creator and Studio differ in fee, limits and AI tools, and include everything below the limits.');
echo '<div class="pt">' . Sections::table($head, $trows, $hi) . '</div>';
echo Sections::close();

echo Sections::related_guides('/pricing', 'white');
echo Sections::faq($faq, 'alt');
$cta_title = 'Start with Free.';
$cta_text = 'Create your free account. When you want to sell, pick Creator or Studio in Billing.';
$cta_no_pricing = true;
$cta_photo = 'pricing';   // the closing band's photo (SitePhotos)
