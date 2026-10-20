<?php
/* /founding: the founding creator offer (Founding): the first TRIAL_DAYS free on any paid plan, plus featured placement. Prices come from PlanTiers, never typed. $left = spots left (cached 60 s). */
$names  = Founding::plan_names();
$prices = array(); foreach (Founding::PLANS as $fk) { $ft = PlanTiers::get($fk); if ($ft) { $prices[] = '$' . number_format((int) $ft['price']) . ' a month on ' . $ft['name']; } }
$after  = implode(' or ', $prices);
$open   = (int) $left > 0;

echo Sections::panel_hero(array('photo' => 'founding', 'variant' => 'panel', 
    'title'   => 'Become a Founding Creator',
    'lead'    => Founding::SPOTS . ' founding spots, open until they are filled. Start the ' . $names . ' plan with your ' . Founding::free_label() . ', and be featured in the creator directory and on the home page.',
    'buttons' => $open ? array(array('Claim a Founding Spot', $claim_url, 'primary', $claim_auth), array('See Pricing', '/pricing', 'secondary'))
                       : array(array('See Pricing', '/pricing', 'primary')),
    'panel'   => Sections::pane($open ? 'The Founding Offer' : 'All Founding Spots Are Taken', '', Sections::pane_rows(array(
        array('First ' . Founding::TRIAL_DAYS . ' days', 'Free'),
        array('Plans', $names),
        array('After the free period', ucfirst($after)),
    ))),
));

// What to do, in order (Daniel 2026-10-12: the page said what the offer is but not how to take it).
echo Sections::open('white', 'How Do You Claim a Spot?');
echo '<ol class="fx-steps fx-steps--3">'
    . '<li><h3>1. Create Your Account</h3><p>Press Claim Your Spot and the sign-up form opens right here. The account is free, and you confirm your email to open it.</p></li>'
    . '<li><h3>2. Pick ' . Sections::e($names) . '</h3><p>The Billing page opens with the founding offer already applied. Choose the plan you want.</p></li>'
    . '<li class="is-last"><h3>3. Start Your Trial</h3><p>No card needed. Your first ' . (int) Founding::TRIAL_DAYS . ' days are free, then the plan is ' . Sections::e($after) . ' once you add a card. No card by then and your account moves to Free, with everything you made kept.</p></li>'
    . '</ol>';
echo Sections::close();

echo Sections::open('alt', 'What You Get');
echo Sections::cards(array(
    array('icon' => 'check', 'title' => 'First ' . Founding::TRIAL_DAYS . ' Days Free', 'text' => 'Start the ' . $names . ' plan with nothing charged today. After your first ' . Founding::TRIAL_DAYS . ' days it is ' . $after . ', until you cancel.'),
    array('icon' => 'check', 'title' => 'Featured Placement', 'text' => 'Founding creators are featured in the creator directory and in the Founding Creators row on the home page.'),
), 2);
echo Sections::close();

echo Sections::open('white', 'What Founding Creators Agree To');
echo Sections::checks(array(
    'Share a short testimonial about your experience. We ask two weeks after your plan starts.',
    'Be featured in the creator directory and on the home page.',
    'Stay listed in the creator directory while you hold the offer.',
));
echo Sections::close();

echo Sections::open('alt', 'The Terms');
echo Sections::checks(array(
    'For accounts starting the ' . $names . ' plan from Free. One spot per account, ' . number_format(Founding::SPOTS) . ' founding spots, open until they are filled.',
    'Your first ' . Founding::TRIAL_DAYS . ' days are free and need no card. The trial comes with ' . Founding::NO_CARD_CREDITS . ' AI credits, and adding a card unlocks the plan\'s full monthly AI credits.',
    'Add a card to keep the plan: ' . $after . ', charged on day ' . (Founding::TRIAL_DAYS + 1) . ' and on that date each month until you cancel. With no card at the end of the trial, your account moves to Free.',
    'Cancel any time from Billing and keep the plan to the end of the paid period.',
    'Founding placement lasts while your paid plan is active or in its past-due grace period. Moving to Free or a lapse ends the founding terms for good.',
    'Payouts go to your bank on request, with a ' . Price::PAYOUT_MIN_LABEL . ' minimum and no platform hold. Earnings from an event are released after the event has taken place.',
));
echo Sections::close();

echo Sections::faq($faq);
