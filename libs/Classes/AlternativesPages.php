<?php
/**
 * List pages /onlyfans-alternatives and /fanvue-alternatives, rendered by PagesController::alternativesAction()
 * through app/views/pages/alternatives.php. Our own entry (always first) is built from config in the controller;
 * the other platforms below are general positioning only (kind of platform, who it suits), never prices, fees or numbers.
 * 'compare' names a PagesController::COMPETITORS slug when a sourced /compare page exists.
 */
class AlternativesPages {

    const PAGES = array(

        'onlyfans' => array(
            'us_text'     => 'One public page at your handle that sells memberships, pay-per-view posts, bundles, services and live events. Unlike a subscription app, it also exports your audience list and publishes every post to nine social networks.',   // our own card on this list, worded for the platform being left
            'name'        => 'OnlyFans',
            'description' => 'OnlyFans alternatives compared: what kind of platform each one is and who it suits, from one-page selling to subscription apps. Updated for {year}.',
            'intro'       => 'An OnlyFans alternative is a platform where a creator sells memberships, pay-per-view and services without a single subscription app in the middle. Creators look for one for one of three reasons. They want to keep more of each sale, sell more than one subscription, or own their audience. Here are the main options and what kind of platform each one is, so you can pick the fit for how you sell.',
            'others' => array(
                array('name' => 'Fansly', 'compare' => 'fansly', 'kind' => 'Subscription platform',
                      'text' => 'Fansly is a subscription platform for creators.',
                      'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'Fanvue', 'compare' => 'fanvue', 'kind' => 'Subscription platform',
                      'text' => 'Fanvue is a subscription platform for creators.',
                      'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'Patreon', 'compare' => 'patreon', 'kind' => 'Membership platform',
                      'text' => 'Patreon is a membership platform for creators.',
                      'best_for' => 'Creators who publish on a regular schedule.'),
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships',
                      'text' => 'Ko-fi is a tipping platform for creators.',
                      'best_for' => 'Artists, writers and streamers.'),
                array('name' => 'LoyalFans', 'compare' => '', 'kind' => 'Subscription platform',
                      'text' => 'LoyalFans is a subscription platform for creators.',
                      'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'JustFor.Fans', 'compare' => '', 'kind' => 'Subscription platform',
                      'text' => 'JustFor.Fans is a subscription platform for creators.',
                      'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'Passes', 'compare' => '', 'kind' => 'Fan platform',
                      'text' => 'Passes is a fan platform for creators.',
                      'best_for' => 'Creators and influencers with a social following.'),
            ),
            'faq' => array(
                array('q' => 'What is the best OnlyFans alternative?', 'a' => 'It depends on what you sell. If you only sell one subscription and your fans are already on a subscription app, another subscription platform is the smallest change. If you also sell pay-per-view, bundles, services or events, {site} puts all of it on one page. The same goes if you want your posts published to your socials.'),
                array('q' => 'Which OnlyFans alternative has the lowest fee?', 'a' => '{site} takes {fee_short}. Other platforms set and change their own rates, so check each one\'s current pricing page before you move. Count card processing too, which is separate almost everywhere. Plan charges and credit purchases on {site} are final and non-refundable.'),
                array('q' => 'Can I keep my OnlyFans page while I try another platform?', 'a' => 'Yes. Run both for a full billing cycle. Build the new page, tell your fans and put the new link in every bio. Let renewals lapse naturally rather than canceling anyone.'),
                array('q' => 'How do payouts work on {site}?', 'a' => 'Every sale lands in one balance, net of your plan\'s fee. You cash out to your bank on request, with a {payout_min} minimum and no platform hold, in every country our payment processor supports.'),
                array('q' => 'Can I sell adult content on {site}?', 'a' => 'Yes, within the content policy. Adult posts are only shown to fans who opt in, and every upload is checked automatically.'),
            ),
        ),

        'fanvue' => array(
            'us_text'     => 'One public page at your handle that sells memberships, pay-per-view posts, bundles, services and live events. It trains an AI character, generates its photos and short videos, and sells them from the same page.',   // our own card on this list, worded for the platform being left
            'name'        => 'Fanvue',
            'description' => 'Fanvue alternatives compared: what kind of platform each one is and who it suits, including options for AI creators. Updated for {year}.',
            'intro'       => 'A Fanvue alternative is a platform where a creator sells memberships, posts and services from one page they control. Creators look for one when they want that page, or an audience they can take with them. Here are the main options and what kind of platform each one is, including where AI creators fit.',
            'others' => array(
                array('name' => 'OnlyFans', 'compare' => 'onlyfans', 'kind' => 'Subscription platform',
                      'text' => 'OnlyFans is a subscription platform for creators.',
                      'best_for' => 'Creators whose fans already use OnlyFans.'),
                array('name' => 'Fansly', 'compare' => 'fansly', 'kind' => 'Subscription platform',
                      'text' => 'Fansly is a subscription platform for creators.',
                      'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'Patreon', 'compare' => 'patreon', 'kind' => 'Membership platform',
                      'text' => 'Patreon is a membership platform for creators.',
                      'best_for' => 'Creators who publish on a regular schedule.'),
                array('name' => 'LoyalFans', 'compare' => '', 'kind' => 'Subscription platform',
                      'text' => 'LoyalFans is a subscription platform for creators.',
                      'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'Passes', 'compare' => '', 'kind' => 'Fan platform',
                      'text' => 'Passes is a fan platform for creators.',
                      'best_for' => 'Creators and influencers with a social following.'),
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships',
                      'text' => 'Ko-fi is a tipping platform for creators.',
                      'best_for' => 'Artists, writers and streamers.'),
                array('name' => 'Stan', 'compare' => 'stan', 'kind' => 'Link-in-bio storefront',
                      'text' => 'Stan is a link-in-bio storefront for creators.',
                      'best_for' => 'Coaches and educators.'),
            ),
            'faq' => array(
                array('q' => 'What is the best Fanvue alternative?', 'a' => 'It depends on what you sell. If your fans only buy a subscription, another subscription app is the smallest change. If you also sell pay-per-view, bundles, services or events, {site} puts all of it on one page. The same goes if you want your posts published to your socials.'),
                array('q' => 'Is there a Fanvue alternative for AI creators?', 'a' => 'Yes. On {site} you can create an AI influencer from photos or a description, then generate photos and short videos of the same person. You sell them as pay-per-view posts, paid messages or membership content. AI media is labeled when it is cross-posted to your socials.'),
                array('q' => 'Which Fanvue alternative has the lowest fee?', 'a' => '{site} takes {fee_short}. Other platforms set and change their own rates, so check each one\'s current pricing page before you move. Count card processing too, which is separate almost everywhere. Plan charges and credit purchases on {site} are final and non-refundable.'),
                array('q' => 'Can I keep posting to Fanvue while I move?', 'a' => 'Yes. {site} can cross-post to Fanvue from the same studio, so you can run both pages while your fans move at their own pace.'),
                array('q' => 'How do payouts work on {site}?', 'a' => 'Every sale lands in one balance, net of your plan\'s fee. You cash out to your bank on request, with a {payout_min} minimum and no platform hold, in every country our payment processor supports.'),
            ),
        ),

        'patreon' => array(
            'us_text'     => 'One public page at your handle that sells membership tiers beside pay-per-view posts, bundles, services and live events. A studio publishes every post to nine social networks, which Patreon leaves to you.',   // our own card on this list, worded for the platform being left
            'name'        => 'Patreon',
            'description' => 'Patreon alternatives compared: memberships, tips, storefronts and one-page selling, with what each platform is and who it suits. Updated for {year}.',
            'intro'       => 'A Patreon alternative is a platform where a creator charges fans for memberships, posts or products outside Patreon. Creators look for one when they want to sell more than a monthly tier, pay a different fee, or publish to their social accounts from the same place. Here are the main options and what kind of platform each one is.',
            'others' => array(
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships', 'text' => 'Ko-fi is a tipping platform for creators.', 'best_for' => 'Artists, writers and streamers.'),
                array('name' => 'Fansly', 'compare' => 'fansly', 'kind' => 'Subscription platform', 'text' => 'Fansly is a subscription platform for creators.', 'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'Fanvue', 'compare' => 'fanvue', 'kind' => 'Subscription platform', 'text' => 'Fanvue is a subscription platform for creators.', 'best_for' => 'Creators who sell subscriptions to fans.'),
                array('name' => 'OnlyFans', 'compare' => 'onlyfans', 'kind' => 'Subscription platform', 'text' => 'OnlyFans is a subscription platform for creators.', 'best_for' => 'Creators whose fans already use OnlyFans.'),
                array('name' => 'Stan Store', 'compare' => 'stan', 'kind' => 'Link-in-bio storefront', 'text' => 'Stan Store is a link-in-bio storefront for creators.', 'best_for' => 'Coaches and educators.'),
                array('name' => 'Beacons', 'compare' => 'beacons', 'kind' => 'Link-in-bio storefront', 'text' => 'Beacons is a link-in-bio storefront for creators.', 'best_for' => 'Creators who do brand partnerships.'),
            ),
            'faq' => array(
                array('q' => 'What is the best Patreon alternative?', 'a' => 'It depends on what you sell. If you only take tips and commissions, a tipping platform is the smallest change. If you also sell pay-per-view posts, bundles, services or event tickets, {site} puts all of it on one page and publishes each post to your social accounts.'),
                array('q' => 'Can I move my Patreon members to another platform?', 'a' => 'You can take the list. Patreon lets creators download their member list, including emails, as a CSV file. Memberships themselves do not transfer, so members join again on the new page.'),
                array('q' => 'Which Patreon alternative has the lowest fee?', 'a' => '{site} takes {fee_short}. Other platforms set and change their own rates, so check each one\'s current pricing page before you move. Count card processing too, which is separate almost everywhere. Plan charges and credit purchases on {site} are final and non-refundable.'),
                array('q' => 'Can I keep my Patreon page while I try another platform?', 'a' => 'Yes. Run both for a full billing cycle. Build the new page, email your members the new address and let Patreon renewals lapse naturally.'),
                array('q' => 'How do payouts work on {site}?', 'a' => 'Every sale lands in one balance, net of your plan\'s fee. You cash out to your bank on request, with a {payout_min} minimum and no platform hold, in every country our payment processor supports.'),
            ),
        ),

        'linktree' => array(
            'us_text'     => 'One public page at your handle that holds your tracked links and takes payment for memberships, pay-per-view posts, bundles, services and live events, so a visitor can buy without leaving it.',   // our own card on this list, worded for the platform being left
            'name'        => 'Linktree',
            'description' => 'Linktree alternatives compared: link pages, storefronts and one-page selling, with what each platform is and who it suits. Updated for {year}.',
            'intro'       => 'A Linktree alternative is a page that holds a creator\'s links and, on most options, also takes payment. Creators look for one when the link page needs to sell memberships, bookings or posts, not only point elsewhere. Here are the main options and what kind of platform each one is.',
            'others' => array(
                array('name' => 'Beacons', 'compare' => 'beacons', 'kind' => 'Link-in-bio storefront', 'text' => 'Beacons is a link-in-bio storefront for creators.', 'best_for' => 'Creators who do brand partnerships.'),
                array('name' => 'Stan Store', 'compare' => 'stan', 'kind' => 'Link-in-bio storefront', 'text' => 'Stan Store is a link-in-bio storefront for creators.', 'best_for' => 'Coaches and educators.'),
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships', 'text' => 'Ko-fi is a tipping platform for creators.', 'best_for' => 'Artists, writers and streamers.'),
                array('name' => 'Patreon', 'compare' => 'patreon', 'kind' => 'Membership platform', 'text' => 'Patreon is a membership platform for creators.', 'best_for' => 'Creators who publish on a regular schedule.'),
            ),
            'faq' => array(
                array('q' => 'What is the best Linktree alternative?', 'a' => 'It depends on what the page has to do. If it only lists links, any link page works. If it has to take payment for memberships, pay-per-view posts, bookings or event tickets, {site} sells all of them from the same page that holds your links.'),
                array('q' => 'Can a link in bio page take payments?', 'a' => 'Yes. On {site} the page at your handle holds tracked links beside memberships, pay-per-view posts, bundles, services and events, so a fan can pay without leaving it.'),
                array('q' => 'Can I export my Linktree audience?', 'a' => 'Yes. Linktree lets creators export audience contacts as a CSV file from the Audience tab on all plans.'),
                array('q' => 'Which Linktree alternative has the lowest fee?', 'a' => '{site} takes {fee_short}. Other platforms set and change their own rates, so check each one\'s current pricing page before you move. Count card processing too, which is separate almost everywhere. Plan charges and credit purchases on {site} are final and non-refundable.'),
                array('q' => 'How do payouts work on {site}?', 'a' => 'Every sale lands in one balance, net of your plan\'s fee. You cash out to your bank on request, with a {payout_min} minimum and no platform hold, in every country our payment processor supports.'),
            ),
        ),
    );
}
