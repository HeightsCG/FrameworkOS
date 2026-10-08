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
            'name'        => 'OnlyFans',
            'description' => 'OnlyFans alternatives compared: what kind of platform each one is and who it suits, from one-page selling to subscription apps. Updated for {year}.',
            'intro'       => 'Most creators look for an OnlyFans alternative for one of three reasons: they want to keep more of each sale, sell more than one subscription, or own their audience. Here are the main options and what kind of platform each one is, so you can pick the fit for how you sell.',
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
                array('q' => 'What is the best OnlyFans alternative?', 'a' => 'It depends on what you sell. If you only sell one subscription and your fans are already on a subscription app, another subscription platform is the smallest change. If you also sell pay-per-view, bundles, services or events, or want your posts published to your socials, {site} puts all of it on one page.'),
                array('q' => 'Which OnlyFans alternative has the lowest fee?', 'a' => '{site} takes {fee_short}. Other platforms set and change their own rates, so check each one\'s current pricing page before you move, and count card processing, which is separate almost everywhere. Plan charges and credit purchases on {site} are final and non-refundable.'),
                array('q' => 'Can I keep my OnlyFans page while I try another platform?', 'a' => 'Yes. Most creators run both for a full billing cycle: build the new page, tell your fans, put the new link in every bio, and let renewals lapse naturally rather than canceling anyone.'),
                array('q' => 'How do payouts work on {site}?', 'a' => 'Every sale lands in one balance, net of your plan\'s fee. You cash out to your bank on request, with a {payout_min} minimum and no platform hold, in every country our payment processor supports.'),
                array('q' => 'Can I sell adult content on {site}?', 'a' => 'Yes, within the content policy. Adult posts are only shown to fans who opt in, and every upload is checked automatically.'),
            ),
        ),

        'fanvue' => array(
            'name'        => 'Fanvue',
            'description' => 'Fanvue alternatives compared: what kind of platform each one is and who it suits, including options for AI creators. Updated for {year}.',
            'intro'       => 'Creators look for a Fanvue alternative when they want one page for memberships, posts and services, or an audience they can take with them. Here are the main options and what kind of platform each one is, including where AI creators fit.',
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
                array('q' => 'What is the best Fanvue alternative?', 'a' => 'It depends on what you sell. If your fans only buy a subscription, another subscription app is the smallest change. If you also sell pay-per-view, bundles, services or events, or want your posts published to your socials, {site} puts all of it on one page.'),
                array('q' => 'Is there a Fanvue alternative for AI creators?', 'a' => 'Yes. On {site} you can create an AI influencer from photos or a description, generate photos and short videos of the same person, and sell them as pay-per-view posts, paid messages or membership content. AI media is labeled when it is cross-posted to your socials.'),
                array('q' => 'Which Fanvue alternative has the lowest fee?', 'a' => '{site} takes {fee_short}. Other platforms set and change their own rates, so check each one\'s current pricing page before you move, and count card processing, which is separate almost everywhere. Plan charges and credit purchases on {site} are final and non-refundable.'),
                array('q' => 'Can I keep posting to Fanvue while I move?', 'a' => 'Yes. {site} can cross-post to Fanvue from the same studio, so you can run both pages while your fans move at their own pace.'),
                array('q' => 'How do payouts work on {site}?', 'a' => 'Every sale lands in one balance, net of your plan\'s fee. You cash out to your bank on request, with a {payout_min} minimum and no platform hold, in every country our payment processor supports.'),
            ),
        ),
    );
}
