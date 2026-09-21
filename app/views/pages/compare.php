<?php
$e = function ($s) { return Sections::e($s); }; $us = PagesController::our_facts(); $site = Main::site_name();
$labels = array('fee' => 'Platform fee', 'payout' => 'Payouts', 'content' => 'What you can sell', 'socials' => 'Social publishing', 'ai' => 'AI tools', 'ownership' => 'Your audience');
$has_reported = false; foreach ($labels as $k => $l) { if (!empty($c[$k]['reported'])) { $has_reported = true; break; } }
echo Sections::panel_hero(array(
    'title' => $site . ' vs ' . $c['name'],
    'lead' => $c['summary'] . ' Here is how the two compare, with sources.',
    'buttons' => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'panel' => Sections::pane('Platform fee', 'What each platform keeps from your sales.', Sections::pane_versus($site, $us['fee'], $c['name'], $c['fee']['value'], $c['fee']['source'])),
    'bg_image' => SiteImages::url('compare_hero'),
));
echo Sections::open('white', 'At a glance');
echo Sections::cards(array(
    array('icon' => 'bank', 'title' => 'Platform fee', 'text' => $site . ': ' . $us['fee'] . '.', 'note' => $c['name'] . ': ' . $c['fee']['value'] . '.'),
    array('icon' => 'package', 'title' => 'What you can sell', 'text' => $site . ': ' . $us['content'] . '.', 'note' => $c['name'] . ': ' . $c['content']['value'] . '.'),
    array('icon' => 'send', 'title' => 'Social publishing', 'text' => $site . ': ' . $us['socials'] . '.', 'note' => $c['name'] . ': ' . $c['socials']['value'] . '.'),
), 3);
echo Sections::close();

echo Sections::open('alt', 'Side by side', $c['name'] . ' details checked on ' . date('F j, Y', strtotime($c['checked'])) . ' from the linked pages.');
$trows = array();
foreach ($labels as $k => $l) {
    $cell = $e($c[$k]['value']) . ' <a class="sx-src" href="' . $e($c[$k]['source']) . '" rel="nofollow noopener" target="_blank">source</a>' . (!empty($c[$k]['reported']) ? ' <span class="sx-src">reported</span>' : '');
    $trows[] = array($e($l), $e($us[$k]), $cell);
}
echo Sections::table(array('', $site, $c['name']), $trows, 1);
if ($has_reported) { echo '<p class="sx-note">' . $e($c['name']) . ' blocks automated access to its help pages, so figures marked "reported" come from published third-party reporting linked in the table. If something has changed, tell us and we will update it.</p>'; }
echo Sections::close();

echo Sections::open('white', 'Which one fits you');
echo Sections::cards(array(
    array('icon' => 'check', 'title' => 'When ' . $c['name'] . ' is the better fit', 'text' => $c['name'] === 'OnlyFans' ? 'Most of your audience already pays on OnlyFans and you do not plan to sell services, events or bundles. Staying there avoids moving anyone.' : 'You only sell subscriptions and messages and want the lowest setup effort. Fanvue does that well.'),
    array('icon' => 'star', 'title' => 'When ' . $site . ' is the better fit', 'points' => array(
        'You post to several social networks and want one studio that publishes to all of them',
        'You sell more than subscriptions: pay-per-view, bundles, services, events and links',
        'You want AI to draft captions and DM replies in your voice, with your approval',
        'You want a fee that falls as you grow rather than a flat rate')),
), 2);
echo Sections::close();

echo Sections::open('alt', 'Moving over');
echo '<p class="sx-p">Keep your ' . $e($c['name']) . ' page live while you set up. Publish to both from the studio, put your new page in every bio, and let fans move at their own pace. Memberships and pay-per-view work from day one; payouts start as soon as your payout account is verified.</p>';
echo Sections::close();
$cta_title = 'Try it alongside ' . $c['name'] . '.';
