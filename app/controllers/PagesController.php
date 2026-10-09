<?php
/**
 * Public, indexable product pages: /features, /pricing, /compare/<competitor>,
 * /best-creator-monetization-platforms, /monetize-your-content. Routed from Bootstrap via
 * ROUTES (the URLs don't fit /controller/action). Rendered with View::public_page().
 */
class PagesController extends Controller {

    public $protected = 0;

    /** Set true by SeoController::render_public_html() while rendering a page internally, so the
     *  constructor doesn't emit a Cache-Control header that would clobber the real response's. */
    public static $embedded = false;

    /** URL first segment => method (without "Action"). */
    const ROUTES = array(
        'features'                            => 'features',
        'lora-character-training'             => 'rootFeature',   // keyword pages on the feature template (FeaturePages::ROOT)
        'consistent-ai-model-face'            => 'rootFeature',
        'ai-ofm-tools'                        => 'rootFeature',
        'pricing'                             => 'pricing',
        'compare'                              => 'compare',
        'best-creator-monetization-platforms' => 'bestPlatforms',
        'monetize-your-content'               => 'monetize',
        'onlyfans-alternatives'               => 'alternatives',
        'fanvue-alternatives'                 => 'alternatives',
        'creators'                           => 'creators',
        'terms'                               => 'terms',
        'privacy'                             => 'privacy',
        'about'                               => 'about',
        'contact'                             => 'contact',
        'founding'                            => 'founding',
        'affiliates'                          => 'affiliates',   // /affiliates (public); /affiliates/apply and /affiliates/dashboard go on to AffiliatesController
    );

    /** Where legal and privacy requests go (shown on /terms and /privacy). */
    const LEGAL_CONTACT = 'support@creatorlinkstudio.com';

    /** Topics on the /contact form (value => label); the contact_send API accepts only these. */
    const CONTACT_TOPICS = array('billing' => 'Billing', 'payouts' => 'Payouts', 'account' => 'Account', 'bug' => 'Bug', 'other' => 'Other');

    /** Below this many listed creators the directory copy doesn't quote the number. */
    const DIRECTORY_COUNT_MIN = 10;

    /** Competitor facts used by /compare/* and the best-of page. Every row has a source + checked date.
     *  Fact-checked 2026-09-21 against the source URLs below; see task-7-report.md for the exact
     *  sentence relied on per row. Rows OnlyFans could not verify directly (its site is behind a
     *  Cloudflare bot check that blocked every fetch attempt) are 'Not published' rather than guessed. */
    const COMPETITORS = array(
        'fanvue' => array(
            'name' => 'Fanvue', 'url' => 'https://www.fanvue.com', 'checked' => '2026-09-21', 'type' => 'fan',
            'fee_short' => '20% of gross revenue',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'Fanvue is a subscription platform for creators with a focus on AI tools for chat and voice. It does not publish to your social accounts or give you a link-in-bio style page with services and events.',
            'fee'       => array('value' => '20% platform fee (creators keep 80% of gross revenue)', 'source' => 'https://legal.fanvue.com/creator-earnings-payouts'),
            'payout'    => array('value' => 'Bank transfer, crypto or third-party wallets; 7-day pending period (up to 28 days)', 'source' => 'https://legal.fanvue.com/creator-earnings-payouts'),
            'content'   => array('value' => 'Posts, subscriptions, tips, paid messages', 'source' => 'https://try.fanvue.com/fanvue'),
            'socials'   => array('value' => 'Not published', 'source' => 'https://www.fanvue.com'),
            'ai'        => array('value' => 'AI voice calls, AI voice notes, AI analytics', 'source' => 'https://www.fanvue.com'),
            'ownership' => array('value' => 'Not published', 'source' => 'https://help.fanvue.com'),
            'best_for'  => 'Subscription creators, including AI-generated creator accounts, who want built-in AI voice and analytics tools.',
            'strengths' => array('Built-in AI voice calls, AI voice notes and AI analytics', 'Open API and app store for third-party tools', 'Creators keep 80% of gross revenue'),
            'faq'       => array(
                array('q' => 'How much does Fanvue take?', 'a' => 'Fanvue\'s standard rate keeps 20% of gross revenue from paid services, and creators receive 80%.'),
                array('q' => 'How do Fanvue payouts work?', 'a' => 'Fanvue pays out by bank transfer, crypto or third-party wallets. The standard pending period is 7 days and can be extended up to 28 days.'),
                array('q' => 'Does Fanvue have AI tools?', 'a' => 'Yes. Fanvue lists AI voice calls, AI voice notes and AI analytics among its creator features.'),
            ),
        ),
        'onlyfans' => array(
            'name' => 'OnlyFans', 'url' => 'https://onlyfans.com', 'checked' => '2026-09-21', 'type' => 'fan',
            'fee_short' => '20% of creator earnings',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'OnlyFans is the largest subscription platform for creators. It has the biggest audience and depends entirely on its own app, with no cross-posting and no services or events for sale.',
            'fee'       => array('value' => '20% of creator earnings', 'source' => 'https://en.wikipedia.org/wiki/OnlyFans', 'reported' => true),
            'payout'    => array('value' => 'Bank transfer and e-wallets, after a holding period (about 7 days)', 'source' => 'https://www.supercreator.app/guides/how-long-do-onlyfans-payouts-take', 'reported' => true),
            'content'   => array('value' => 'Subscriptions, tips, pay-per-view', 'source' => 'https://en.wikipedia.org/wiki/OnlyFans', 'reported' => true),
            'socials'   => array('value' => 'Not published', 'source' => 'https://onlyfans.com/help'),
            'ai'        => array('value' => 'Not published', 'source' => 'https://onlyfans.com/help'),
            'ownership' => array('value' => 'Not published', 'source' => 'https://onlyfans.com/help'),
            'best_for'  => 'Subscription creators who want to sell to fans inside one large, established platform using subscriptions, tips and pay-per-view.',
            'strengths' => array('Reported to have more than 370 million registered users and 4 million creators (2024)', 'Subscriptions, tips and pay-per-view in one place', 'Used by a wide range of creators, including athletes, musicians and comedians, as well as adult creators'),
            'faq'       => array(
                array('q' => 'How much does OnlyFans take from creators?', 'a' => 'OnlyFans is reported to take 20% of creator earnings.'),
                array('q' => 'How do OnlyFans payouts work?', 'a' => 'Creators are reported to be paid by bank transfer or e-wallet after a holding period of about 7 days.'),
                array('q' => 'Can I export my OnlyFans subscriber list?', 'a' => 'OnlyFans does not publish whether creators can export their subscriber list.'),
            ),
        ),
        'patreon' => array(
            'name' => 'Patreon', 'url' => 'https://www.patreon.com', 'checked' => '2026-09-25', 'type' => 'membership',
            'fee_short' => '10% platform fee, plus processing',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'Patreon is a membership platform where creators offer monthly or annual paid tiers, sell digital products and run community chats for their fans. It is used by video creators, podcasters, writers and artists who want recurring income from their audience.',
            'fee'       => array('value' => '10% platform fee for pages published after August 4, 2025, plus payment processing (from 2.9% + $0.30 in USD), currency conversion and payout fees; older legacy plans are 5%, 8% or 11%', 'source' => 'https://support.patreon.com/hc/en-us/articles/11111747095181-Creator-fees-overview'),
            'payout'    => array('value' => 'Creator-initiated withdrawal by bank transfer or online wallet; 5-day hold after adding a payout method; one-time purchase funds pending up to 7 days (up to 75 days for iOS in-app purchases)', 'source' => 'https://support.patreon.com/hc/en-us/articles/208656246-How-payouts-work'),
            'content'   => array('value' => 'Monthly and annual memberships, digital products (one-time purchases), video hosting, community chats and polls', 'source' => 'https://support.patreon.com/hc/en-us/articles/11111747095181-Creator-fees-overview'),
            'socials'   => array('value' => 'A share menu lets creators copy their page link or share it on social media themselves; automatic posting to social accounts is not published', 'source' => 'https://support.patreon.com/hc/en-us/articles/8641994390413-How-to-get-traffic-to-your-Creator-page'),
            'ai'        => array('value' => 'Clips: AI suggests shareable clips from Patreon videos (iOS, English audio, videos of 8+ minutes)', 'source' => 'https://support.patreon.com/hc/en-us/articles/47461310686349-Clips'),
            'ownership' => array('value' => 'Yes, the member list including emails can be downloaded as a CSV from the Relationship manager', 'source' => 'https://support.patreon.com/hc/en-us/articles/34784011795469-Exporting-your-audience-s-emails-from-Patreon'),
            'best_for'  => 'Creators who publish regularly, such as video makers, podcasters and writers, and want paid membership tiers with digital products and community chats in one place.',
            'strengths' => array('Monthly and annual memberships with multiple tiers', 'Built-in video hosting and community chats', 'Member list including emails can be exported as a CSV'),
            'faq'       => array(
                array('q' => 'How much does Patreon take from creators?', 'a' => 'Creators who published their page after August 4, 2025 pay a 10% platform fee. Payment processing, currency conversion and payout fees are charged on top, and older legacy plans range from 5% to 11%.'),
                array('q' => 'Can I export my Patreon members?', 'a' => 'Yes. Patreon lets creators download their member list, including emails, as a CSV file from the Relationship manager.'),
                array('q' => 'Does Patreon post to my social media accounts?', 'a' => 'Patreon provides a share menu for copying your page link or sharing it on social media yourself. It does not publish information about posting automatically to connected social accounts.'),
            ),
        ),
        'fansly' => array(
            'name' => 'Fansly', 'url' => 'https://fansly.com', 'checked' => '2026-09-25', 'type' => 'fan',
            'fee_short' => '20% platform fee',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'Fansly is a subscription platform where creators, including adult creators, sell subscriptions, pay-per-view posts, livestreams and paid messages. It includes an internal For You discovery feed that shows creators\' content to new viewers on Fansly.',
            'fee'       => array('value' => '20% platform fee; creators keep 80% of subscription and direct sales earnings', 'source' => 'https://fansly.com/application'),
            'payout'    => array('value' => 'Bank transfer (ACH/SEPA) or e-wallet; once verified, payouts above $20 as often as you like, typically sent within 3 business days by bank transfer', 'source' => 'https://fansly.com/application'),
            'content'   => array('value' => 'Subscriptions, pay-per-view posts, bundles, livestreams (public, ticketed, 1:1), paid messages and custom content', 'source' => 'https://creatorhub.fansly.com/clip-store-migration-guide/'),
            'socials'   => array('value' => 'Not published', 'source' => 'https://fansly.com/help'),
            'ai'        => array('value' => 'Not published', 'source' => 'https://fansly.com/help'),
            'ownership' => array('value' => 'Not published', 'source' => 'https://fansly.com/help'),
            'best_for'  => 'Subscription creators, including adult creators, who want an 80/20 split and in-platform discovery through a For You feed.',
            'strengths' => array('Built-in For You discovery feed for reaching new viewers', 'Automated and keyword-triggered messages', 'Livestream options including ticketed and 1:1 streams'),
            'faq'       => array(
                array('q' => 'How much does Fansly take?', 'a' => 'Fansly takes a 20% fee, so creators keep 80% of their subscription and direct sales earnings.'),
                array('q' => 'How do Fansly payouts work?', 'a' => 'Fansly pays out by bank transfer (ACH/SEPA) or e-wallet. Once verified, creators can withdraw amounts above $20 as often as they like, and bank transfers typically arrive within 3 business days.'),
                array('q' => 'What can I sell on Fansly?', 'a' => 'Fansly supports subscriptions, pay-per-view posts, bundles, livestreams (public, ticketed or 1:1), paid messages and custom content.'),
            ),
        ),
        'kofi' => array(
            'name' => 'Ko-fi', 'url' => 'https://ko-fi.com', 'checked' => '2026-09-25', 'type' => 'membership',
            'fee_short' => '0% on one-time tips, 5% on the rest',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'Ko-fi is a creator support platform for tips, memberships, a shop and commissions, used by artists, writers, musicians and streamers. It does not hold earnings for a payout schedule; payments go directly into the creator\'s own connected payment account.',
            'fee'       => array('value' => 'Ko-fi Free: 0% on one-time tips and goals, 5% on memberships, monthly tips, shop and commissions; Standard mode: 5% on everything; payment processing fees apply on top', 'source' => 'https://help.ko-fi.com/hc/en-us/articles/360002506494-Does-Ko-fi-take-a-fee'),
            'payout'    => array('value' => 'Paid immediately and directly into the creator\'s own connected payment account; no payout schedule or minimum balance', 'source' => 'https://help.ko-fi.com/hc/en-us/articles/115003980093-How-do-I-get-paid'),
            'content'   => array('value' => 'One-time and recurring tips, membership tiers, physical and digital shop items, commissions and services, stream alerts', 'source' => 'https://help.ko-fi.com/hc/en-us/articles/115004000994-What-is-Ko-fi'),
            'socials'   => array('value' => 'Not published', 'source' => 'https://help.ko-fi.com/hc/en-us'),
            'ai'        => array('value' => 'Not published', 'source' => 'https://help.ko-fi.com/hc/en-us'),
            'ownership' => array('value' => 'Yes, supporter and member lists (including supporter emails) can be downloaded as CSV files', 'source' => 'https://help.ko-fi.com/hc/en-us/articles/360016956178-Direct-messages-on-Ko-fi'),
            'best_for'  => 'Artists, writers, musicians and streamers who want to take tips, commissions and shop sales with low fees and instant payment to their own account.',
            'strengths' => array('No service fee on one-time tips with Ko-fi Free', 'Payments go straight to the creator\'s own account with no payout schedule or minimum', 'Supporter and member lists can be downloaded as CSV files'),
            'faq'       => array(
                array('q' => 'Does Ko-fi take a fee?', 'a' => 'On Ko-fi Free, one-time tips have no service fee and memberships, monthly tips, shop sales and commissions have a 5% fee. Standard mode charges 5% on everything, and payment processing fees apply on top in both cases.'),
                array('q' => 'When do I get paid on Ko-fi?', 'a' => 'Payments go immediately and directly into the creator\'s own connected payment account, with no payout schedule or minimum balance.'),
                array('q' => 'Can I export my Ko-fi supporters?', 'a' => 'Yes. Ko-fi lets creators download supporter and member lists, including supporter emails, as CSV files.'),
            ),
        ),
        'linktree' => array(
            'name' => 'Linktree', 'url' => 'https://linktr.ee', 'checked' => '2026-09-25', 'type' => 'bio',
            'fee_short' => '$0 to $35/mo, plus 0% to 12% of sales',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'Linktree is a link-in-bio page that lets creators share links, collect contacts and sell digital products and courses from one URL. Selling digital products and courses through Linktree is only available in selected countries.',
            'fee'       => array('value' => 'Free $0; Starter $8/mo ($6/mo billed annually); Pro $15/mo ($12/mo annually); Premium $35/mo ($30/mo annually). Linktree fee on digital product and course sales is 12% on Free, 9% on Starter and Pro, and 0% on Premium; card processing fees apply on all plans, plus $0.25 per payout.', 'source' => 'https://linktr.ee/help/en/articles/11410206-understanding-transaction-and-processing-fees-on-linktree'),
            'payout'    => array('value' => 'Product and course sales settle to the bank account connected to the creator\'s payment account, on a daily, weekly or monthly schedule the creator chooses. Affiliate commissions (US only) are usually paid monthly once they reach $10.', 'source' => 'https://linktr.ee/help/en/articles/11195009-how-to-track-your-earnings-and-set-up-a-payout-account'),
            'content'   => array('value' => 'One-time digital products such as eBooks, guides, templates and courses, affiliate links and sponsored links. Pricing page also lists paid access to private communities (US only). Recurring memberships and bookings are not listed in the selling guide.', 'source' => 'https://linktr.ee/help/en/articles/10518645-guide-to-selling-digital-products-on-linktree'),
            'socials'   => array('value' => 'Yes. Social Planner auto-posts to Instagram, TikTok, Facebook, LinkedIn, Pinterest and YouTube Shorts; the pricing page lists social media scheduling on Premium.', 'source' => 'https://linktr.ee/help/en/articles/9885339-plan-and-schedule-social-media-posts-using-social-planner'),
            'ai'        => array('value' => 'An AI idea generator and caption writer for social posts, plus Instagram comment and DM auto-replies (unlimited on Premium).', 'source' => 'https://linktr.ee/s/pricing'),
            'ownership' => array('value' => 'Yes. Audience contacts can be exported as a CSV from the Audience tab on all plans.', 'source' => 'https://linktr.ee/help/en/articles/11069074-setting-up-third-party-audience-integrations'),
            'best_for'  => 'Creators who mainly need a simple, widely recognized link page with optional digital product sales and social scheduling.',
            'strengths' => array('Free plan with unlimited basic links', 'Built-in social scheduling to six networks', 'Contact CSV export on every plan'),
            'faq'       => array(
                array('q' => 'Does Linktree take a cut of sales?', 'a' => 'Yes, on lower plans. Linktree charges 12% on Free and 9% on Starter and Pro for digital product and course sales, and 0% on Premium; processing fees and a $0.25 payout fee apply on all plans.'),
                array('q' => 'Can Linktree post to my social accounts?', 'a' => 'Yes. Its Social Planner auto-posts to Instagram, TikTok, Facebook, LinkedIn, Pinterest and YouTube Shorts, and social scheduling is listed on the Premium plan.'),
                array('q' => 'Can I export my Linktree audience?', 'a' => 'Yes. Linktree lets you export audience contacts as a CSV file from the Audience tab on all plans.'),
            ),
        ),
        'beacons' => array(
            'name' => 'Beacons', 'url' => 'https://beacons.ai', 'checked' => '2026-09-25', 'type' => 'bio',
            'fee_short' => '$0 to $100/mo, plus 0% or 9% of sales',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'Beacons is a creator business platform that combines a link-in-bio page, website, store, email marketing and media kit. Its social features track and analyze posts but do not publish them to your social accounts.',
            'fee'       => array('value' => 'Free $0 with 9% seller fees; Creator $10/mo ($100/yr) with 9% seller fees; Creator Plus $30/mo ($300/yr) with 0% seller fees; Creator Max $100/mo ($900/yr) with 0% seller fees.', 'source' => 'https://beacons.ai/i/pricing'),
            'payout'    => array('value' => 'Store sale funds go straight to the seller\'s connected payment account, from which they transfer to their bank on a daily, weekly or custom schedule. Affiliate commissions are cashed out from the Payouts tab.', 'source' => 'https://help.beacons.ai/en/articles/4700481'),
            'content'   => array('value' => 'Digital products, appointments and imported physical products on all plans; memberships (weekly, monthly or yearly) and unlimited courses with video hosting on Creator Plus and above; affiliate links and brand deals.', 'source' => 'https://beacons.ai/i/pricing'),
            'socials'   => array('value' => 'No post publishing is listed. Post Activity tracks and analyzes posts across connected accounts, and Instagram DM automations reply to comments and messages.', 'source' => 'https://help.beacons.ai/en/articles/4704961'),
            'ai'        => array('value' => 'An AI assistant (Beam) plus AI text, image, email and page generation, trending content search and brand outreach emails, limited by daily credits (30 on Free, 300 on Creator, unlimited on Creator Plus and Max).', 'source' => 'https://beacons.ai/i/pricing'),
            'ownership' => array('value' => 'Yes. Audience contacts can be downloaded as a CSV from Audience Manager; automatic export to email tools is a paid feature.', 'source' => 'https://help.beacons.ai/en/articles/4703745'),
            'best_for'  => 'Creators who want a store, email marketing and a brand-deal media kit in one tool, and who do brand partnerships.',
            'strengths' => array('Free plan that includes digital product sales', 'Built-in email marketing and audience manager', 'Auto-updating media kit for brand deals'),
            'faq'       => array(
                array('q' => 'How much does Beacons charge on sales?', 'a' => 'Beacons charges a 9% seller fee on the Free and Creator plans and 0% on Creator Plus ($30/mo) and Creator Max ($100/mo).'),
                array('q' => 'Can I sell memberships on Beacons?', 'a' => 'Yes. Memberships are listed on Creator Plus and above, with weekly, monthly or yearly billing.'),
                array('q' => 'Does Beacons schedule posts to my social accounts?', 'a' => 'Beacons does not list post publishing. Its Post Activity feature tracks and analyzes posts across connected accounts.'),
            ),
        ),
        'stan' => array(
            'name' => 'Stan', 'url' => 'https://stan.store', 'checked' => '2026-09-25', 'type' => 'bio',
            'fee_short' => '$29 or $99/mo, no transaction fee',   // hero panel only; the table shows the sourced 'fee' row
            'summary' => 'Stan (Stan Store) is a creator storefront for selling courses, digital products, bookings, subscriptions and communities from a link-in-bio page. It offers two paid plans and no free plan.',
            'fee'       => array('value' => 'Creator $29/mo or $300/yr; Creator Pro $99/mo or $948/yr. Stan charges no transaction fee on sales; card processing fees still apply.', 'source' => 'https://stan.store/blog/stan-store-pricing/'),
            'payout'    => array('value' => 'Creators cash out their full available balance (minimum $10) from the Income tab to their connected bank account; funds arrive in 24-48 hours after cashing out, once sales have finished processing (2-7 business days in the US).', 'source' => 'https://help.stan.store/article/75-how-do-i-cash-out-inside-stan'),
            'content'   => array('value' => 'Digital products, courses, bookings, recurring subscriptions, communities and lead magnets on Creator; payment plans, upsells, discount codes, affiliates and email broadcasts on Creator Pro. An AI Twin subscription product is in beta.', 'source' => 'https://stan.store/blog/stan-store-pricing/'),
            'socials'   => array('value' => 'Stan Store plans do not list post publishing. Stanley, a separately priced Stan product, helps create and repurpose content for LinkedIn, Instagram, X, Threads and Substack.', 'source' => 'https://help.stan.store/article/438-stan-store-vs-stanley'),
            'ai'        => array('value' => 'AI Twin (beta), a subscribable AI version of the creator trained on their content; Stan AutoDM automates Instagram replies; Stanley is a separate AI content product.', 'source' => 'https://help.stan.store/article/424-ai-twin-product-type-beta'),
            'ownership' => array('value' => 'Yes. Customer and order data can be downloaded as a CSV from the Income tab.', 'source' => 'https://help.stan.store/article/219-i-added-additional-fields-to-my-checkout-how-do-i-access-this-information'),
            'best_for'  => 'Coaches and educators who sell courses, bookings and digital products and prefer a flat monthly fee with no transaction fee.',
            'strengths' => array('No transaction fee on either plan', 'Courses, bookings and subscriptions included on the base plan', 'Built-in Instagram auto-DMs'),
            'faq'       => array(
                array('q' => 'Does Stan take a percentage of sales?', 'a' => 'No. Stan charges no transaction fee on either plan; you pay $29 or $99 a month, and card processing fees still apply.'),
                array('q' => 'How do I get paid on Stan?', 'a' => 'You cash out your full available balance (minimum $10) from the Income tab to your bank account, and it arrives within 24-48 hours.'),
                array('q' => 'Does Stan post to my social accounts?', 'a' => 'Stan Store plans do not list post publishing. Stanley, a separately priced Stan product, helps create and repurpose social content.'),
            ),
        ),
        'eromify' => array(   // type 'ai': an AI generation studio, not a selling platform, so it stays off the best-of groups
            'name' => 'Eromify', 'url' => 'https://www.eromify.com', 'checked' => '2026-10-08', 'type' => 'ai',
            'fee_short' => 'No fan sales listed; monthly plans with generation credits',   // hero panel only
            'summary' => 'Eromify is an AI influencer generator: you train a persona and generate images and videos of it with a wide choice of image and video models, then post and sell that content somewhere else.',
            'fee'       => array('value' => 'Monthly plans with a monthly credit allowance (Builder, Launch, Growth and Creator, from 500 to 6,000 credits a month); each image or video model costs a set number of credits. No fan sales or selling fee are listed.', 'source' => 'https://www.eromify.com/pricing'),
            'payout'    => array('value' => 'Not published; fan payments and payouts are not listed (an affiliate program pays on referrals)', 'source' => 'https://www.eromify.com'),
            'content'   => array('value' => 'AI images and videos of a trained persona, for monetizing through social media, brand promotions and private media on other platforms', 'source' => 'https://www.eromify.com'),
            'socials'   => array('value' => 'Not published; content is made to grow on Instagram, TikTok and YouTube, automatic posting is not listed', 'source' => 'https://www.eromify.com/pricing'),
            'ai'        => array('value' => 'Influencer training (Flux LoRA), image and video generation across many third-party models, motion control, image and video upscale, a workflow canvas, an AI agent and a Claude connector', 'source' => 'https://www.eromify.com/pricing'),
            'ownership' => array('value' => 'Not published; no fan, subscriber or customer list is listed', 'source' => 'https://www.eromify.com/pricing'),
            'best_for'  => 'Creators who only need to generate AI influencer images and video, with a wide choice of generation models, and already sell somewhere else.',
            'strengths' => array('A wide choice of image and video models in one studio', 'Workflow canvas and an AI agent for batch generation', 'Outputs are not watermarked'),
            'us_points' => array(
                'You want to generate your AI influencer and sell the content on the same page',
                'You want memberships, pay-per-view and paid messages without a second platform',
                'You want posts published to nine social networks from the same studio',
                'You want a DM agent that answers fans in your voice, with your approval',
            ),
            'faq'       => array(
                array('q' => 'What is Eromify?', 'a' => 'An AI influencer generator. You train a persona, then generate images and videos of it with a choice of image and video models.'),
                array('q' => 'Can I sell content to fans on Eromify?', 'a' => 'Eromify does not list fan payments or payouts. It describes monetizing through social media, brand promotions and private media, which means selling on another platform.'),
                array('q' => 'How is Eromify priced?', 'a' => 'Monthly plans, each with a monthly credit allowance, and each model costs a set number of credits per image or clip. Prices are on its pricing page.'),
            ),
        ),
    );

    public function __construct(){
        parent::__construct();
        if (!self::$embedded) { header('Cache-Control: private, max-age=300'); }
    }

    private function page($view, array $meta, array $vars = array()){
        $meta['url'] = SeoMeta::base() . $meta['path'];
        if (!self::$embedded && (int) Session::get('user_id') === 0) { Affiliates::capture($this->get_ip_address()); }   // ?aff=<code> on any public page, signed out: cls_aff + a click
        if (!isset($meta['jsonld'])) {
            $meta['jsonld'] = array(
                SeoMeta::org(),
                SeoMeta::breadcrumbs(array(
                    array('name' => 'Home', 'url' => '/'),
                    array('name' => $meta['title'], 'url' => $meta['path']),
                )),
            );
        }
        // every page carries one top-level Organization (with its contactPoint), never two
        if (isset($meta['jsonld']['@type'])) { $meta['jsonld'] = array($meta['jsonld']); }
        if (!in_array('Organization', array_map(function ($b) { return is_array($b) ? ($b['@type'] ?? '') : ''; }, (array) $meta['jsonld']), true)) { $meta['jsonld'][] = SeoMeta::org(); }
        unset($meta['path']);
        $meta['sections'] = true;   // full-width section system (libs/Classes/Sections.php)
        $this->view->public_page(Main::app_path() . '/app/views/pages/' . $view . '.php', $meta, $vars);
    }

    /** Feature pages under /features/<slug> (FeaturePages::PAGES). */
    public static function feature_pages(): array {
        return self::fill_feature_text(FeaturePages::PAGES);
    }

    /** Keyword pages at the site root on the same template (FeaturePages::ROOT, routed in ROUTES to rootFeature). */
    public static function root_feature_pages(): array {
        return self::fill_feature_text(FeaturePages::ROOT);
    }

    /** Said wherever plan prices or credit purchases are mentioned. */
    const FINAL_NOTE = 'Plan charges and credit purchases are final and non-refundable.';

    /** Old feature slugs => new ones (301). */
    const FEATURE_MOVED = array('character-generation' => 'ai-influencer');

    /** Placeholders in the constants are filled from config here, so plans, fees and names never go stale in the text. */
    private static function fill_feature_text(array $pages): array {
        $domain_plan = ''; $inf = array(); $seats = array();
        foreach (self::selling_tiers() as $t) {
            if ($domain_plan === '' && !empty($t['features']['custom_domain'])) { $domain_plan = (string) $t['name']; }
            if ((int) ($t['limits']['seats'] ?? 1) > 1) { $seats[] = (string) $t['name']; }
            if ((int) ($t['limits']['influencers'] ?? 0) > 0) { $inf[] = (int) $t['limits']['influencers'] . ' on ' . $t['name']; }
        }
        $vars = array(
            '{fee_sentence}'     => self::fee_sentence(),
            '{fee_short}'        => self::fee_short(),
            '{site}'             => Main::site_name(),
            '{host}'             => preg_replace('#^https?://(www\.)?#', '', SeoMeta::base()),
            '{networks}'         => SeoController::NETWORKS,
            '{domain_plan}'      => $domain_plan,
            '{influencer_plans}' => implode(', ', $inf),
            '{seats_plans}'      => implode(' or ', $seats),
            '{final_note}'       => self::FINAL_NOTE,
        );
        // wherever plan prices appear (fee_sentence), the non-refundable line follows
        foreach ($pages as $k => $p) {
            foreach (array('rows' => 'text', 'faq' => 'a') as $list => $field) {
                foreach ((array) ($p[$list] ?? array()) as $i => $item) {
                    $v = (string) ($item[$field] ?? '');
                    if (strpos($v, '{fee_sentence}') !== false && strpos($v, '{final_note}') === false) { $pages[$k][$list][$i][$field] = $v . ' {final_note}'; }
                }
            }
        }
        $map = array();
        foreach ($vars as $k => $v) { $map[$k] = addcslashes((string) $v, '"\\/'); }
        return json_decode(strtr(json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $map), true);
    }

    /** One feature-template page: FAQ + breadcrumb markup, related cards (the page's 'related' slugs, else every other page). */
    private function render_feature(array $page, string $slug, string $path, array $crumbs){
        $all = array();
        foreach (self::feature_pages() as $k => $p) { $p['path'] = '/features/' . $k; $all[$k] = $p; }
        foreach (self::root_feature_pages() as $k => $p) { $p['path'] = '/' . $k; $all[$k] = $p; }
        $siblings = array();
        foreach ((array) ($page['related'] ?? array_keys($all)) as $k) { if ($k !== $slug && isset($all[$k])) { $siblings[$k] = $all[$k]; } }
        $jsonld = array(SeoMeta::faq((array) ($page['faq'] ?? array())), SeoMeta::breadcrumbs($crumbs));
        $cta = (array) ($page['cta'] ?? array());
        $this->page('feature', array('path' => $path, 'title' => $page['title'], 'description' => $page['description'], 'jsonld' => $jsonld),
            array('page' => $page, 'slug' => $slug, 'siblings' => $siblings,
                  'cta_title' => $cta['title'] ?? 'Start selling from one page.', 'cta_text' => $cta['text'] ?? ''));
    }

    /** /lora-character-training, /consistent-ai-model-face, /ai-ofm-tools. */
    public function rootFeatureAction(){
        $url = Main::get_url();
        $slug = (string) ($url[0] ?? '');
        $pages = self::root_feature_pages();
        if (count($url) > 1 || !isset($pages[$slug])) { Errors::page_not_found(); return; }
        $page = $pages[$slug];
        $this->render_feature($page, $slug, '/' . $slug, array(
            array('name' => 'Home', 'url' => '/'),
            array('name' => $page['nav_title'] ?? $page['title'], 'url' => '/' . $slug),
        ));
    }

    public function featuresAction(){
        $url = Main::get_url();
        if (count($url) > 1) {
            $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $url[1]));
            if (count($url) === 2 && isset(self::FEATURE_MOVED[$slug])) {   // renamed page: keep old links and rankings
                header('Location: ' . SeoMeta::base() . '/features/' . self::FEATURE_MOVED[$slug], true, 301); return;
            }
            $pages = self::feature_pages();
            if (count($url) > 2 || $slug === '' || !isset($pages[$slug])) { Errors::page_not_found(); return; }
            $page = $pages[$slug];
            $path = '/features/' . $slug;
            $this->render_feature($page, $slug, $path, array(
                array('name' => 'Home', 'url' => '/'),
                array('name' => 'Features', 'url' => '/features'),
                array('name' => $page['nav_title'] ?? $page['title'], 'url' => $path),
            ));
            return;
        }
        $faq = array(
            array('q' => 'Do I need my own website?', 'a' => 'No. Your public page lives at our domain under your handle and includes your posts, tiers, services, events and links.'),
            array('q' => 'Can I keep posting to my social accounts?', 'a' => 'Yes. The studio publishes each post to your page and to any connected social accounts at the same time, and pulls their engagement back into analytics.'),
            array('q' => 'Who owns my content and subscriber list?', 'a' => 'You do. Media, posts and your audience list can be exported at any time.'),
            array('q' => 'How do I get paid?', 'a' => 'Payouts go straight to your bank account. Fans pay with a credit wallet for unlocks and by card for memberships.'),
            array('q' => 'Is adult content allowed?', 'a' => 'Yes, within the content policy. Adult posts are only shown to fans who opt in, and every upload is checked automatically.'),
        );
        $jsonld = array(
            self::software_app('/features', Main::site_name(), SeoMeta::brand_description()),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Features', 'url' => '/features'))),
        );
        $this->page('features', array('path' => '/features', 'title' => 'Features: Memberships, Pay-Per-View and AI Tools for Creators', 'description' => 'One creator platform for your public page, memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'type' => 'product', 'jsonld' => $jsonld, 'no_guides' => true), array('faq' => $faq));
    }
    /** One row per tier, in rank order, with the live Stripe monthly price when available. */
    /**
     * One row per plan for every public surface (pricing page, home FAQ, schema, llms.txt),
     * straight from PlanTiers. Free has no Stripe price and still belongs here, so
     * prices come from config, never from the Stripe catalogue.
     */
    public static function pricing_rows(): array {
        $rows = array();
        foreach (PlanTiers::offered() as $tier) {   // retired plans are never shown publicly
            $rows[] = array(
                'tier'     => $tier,
                'amount'   => (int) $tier['price'] * 100,   // cents, so callers keep formatting as before
                'interval' => 'month',
            );
        }
        usort($rows, function ($a, $b) { return $a['tier']['rank'] <=> $b['tier']['rank']; });
        return $rows;
    }

    public function termsAction(){
        $this->page('legal-terms', array('path' => '/terms', 'title' => 'Terms of Service', 'description' => 'The terms for using ' . Main::site_name() . ': accounts, purchases, memberships, credits, creator plans and content rules.', 'type' => 'website', 'jsonld' => array(SeoMeta::org()), 'no_guides' => true, 'no_band' => true, 'sections' => true));
    }

    public function privacyAction(){
        $this->page('legal-privacy', array('path' => '/privacy', 'title' => 'Privacy Policy', 'description' => 'What ' . Main::site_name() . ' collects, how it is used and shared, and the choices you have.', 'type' => 'website', 'jsonld' => array(SeoMeta::org()), 'no_guides' => true, 'no_band' => true, 'sections' => true));
    }

    public function aboutAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $site = Main::site_name();
        $desc = 'What ' . $site . ' is, who it is for, the AI influencer tools, and how creators get paid to their bank.';
        $jsonld = array(
            array('@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About ' . $site, 'description' => $desc, 'url' => SeoMeta::base() . '/about', 'about' => SeoMeta::org()),
            SeoMeta::org(),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'About', 'url' => '/about'))),
        );
        $this->page('about', array('path' => '/about', 'title' => 'About ' . $site, 'description' => $desc, 'type' => 'website', 'jsonld' => $jsonld, 'no_guides' => true));
    }

    public function contactAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $site = Main::site_name();
        $desc = 'Contact ' . $site . ' support about billing, payouts, your account or a bug. Send a message or email ' . self::LEGAL_CONTACT . '.';
        $jsonld = array(
            array('@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact ' . $site, 'description' => $desc, 'url' => SeoMeta::base() . '/contact', 'about' => SeoMeta::org()),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Contact', 'url' => '/contact'))),
        );
        $this->page('contact', array('path' => '/contact', 'title' => 'Contact Support', 'description' => $desc, 'type' => 'website', 'jsonld' => $jsonld, 'no_guides' => true, 'no_band' => true));
    }

    public function pricingAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $rows = self::pricing_rows();
        $ai_first = PlanTiers::lowest_including('influencers');
        $faq  = array(
            array('q' => 'Is there a free plan?', 'a' => 'Yes. Everyone starts with a Free account: it costs nothing, needs no card, and lets you follow creators, join memberships, unlock posts and buy tickets and bookings. To sell, upgrade to Creator or Studio, which include your page, memberships, pay-per-view, publishing and payouts.'),
            array('q' => 'What is the platform take rate?', 'a' => self::fee_sentence()),
            array('q' => 'Can I buy AI credits on any plan?', 'a' => 'AI tools are part of Creator and Studio, which include AI credits every month. Buy more at any time. Credits pay for AI images and video' . ($ai_first ? ', and for AI influencers on ' . $ai_first['name'] . ' and up' : '') . '.'),
            array('q' => 'Are plans and credits refundable?', 'a' => 'No. Plan charges and credit purchases are final and non-refundable. You can cancel a plan at any time and keep it until the end of the paid period.'),
            array('q' => 'Can I change plans later?', 'a' => 'Yes, up or down at any time from Billing. Moving between paid plans prorates. Moving to Free takes effect when your paid period ends. Anything over the new limits is kept and locked, never deleted.'),
        );
        foreach (PlanTiers::addons() as $ad) {
            $on = array(); foreach ((array) $ad['plans'] as $k) { $t = PlanTiers::get($k); if ($t) { $on[] = $t['name']; } }
            $faq[] = array('q' => 'Can I add more AI influencers?', 'a' => 'On ' . implode(' and ', $on) . ', yes: each extra AI influencer is $' . (int) $ad['price'] . ' a month, up to ' . (int) $ad['max'] . ' extra. Add or remove them from Billing.');
        }
        $faq = array_merge($faq, array(
            array('q' => 'Are there payment processing fees on top?', 'a' => 'Card processing fees apply to card payments as with any platform; they are separate from the take rate.'),
        ));
        $jsonld = array(
            self::software_app('/pricing', Main::site_name() . ' plans', self::pricing_meta()),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Pricing', 'url' => '/pricing'))),
        );
        $this->page('pricing', array('path' => '/pricing', 'title' => 'Pricing: ' . implode(', ', array_map(function ($r) { return $r['tier']['name'] . ' $' . number_format($r['amount'] / 100); }, self::pricing_rows())) . ' per Month', 'description' => self::pricing_meta(), 'type' => 'product', 'jsonld' => $jsonld), array('rows' => $rows, 'faq' => $faq, 'founding_left' => Founding::remaining_cached()));
    }
    /** "Free $0, Creator $49, Studio $199, all per month." (whatever PlanTiers offers) — one sentence. */
    public static function plan_price_sentence(): string {
        $bits = array();
        foreach (self::pricing_rows() as $r) {
            $bits[] = $r['tier']['name'] . ' $' . number_format($r['amount'] / 100);
        }
        return implode(', ', $bits) . ', all per month.';
    }

    /** Answer to "What do the plans cost?" (home FAQ + its schema): prices, Free's take rate and add-ons, from config. */
    public static function plan_cost_answer(): string {
        return trim(self::plan_price_sentence() . ' Free needs no card and is the account everyone starts with, to follow, subscribe and buy; it cannot sell and pays no fee. Selling starts on Creator, and the platform fee is ' . self::fee_short() . '; Studio also adds more AI influencers and team seats. ' . self::addon_sentence());
    }

    /** plan_offers() with availability, as Product markup (/features, /pricing) wants them. */
    /**
     * The platform as a SoftwareApplication with the plan prices. Not Product: Google treats a Product with offers
     * as a merchant listing and asks for shipping, returns and a Brand, none of which fit a subscription service.
     * No review/aggregateRating until there are real ones to show.
     */
    public static function software_app(string $path, string $name, string $description): array {
        $app = array('@type' => 'SoftwareApplication', 'name' => $name, 'description' => $description,
            'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
            'url' => SeoMeta::base() . $path, 'image' => SeoMeta::default_image(), 'publisher' => SeoMeta::org());
        $offers = self::plan_offers();
        if (!empty($offers)) { $app['offers'] = $offers; }
        return $app;
    }

    /** schema.org Offer rows for every plan and add-on (home, /features and /pricing SoftwareApplication). */
    public static function plan_offers(): array {
        $offers = array();
        foreach (self::pricing_rows() as $r) {
            $offers[] = array('@type' => 'Offer', 'name' => $r['tier']['name'],
                'price' => number_format($r['amount'] / 100, 2, '.', ''), 'priceCurrency' => 'USD',
                'url' => SeoMeta::base() . '/pricing');
        }
        foreach (PlanTiers::addons() as $ad) {
            $offers[] = array('@type' => 'Offer', 'name' => $ad['name'] . ' add-on (per month)',
                'price' => number_format((float) $ad['price'], 2, '.', ''), 'priceCurrency' => 'USD',
                'url' => SeoMeta::base() . '/pricing');
        }
        return $offers;
    }

    /** "Extra AI influencer: $15/month each on Creator, up to 5." for every add-on, from config. */
    public static function addon_sentence(): string {
        $bits = array();
        foreach (PlanTiers::addons() as $ad) {
            $on = array(); foreach ((array) $ad['plans'] as $k) { $t = PlanTiers::get($k); if ($t) { $on[] = $t['name']; } }
            $bits[] = $ad['name'] . ': $' . (int) $ad['price'] . '/month each on ' . implode(' and ', $on) . ', up to ' . (int) $ad['max'] . '.';
        }
        return implode(' ', $bits);
    }

    /** Meta description for /pricing, built from the plans so the prices in search results stay right. */
    public static function pricing_meta(): string {
        $bits = array();
        foreach (self::pricing_rows() as $r) {
            $bits[] = $r['tier']['name'] . ' $' . number_format($r['amount'] / 100) . '/mo';
        }
        return 'Plans: ' . implode(', ', $bits) . '. Everyone starts with a Free account to follow, subscribe and buy; selling starts on Creator. Platform fee: ' . self::fee_short() . '.';
    }

    /** Our column of the comparison table, derived from PlanTiers so it can't drift. */
    /** One-line role per plan (home plan strip, pricing cards), by PlanTiers key. */
    // card label, then the clause after the plan name in the hero sentence. Free builds a page in the Studio but
    // has no public page and cannot sell, so its line says build, not get.
    const PLAN_ROLES = array(
        'free'    => array('Build your page', 'lets you build your page'),
        'creator' => array('Get discovered',  'gets you discovered'),
        'studio'  => array('Get promoted',    'gets you promoted'),
    );

    public static function plan_role(string $key): string {
        return (string) (self::PLAN_ROLES[$key][0] ?? '');
    }

    /** "Free lets you build your page. Creator gets you discovered. Studio gets you promoted." from PLAN_ROLES, for the plans on sale. */
    public static function plan_roles_sentence(): string {
        $bits = array();
        foreach (self::pricing_rows() as $r) {
            $clause = (string) (self::PLAN_ROLES[(string) $r['tier']['key']][1] ?? '');
            if ($clause !== '') { $bits[] = $r['tier']['name'] . ' ' . $clause . '.'; }
        }
        return implode(' ', $bits);
    }

    /** The plans that sell: paid, not retired. Free is the fan account and has no fee because it sells nothing. */
    public static function selling_tiers(): array {
        $out = array();
        foreach (PlanTiers::all() as $t) { if ((int) ($t['price'] ?? 0) > 0 && empty($t['retired'])) { $out[] = $t; } }
        return $out;
    }

    /** "10% on Creator, 3% on Studio" — the fee per selling plan, from config, never a range that includes Free. */
    public static function fee_short(): string {
        $bits = array();
        foreach (self::selling_tiers() as $t) { $bits[] = (int) $t['limits']['fee_percent'] . '% on ' . $t['name']; }
        return implode(', ', $bits);
    }

    /**
     * The plan card's fee comparison: "OnlyFans takes 20%. You pay less than a sixth of that." Both numbers come from
     * config (COMPETITORS['onlyfans'] and the tier's fee_percent), so the wording follows the ratio: an exact 2x says
     * "twice as much of the difference", a whole ratio "a fifth of that", anything between "less than a sixth of that".
     * '' when there is nothing to compare (Free, or a fee at or above theirs).
     */
    public static function fee_vs_onlyfans(array $tier): string {
        $c = self::COMPETITORS['onlyfans'] ?? array();
        if (!preg_match('/(\d+(?:\.\d+)?)%/', (string) ($c['fee_short'] ?? ''), $m)) { return ''; }
        $theirs = (float) $m[1];
        $ours   = (float) ($tier['limits']['fee_percent'] ?? 0);
        if ($ours <= 0 || $theirs <= $ours) { return ''; }
        $ratio = $theirs / $ours;
        $whole = abs($ratio - round($ratio)) < 0.001;
        $n     = $whole ? (int) round($ratio) : (int) floor($ratio);
        $parts = array(2 => 'half', 3 => 'a third', 4 => 'a quarter', 5 => 'a fifth', 6 => 'a sixth', 7 => 'a seventh', 8 => 'an eighth', 9 => 'a ninth', 10 => 'a tenth');
        if ($whole && $n === 2)  { $tail = 'You keep twice as much of the difference.'; }
        elseif ($n < 2)          { $tail = 'You pay less.'; }
        else                     { $tail = 'You pay ' . ($whole ? '' : 'less than ') . ($parts[$n] ?? 'a ' . $n . 'th') . ' of that.'; }
        return (string) ($c['name'] ?? 'OnlyFans') . ' takes ' . rtrim(rtrim(number_format($theirs, 2), '0'), '.') . '%. ' . $tail;
    }

    /** The quotable fee sentence (pricing FAQ, payouts page, llms.txt, the drafter's facts): numbers, plan prices, what Free is. */
    public static function fee_sentence(): string {
        $bits = array();
        foreach (self::selling_tiers() as $t) { $bits[] = (int) $t['limits']['fee_percent'] . '% of each sale on the ' . $t['name'] . ' plan ($' . number_format((int) $t['price']) . '/month)'; }
        $last = array_pop($bits);
        $list = (count($bits) > 0) ? implode(', ', $bits) . ' and ' . $last : $last;
        return Main::site_name() . '\'s platform fee is ' . $list . '. Free accounts are for fans and cannot sell, so they pay no platform fee. Card processing fees are separate.';
    }

    /**
     * One quotable, brand-named sentence per question people ask AI assistants (fee, OnlyFans alternative,
     * AI influencer, payouts, live video, link in bio). Shown on the page each belongs to and listed in
     * llms.txt under Facts. Numbers come from config.
     */
    public static function quotable_facts(): array {
        $site = Main::site_name();
        $host = preg_replace('#^https?://(www\.)?#', '', SeoMeta::base());
        $of = (string) (self::COMPETITORS['onlyfans']['fee_short'] ?? '');
        $of_pct = preg_match('/\d+%/', $of, $m) ? $m[0] : '';
        $inf = array();
        foreach (self::selling_tiers() as $t) { if ((int) ($t['limits']['influencers'] ?? 0) > 0) { $inf[] = (int) $t['limits']['influencers'] . ' on ' . $t['name']; } }
        return array(
            'fee'          => self::fee_sentence(),
            'onlyfans'     => $site . ' is an OnlyFans alternative with a lower platform fee (' . self::fee_short() . ($of_pct !== '' ? ', against the ' . $of_pct . ' OnlyFans is reported to keep' : '') . '), and it sells memberships, pay-per-view, bundles, services and live events from one page.',
            'ai_influencer'=> 'On ' . $site . ' you can create an AI influencer from your photos or a description, generate photos and short videos of the same person, and sell them as pay-per-view posts, paid messages or membership content' . ($inf ? ' (AI influencers included: ' . implode(', ', $inf) . ')' : '') . '.',
            'payouts'      => 'Every sale on ' . $site . ', from memberships and unlocks to bundles, services and events, lands in one balance net of your plan\'s fee, and you cash out to your bank whenever you want with no per-payout fee.',
            'events'       => $site . ' sells tickets to live events and 1:1 sessions and hosts the call itself, in a built-in video room with a waiting room, chat, screen share and host controls, so fans join without Zoom or a separate link.',
            'link_in_bio'  => $site . ' is a link-in-bio page that takes payments: your page at ' . $host . '/@handle holds tracked links plus memberships, pay-per-view posts, bundles, services and events, fans pay by card or credit wallet, and earnings pay out to your bank.',
        );
    }

    public static function our_facts(): array {
        return array(
            'fee'       => self::fee_short() . ' (Free is for fans and cannot sell)',
            'payout'    => 'Direct to your bank account',
            'content'   => 'Posts, pay-per-view, bundles, memberships with tiers, services, events, links',
            'socials'   => 'Publishes to nine social networks from one studio',
            'ai'        => 'AI captions, AI inbox replies, AI influencers',
            'ownership' => 'Export your audience and media any time',
        );
    }

    /** Competitor groups on the best-of page, in page order. */
    const COMPETITOR_GROUPS = array(
        'fan'        => array('Subscription platforms', 'Paid subscriptions, pay-per-view and messages inside the platform\'s own app.'),
        'membership' => array('Memberships and tips', 'Recurring support tiers, tips and small shops for podcasters, writers and artists.'),
        'bio'        => array('Link-in-Bio Storefronts', 'A page for your bio link that sells digital products, courses or bookings.'),
    );

    /** Best-of FAQ: every answer is built from the sourced competitor rows and our own plan facts. */
    public static function best_faq(): array {
        $c = self::COMPETITORS; $us = self::our_facts(); $site = Main::site_name();
        return array(
            array('q' => 'Which creator platform takes the lowest fee?', 'a' => 'It depends on what you sell. Ko-fi Free charges no service fee on one-time tips and 5% on memberships and shop sales. Stan charges no transaction fee but costs $29 or $99 a month. Subscription platforms like OnlyFans and Fansly keep about 20%. ' . $site . ' takes ' . $us['fee'] . '. Card processing fees apply on top almost everywhere.'),
            array('q' => 'Which platforms publish to my social accounts?', 'a' => 'Linktree\'s Social Planner auto-posts to Instagram, TikTok, Facebook, LinkedIn, Pinterest and YouTube Shorts. ' . $site . ' publishes to nine networks from one studio. Patreon, Ko-fi, Fansly, OnlyFans, Beacons and Stan do not publish automatic posting to your social accounts.'),
            array('q' => 'Can I sell memberships and pay-per-view on the same platform?', 'a' => 'Subscription platforms such as OnlyFans and Fansly combine subscriptions with pay-per-view posts and messages. Patreon combines memberships with digital products. ' . $site . ' sells memberships, pay-per-view posts, bundles, services and events from one page.'),
            array('q' => 'Can I export my audience if I leave?', 'a' => 'Patreon, Ko-fi, Linktree, Beacons and Stan all let you download your members, supporters or customers as a CSV. OnlyFans and Fansly do not publish an export. ' . $site . ': ' . $us['ownership'] . '.'),
            array('q' => 'Can I use more than one platform?', 'a' => 'Yes. Many creators keep a subscription platform, a link-in-bio page and a membership platform at once. The simplest setup is one page that links or sells everything, with your socials pointing to it.'),
        );
    }

    public function bestPlatformsAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $path = '/best-creator-monetization-platforms'; $title = 'Best creator monetization platforms';
        $desc = 'How the main creator platforms compare on fees, what you can sell, payouts, social publishing and ownership, with sources.';
        $items = array(array('@type' => 'ListItem', 'position' => 1, 'name' => Main::site_name(), 'url' => SeoMeta::base() . '/features'));
        $pos = 2; foreach (self::COMPETITORS as $slug => $c) { if (!isset(self::COMPETITOR_GROUPS[$c['type'] ?? ''])) { continue; } $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $c['name'], 'url' => SeoMeta::base() . '/compare/' . $slug); }
        $jsonld = array(
            SeoMeta::article(array('headline' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $path, 'published' => '2026-09-21T00:00:00+00:00')),
            array('@type' => 'ItemList', 'name' => $title, 'itemListElement' => $items),
            SeoMeta::faq(self::best_faq()),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Best creator monetization platforms', 'url' => $path))),
        );
        $this->page('best-platforms', array('path' => $path, 'title' => 'Best Creator Monetization Platforms (' . gmdate('Y') . ')', 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld), array('faq' => self::best_faq()));
    }

    public function monetizeAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $path = '/monetize-your-content'; $title = 'How to monetize your content';
        $desc = 'Memberships, pay-per-view, bundles, services and events: the five ways creators get paid, and how to price each one.';
        $faq = array(
            array('q' => 'How many membership tiers should I have?', 'a' => 'Two to start: an easy entry tier and one higher tier. Add a third only when fans ask for something in between.'),
            array('q' => 'Should members get pay-per-view posts free?', 'a' => 'No. Pay-per-view is for your strongest single pieces and is priced for everyone. Put your regular content on the tiers.'),
            array('q' => 'How do I price a service?', 'a' => 'Your hourly worth times the real time it takes, plus a third for revisions and messages. Fixed price, fixed scope, a delivery window you can keep.'),
            array('q' => 'Can I run all five on one page?', 'a' => 'Yes. On ' . Main::site_name() . ' memberships, pay-per-view, bundles, services and events all live on your public page and share one wallet for fans.'),
        );
        $jsonld = array(
            SeoMeta::article(array('headline' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $path, 'published' => '2026-09-21T00:00:00+00:00')),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Monetize your content', 'url' => $path))),
        );
        $this->page('monetize', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld), array('faq' => $faq));
    }

    /** The founding creator offer (/founding): terms, what founding creators agree to, spots left (Founding). */
    public static function founding_meta(): array {
        $c = PlanTiers::get(Founding::PLAN);
        return array('title' => 'Founding Creators: First Period Free, ' . Founding::fee_label() . ' Fee Locked',
            'description' => Founding::SPOTS . ' founding spots on the ' . $c['name'] . ' plan, open until they are filled: your first billing period free and the ' . Founding::fee_label() . ' Studio fee locked in while the plan stays active.');
    }

    public static function founding_faq(): array {
        $c = PlanTiers::get(Founding::PLAN); $price = '$' . number_format((int) $c['price']);
        return array(
            array('q' => 'Who can claim a founding spot?', 'a' => 'Any account starting the ' . $c['name'] . ' plan from Free, while spots remain. There are ' . Founding::SPOTS . ' founding spots, one per account, open until they are filled.'),
            array('q' => 'What does it cost?', 'a' => 'Your first billing period on ' . $c['name'] . ' is free. After that the plan is ' . $price . ' a month, charged to your card until you cancel. Plan charges are final and non-refundable.'),
            array('q' => 'Is the free period always a full month?', 'a' => 'Usually. If your account already renews a monthly AI credit pack, the plan joins that billing date, so the free period runs until then and the regular price starts on that date.'),
            array('q' => 'How long does the ' . Founding::fee_label() . ' fee last?', 'a' => 'For as long as your ' . $c['name'] . ' plan stays active, including a short past-due grace period. If you change plans, move to Free or the plan lapses, the founding terms end and do not come back.'),
            array('q' => 'What do founding creators agree to?', 'a' => 'To share a short testimonial about their experience (we ask after two weeks), to be featured in the creator directory and on the home page, and to stay listed in the directory.'),
            array('q' => 'Do I need a card?', 'a' => 'Yes. Nothing is charged today, but the card is kept for the monthly charge that starts after the free period. Cancel any time from Billing before then and you will not be charged.'),
            array('q' => 'How do I get paid?', 'a' => 'Every sale lands in one balance net of your fee, and you cash out to your bank on request with a ' . Price::PAYOUT_MIN_LABEL . ' minimum and no platform hold.'),
        );
    }

    public function foundingAction(){
        $u = Main::get_url();
        if (count($u) === 2 && (string) $u[1] === 'testimonial') { (new FoundingController())->testimonialAction(); return; }   // the app page for founding creators
        if (count($u) > 1) { Errors::page_not_found(); return; }
        if (!self::$embedded) { header('Cache-Control: private, max-age=60'); }   // the spots counter is at most a minute old
        $path = '/founding'; $m = self::founding_meta(); $faq = self::founding_faq();
        $jsonld = array(
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Founding Creators', 'url' => $path))),
        );
        $signed_in = (int) Session::get('user_id') > 0;
        $this->page('founding', array('path' => $path, 'title' => $m['title'], 'description' => $m['description'], 'type' => 'website', 'jsonld' => $jsonld, 'no_band' => true),
            array('faq' => $faq, 'left' => Founding::remaining_cached(),
                  'claim_url' => $signed_in ? '/account/billing?tab=plan&plan=' . Founding::PLAN . '&founding=1' : '/?auth=register&role=creator&plan=' . Founding::PLAN . '&founding=1',
                  'claim_auth' => $signed_in ? '' : 'register'));
    }

    /** The affiliate program (/affiliates): Affiliates::RATE_PERCENT of the plan payments of referred accounts. */
    public static function affiliates_meta(): array {
        return array('title' => 'Affiliate Program: Earn ' . Affiliates::RATE_PERCENT . '% of Plan Payments',
            'description' => 'Refer creators to ' . Main::site_name() . ' and earn ' . Affiliates::RATE_PERCENT . '% of what they pay for their ' . self::selling_names() . ' plan, every month they stay on it. Payouts on request.');
    }

    /** "Creator or Studio": the selling plan names, from config. */
    private static function selling_names(): string {
        return implode(' or ', array_column(self::selling_tiers(), 'name'));
    }

    public static function affiliates_faq(): array {
        $r = Affiliates::RATE_PERCENT . '%'; $plans = self::selling_names();
        return array(
            array('q' => 'What do affiliates earn?', 'a' => $r . ' of what each account you refer pays for its ' . $plans . ' plan, on every paid invoice for as long as the account stays on a paid plan and keeps paying. What you earn depends entirely on who signs up and what they pay.'),
            array('q' => 'Is there a commission on credits?', 'a' => 'No. Credit packs and AI credit purchases earn nothing. Commissions are paid on plan payments only.'),
            array('q' => 'How is a referral counted?', 'a' => 'Someone opens your link, and creates an account in the same browser within ' . Affiliates::DAYS . ' days. If they open another affiliate\'s link after yours, the last link wins.'),
            array('q' => 'When can I cash out?', 'a' => 'Request a payout from your affiliate dashboard once you have ' . Price::PAYOUT_MIN_LABEL . ' earned. Payouts go to your bank.'),
            array('q' => 'What if a payment is disputed?', 'a' => 'A payment disputed with the bank reverses its commission. If that commission was already paid out, the amount is taken from your next earnings.'),
            array('q' => 'Who can apply?', 'a' => 'Anyone with an account, creators included. Applications are reviewed by our team, and your own account never earns a commission on itself.'),
        );
    }

    public function affiliatesAction(){
        $url = Main::get_url();
        if (in_array((string) ($url[1] ?? ''), array('apply', 'dashboard'), true) && count($url) === 2) {   // the signed-in pages (app shell)
            header('Cache-Control: no-store');   // live numbers, never the public pages' cache
            $c = new AffiliatesController(); $m = $url[1] . 'Action'; $c->$m(); return;
        }
        if (count($url) > 1) { Errors::page_not_found(); return; }
        $path = '/affiliates'; $m = self::affiliates_meta(); $faq = self::affiliates_faq();
        $jsonld = array(
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Affiliate Program', 'url' => $path))),
        );
        $signed_in = (int) Session::get('user_id') > 0;
        $mine = $signed_in ? Affiliates::approved_for_user((int) Session::get('user_id')) : null;
        $this->page('affiliates', array('path' => $path, 'title' => $m['title'], 'description' => $m['description'], 'type' => 'website', 'jsonld' => $jsonld, 'no_band' => true),
            array('faq' => $faq, 'plans' => self::selling_names(),
                  'apply_label' => $mine ? 'Open Your Dashboard' : 'Apply Now',
                  'apply_url' => $mine ? '/affiliates/dashboard' : ($signed_in ? '/affiliates/apply' : '/?auth=register&next=' . rawurlencode('/affiliates/apply'))));   // a full load, so ?next brings them back to the form
    }

    /**
     * One alternatives list page (AlternativesPages::PAGES) with placeholders filled from config: {site}, {fee_short},
     * {payout_min}, {year}. Adds 'path', 'title' and 'us' (our own entry, always first in the list).
     */
    public static function alternatives_page(string $key): ?array {
        if (!isset(AlternativesPages::PAGES[$key])) { return null; }
        $site = Main::site_name();
        $fill = array('{site}' => $site, '{fee_short}' => self::fee_short(), '{payout_min}' => Price::PAYOUT_MIN_LABEL, '{year}' => gmdate('Y'));
        $p = json_decode(strtr(json_encode(AlternativesPages::PAGES[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), array_map(function ($v) { return addcslashes($v, '"\\'); }, $fill)), true);
        $p['path']  = '/' . $key . '-alternatives';
        $p['title'] = $p['name'] . ' Alternatives for Creators in ' . gmdate('Y');
        $p['us'] = array(
            'name' => $site, 'kind' => 'One page, every way to sell',
            'text' => 'One public page at your handle that sells memberships, pay-per-view posts, bundles, services and live events, with a studio that publishes every post to nine social networks.',
            'points' => array(
                'Memberships, pay-per-view, bundles, services and events on one page',
                'Publishes to nine social networks from one studio',
                'AI influencers: photos and short videos of the same person, ready to sell',
                'A DM agent that drafts replies in your voice, with your approval',
                'Platform fee: ' . self::fee_short(),
                'Payouts to your bank on request, ' . Price::PAYOUT_MIN_LABEL . ' minimum, no platform hold',
            ),
            'note' => 'Plan charges and credit purchases are final and non-refundable.',
            'best_for' => 'Creators who sell more than a subscription and want one page, one studio and one balance.',
        );
        return $p;
    }

    public function alternativesAction(){
        $url = Main::get_url();
        if (count($url) > 1) { Errors::page_not_found(); return; }
        $key = preg_replace('/-alternatives$/', '', (string) ($url[0] ?? ''));
        $p = self::alternatives_page($key);
        if ($p === null) { Errors::page_not_found(); return; }
        $base = SeoMeta::base();
        $items = array(array('@type' => 'ListItem', 'position' => 1, 'name' => Main::site_name(), 'url' => $base . '/features'));
        $pos = 2;
        foreach ($p['others'] as $o) {
            $item = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $o['name']);
            if ($o['compare'] !== '') { $item['url'] = $base . '/compare/' . $o['compare']; }
            $items[] = $item;
        }
        $jsonld = array(
            SeoMeta::article(array('headline' => $p['title'], 'description' => $p['description'], 'url' => $base . $p['path'], 'published' => '2026-10-08T00:00:00+00:00')),
            array('@type' => 'ItemList', 'name' => $p['title'], 'itemListOrder' => 'https://schema.org/ItemListOrderAscending', 'numberOfItems' => count($items), 'itemListElement' => $items),
            SeoMeta::faq($p['faq']),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => $p['name'] . ' Alternatives', 'url' => $p['path']))),
        );
        $this->page('alternatives', array('path' => $p['path'], 'title' => $p['title'], 'description' => $p['description'], 'type' => 'article', 'jsonld' => $jsonld, 'no_band' => true), array('p' => $p, 'faq' => $p['faq']));
    }

    /** The competitor's researched questions plus one about using both, shared by the page and its FAQ markup. */
    public static function compare_faq(array $c): array {
        $faq = (array) ($c['faq'] ?? array());
        $faq[] = array('q' => 'Can I use ' . Main::site_name() . ' and ' . $c['name'] . ' at the same time?',
            'a' => (($c['type'] ?? '') === 'ai')   // a generation tool has no fan page to keep live or publish to
                ? 'Yes. Keep generating in ' . $c['name'] . ' while you build your page here: download your images, upload them to your Library, and publish or sell them from the Studio.'
                : 'Yes. Keep your ' . $c['name'] . ' page live while you set up, publish to both from the studio, and put your new page in every bio so fans can move at their own pace.');
        return $faq;
    }

    public function compareAction(){
        $url  = Main::get_url();
        if (count($url) > 2) { Errors::page_not_found(); return; }
        $raw  = (string) ($url[1] ?? '');
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', $raw));
        if ($slug === '' || $slug !== $raw || !isset(self::COMPETITORS[$slug])) { Errors::page_not_found(); return; }
        $c = self::COMPETITORS[$slug];
        $path = '/compare/' . $slug; $title = Main::site_name() . ' vs ' . $c['name'];
        $desc = (preg_match('/^[AEIOU]/i', $c['name']) ? 'An ' : 'A ') . $c['name'] . ' alternative for creators: fees, content types, payouts and ownership compared, with sources.';
        $faq = self::compare_faq($c);
        $jsonld = array(
            SeoMeta::article(array('headline' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $path, 'published' => '2026-09-21T00:00:00+00:00', 'modified' => gmdate('c', filemtime(Main::app_path() . '/app/controllers/PagesController.php')))),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => $c['name'], 'url' => $path))),
        );
        $this->page('compare', array('path' => $path, 'title' => $title . ': Fees and Features Compared', 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld), array('slug' => $slug, 'c' => $c, 'faq' => $faq));
    }

    /**
     * Creator directory: /creators and /creators/<niche> (DirectoryService; opt-in, safe for work). ?q= searches name,
     * handle and bio (noindex), ?sort= picks the order (canonical always points at the default order), ?page=N.
     */
    public function creatorsAction(){
        $url  = Main::get_url();
        $cats = DirectoryService::categories();
        if (count($url) > 2) { Errors::page_not_found(); return; }
        $cat = '';
        if (count($url) === 2) {
            $cat = (string) $url[1];
            if (!isset($cats[$cat])) { Errors::page_not_found(); return; }
        }
        $raw  = (string) ($_GET['page'] ?? '1');
        $page = ctype_digit($raw) ? max(1, (int) $raw) : 0;
        if ($page === 0) { Errors::page_not_found(); return; }
        $sorts = array_keys(DirectoryService::SORTS);
        $sort  = is_string($_GET['sort'] ?? null) && isset(DirectoryService::SORTS[$_GET['sort']]) ? $_GET['sort'] : $sorts[0];   // unknown sort: the default order
        $q    = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
        $q_on = mb_strlen(ltrim($q, '@')) >= 2;

        $model  = new CreatorProfileModel();
        $counts = array();
        try { $counts = $model->directory_counts(); }
        catch (\Throwable $e) { error_log('[directory] counts: ' . $e->getMessage()); }   // before the niches SQL runs: no chips, the list still renders
        $listed = $model->directory_total($cat, '');
        $all    = $cat === '' ? $listed : $model->directory_total('', '');   // the "All" chip: every listed creator, on every page
        $total  = $q_on ? $model->directory_total($cat, $q) : $listed;
        $pages  = max(1, (int) ceil($total / DirectoryService::PER_PAGE));
        if ($page > $pages) { Errors::page_not_found(); return; }
        $creators = $model->directory($cat, $q_on ? $q : '', $sort, DirectoryService::PER_PAGE, ($page - 1) * DirectoryService::PER_PAGE);
        $featured = ($page === 1 && !$q_on && $listed > DirectoryService::FEATURED) ? $model->directory_featured($cat, gmdate('Y-m-d'), DirectoryService::FEATURED) : array();

        $label = $cat === '' ? '' : $cats[$cat];
        $base  = '/creators' . ($cat !== '' ? '/' . $cat : '');
        $title = $q_on ? 'Creators Matching "' . $q . '"' : ($cat === '' ? 'Creator Directory: Find Creators to Follow and Support' : $label . ' Creators to Follow and Support');
        $show_n = $listed >= self::DIRECTORY_COUNT_MIN;
        $desc  = $cat === ''
            ? 'Browse creators on ' . Main::site_name() . ': memberships, posts, services and events from ' . ($show_n ? $listed . ' creators' : 'creators') . '.'
            : 'Browse ' . ($show_n ? $listed . ' ' : '') . strtolower($label) . ' creators on ' . Main::site_name() . ' and follow, subscribe or book them from their page.';
        $link = function ($n) use ($base, $sort, $q, $q_on, $sorts) { return self::directory_url($base, $n, $sort === $sorts[0] ? '' : $sort, $q_on ? $q : ''); };
        $extra = array();
        if ($page > 1)      { $extra[] = '<link rel="prev" href="' . Sections::e(SeoMeta::base() . $link($page - 1)) . '">'; }
        if ($page < $pages) { $extra[] = '<link rel="next" href="' . Sections::e(SeoMeta::base() . $link($page + 1)) . '">'; }

        $items = array(); $pos = 1;
        foreach ($creators as $c) { $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'url' => SeoMeta::base() . '/@' . rawurlencode((string) $c['u_name']), 'name' => (string) ($c['display_name'] ?: $c['u_name'])); }
        $crumbs = array(array('name' => 'Home', 'url' => '/'), array('name' => 'Creators', 'url' => '/creators'));
        if ($cat !== '') { $crumbs[] = array('name' => $label, 'url' => $base); }
        $jsonld = array(
            array('@type' => 'CollectionPage', 'name' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $base, 'isPartOf' => array('@type' => 'WebSite', 'name' => Main::site_name(), 'url' => SeoMeta::base() . '/')),
            array('@type' => 'ItemList', 'numberOfItems' => $total, 'itemListElement' => $items),
            SeoMeta::breadcrumbs($crumbs),
        );
        // canonical: the default order of this page; a search is noindex and points at the unfiltered list.
        $this->page('creators', array('path' => self::directory_url($base, $q_on ? 1 : $page, '', ''), 'title' => $title . ($page > 1 ? ' (Page ' . $page . ')' : ''), 'description' => $desc,
            'jsonld' => $jsonld, 'extra' => $extra, 'noindex' => $total === 0 || $q_on),
            array('creators' => $creators, 'featured' => $featured, 'counts' => $counts, 'cats' => $cats, 'show_counts' => $show_n, 'cat' => $cat, 'label' => $label,
                'page' => $page, 'pages' => $pages, 'total' => $total, 'listed' => $listed, 'all' => $all, 'base' => $base, 'sort' => $sort, 'q' => $q, 'q_on' => $q_on, 'link' => $link));
    }

    /** A directory URL: /creators[/<niche>] with ?q=, ?sort= (omitted for the default) and ?page= (omitted for page 1). */
    public static function directory_url($base, $page, $sort, $q): string {
        $qs = array();
        if ((string) $q !== '')    { $qs['q'] = (string) $q; }
        if ((string) $sort !== '') { $qs['sort'] = (string) $sort; }
        if ((int) $page > 1)       { $qs['page'] = (int) $page; }
        return $base . (empty($qs) ? '' : '?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986));
    }
}
