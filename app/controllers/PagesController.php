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
    );

    /** Competitor facts used by /compare/* and the best-of page. Every row has a source + checked date.
     *  Fact-checked 2026-09-21 against the source URLs below; see task-7-report.md for the exact
     *  sentence relied on per row. Rows OnlyFans could not verify directly (its site is behind a
     *  Cloudflare bot check that blocked every fetch attempt) are 'Not published' rather than guessed. */
    const COMPETITORS = array(
        'fanvue' => array(
            'name' => 'Fanvue', 'url' => 'https://www.fanvue.com', 'checked' => '2026-09-21',
            'summary' => 'Fanvue is a subscription platform for creators with a focus on AI tools for chat and voice. It does not publish to your social accounts or give you a link-in-bio style page with services and events.',
            'fee'       => array('value' => '20% platform fee (creators keep 80% of gross revenue)', 'source' => 'https://legal.fanvue.com/creator-earnings-payouts'),
            'payout'    => array('value' => 'Bank transfer, crypto or third-party wallets; 7-day pending period (up to 28 days)', 'source' => 'https://legal.fanvue.com/creator-earnings-payouts'),
            'content'   => array('value' => 'Posts, subscriptions, tips, paid messages', 'source' => 'https://try.fanvue.com/fanvue'),
            'socials'   => array('value' => 'Not published', 'source' => 'https://www.fanvue.com'),
            'ai'        => array('value' => 'AI voice calls, AI voice notes, AI analytics', 'source' => 'https://www.fanvue.com'),
            'ownership' => array('value' => 'Not published', 'source' => 'https://help.fanvue.com'),
        ),
        'onlyfans' => array(
            'name' => 'OnlyFans', 'url' => 'https://onlyfans.com', 'checked' => '2026-09-21',
            'summary' => 'OnlyFans is the largest subscription platform for creators. It has the biggest audience and depends entirely on its own app, with no cross-posting and no services or events for sale.',
            'fee'       => array('value' => '20% of creator earnings', 'source' => 'https://en.wikipedia.org/wiki/OnlyFans', 'reported' => true),
            'payout'    => array('value' => 'Bank transfer and e-wallets, after a holding period (about 7 days)', 'source' => 'https://www.supercreator.app/guides/how-long-do-onlyfans-payouts-take', 'reported' => true),
            'content'   => array('value' => 'Subscriptions, tips, pay-per-view', 'source' => 'https://en.wikipedia.org/wiki/OnlyFans', 'reported' => true),
            'socials'   => array('value' => 'Not published', 'source' => 'https://onlyfans.com/help'),
            'ai'        => array('value' => 'Not published', 'source' => 'https://onlyfans.com/help'),
            'ownership' => array('value' => 'Not published', 'source' => 'https://onlyfans.com/help'),
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
        try { $meta['guides'] = (new SeoArticlesModel())->newest_published(3); } catch (\Throwable $e) { $meta['guides'] = array(); }
        unset($meta['path']);
        $this->view->public_page(Main::app_path() . '/app/views/pages/' . $view . '.php', $meta, $vars);
    }

    public function featuresAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $faq = array(
            array('q' => 'Do I need my own website?', 'a' => 'No. Your public page lives at our domain under your handle and includes your posts, tiers, services, events and links.'),
            array('q' => 'Can I keep posting to my social accounts?', 'a' => 'Yes. The studio publishes each post to your page and to any connected social accounts at the same time, and pulls their engagement back into analytics.'),
            array('q' => 'Who owns my content and subscriber list?', 'a' => 'You do. Media, posts and your audience list can be exported at any time.'),
            array('q' => 'How do I get paid?', 'a' => 'Payouts go through Stripe Connect to your bank account. Fans pay with a credit wallet for unlocks and by card for memberships.'),
            array('q' => 'Is adult content allowed?', 'a' => 'Yes, within the content policy. Adult posts are only shown to fans who opt in, and every upload is checked automatically.'),
        );
        $jsonld = array(
            array('@type' => 'Product', 'name' => Main::site_name(), 'description' => 'Creator platform with memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'brand' => SeoMeta::org(), 'url' => SeoMeta::base() . '/features'),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Features', 'url' => '/features'))),
        );
        $this->page('features', array('path' => '/features', 'title' => 'Features', 'description' => 'One creator platform for your public page, memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'type' => 'product', 'jsonld' => $jsonld), array('faq' => $faq));
    }
    /** One row per tier, in rank order, with the live Stripe monthly price when available. */
    public static function pricing_rows(): array {
        $cache = sys_get_temp_dir() . '/cls_public_plans.json';
        if (is_file($cache) && filemtime($cache) > time() - 600) {
            $plans = json_decode(file_get_contents($cache), true) ?: array();
        } else {
            $plans = (array) StripeService::get_plans();
            if (!empty($plans)) { @file_put_contents($cache, json_encode($plans), LOCK_EX); }
        }
        $by_tier = array();
        foreach ($plans as $p) {
            $key = PlanTiers::match((string) ($p['name'] ?? ''));
            if ($key === '' || (($p['interval'] ?? 'month') !== 'month')) { continue; }
            if (!isset($by_tier[$key])) { $by_tier[$key] = $p; }
        }
        $rows = array();
        foreach (PlanTiers::all() as $tier) {
            $p = $by_tier[$tier['key']] ?? null;
            $rows[] = array('tier' => $tier, 'amount' => $p ? (int) $p['amount'] : null, 'interval' => $p ? (string) $p['interval'] : null);
        }
        usort($rows, function ($a, $b) { return $a['tier']['rank'] <=> $b['tier']['rank']; });
        return $rows;
    }

    public function pricingAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $rows = self::pricing_rows();
        $faq  = array(
            array('q' => 'Is there a free plan?', 'a' => 'No. Every plan includes the whole platform and unlimited fans; the plans differ in take rate, seats, AI credits, automations and storage.'),
            array('q' => 'What is the platform take rate?', 'a' => 'A flat percentage of what fans pay you, set by your plan and shown on this page. It falls as you move up.'),
            array('q' => 'Can I change plans later?', 'a' => 'Yes, up or down at any time from Billing. Changes prorate.'),
            array('q' => 'Are there payment processing fees on top?', 'a' => 'Stripe processing fees apply to card payments as with any platform; they are separate from the take rate.'),
        );
        $offers = array();
        foreach ($rows as $r) {
            if ($r['amount'] === null) { continue; }
            $offers[] = array('@type' => 'Offer', 'name' => $r['tier']['name'], 'price' => number_format($r['amount'] / 100, 2, '.', ''), 'priceCurrency' => 'USD', 'url' => SeoMeta::base() . '/pricing', 'availability' => 'https://schema.org/InStock');
        }
        $product = array('@type' => 'Product', 'name' => Main::site_name() . ' plans', 'brand' => SeoMeta::org(), 'url' => SeoMeta::base() . '/pricing');
        if (!empty($offers)) { $product['offers'] = $offers; }
        $jsonld = array(
            $product,
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Pricing', 'url' => '/pricing'))),
        );
        $this->page('pricing', array('path' => '/pricing', 'title' => 'Pricing', 'description' => 'Three monthly plans. Every plan includes the whole platform; the take rate falls as you grow.', 'type' => 'product', 'jsonld' => $jsonld, 'wide' => true), array('rows' => $rows, 'faq' => $faq));
    }
    /** Our column of the comparison table, derived from PlanTiers so it can't drift. */
    public static function our_facts(): array {
        $tiers = PlanTiers::all(); $fees = array();
        foreach ($tiers as $t) { $fees[] = (int) $t['limits']['fee_percent']; }
        return array(
            'fee'       => min($fees) . '% to ' . max($fees) . '% by plan (falls as you grow)',
            'payout'    => 'Stripe Connect to your bank account',
            'content'   => 'Posts, pay-per-view, bundles, memberships with tiers, services, events, links',
            'socials'   => 'Publishes to 9 social networks from one studio',
            'ai'        => 'AI captions, AI inbox replies, AI influencers',
            'ownership' => 'Export your audience and media any time',
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
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Best creator monetization platforms', 'url' => $path))),
        );
        $this->page('best-platforms', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld));
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

    public function compareAction(){
        $url  = Main::get_url();
        if (count($url) > 2) { Errors::page_not_found(); return; }
        $raw  = (string) ($url[1] ?? '');
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', $raw));
        if ($slug === '' || $slug !== $raw || !isset(self::COMPETITORS[$slug])) { Errors::page_not_found(); return; }
        $c = self::COMPETITORS[$slug];
        $path = '/compare/' . $slug; $title = Main::site_name() . ' vs ' . $c['name'];
        $desc = 'A ' . $c['name'] . ' alternative for creators: fees, content types, payouts and ownership compared, with sources.';
        $jsonld = array(
            SeoMeta::article(array('headline' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $path, 'published' => '2026-09-21T00:00:00+00:00', 'modified' => gmdate('c', filemtime(Main::app_path() . '/app/controllers/PagesController.php')))),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => $c['name'], 'url' => $path))),
        );
        $this->page('compare', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld), array('slug' => $slug, 'c' => $c));
    }
}
