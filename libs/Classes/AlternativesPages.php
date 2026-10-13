<?php
/**
 * List pages /onlyfans-alternatives and /fanvue-alternatives, rendered by PagesController::alternativesAction()
 * through app/views/pages/alternatives.php. Our own entry (always first) is built from config in the controller;
 * each other platform carries one paragraph written for that page's reader: why someone leaving the page's platform would pick
 * it, and its limits for that person (Daniel 2026-10-12). Figures stay out of these paragraphs; the sourced facts are shared data,
 * read from PagesController::COMPETITORS at render.
 * 'compare' names a PagesController::COMPETITORS slug when a sourced /compare page exists.
 */
class AlternativesPages {

    /**
     * Sourced records for listed platforms that have no /compare page: each fact was read on the platform's own page on the
     * 'checked' date and links to it. A platform with neither a compare record nor an entry here is not listed (2026-10-13:
     * LoyalFans and JustFor.Fans were removed, because their terms could not be read from their own pages).
     */
    const RECORDS = array(
        'Buy Me a Coffee' => array('checked' => '2026-10-13', 'facts' => array(
            array('Charges a 5% transaction fee on what supporters pay.', 'https://help.buymeacoffee.com/en/articles/4539170-frequently-asked-questions'),
            array('Your supporter list is yours and exports at any time.', 'https://help.buymeacoffee.com/en/articles/4539170-frequently-asked-questions'),
            array('A payout can be requested once the balance reaches $10.', 'https://help.buymeacoffee.com/en/articles/6364717-when-are-payouts-processed'),
            array('Its terms prohibit nudity and sexual content.', 'https://buymeacoffee.com/terms'),
        )),
        'Memberful' => array('checked' => '2026-10-13', 'facts' => array(
            array('Takes a 4.9% transaction fee on each purchase or renewal; its monthly price is a separate line.', 'https://memberful.com/pricing/'),
            array('Payments reach your bank through your own payment processor account.', 'https://memberful.com/docs/overview/getting-started/payment-processing'),
            array('Members export as a CSV file from the Members tab.', 'https://memberful.com/docs/metrics-and-analytics/compare-and-export/export-data'),
        )),
        'Substack' => array('checked' => '2026-10-13', 'facts' => array(
            array('Takes a 10% share of paid subscription revenue, and nothing while a publication is free.', 'https://substack.com/features'),
            array('Posts and the complete subscriber list export at any time.', 'https://substack.com/features'),
            array('Does not allow porn; erotic literature and nudity for artistic or journalistic purposes are allowed.', 'https://substack.com/content'),
        )),
        'Gumroad' => array('checked' => '2026-10-13', 'facts' => array(
            array('Charges 10% and $0.50 per sale from your own links, 30% when a buyer finds you through its Discover marketplace, and no monthly fee.', 'https://gumroad.com/pricing'),
            array('Customers and their purchases download as a CSV file.', 'https://gumroad.com/help/article/268-customer-dashboard'),
            array('A payout needs a balance of at least $100.', 'https://gumroad.com/help/article/13-getting-paid'),
            array('Does not allow sales of sexually explicit content.', 'https://gumroad.com/help/article/156-gumroad-and-adult-content'),
        )),
        'Podia' => array('checked' => '2026-10-13', 'facts' => array(
            array('The entry plan takes a 5% transaction fee and the two higher plans take none; plan prices are a separate monthly line.', 'https://www.podia.com/pricing'),
            array('Your contacts export as a CSV file sent by email.', 'https://help.podia.com/en/articles/366-exporting-your-audience-customer-list'),
            array('Its terms prohibit nudity and sexual content.', 'https://www.podia.com/terms'),
        )),
        'Ghost' => array('checked' => '2026-10-13', 'facts' => array(
            array('Charges a plan price and no transaction fee of its own; payment processor fees still apply.', 'https://ghost.org/pricing/'),
            array('Member data imports and exports as CSV at any time.', 'https://ghost.org/pricing/'),
            array('Revenue is paid to you directly by your payment processor.', 'https://ghost.org/integrations/stripe/'),
        )),
        'Carrd' => array('checked' => '2026-10-13', 'facts' => array(
            array('Takes payment only through payment-enabled forms and embedded third-party widgets, on its paid plans.', 'https://carrd.com/pro'),
            array('Publishes to custom domains you own on its paid plans.', 'https://carrd.com/pro'),
            array('Site analytics come from third-party tools you connect.', 'https://carrd.com/pro'),
        )),
        'Hopp by Wix' => array('checked' => '2026-10-13', 'facts' => array(
            array('Sells digital products and services, collects tips, and takes bookings on the page.', 'https://www.hopp.co/'),
            array('States a 0% transaction fee on every plan.', 'https://www.hopp.co/hopp-vs-linktree'),
            array('Runs on your own URL.', 'https://www.hopp.co/'),
        )),
        'Milkshake' => array('checked' => '2026-10-13', 'facts' => array(
            array('Takes a fee on each sale: 12% on Free and Lite, 9% on Pro and 7% on Pro+, before card processing.', 'https://milkshake.app/pricing/'),
            array('A custom domain is on its Pro+ plan.', 'https://milkshake.app/pricing/'),
            array('Insights data exports on its Pro plan.', 'https://milkshake.app/pricing/'),
        )),
        'Campsite.bio' => array('checked' => '2026-10-13', 'facts' => array(
            array('Collects tips and takes paid requests from followers on every plan.', 'https://campsite.bio/pricing'),
            array('Its transaction fee is shown as 0% for a limited time.', 'https://campsite.bio/pricing'),
            array('Your own domain and in-depth analytics start on its Pro plan.', 'https://campsite.bio/pricing'),
        )),
        'Later' => array('checked' => '2026-10-13', 'facts' => array(
            array('Its link page sends followers to your website or online shop.', 'https://later.com/link-in-bio/'),
            array('Link in Bio analytics are part of its paid scheduling plans, which start with a 14-day free trial.', 'https://later.com/pricing/'),
        )),
        'Passes' => array('checked' => '2026-10-13', 'facts' => array(
            array('Safe-for-work only: its guidelines do not allow explicit adult content, nudity or pornography.', 'https://www.passes.com/guidelines'),
            array('Sells exclusive content, livestreams, membership tiers, one-to-one video calls, paid messages and digital products.', 'https://www.passes.com/faq'),
            array('Its terms state a 20% fee on card sales, while its home page advertises 10%.', 'https://www.passes.com/terms'),
            array('Its terms pay sale proceeds every two weeks, by bank transfer.', 'https://www.passes.com/terms'),
        )),
    );

    const PAGES = array(

        'onlyfans' => array(
            'us_points'   => array('Sells pay-per-view posts, bundles, services and event tickets beside the subscription.', 'Your audience list and your media export whenever you want them.', 'One post goes to your page and to nine social networks together.'),   // our card on this list: three points chosen for this reader
            'us_best'     => 'a creator who sells more than one subscription and wants to own the audience list.',
            'us_text'     => 'One public page at your handle that sells memberships, pay-per-view posts, bundles, services and live events. Unlike a subscription app, it also exports your audience list and publishes every post to nine social networks.',   // our own card on this list, worded for the platform being left
            'name'        => 'OnlyFans',
            'description' => 'OnlyFans alternatives compared: what kind of platform each one is and who it suits, from one-page selling to subscription apps. Updated for {year}.',
            'intro'       => 'An OnlyFans alternative is a platform where a creator sells memberships, pay-per-view and services without a single subscription app in the middle. Creators look for one for one of three reasons. They want to keep more of each sale, sell more than one subscription, or own their audience. Here are the main options and what kind of platform each one is, so you can pick the fit for how you sell.',
            'others' => array(
                array('name' => 'Fansly', 'compare' => 'fansly', 'kind' => 'Subscription platform',
                      'text' => 'For a creator leaving OnlyFans, Fansly is the closest match: the same subscription model, with a feed that shows your posts to new viewers. Its limit is that the move changes little else. It keeps the same share of each sale, and it publishes no export of your fan list either.'),
                array('name' => 'Fanvue', 'compare' => 'fanvue', 'kind' => 'Subscription platform',
                      'text' => 'Fanvue suits an OnlyFans creator who wants the same subscription model with AI tools on the fan side, such as AI voice calls and voice notes. The share it keeps matches the one you pay now, so the move does not change what you keep. It does not publish whether you can take your fan list with you.'),
                array('name' => 'Patreon', 'compare' => 'patreon', 'kind' => 'Membership platform',
                      'text' => 'Patreon fits an OnlyFans creator who publishes on a schedule and wants paid tiers with community chats. You gain a member list you can download, emails included. Its limit is the format: it is built around tiers and one-time digital products.'),
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships',
                      'text' => 'Ko-fi suits a creator leaving OnlyFans for tips, commissions and a small shop. It pays straight into your own payment account, with no payout schedule to wait on. Its limit is reach: it publishes nothing about posting to social accounts, so promotion stays manual.'),
                array('name' => 'Passes', 'compare' => '', 'kind' => 'Safe-for-work memberships',
                      'text' => 'Passes sells memberships, livestreams, paid messages and video calls, so the formats will feel familiar to an OnlyFans creator. Its own rules allow safe-for-work content only, which rules it out for adult creators. Read its fee terms closely, because its pages state more than one rate.'),
            ),
            'faq' => array(
                array('q' => 'What is the best OnlyFans alternative?', 'a' => 'It depends on what you sell. If you only sell one subscription and your fans are already on a subscription app, another subscription platform is the smallest change. If you also sell pay-per-view, bundles, services or events, {site} puts all of it on one page. The same goes if you want your posts published to your socials.'),
                array('q' => 'Which OnlyFans alternative has the lowest fee?', 'a' => '{site}\'s platform fee is {fee_short}, against the share OnlyFans deducts from each fan payment. The other platforms on this list set their own rates, so read each pricing page before you move.'),
                array('q' => 'Can I keep my OnlyFans page while I try another platform?', 'a' => 'Yes. Run both for a full billing cycle. Build the new page, tell your fans and put the new link in every bio. Let renewals lapse naturally rather than canceling anyone.'),
                array('q' => 'How do payouts work after leaving OnlyFans?', 'a' => 'On {site} each sale is added to a single balance after your plan\'s platform fee. Once it passes {payout_min} you can send it to your bank whenever you ask, and the platform holds nothing back. Ticket earnings from an event are the exception: they are released after the event.'),
                array('q' => 'Can I sell adult content on {site}?', 'a' => 'Yes, within the content policy. Adult posts are only shown to fans who opt in, and every upload is checked automatically.'),
            ),
        ),

        'fanvue' => array(
            'us_points'   => array('Trains an AI character and generates its photos and short videos in the same account.', 'A DM agent drafts replies in the character\'s voice for you to approve.', 'Cross-posts to Fanvue, so the old page stays fed while fans move.'),   // our card on this list: three points chosen for this reader
            'us_best'     => 'a creator who wants the character, the content and the sales in one account.',
            'us_text'     => 'One public page at your handle that sells memberships, pay-per-view posts, bundles, services and live events. It trains an AI character, generates its photos and short videos, and sells them from the same page.',   // our own card on this list, worded for the platform being left
            'name'        => 'Fanvue',
            'description' => 'Fanvue alternatives compared: what kind of platform each one is and who it suits, including options for AI creators. Updated for {year}.',
            'intro'       => 'A Fanvue alternative is a platform where a creator sells memberships, posts and services from one page they control. Creators look for one when they want that page, or an audience they can take with them. Here are the main options and what kind of platform each one is, including where AI creators fit.',
            'others' => array(
                array('name' => 'OnlyFans', 'compare' => 'onlyfans', 'kind' => 'Subscription platform',
                      'text' => 'Moving from Fanvue to OnlyFans keeps the subscription model and gains the largest audience of any option on this list. It does not solve ownership, because OnlyFans publishes no export of your subscriber list. The share of each sale is the same as you pay on Fanvue.'),
                array('name' => 'Fansly', 'compare' => 'fansly', 'kind' => 'Subscription platform',
                      'text' => 'Fansly gives a Fanvue creator the same model with a discovery feed and ticketed livestreams. The share it keeps is the same, and no fan export is published. Choose it for reach inside the app, not for ownership.'),
                array('name' => 'Patreon', 'compare' => 'patreon', 'kind' => 'Membership platform',
                      'text' => 'Patreon answers the ownership question a Fanvue creator usually asks: the member list downloads as a file, emails included. It suits regular publishing in tiers. Check its content rules before moving an AI character page.'),
                array('name' => 'Passes', 'compare' => '', 'kind' => 'Safe-for-work memberships',
                      'text' => 'Passes gives a Fanvue creator membership tiers, paid messages and one-to-one video calls. It is safe-for-work only by its own rules, and its guidelines say nothing general about AI characters. Check which fee rate applies to you before moving.'),
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships',
                      'text' => 'Ko-fi fits a Fanvue creator who wants tips, commissions and a shop beside a membership. Supporter lists download as files, and money arrives in your own payment account at once. Its limit is promotion, since it publishes nothing about posting to social accounts.'),
                array('name' => 'Stan Store', 'compare' => 'stan', 'kind' => 'Link-in-bio storefront',
                      'text' => 'Stan Store suits a Fanvue creator moving toward courses, bookings and digital products. It charges a flat monthly price and no share of sales. It is a storefront, not a fan feed, so subscribers who came for posts need a new habit.'),
            ),
            'faq' => array(
                array('q' => 'What is the best Fanvue alternative?', 'a' => 'That turns on what your fans buy. If your fans only buy a subscription, another subscription app is the smallest change. If you also sell pay-per-view, bundles, services or events, {site} puts all of it on one page. The same goes if you want your posts published to your socials.'),
                array('q' => 'Is there a Fanvue alternative for AI creators?', 'a' => 'Yes. On {site} you can create an AI influencer from photos or a description, then generate photos and short videos of the same person. You sell them as pay-per-view posts, paid messages or membership content. AI media is labeled when it is cross-posted to your socials.'),
                array('q' => 'Which Fanvue alternative has the lowest fee?', 'a' => 'Fanvue keeps a share of gross revenue, and {site}\'s platform fee is {fee_short}. Rates elsewhere move, so look at each platform\'s pricing page on the day you decide. Card processing comes on top on nearly all of them.'),
                array('q' => 'Can I keep posting to Fanvue while I move?', 'a' => 'Yes. {site} can cross-post to Fanvue from the same studio, so you can run both pages while your fans move at their own pace.'),
                array('q' => 'How do I get paid on {site} after Fanvue?', 'a' => 'Memberships, paid posts and paid messages all collect in the same balance. When it is above {payout_min} you request a payout, and it goes to your bank with no holding period from us. Event tickets are the one exception, released after the event.'),
            ),
        ),

        'patreon' => array(
            'us_points'   => array('Membership tiers with weekly, monthly or yearly billing and optional free trials.', 'Pay-per-view posts and bundles for fans who will never join a tier.', 'Bookings and ticketed live sessions in a built-in video room.'),   // our card on this list: three points chosen for this reader
            'us_best'     => 'a creator whose members would also buy single posts, bundles or sessions.',
            'us_text'     => 'One public page at your handle that sells membership tiers beside pay-per-view posts, bundles, services and live events. A studio publishes every post to nine social networks, which Patreon leaves to you.',   // our own card on this list, worded for the platform being left
            'name'        => 'Patreon',
            'description' => 'Patreon alternatives compared: memberships, tips, storefronts and one-page selling, with what each platform is and who it suits. Updated for {year}.',
            'intro'       => 'A Patreon alternative is a platform where a creator charges fans for memberships, posts or products outside Patreon. Creators look for one when they want to sell more than a monthly tier, pay a different fee, or publish to their social accounts from the same place. Here are the main options and what kind of platform each one is.',
            'others' => array(
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships',
                      'text' => 'Ko-fi is the smallest step away from Patreon: memberships still work, and one-off tips and commissions sit beside them. Money goes into your own payment account straight away. Like Patreon, it leaves posting to your social accounts to you.'),
                array('name' => 'Fansly', 'compare' => 'fansly', 'kind' => 'Subscription platform',
                      'text' => 'Fansly suits a Patreon creator whose audience wants a subscription feed with direct messages and livestreams. You give up the member export Patreon offers, since Fansly publishes none. It also keeps a larger share of each sale than Patreon\'s standard rate.'),
                array('name' => 'Fanvue', 'compare' => 'fanvue', 'kind' => 'Subscription platform',
                      'text' => 'Fanvue suits a Patreon creator building an AI character, because it welcomes AI creator accounts and sells AI voice notes to fans. It keeps a larger share of each sale than Patreon does. It does not publish whether the fan list exports, which Patreon does.'),
                array('name' => 'OnlyFans', 'compare' => 'onlyfans', 'kind' => 'Subscription platform',
                      'text' => 'OnlyFans gives a Patreon creator pay-per-view and the largest subscription audience. It keeps a larger share of each sale than Patreon and publishes no subscriber export. Move there for the audience, not for the terms.'),
                array('name' => 'Stan Store', 'compare' => 'stan', 'kind' => 'Link-in-bio storefront',
                      'text' => 'Stan Store fits a Patreon creator whose members really want courses, bookings and downloads. It charges a flat monthly price with no share of sales, and customer data downloads as a file. It is a storefront first, so a member used to a feed of posts finds a shop.'),
                array('name' => 'Beacons', 'compare' => 'beacons', 'kind' => 'Link-in-bio storefront',
                      'text' => 'Beacons suits a Patreon creator who also does brand deals: a store, email marketing and a media kit sit in one tool. Memberships are on its higher plans only. Its lower plans keep a seller fee on each sale.'),
                array('name' => 'Buy Me a Coffee', 'compare' => '', 'kind' => 'Tips, memberships and shop',
                      'text' => 'Buy Me a Coffee keeps the monthly membership a Patreon creator already runs and adds one-off tips and a small shop. Supporters are yours, and the list exports. Its terms rule out nudity and sexual content, and a payout has to be requested.'),
                array('name' => 'Memberful', 'compare' => '', 'kind' => 'Membership software for your site',
                      'text' => 'Memberful suits a Patreon creator who already has a website and wants members to join there. It adds memberships to your own site and leaves the audience and the content with you. There is no discovery, so every member arrives through your own channels.'),
                array('name' => 'Substack', 'compare' => '', 'kind' => 'Paid newsletter subscriptions',
                      'text' => 'Substack fits a Patreon creator whose members mostly read. Posts and podcasts go out by email, with free and paid subscribers on one list that exports. What you sell is a subscription to a publication, so single products and bookings have no place there.'),
                array('name' => 'Gumroad', 'compare' => '', 'kind' => 'Digital products and memberships',
                      'text' => 'Gumroad suits a Patreon creator whose members really want files: ebooks, courses, presets and downloads, with memberships beside them. There is no monthly price, only a cut of each sale. Buyers who find you through its marketplace cost a much larger cut.'),
                array('name' => 'Podia', 'compare' => '', 'kind' => 'Courses, downloads and community',
                      'text' => 'Podia fits a Patreon creator ready to teach: courses, downloads, coaching and a community sit in one store. You pay for the plan whether or not you sell that month. Its terms prohibit nudity and sexual content.'),
                array('name' => 'Ghost', 'compare' => '', 'kind' => 'Publishing with paid memberships',
                      'text' => 'Ghost suits a Patreon creator who wants a publication on a site they control, with paid tiers and tips. It takes no cut of its own from a subscription. It is a publishing tool first, so a catalogue of one-off products is not what it is for.'),
            ),
            'faq' => array(
                array('q' => 'What is the best Patreon alternative?', 'a' => 'The answer follows what your members pay for. If you only take tips and commissions, a tipping platform is the smallest change. If you also sell pay-per-view posts, bundles, services or event tickets, {site} puts all of it on one page and publishes each post to your social accounts.'),
                array('q' => 'Can I move my Patreon members to another platform?', 'a' => 'You can take the list. Patreon lets creators download their member list, including emails, as a CSV file. Memberships themselves do not transfer, so members join again on the new page.'),
                array('q' => 'Which Patreon alternative has the lowest fee?', 'a' => 'Patreon and the tools on this list each publish a rate of their own and revise it, so compare the current pricing pages side by side. {site}\'s platform fee is {fee_short}. Most of them add card processing to that.'),
                array('q' => 'Can I keep my Patreon page while I try another platform?', 'a' => 'Yes. Run both for a full billing cycle. Build the new page, email your members the new address and let Patreon renewals lapse naturally.'),
                array('q' => 'When do membership payments reach my bank on {site}?', 'a' => 'A membership payment joins your balance when the member\'s card is charged. You ask for a payout once the balance is over {payout_min}, as often as you like.'),
            ),
        ),

        'linktree' => array(
            'us_points'   => array('Tracked links, with a click count on each one.', 'Takes payment on the page for tiers, single posts, bundles, bookings and tickets.', 'Runs on your own domain on the Studio plan.'),   // our card on this list: three points chosen for this reader
            'us_best'     => 'a creator whose bio link should take payment, not only point elsewhere.',
            'us_text'     => 'One public page at your handle that holds your tracked links and takes payment for memberships, pay-per-view posts, bundles, services and live events, so a visitor can buy without leaving it.',   // our own card on this list, worded for the platform being left
            'name'        => 'Linktree',
            'description' => 'Linktree alternatives compared: link pages, storefronts and one-page selling, with what each platform is and who it suits. Updated for {year}.',
            'intro'       => 'A Linktree alternative is a page that holds a creator\'s links and, on most options, also takes payment. Creators look for one when the link page needs to sell memberships, bookings or posts, not only point elsewhere. Here are the main options and what kind of platform each one is.',
            'others' => array(
                array('name' => 'Beacons', 'compare' => 'beacons', 'kind' => 'Link-in-bio storefront',
                      'text' => 'Beacons is the closest move from Linktree: still a link page, with a store, email marketing and a media kit added. Memberships and courses need one of its higher plans. It lists no post publishing, where Linktree has a social planner.'),
                array('name' => 'Stan Store', 'compare' => 'stan', 'kind' => 'Link-in-bio storefront',
                      'text' => 'Stan Store suits a Linktree user whose links mostly point at things for sale. Courses, bookings and subscriptions are sold on the page for a flat monthly price and no share of sales. It is a store more than a link list, so a long set of plain links fits less well.'),
                array('name' => 'Ko-fi', 'compare' => 'kofi', 'kind' => 'Tips and memberships',
                      'text' => 'Ko-fi fits a Linktree user who wants supporters to tip or join a membership from the bio link. Money arrives in your own payment account straight away. It is a support page, not a link hub, so your other links need a home.'),
                array('name' => 'Patreon', 'compare' => 'patreon', 'kind' => 'Membership platform',
                      'text' => 'Patreon suits a Linktree user ready to turn followers into paying members, with tiers and community chats. It is a membership site, not a link page, so you would still keep a bio link pointing at it. Its member list downloads with emails.'),
                array('name' => 'Carrd', 'compare' => '', 'kind' => 'One-page site builder',
                      'text' => 'Carrd gives a Linktree user a full one-page site in place of a list of links. It has no checkout of its own, so payment means embedding a provider you already use. That takes one of its paid plans.'),
                array('name' => 'Hopp by Wix', 'compare' => '', 'kind' => 'Link-in-bio storefront',
                      'text' => 'Hopp is close to Linktree in shape, a link page, with selling built in: digital products, services, tips and bookings. Its public pages do not show its plan prices, so check what the selling tools cost before you switch.'),
                array('name' => 'Milkshake', 'compare' => '', 'kind' => 'Mobile card-style site',
                      'text' => 'Milkshake suits a Linktree user who builds on a phone and wants swipeable cards instead of a list. It sells digital files from the page. It keeps a cut of every sale on every plan, the largest on the free one.'),
                array('name' => 'Campsite.bio', 'compare' => '', 'kind' => 'Link page with tips',
                      'text' => 'Campsite.bio stays closest to what Linktree does: a link page with analytics. Followers can tip or pay for a request from the page. It has no product checkout, so anything else you sell still lives elsewhere.'),
                array('name' => 'Later', 'compare' => '', 'kind' => 'Social scheduler with a link page',
                      'text' => 'Later suits a Linktree user who mainly wants scheduling, with the link page as part of the same tool. The page sends followers out to your site or shop and takes no payment itself. It comes with a paid scheduling plan.'),
                array('name' => 'Buy Me a Coffee', 'compare' => '', 'kind' => 'Tips, memberships and shop',
                      'text' => 'Buy Me a Coffee fits a Linktree user whose bio link should collect support: tips, a membership and a few shop items. It is a support page, so your other links need a home somewhere else. Its terms rule out nudity and sexual content.'),
                array('name' => 'Gumroad', 'compare' => '', 'kind' => 'Digital products and memberships',
                      'text' => 'Gumroad suits a Linktree user whose links mostly lead to things for sale. A product page takes payment directly, with no monthly price. It is a store, not a link hub, and it keeps a cut of each sale.'),
            ),
            'faq' => array(
                array('q' => 'What is the best Linktree alternative?', 'a' => 'It depends on what the page has to do. If it only lists links, any link page works. If it has to take payment for memberships, pay-per-view posts, bookings or event tickets, {site} sells all of them from the same page that holds your links.'),
                array('q' => 'Can a link in bio page take payments?', 'a' => 'Yes. On {site} the page at your handle holds tracked links beside memberships, pay-per-view posts, bundles, services and events, so a fan can pay without leaving it.'),
                array('q' => 'Can I export my Linktree audience?', 'a' => 'Yes. Linktree lets creators export audience contacts as a CSV file from the Audience tab on all plans.'),
                array('q' => 'Which Linktree alternative has the lowest fee?', 'a' => 'Link page tools charge a monthly price, a share of sales, or both, and those figures change often. {site}\'s platform fee is {fee_short}. Read each tool\'s pricing page before you switch your bio link.'),
                array('q' => 'Where does the money from my bio link go on {site}?', 'a' => 'Into one balance on your account, whatever the visitor bought. From {payout_min} upward you can move it to your bank on request.'),
            ),
        ),
    );
}
