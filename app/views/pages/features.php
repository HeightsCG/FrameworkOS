<?php
/**
 * Features page (redesigned 2026-10-12): the creator's working loop told in order, post to bank, with a table of what fans buy,
 * prose for each part of the product, the fee on real figures, and a list of the detailed feature pages. No columns of bullets.
 * Every fact comes from config (PagesController, Price, PlanTiers) or the quotable facts; nothing invented.
 */
$site = Main::site_name(); $e = function ($s) { return Sections::e($s); };
$facts = PagesController::quotable_facts();
$fee = PagesController::fee_short(); $min = Price::PAYOUT_MIN_LABEL;

/* The six jump links under the hero (pointing at the sections below). */
$jumps = array(array('loop', 'Your Page', 'layers'), array('sell', 'Get Paid', 'wallet'), array('publish', 'Publishing', 'send'), array('inbox', 'Inbox', 'inbox'), array('money', 'Analytics', 'chart'), array('payouts', 'Payouts', 'bank'));
$jump = '<nav class="sx-jump" aria-label="Feature sections">';
foreach ($jumps as $j) { $jump .= '<a href="#' . $e($j[0]) . '">' . Sections::icon($j[2], 20) . '<span>' . $e($j[1]) . '</span></a>'; }
$jump .= '</nav>';
// 2026-10-15: the approved home hero (Sections::premium_hero), same H1 sentence split into a white and an orange line, its own portrait.
echo Sections::premium_hero(array(
    'title'     => 'Everything a creator sells,',
    'highlight' => 'From one page.',
    'lead'      => $site . ' is one public page for memberships, pay-per-view, bundles, services and events, with a studio that publishes to your socials and payouts to your bank behind it.',
    'points'    => array(array('layers', 'Memberships, PPV & Bundles'), array('send', 'Publishing to Ten Platforms'), array('chart', 'Inbox, Analytics & Payouts')),
    'buttons'   => array(array('Get Started', '/?auth=register', 'primary', 'register'), array('See Pricing', '/pricing', 'secondary')),
    'photo'     => 'featport',
));
echo '<div class="pm-jumpbar"><div class="ld-wrap">' . $jump . '</div></div>';   // the six jump links as a slim bar under the hero, not boxes over the photo

/* The spine of the page: one post, followed from upload to the bank, as a diagram built from the product's own steps. */
echo Sections::open('white', 'How does a post turn into money?', 'The same four steps for every creator, whatever you sell.', 'loop');
echo '<ol class="fx-steps">'
    . '<li><h3>Upload once</h3><p>Add a photo or a video in the studio. AI drafts the caption in your voice and trims it for each network.</p></li>'
    . '<li><h3>Publish everywhere</h3><p>The same post goes to your page and to the ten platforms you connect, now, on a schedule, or by an automation.</p></li>'
    . '<li><h3>Fans pay on your page</h3><p>They follow for free, join a tier, unlock a post, buy a bundle or book a session, from one wallet they load once.</p></li>'
    . '<li class="is-last"><h3>Cash out</h3><p>Every sale lands in one balance net of your plan\'s platform fee, ' . $e($fee) . '. Payouts go to your bank on request from ' . $e($min) . '.</p></li>'
    . '</ol>';
echo Sections::close();

/* What fans can buy, as a table a reader uses: how each thing is paid for, what the fan gets, and when the money is yours. */
echo Sections::open('alt', 'What can fans buy from your page?', 'Six ways to be paid, all on the one page at your handle.', 'sell');
echo Sections::table(array('What you sell', 'How fans pay', 'What the fan gets', 'When it reaches your balance'), array(
    array($e('Membership tiers'), $e('By card, on the tier\'s billing interval, with a free trial if you set one.'), $e('Every post you target at that tier, for as long as they stay.'), $e('Each billing, net of the platform fee.')),
    array($e('Pay-per-view posts'), $e('From the credit wallet, in one tap.'), $e('That post, unblurred, kept in their purchases.'), $e('At the unlock.')),
    array($e('Bundles'), $e('From the credit wallet.'), $e('A set of media from your library, saved to their purchases.'), $e('At the purchase.')),
    array($e('Services'), $e('From the credit wallet when they book.'), $e('The booking, with access details shared after purchase.'), $e('At the booking.')),
    array($e('Events and 1:1 calls'), $e('From the credit wallet for a ticket or a session.'), $e('A seat in the built-in video room, with a waiting room, chat and screen share.'), $e('After the event has taken place.')),
    array($e('Paid messages'), $e('From the credit wallet, inside the conversation.'), $e('The attached media, unblurred.'), $e('At the unlock.')),
));
echo '<p class="sx-note">Fans load the wallet once, at ' . $e((string) (int) Price::CREDITS_PER_DOLLAR) . ' credits to the dollar, and spend it across every creator. Memberships are the one thing billed by card.</p>';
echo Sections::close();

/* Publishing, inbox, analytics: the home's split sections (2026-10-15). The question is the H2 beside the schematic (Sections::ui,
   invented values, never a screenshot); the answer keeps its H3 and full text. Backgrounds alternate white, alt, white. */
$split = function ($bg, $id, $h2, $lead, $h3, $text, $link, $ui, $rev) use ($e) {
    $copy = '<div class="sx-split__copy"><h2 class="sx-h2">' . $e(Sections::tc($h2)) . '</h2>' . ($lead !== '' ? '<p class="sx-lead">' . $e($lead) . '</p>' : '')
        . '<h3 class="sx-h3 fx-split__h3">' . $e(Sections::tc($h3)) . '</h3>' . Sections::paragraphs($text, 'sx-p') . '<a class="sx-more" href="' . $e($link[1]) . '">' . $e($link[0]) . '</a></div>';
    return Sections::open($bg, '', '', $id) . '<div class="sx-split' . ($rev ? ' sx-split--rev' : '') . '">' . ($rev ? Sections::ui($ui) . $copy : $copy . Sections::ui($ui)) . '</div>' . Sections::close();
};
echo $split('white', 'publish', 'How does publishing work?', '', 'One upload, every network',
    'Upload a photo or a video once and the studio posts it to your page and to X, Instagram, TikTok, Facebook, LinkedIn, Pinterest, YouTube, Threads and Bluesky, plus Fanvue. AI writes the caption in your brand voice and trims it to each network\'s length. Keep a draft, schedule it for a time, or let an automation publish on the rhythm you set. Engagement from every network comes back into one analytics view.',
    array('See publishing', '/features/publishing'), 'targets', false);
echo $split('alt', 'inbox', 'What happens in the inbox?', 'A conversation that sells, and a list that stays yours.', 'One inbox, replies in your voice',
    'Every fan message lands in one inbox, whichever network the fan came from. The DM agent drafts a reply in your voice and waits for your approval, or sends on its own once you let it. A welcome message can greet every new fan, with media and a price attached if you want, and a broadcast reaches one segment or all of them at once. Behind the inbox sits your audience list, followers, subscribers and buyers with tags and notes, exportable whenever you want it.',
    array('See the DM agent', '/features/dm-agent'), 'inbox', true);
echo $split('white', 'money', 'What do you see once money moves?', 'Numbers first, then the money.', 'One dashboard for revenue, content and audience',
    'The dashboard shows revenue, content, audience and customers for the last 7, 30 or 90 days. Every post carries its views, shares and conversion, every link on your page its clicks, and your social posts bring their engagement into the same view. Team seats let collaborators work on your account while every sale stays in your balance, and the Claude connector runs posts, messages and analytics from a chat.',
    array('See analytics in the dashboard', '/features/analytics'), 'analytics', false);

/* Payouts: the facts, in prose, as the jump target. */
echo Sections::prose_id('alt', 'How do payouts work?', 'payouts', array(
    'Every sale lands in one balance, net of your plan\'s platform fee, which is ' . $fee . '. Memberships bill the fan\'s card; unlocks, bundles, services and tickets are paid from the fan\'s credit wallet. Earnings from an event are released after the event has taken place.',
    'You cash out to your bank on request from a ' . $min . ' balance, with no platform hold on content, membership or service sales. Earnings from an event are released after the event has taken place. Payouts are available in every country our payment processor supports, and your audience list, media and posts export whenever you want them.',
), 'One balance, paid to your bank on request.');

/* The detailed pages, as a plain list a reader scans. */
$guides = array();
foreach (PagesController::feature_pages() as $f_slug => $f) { $guides[] = array('/features/' . $f_slug, (string) ($f['nav_title'] ?? $f['title']), (string) $f['description']); }
foreach (PagesController::root_feature_pages() as $f_slug => $f) { $guides[] = array('/' . $f_slug, (string) ($f['nav_title'] ?? $f['title']), (string) $f['description']); }
echo Sections::open('white', 'Where is each part explained in full?', 'One page per part of ' . $site . ', each with its own limits, prices and questions.', 'guides');
echo '<dl class="fx-list">';
foreach ($guides as $g) { echo '<div class="fx-list__row"><dt><a href="' . $e($g[0]) . '">' . $e(Sections::tc($g[1])) . '</a></dt><dd>' . $e(Sections::cap($g[2], 2)[0]) . '</dd></div>'; }
echo '</dl>';
echo Sections::close();

echo Sections::faq($faq);
$cta_title = 'Create your page in a few minutes.';
$cta_photo = 'features';   // the closing band's photo, like the home's final band
