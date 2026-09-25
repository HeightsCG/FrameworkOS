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
        'pricing'                             => 'pricing',
        'compare'                              => 'compare',
        'best-creator-monetization-platforms' => 'bestPlatforms',
        'monetize-your-content'               => 'monetize',
        'creators'                            => 'creators',
        'terms'                               => 'terms',
        'privacy'                             => 'privacy',
    );

    /** Where legal and privacy requests go (shown on /terms and /privacy). */
    const LEGAL_CONTACT = 'support@creatorlinkstudio.com';

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
    );

    public function __construct(){
        parent::__construct();
        if (!self::$embedded) { header('Cache-Control: private, max-age=300'); }
    }

    private function page($view, array $meta, array $vars = array()){
        $meta['url'] = SeoMeta::base() . $meta['path'];
        if (!isset($meta['jsonld'])) {
            $meta['jsonld'] = array(
                SeoMeta::org(),
                SeoMeta::breadcrumbs(array(
                    array('name' => 'Home', 'url' => '/'),
                    array('name' => $meta['title'], 'url' => $meta['path']),
                )),
            );
        }
        unset($meta['path']);
        $meta['sections'] = true;   // full-width section system (libs/Classes/Sections.php)
        $this->view->public_page(Main::app_path() . '/app/views/pages/' . $view . '.php', $meta, $vars);
    }

    /** Feature pages under /features/<slug> (FeaturePages::PAGES). */
    public static function feature_pages(): array {
        return FeaturePages::PAGES;
    }

    public function featuresAction(){
        $url = Main::get_url();
        if (count($url) > 1) {
            $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $url[1]));
            $pages = self::feature_pages();
            if (count($url) > 2 || $slug === '' || !isset($pages[$slug])) { Errors::page_not_found(); return; }
            $page = $pages[$slug];
            $path = '/features/' . $slug;
            $jsonld = array(
                SeoMeta::faq((array) ($page['faq'] ?? array())),
                SeoMeta::breadcrumbs(array(
                    array('name' => 'Home', 'url' => '/'),
                    array('name' => 'Features', 'url' => '/features'),
                    array('name' => $page['nav_title'] ?? $page['title'], 'url' => $path),
                )),
            );
            $siblings = $pages; unset($siblings[$slug]);
            $cta = (array) ($page['cta'] ?? array());
            $this->page('feature', array('path' => $path, 'title' => $page['title'], 'description' => $page['description'], 'jsonld' => $jsonld),
                array('page' => $page, 'slug' => $slug, 'siblings' => $siblings,
                      'cta_title' => $cta['title'] ?? 'Start selling from one page.', 'cta_text' => $cta['text'] ?? ''));
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
            // Google's product snippets need offers (or a review/rating, which we don't have): the plan prices, same as /pricing.
            array('@type' => 'Product', 'name' => Main::site_name(), 'description' => 'Creator platform with memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'brand' => SeoMeta::org(), 'url' => SeoMeta::base() . '/features', 'offers' => self::product_offers()),
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

    public function pricingAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $rows = self::pricing_rows();
        $free = PlanTiers::get(PlanTiers::FREE_KEY);
        $ai_first = PlanTiers::lowest_including('influencers');
        $faq  = array(
            array('q' => 'Is there a free plan?', 'a' => 'Yes. Free costs nothing and needs no card. It includes your page, memberships, pay-per-view, publishing and payouts. The platform takes ' . (int) $free['limits']['fee_percent'] . '% of what you earn. Paid plans lower that rate and add AI influencers, automations and AI inbox replies.'),
            array('q' => 'What is the platform take rate?', 'a' => 'A flat percentage of what fans pay you, set by your plan and shown on this page. It falls as you move up.'),
            array('q' => 'Can I buy AI credits on any plan?', 'a' => 'Yes, including Free. Free starts with ' . (int) $free['limits']['ai_credits'] . ' AI credits, and paid plans add credits every month. Buy more at any time. Credits pay for AI images and video' . ($ai_first ? ', and for AI influencers on ' . $ai_first['name'] . ' and up' : '') . '.'),
            array('q' => 'Can I change plans later?', 'a' => 'Yes, up or down at any time from Billing. Moving between paid plans prorates. Moving to Free takes effect when your paid period ends. Anything over the new limits is kept and locked, never deleted.'),
        );
        foreach (PlanTiers::addons() as $ad) {
            $on = array(); foreach ((array) $ad['plans'] as $k) { $t = PlanTiers::get($k); if ($t) { $on[] = $t['name']; } }
            $faq[] = array('q' => 'Can I add more AI influencers?', 'a' => 'On ' . implode(' and ', $on) . ', yes: each extra AI influencer is $' . (int) $ad['price'] . ' a month, up to ' . (int) $ad['max'] . ' extra. Add or remove them from Billing.');
        }
        $faq = array_merge($faq, array(
            array('q' => 'Are there payment processing fees on top?', 'a' => 'Card processing fees apply to card payments as with any platform; they are separate from the take rate.'),
        ));
        $offers = self::product_offers();
        $product = array('@type' => 'Product', 'name' => Main::site_name() . ' plans', 'brand' => SeoMeta::org(), 'url' => SeoMeta::base() . '/pricing');
        if (!empty($offers)) { $product['offers'] = $offers; }
        $jsonld = array(
            $product,
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Pricing', 'url' => '/pricing'))),
        );
        $this->page('pricing', array('path' => '/pricing', 'title' => 'Pricing: ' . implode(', ', array_map(function ($r) { return $r['tier']['name'] . ' $' . number_format($r['amount'] / 100); }, self::pricing_rows())) . ' per Month', 'description' => self::pricing_meta(), 'type' => 'product', 'jsonld' => $jsonld), array('rows' => $rows, 'faq' => $faq));
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
        $free = PlanTiers::get(PlanTiers::FREE_KEY);
        return trim(self::plan_price_sentence() . ' Free needs no card and takes ' . (int) ($free['limits']['fee_percent'] ?? 0) . '% of what you earn; the paid plans lower the take rate, raise the limits and add AI influencers, automations and AI inbox replies. ' . self::addon_sentence());
    }

    /** plan_offers() with availability, as Product markup (/features, /pricing) wants them. */
    public static function product_offers(): array {
        $offers = array();
        foreach (self::plan_offers() as $o) { $offers[] = $o + array('availability' => 'https://schema.org/InStock'); }
        return $offers;
    }

    /** schema.org Offer rows for every plan and add-on (home SoftwareApplication + /pricing Product). */
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
        $free = PlanTiers::get(PlanTiers::FREE_KEY);
        return 'Plans: ' . implode(', ', $bits) . '. Free forever with a ' . (int) ($free['limits']['fee_percent'] ?? 0) . '% take rate; paid plans lower it and raise the limits.';
    }

    /** Our column of the comparison table, derived from PlanTiers so it can't drift. */
    public static function our_facts(): array {
        $tiers = PlanTiers::all(); $fees = array();
        foreach ($tiers as $t) { $fees[] = (int) $t['limits']['fee_percent']; }
        return array(
            'fee'       => min($fees) . '% to ' . max($fees) . '% by plan (falls as you grow)',
            'payout'    => 'Direct to your bank account',
            'content'   => 'Posts, pay-per-view, bundles, memberships with tiers, services, events, links',
            'socials'   => 'Publishes to 9 social networks from one studio',
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
        $pos = 2; foreach (self::COMPETITORS as $slug => $c) { $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $c['name'], 'url' => SeoMeta::base() . '/compare/' . $slug); }
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

    /** The competitor's researched questions plus one about using both, shared by the page and its FAQ markup. */
    public static function compare_faq(array $c): array {
        $faq = (array) ($c['faq'] ?? array());
        $faq[] = array('q' => 'Can I use ' . Main::site_name() . ' and ' . $c['name'] . ' at the same time?',
            'a' => 'Yes. Keep your ' . $c['name'] . ' page live while you set up, publish to both from the studio, and put your new page in every bio so fans can move at their own pace.');
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

    /** Creator directory: /creators and /creators/<category>, ?page=N (DirectoryService; opt-in, safe for work). */
    public function creatorsAction(){
        $url = Main::get_url();
        if (count($url) > 2) { Errors::page_not_found(); return; }
        $cat = '';
        if (count($url) === 2) {
            $cat = (string) $url[1];
            if (!isset(DirectoryService::CATEGORIES[$cat])) { Errors::page_not_found(); return; }
        }
        $raw  = (string) ($_GET['page'] ?? '1');
        $page = ctype_digit($raw) ? max(1, (int) $raw) : 0;
        if ($page === 0) { Errors::page_not_found(); return; }

        $model  = new CreatorProfileModel();
        $counts = $model->directory_counts();
        $total  = $cat === '' ? array_sum($counts) : (int) ($counts[$cat] ?? 0);
        $pages  = max(1, (int) ceil($total / DirectoryService::PER_PAGE));
        if ($page > $pages) { Errors::page_not_found(); return; }
        $creators = $model->directory($cat, DirectoryService::PER_PAGE, ($page - 1) * DirectoryService::PER_PAGE);

        $label = $cat === '' ? '' : DirectoryService::CATEGORIES[$cat];
        $base  = '/creators' . ($cat !== '' ? '/' . $cat : '');
        $title = $cat === '' ? 'Creator Directory: Find Creators to Follow and Support' : $label . ' Creators to Follow and Support';
        $desc  = $cat === ''
            ? 'Browse creators on ' . Main::site_name() . ': memberships, posts, services and events from ' . $total . ' creators, sorted by who\'s active.'
            : 'Browse ' . $total . ' ' . strtolower($label) . ' creator' . ($total === 1 ? '' : 's') . ' on ' . Main::site_name() . ' and follow, subscribe or book them from their page.';
        $extra = array();
        if ($page > 1)      { $extra[] = '<link rel="prev" href="' . Sections::e(SeoMeta::base() . $base . ($page > 2 ? '?page=' . ($page - 1) : '')) . '">'; }
        if ($page < $pages) { $extra[] = '<link rel="next" href="' . Sections::e(SeoMeta::base() . $base . '?page=' . ($page + 1)) . '">'; }

        $items = array(); $pos = 1;
        foreach ($creators as $c) { $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'url' => SeoMeta::base() . '/@' . rawurlencode((string) $c['u_name']), 'name' => (string) ($c['display_name'] ?: $c['u_name'])); }
        $crumbs = array(array('name' => 'Home', 'url' => '/'), array('name' => 'Creators', 'url' => '/creators'));
        if ($cat !== '') { $crumbs[] = array('name' => $label, 'url' => $base); }
        $jsonld = array(
            array('@type' => 'CollectionPage', 'name' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $base, 'isPartOf' => array('@type' => 'WebSite', 'name' => Main::site_name(), 'url' => SeoMeta::base() . '/')),
            array('@type' => 'ItemList', 'numberOfItems' => $total, 'itemListElement' => $items),
            SeoMeta::breadcrumbs($crumbs),
        );
        $this->page('creators', array('path' => $base . ($page > 1 ? '?page=' . $page : ''), 'title' => $title . ($page > 1 ? ' (Page ' . $page . ')' : ''), 'description' => $desc,
            'jsonld' => $jsonld, 'extra' => $extra, 'noindex' => $total === 0),
            array('creators' => $creators, 'counts' => $counts, 'cat' => $cat, 'label' => $label, 'page' => $page, 'pages' => $pages, 'total' => $total, 'base' => $base));
    }
}
