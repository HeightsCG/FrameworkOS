<?php
/* /affiliates: the affiliate program (Affiliates). Rate, cookie days and payout minimum come from code, plan names from PlanTiers; no earnings projections. */
$rate = Affiliates::RATE_PERCENT . '%';

echo Sections::panel_hero(array(
    'title'   => 'Earn ' . $rate . ' Referring Creators',
    'lead'    => 'Share your link. When someone you refer pays for a ' . $plans . ' plan, you earn ' . $rate . ' of what they pay, on every paid invoice for as long as they stay on a paid plan.',
    'buttons' => array(array($apply_label, $apply_url, 'primary'), array('See Pricing', '/pricing', 'secondary')),
    'panel'   => Sections::pane('The Program', '', Sections::pane_rows(array(
        array('Commission', $rate . ' of plan payments', true),
        array('Paid on', $plans . ' plan invoices'),
        array('Link window', Affiliates::DAYS . ' days, last link wins'),
        array('Payouts', 'On request, from ' . Price::PAYOUT_MIN_LABEL),
    ))),
));

echo Sections::open('white', 'How It Works');
echo Sections::cards(array(
    array('icon' => 'check', 'title' => 'Apply', 'text' => 'Tell us where you will share your link. Our team reviews every application, and you get your link once you are approved.'),
    array('icon' => 'check', 'title' => 'Share Your Link', 'text' => 'Anyone who opens your link and creates an account within ' . Affiliates::DAYS . ' days is counted as your referral.'),
    array('icon' => 'check', 'title' => 'Earn on Every Paid Invoice', 'text' => 'You earn ' . $rate . ' of what they pay for their ' . $plans . ' plan, each time they pay, while they stay on it.'),
), 3);
echo Sections::close();

echo Sections::open('alt', 'The Terms');
echo Sections::checks(array(
    $rate . ' of each paid ' . $plans . ' plan invoice from accounts you refer, for as long as they stay active and paid.',
    'Nothing on credit packs or AI credit purchases.',
    'A payment disputed with the bank reverses its commission, including one already paid out.',
    'Your own account never earns a commission on itself.',
    'Payouts to your bank on request, once you have ' . Price::PAYOUT_MIN_LABEL . ' earned.',
    'Anyone can apply, creators included. Accounts stay pending until our team approves them.',
));
echo Sections::close();

echo Sections::faq($faq);
