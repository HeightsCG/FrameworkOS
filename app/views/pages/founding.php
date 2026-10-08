<?php
/* /founding: the founding creator offer (Founding). Prices and fees come from PlanTiers, never typed. $left = spots left (cached 60 s). */
$c      = PlanTiers::get(Founding::PLAN);
$studio = PlanTiers::get(Founding::FEE_FROM);
$price  = '$' . number_format((int) $c['price']);
$fee    = Founding::fee_label();
$own    = (int) $c['limits']['fee_percent'] . '%';
$open   = (int) $left > 0;

echo Sections::panel_hero(array(
    'title'   => 'Become a Founding Creator',
    'lead'    => Founding::SPOTS . ' founding spots, open until they are filled: start the ' . $c['name'] . ' plan with your first month free and keep the ' . $studio['name'] . ' platform fee of ' . $fee . ', instead of ' . $own . ', for as long as your ' . $c['name'] . ' plan stays active.',
    'buttons' => $open ? array(array('Claim a Founding Spot', $claim_url, 'primary', $claim_auth), array('See Pricing', '/pricing', 'secondary'))
                       : array(array('See Pricing', '/pricing', 'primary')),
    'panel'   => Sections::pane($open ? 'Founding Spots Left' : 'All Founding Spots Are Taken', '', Sections::pane_rows(array(
        array('Spots left', number_format((int) $left) . ' of ' . number_format(Founding::SPOTS), true),
        array('First billing period on ' . $c['name'], 'Free'),
        array('Platform fee', $fee . ' while on ' . $c['name']),
        array('After the free period', $price . ' / month'),
    ))),
));

echo Sections::open('white', 'What You Get');
echo Sections::cards(array(
    array('icon' => 'check', 'title' => 'First Billing Period Free', 'text' => 'Start the ' . $c['name'] . ' plan with nothing charged today. After your first billing period it is ' . $price . ' a month until you cancel.'),
    array('icon' => 'check', 'title' => 'The ' . $studio['name'] . ' Fee on ' . $c['name'], 'text' => 'Your platform fee is locked at ' . $fee . ' instead of ' . $own . ' for as long as your ' . $c['name'] . ' plan stays active, with payouts to your bank.'),
    array('icon' => 'check', 'title' => 'Featured Placement', 'text' => 'Founding creators are featured in the creator directory and in the Founding Creators row on the home page.'),
), 3);
echo Sections::close();

echo Sections::open('alt', 'What Founding Creators Agree To');
echo Sections::checks(array(
    'Share a short testimonial about your experience. We ask two weeks after your plan starts.',
    'Be featured in the creator directory and on the home page.',
    'Stay listed in the creator directory while you hold the offer.',
));
echo Sections::close();

echo Sections::open('white', 'The Terms');
echo Sections::checks(array(
    'For accounts starting the ' . $c['name'] . ' plan from Free. One spot per account, ' . number_format(Founding::SPOTS) . ' founding spots, open until they are filled.',
    'Your first billing period is free. Then ' . $price . ' a month, charged to your card on the same date each month until you cancel.',
    'Plan charges are final and non-refundable. Cancel any time from Billing and keep the plan to the end of the paid period.',
    'The ' . $fee . ' fee lasts while your ' . $c['name'] . ' plan is active or in its past-due grace period. Changing plans, moving to Free or a lapse ends the founding terms for good.',
    'Payouts go to your bank on request, with a ' . Price::PAYOUT_MIN_LABEL . ' minimum and no platform hold.',
));
echo Sections::close();

echo Sections::faq($faq);
