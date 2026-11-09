<?php
/**
 * Public, indexable product pages: /features, /pricing, /about, /press, /integrations and the
 * feature and niche pages. Routed from Bootstrap via
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
        'for'                                 => 'forNiche',   // /for/<niche> landing pages (NichePages) on the feature template
        'pricing'                             => 'pricing',
        'creators'                           => 'creators',
        'terms'                               => 'terms',
        'privacy'                             => 'privacy',
        'about'                               => 'about',
        'author'                              => 'author',
        'contact'                             => 'contact',
        'founding'                            => 'founding',
        'integrations'                        => 'integrations',   // /integrations and /integrations/<platform> (IntegrationPages)
        'press'                               => 'press',
        'alternatives'                        => 'alternativesHub',   // the AI influencer tool categories page
        'affiliates'                          => 'affiliates',   // /affiliates (public); /affiliates/apply and /affiliates/dashboard go on to AffiliatesController
        'start'                               => 'start',   // the TikTok ads landing page (StartPage), noindex, its own document
        'signup'                              => 'authRedirect',   // guessed / ad URLs: 301 to the sign-in dialog on the home page
        'register'                            => 'authRedirect',
        'login'                               => 'authRedirect',
    );

    /** "Why we built this" on /about (D3.3): two paragraphs for Daniel to write in his own words. The section is hidden until both are filled. */
    const WHY_WE_BUILT_THIS = array(
        '',   // TODO Daniel: paragraph one
        '',   // TODO Daniel: paragraph two
    );

    /** Where legal and privacy requests go (shown on /terms and /privacy). */
    const LEGAL_CONTACT = 'support@creatorlinkstudio.com';

    /** Where press and partnership requests go (shown on /press). */
    const PRESS_CONTACT = self::LEGAL_CONTACT;

    /** Topics on the /contact form (value => label); the contact_send API accepts only these. */
    const CONTACT_TOPICS = array('billing' => 'Billing', 'account' => 'Account', 'bug' => 'Bug', 'other' => 'Other');

    /** Below this many listed creators the directory copy doesn't quote the number. */
    const DIRECTORY_COUNT_MIN = 10;

    public function __construct(){
        parent::__construct();
        if (!self::$embedded) { Session::cache_header(300); }
    }

    private function page($view, array $meta, array $vars = array()){
        $meta['url'] = SeoMeta::base() . $meta['path'];
        // The photo hero is the page's LCP image (site-wide redesign 2026-10-15): preload the same srcset the <img> uses.
        $hp = (string) ($meta['hero_photo'] ?? ''); unset($meta['hero_photo']);
        if ($hp !== '' && SitePhotos::has($hp)) {
            $meta['preload_image'] = SitePhotos::src($hp); $meta['preload_srcset'] = SitePhotos::srcset($hp);
            $meta['preload_sizes'] = (string) ($meta['hero_sizes'] ?? '100vw');
        }
        unset($meta['hero_sizes']);
        if (!self::$embedded && (int) Session::get('user_id') === 0) { Affiliates::capture($this->get_ip_address()); }   // ?aff=<code> on any public page, signed out: cls_aff + a click
        if (!self::$embedded && isset($_GET['aff'])) { Session::cache_header(300); }   // the affiliate cookie was just set: this response is private
        if (!isset($meta['jsonld'])) {
            $meta['jsonld'] = array(
                SeoMeta::org(),
                SeoMeta::breadcrumbs(array(
                    array('name' => 'Home', 'url' => '/'),
                    array('name' => $meta['title'], 'url' => $meta['path']),
                )),
            );
        }
        // every page carries one top-level Organization (with its contactPoint), never two, and one WebPage unless it is an Article
        if (isset($meta['jsonld']['@type'])) { $meta['jsonld'] = array($meta['jsonld']); }
        $types = array_map(function ($b) { return is_array($b) ? (string) ($b['@type'] ?? '') : ''; }, (array) $meta['jsonld']);
        if (!in_array('Organization', $types, true)) { $meta['jsonld'][] = SeoMeta::org(); }
        if (empty($meta['page_modified'])) { $meta['page_modified'] = PageDates::iso((string) $meta['path']); }   // the day this page's text last changed (cron/build_page_dates.php)
        if (!in_array('WebPage', $types, true)) { $meta['jsonld'][] = SeoMeta::webpage($meta); }   // an Article points at it as mainEntityOfPage; a typed page node is merged with it (SeoMeta::merge_page_node)
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

    /** Niche landing pages under /for/<slug> (NichePages::PAGES). */
    public static function niche_pages(): array {
        return self::fill_feature_text(NichePages::PAGES);
    }

    /** Said wherever plan prices or credit purchases are mentioned. */
    /** The one description of the Free plan (2026-10-13): the pricing page says it in these words wherever Free is described, and so does the home FAQ. */
    const FREE_LINE = 'Free is the account everyone starts with: it costs nothing and needs no card. You can follow creators and build your page and drafts on it. Publishing, scheduling and the AI tools start on Creator or Studio.';
    const FINAL_NOTE = 'Plan charges and AI credit purchases are final and non-refundable.';

    /** Old feature slugs => new ones (301). */
    const FEATURE_MOVED = array('character-generation' => 'ai-influencer');

    /** Placeholders in the constants are filled from config here, so plans, limits and names never go stale in the text. */
    private static function fill_feature_text(array $pages): array {
        $inf = array(); $seats = array();
        foreach (self::paid_tiers() as $t) {
            if ((int) ($t['limits']['seats'] ?? 1) > 1) { $seats[] = (string) $t['name']; }
            if ((int) ($t['limits']['influencers'] ?? 0) > 0) { $inf[] = (int) $t['limits']['influencers'] . ' on ' . $t['name']; }
        }
        $vars = array(
            '{site}'             => Main::site_name(),
            '{host}'             => preg_replace('#^https?://(www\.)?#', '', SeoMeta::base()),
            '{networks}'         => SeoController::NETWORKS,
            '{influencer_plans}' => implode(', ', $inf),
            '{seats_plans}'      => implode(' or ', $seats),
            '{final_note}'       => self::FINAL_NOTE,
            '{min_photos}'       => (string) (int) InfluencerConfig::get('training_min_photos', 10),   // the training set the influencer code actually accepts
            '{max_photos}'       => (string) (int) InfluencerConfig::get('training_max_photos', 50),
        );
        // the non-refundable line lives on /pricing, /terms and checkout only (Decision 2026-10-13), so nothing is appended here
        $map = array();
        foreach ($vars as $k => $v) { $map[$k] = addcslashes((string) $v, '"\\/'); }
        return json_decode(strtr(json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $map), true);
    }

    /** One feature-template page: FAQ + breadcrumb markup, related cards (the page's 'related' slugs, else every other page). */
    private function render_feature(array $page, string $slug, string $path, array $crumbs, ?array $look = null){
        $all = array();
        foreach (self::feature_pages() as $k => $p) { $p['path'] = '/features/' . $k; $all[$k] = $p; }
        foreach (self::root_feature_pages() as $k => $p) { $p['path'] = '/' . $k; $all[$k] = $p; }
        $siblings = array();
        foreach ((array) ($page['related'] ?? array_keys($all)) as $k) { if ($k !== $slug && isset($all[$k])) { $siblings[$k] = $all[$k]; } }
        $jsonld = array(SeoMeta::faq((array) ($page['faq'] ?? array())), SeoMeta::breadcrumbs($crumbs));
        $cta = (array) ($page['cta'] ?? array());
        $look = $look ?? (FeaturePages::LOOK[$slug] ?? array());
        $this->page('feature', array('path' => $path, 'title' => $page['title'], 'description' => $page['description'], 'jsonld' => $jsonld,
                'hero_photo' => (string) ($look['photo'] ?? ''), 'hero_sizes' => ($look['variant'] ?? 'split') === 'split' ? '(max-width: 760px) 100vw, 52vw' : '100vw'),
            array('page' => $page, 'slug' => $slug, 'path' => $path, 'siblings' => $siblings, 'look' => $look,
                  'cta_title' => $cta['title'] ?? 'Start Creating With AI Today.', 'cta_text' => $cta['text'] ?? ''));
    }

    /** /for/<niche> (NichePages). There is no /for index: the pages are linked from the footer and the feature pages they name. */
    public function forNicheAction(){
        $url = Main::get_url();
        $raw = (string) ($url[1] ?? '');
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', $raw));
        $pages = self::niche_pages();
        if (count($url) !== 2 || $slug !== $raw || !isset($pages[$slug])) { Errors::page_not_found(); return; }
        $page = $pages[$slug];
        $this->render_feature($page, $slug, '/for/' . $slug, array(
            array('name' => 'Home', 'url' => '/'),
            array('name' => 'For ' . lcfirst((string) $page['nav_title']), 'url' => '/for/' . $slug),
        ), NichePages::LOOK[$slug] ?? array());
    }

    /** /lora-character-training, /consistent-ai-model-face. */
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
            array('q' => 'Do I need my own website?', 'a' => 'No. Your link-in-bio page lives at our domain under your handle and holds your posts and links.'),
            array('q' => 'Can I keep posting to my social accounts?', 'a' => 'Yes. The studio publishes each post to your page and to any connected social accounts at the same time, and pulls their engagement back into analytics.'),
            array('q' => 'Who owns my content?', 'a' => 'You do. Your posts, library files, AI images and video and messages download at any time from Settings.'),   // there is no audience export (2026-10-26)
            array('q' => 'What do the AI tools cost?', 'a' => 'Creator and Studio include AI credits every month. AI images, video and AI influencers spend those credits, and you can buy more credits at any time on either plan.'),
            self::FLAGGED_FAQ,
        );
        $jsonld = array(
            self::software_app('/features', Main::site_name(), SeoMeta::brand_description()),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Features', 'url' => '/features'))),
        );
        $this->page('features', array('hero_photo' => 'featport', 'hero_sizes' => '(max-width: 760px) 100vw, 58vw', 'path' => '/features', 'title' => 'Features: AI Influencers, AI Content and Scheduling', 'description' => 'One AI content studio for creators and brands: AI influencers, AI images and video, scheduling and cross-posting, link in bio, inbox automation and analytics.', 'type' => 'product', 'jsonld' => $jsonld, 'no_guides' => true), array('faq' => $faq));
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
        $this->page('legal-terms', array('path' => '/terms', 'title' => 'Terms of Service', 'description' => 'The terms for using ' . Main::site_name() . ': accounts, plans, AI credits and content rules.', 'type' => 'website', 'jsonld' => array(SeoMeta::org()), 'no_guides' => true, 'no_band' => true, 'sections' => true));
    }

    public function privacyAction(){
        $this->page('legal-privacy', array('path' => '/privacy', 'title' => 'Privacy Policy', 'description' => 'What ' . Main::site_name() . ' collects from creators and other users, how it is used and shared, and the choices you have over your data.', 'type' => 'website', 'jsonld' => array(SeoMeta::org()), 'no_guides' => true, 'no_band' => true, 'sections' => true));
    }

    public function aboutAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $site = Main::site_name();
        $desc = 'What ' . $site . ' is, who it is for, what its AI content studio does for creators and brands, and who writes and checks the articles.';   // 120-160 characters
        $jsonld = array(
            array('@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About ' . $site, 'description' => $desc, 'url' => SeoMeta::base() . '/about', 'about' => SeoMeta::org_ref()),
            SeoMeta::org(),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'About', 'url' => '/about'))),
        );
        $recent = array();
        try { $recent = (new SeoArticlesModel())->published(8, 0); } catch (\Throwable $e) { error_log('[seo] about recent articles: ' . $e->getMessage()); }   // the editorial section lists the latest pieces; the page never breaks on it
        $this->page('about', array('hero_photo' => 'about', 'hero_sizes' => '100vw', 'path' => '/about', 'title' => 'About ' . $site . ': The AI Content Studio for Creators', 'description' => $desc, 'type' => 'website', 'jsonld' => $jsonld, 'no_guides' => true), array('recent' => $recent));
    }

    /** /author/dr-glauber: the author page behind the byline (SeoMeta::AUTHOR_NAME, AUTHOR_BIO, AUTHOR_EXPERIENCE). Linked only from article bylines; not in the nav, the footer or the sitemap. */
    public function authorAction(){
        $url = Main::get_url();
        if (count($url) !== 2 || '/author/' . (string) $url[1] !== SeoMeta::AUTHOR_PATH) { Errors::page_not_found(); return; }
        $site = Main::site_name(); $name = SeoMeta::AUTHOR_NAME;
        $desc = $name . ' leads the ' . $site . ' team and reviews every article on the blog before it is published. Role, background and every published article.';
        $recent = array();
        try { $recent = (new SeoArticlesModel())->published(200, 0); } catch (\Throwable $e) { error_log('[author] articles: ' . $e->getMessage()); }
        $person = SeoMeta::author();
        $person['description'] = SeoMeta::AUTHOR_BIO[0];
        $jsonld = array(
            array('@context' => 'https://schema.org', '@type' => 'ProfilePage', 'name' => $name, 'description' => $desc, 'url' => SeoMeta::base() . SeoMeta::AUTHOR_PATH, 'mainEntity' => $person),
            SeoMeta::org(),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Blog', 'url' => '/blog'), array('name' => $name, 'url' => SeoMeta::AUTHOR_PATH))),
        );
        $this->page('author', array('path' => SeoMeta::AUTHOR_PATH, 'title' => $name . ': ' . SeoMeta::AUTHOR_ROLE, 'description' => $desc, 'type' => 'profile', 'jsonld' => $jsonld, 'no_guides' => true, 'no_band' => true), array('recent' => $recent));
    }

    public function contactAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $site = Main::site_name();
        $desc = 'Contact ' . $site . ' support about billing, your account or a bug. Send a message or email ' . self::LEGAL_CONTACT . '.';
        $jsonld = array(
            array('@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact ' . $site, 'description' => $desc, 'url' => SeoMeta::base() . '/contact', 'about' => SeoMeta::org_ref()),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Contact', 'url' => '/contact'))),
        );
        $this->page('contact', array('hero_photo' => 'contact', 'hero_sizes' => '(max-width: 760px) 100vw, 52vw', 'path' => '/contact', 'title' => 'Contact Support', 'description' => $desc, 'type' => 'website', 'jsonld' => $jsonld, 'no_guides' => true, 'no_band' => true));
    }

    public function pricingAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $rows = self::pricing_rows();
        $ai_first = PlanTiers::lowest_including('influencers');
        $faq  = array(
            array('q' => 'Is there a free plan?', 'a' => 'Yes. ' . self::FREE_LINE),
            array('q' => 'What do I pay for?', 'a' => 'Two things only: your plan and any extra AI credits you choose to buy. ' . self::plan_price_sentence() . ' ' . self::addon_sentence()),
            array('q' => 'Can I buy AI credits on any plan?', 'a' => 'No. AI credits can be bought on Creator and Studio only, which include AI credits every month. Buy more at any time on either plan. Credits pay for AI images and video' . ($ai_first ? ', and for AI influencers on ' . $ai_first['name'] . ' and up' : '') . '.'),
            array('q' => 'Are plans and credits refundable?', 'a' => 'No. Plan charges and AI credit purchases are final and non-refundable. You can cancel a plan at any time and keep it until the end of the paid period.'),
            array('q' => 'Can I change plans later?', 'a' => 'Yes, up or down at any time from Billing. Moving between paid plans prorates. Moving to Free takes effect when your paid period ends. Anything over the new limits is kept and locked, never deleted.'),
        );
        foreach (PlanTiers::addons() as $ad) {
            $on = array(); foreach ((array) $ad['plans'] as $k) { $t = PlanTiers::get($k); if ($t) { $on[] = $t['name']; } }
            $faq[] = array('q' => 'Can I add more AI influencers?', 'a' => 'On ' . implode(' and ', $on) . ', yes: each extra AI influencer is $' . (int) $ad['price'] . ' a month, up to ' . (int) $ad['max'] . ' extra. Add or remove them from Billing.');
        }
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

    /** Answer to "What do the plans cost?" (home FAQ + its schema): prices, what Free is and add-ons, from config. */
    public static function plan_cost_answer(): string {
        return trim(self::plan_price_sentence() . ' ' . CreatorPromo::sentence() . ' ' . self::FREE_LINE . ' Studio adds more AI credits, more AI influencers and team seats. ' . self::addon_sentence());   // the Founding Creator line drops out by itself after the deadline
    }

    /** plan_offers() with availability, as Product markup (/features, /pricing) wants them. */
    /**
     * The platform as a SoftwareApplication with the plan prices. Not Product: Google treats a Product with offers
     * as a merchant listing and asks for shipping, returns and a Brand, none of which fit a subscription service.
     * No review/aggregateRating until there are real ones to show.
     */
    public static function software_app(string $path, string $name, string $description): array {
        $app = array('@type' => 'SoftwareApplication', '@id' => SeoMeta::base() . '/#software', 'name' => $name, 'description' => $description,
            'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
            'url' => SeoMeta::base() . $path, 'image' => SeoMeta::default_image(), 'publisher' => SeoMeta::org_ref());
        $offers = self::plan_offers();
        if (!empty($offers)) { $app['offers'] = $offers; }
        return $app;
    }

    /** schema.org Offer rows for every plan and add-on (home, /features and /pricing SoftwareApplication). */
    public static function plan_offers(): array {
        $offers = array();
        foreach (self::pricing_rows() as $r) {
            $price = number_format($r['amount'] / 100, 2, '.', '');
            $offers[] = array('@type' => 'Offer', 'name' => $r['tier']['name'],
                'price' => $price, 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock')
                + (((string) $r['tier']['key'] === CreatorPromo::PLAN && CreatorPromo::active()) ? array('priceValidUntil' => substr(CreatorPromo::ENDS, 0, 10)) : array())   // the Founding Creator price has an end date
                + array(
                'priceSpecification' => array('@type' => 'UnitPriceSpecification', 'price' => $price, 'priceCurrency' => 'USD', 'billingDuration' => 'P1M', 'unitCode' => 'MON'),
                'url' => SeoMeta::base() . '/pricing');
        }
        foreach (PlanTiers::addons() as $ad) {
            $offers[] = array('@type' => 'Offer', 'name' => $ad['name'] . ' add-on (per month)',
                'price' => number_format((float) $ad['price'], 2, '.', ''), 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock',
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
            $bits[] = $r['tier']['name'] . ' $' . number_format($r['amount'] / 100);
        }
        $last = array_pop($bits);
        $plans = $bits ? implode(', ', $bits) . ' and ' . $last : $last;
        $paid = self::paid_tiers(); usort($paid, function ($a, $b) { return (int) $a['rank'] <=> (int) $b['rank']; });
        $first = $paid ? (string) $paid[0]['name'] : 'Creator';
        // 110-160 characters: the description the audit scores
        return 'Plans: ' . $plans . ' a month. Free is the starting account; AI credits, AI influencers and scheduling start on ' . $first . '.';
    }

    /** Our column of the comparison table, derived from PlanTiers so it can't drift. */
    /** One-line role per plan (home plan strip, pricing cards), by PlanTiers key. */
    // card label, then the clause after the plan name in the hero sentence. Free builds a page in the Studio but
    // has no public page and no AI tools, so its line says build, not get.
    const PLAN_ROLES = array(
        'free'    => array('Build your page', 'lets you build your page'),
        'creator' => array('Get discovered',  'gets you discovered'),
        'studio'  => array('Get promoted',    'gets you promoted'),
    );

    public static function plan_role(string $key): string {
        return (string) (self::PLAN_ROLES[$key][0] ?? '');
    }

    /** The home page FAQ: one source for the visible section (home_body.php) and the FAQPage markup (login_form.php), so they cannot drift. Each answer stands alone and names the figures. */
    public static function home_faq(): array {
        return array(
            array('q' => 'What can I make with the AI tools?', 'a' => 'AI influencers that keep the same face from post to post, AI images and short AI videos, and captions written in your voice. Each generation spends AI credits from your plan.'),
            array('q' => 'Where do my posts go?', 'a' => 'To your link-in-bio page and to every social account you connect: ' . SeoController::NETWORKS . '. Publish now or schedule a time, or let an automation post on a routine.'),
            array('q' => 'What do AI credits cost?', 'a' => 'Creator and Studio include AI credits every month. If you run out, buy an AI credit pack at any time on either plan. Plans and AI credits are the only things you pay for.'),
            array('q' => 'Can people follow me for free?', 'a' => 'Yes. Following a creator is free.'),
            array('q' => 'What do the plans cost?', 'a' => self::plan_cost_answer()),
            array('q' => 'How is my data handled?', 'a' => self::DATA_MONEY_ANSWER),
            self::FLAGGED_FAQ,
            self::LEAVE_FAQ,
        );
    }

    /** heycatch D1.4 (2026-10-26): the suspension and exit objection. Facts from the Terms (reports reviewed, removal or suspension, data download). */
    const FLAGGED_FAQ = array('q' => 'What happens if my account is flagged?', 'a' => 'Reported posts and profiles are reviewed by a person. Content that breaks the rules can be removed, and an account that keeps breaking them can be suspended. If you think a decision is wrong, contact support and ask for a review.');
    const LEAVE_FAQ   = array('q' => 'Can I take my content with me if I leave?', 'a' => 'Yes. Download Your Data in Settings gives you your posts, your library files, your AI images and video, your messages and your credit history.');

    const DATA_MONEY_ANSWER = 'We store what the product needs: your profile, your content and AI generations, your connected accounts, your messages and the list of who follows you. Your data is never sold. Card numbers never reach our servers: plan and AI credit payments are handled by a secure payment processor, and we keep only a reference to a saved card.';


    /** The paid plans on sale: paid, not retired (pricing meta, feature-page placeholders, admin Free-builders). */
    public static function paid_tiers(): array {
        $out = array();
        foreach (PlanTiers::all() as $t) { if ((int) ($t['price'] ?? 0) > 0 && empty($t['retired'])) { $out[] = $t; } }
        return $out;
    }

    /** Old name of paid_tiers(), kept for callers outside the public site. */
    public static function selling_tiers(): array {
        return self::paid_tiers();
    }

    /**
     * /start (2026-10-16): the paid-traffic landing page for TikTok ads. Its own standalone document (app/views/pages/start.php):
     * no site nav or footer, signup in a sheet on the page. noindex: it is an ad destination, not a search page.
     */
    /** /signup, /register, /login: 301 to the dialog on the home page (?auth=register|login), keeping the query (?plan=, ?role=, ?ref=, utm_*). */
    public function authRedirectAction(){
        if (self::$embedded) { return; }   // never rendered as a page (Markdown copy, llms)
        $panel = (string) (Main::get_url()[0] ?? '') === 'login' ? 'login' : 'register';
        $q = $_GET; unset($q['url'], $q['auth']);
        header('Location: /?' . http_build_query(array('auth' => $panel) + $q), true, 301);
        exit;
    }

    public function startAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        if (!self::$embedded) { Session::cache_header(60); }   // the trial line follows the founding spots, at most a minute old
        $hero = StartPage::HERO;
        $meta = array(
            'title' => 'Make Your Own AI Influencer',
            'description' => 'Design her face once. Make her photos and videos. Post her everywhere: nine platforms from one place.',
            'url' => SeoMeta::base() . '/start', 'type' => 'website', 'noindex' => true,
            'preload_image' => $hero['poster'],   // the video's poster is the first paint of the hero
            'image' => SeoMeta::base() . $hero['poster'],
        );
        $signed_in = (int) Session::get('user_id') > 0;
        $trial = StartPage::trial_open();
        require Main::app_path() . '/app/views/pages/start.php';
    }

    /** /founding/testimonial only: the public /founding page was removed 2026-10-20 (Daniel); the founding trial itself never needed it. */
    public function foundingAction(){
        $u = Main::get_url();
        if (count($u) === 2 && (string) $u[1] === 'testimonial') { (new FoundingController())->testimonialAction(); return; }   // the app page for founding creators
        Errors::page_not_found();
    }

    /** The affiliate program (/affiliates): Affiliates::RATE_PERCENT of the plan payments of referred accounts. */
    public static function affiliates_meta(): array {
        return array('title' => 'Affiliate Program: Earn ' . Affiliates::RATE_PERCENT . '% of Plan Payments',
            'description' => 'Refer creators to ' . Main::site_name() . ' and earn ' . Affiliates::RATE_PERCENT . '% of what they pay for their ' . self::paid_names() . ' plan, every month they stay on it. Payouts on request.');
    }

    /** "Creator or Studio": the paid plan names, from config. */
    private static function paid_names(): string {
        return implode(' or ', array_column(self::paid_tiers(), 'name'));
    }

    public static function affiliates_faq(): array {
        $r = Affiliates::RATE_PERCENT . '%'; $plans = self::paid_names();
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
        $this->page('affiliates', array('hero_photo' => 'affiliates', 'hero_sizes' => '100vw', 'path' => $path, 'title' => $m['title'], 'description' => $m['description'], 'type' => 'website', 'jsonld' => $jsonld, 'no_band' => true),
            array('faq' => $faq, 'plans' => self::paid_names(),
                  'apply_label' => $mine ? 'Open Your Dashboard' : 'Apply Now',
                  'apply_url' => $mine ? '/affiliates/dashboard' : ($signed_in ? '/affiliates/apply' : '/?auth=register&next=' . rawurlencode('/affiliates/apply'))));   // a full load, so ?next brings them back to the form
    }

    /** The /integrations index title and description (also listed in SeoController::public_pages). */
    const INTEGRATIONS_TITLE = 'Integrations: Publish to 9 Platforms From One Editor';
    const INTEGRATIONS_DESC  = 'Connect LinkedIn, Bluesky, X, Facebook, Instagram, Threads, TikTok, YouTube and Pinterest, then publish or schedule each post to all of them at once.';

    /** One integration page's title and description. */
    public static function integration_meta(string $slug): array {
        $n = IntegrationPages::PAGES[$slug]['name'];
        return array(
            'title'       => $n . ' Integration: Publish and Schedule Posts',
            'description' => 'Connect ' . $n . ' to Creator Link Studio and publish or schedule each new post there from the editor you already use for your page.',
        );
    }

    /** /integrations and /integrations/<platform> (IntegrationPages::PAGES). */
    public function integrationsAction(){
        $url = Main::get_url();
        if (count($url) > 1) {
            $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $url[1]));
            if (count($url) > 2 || $slug === '' || !isset(IntegrationPages::PAGES[$slug])) { Errors::page_not_found(); return; }
            $page = IntegrationPages::PAGES[$slug]; $m = self::integration_meta($slug);
            $path = '/integrations/' . $slug; $faq = IntegrationPages::faq($slug);
            $jsonld = array(SeoMeta::faq($faq), SeoMeta::breadcrumbs(array(
                array('name' => 'Home', 'url' => '/'), array('name' => 'Integrations', 'url' => '/integrations'), array('name' => $page['name'], 'url' => $path),
            )));
            $this->page('integration', array('hero_photo' => 'integration', 'hero_sizes' => '100vw', 'path' => $path, 'title' => $m['title'], 'description' => $m['description'], 'jsonld' => $jsonld, 'no_guides' => true, 'no_band' => true),
                array('page' => $page, 'slug' => $slug, 'faq' => $faq, 'steps' => IntegrationPages::steps($slug)));
            return;
        }
        $faq = array(
            array('q' => 'Which platforms does Creator Link Studio publish to?', 'a' => 'LinkedIn, Bluesky, X, Facebook, Instagram, Threads, TikTok, YouTube and Pinterest.'),
            array('q' => 'What gets posted to social networks?', 'a' => 'Each post goes out with its caption and media, sized for each platform. You choose which platforms get it, and the same post appears on your page.'),
            array('q' => 'Can I schedule posts to every platform?', 'a' => 'Yes. Publish now or pick a time, and every platform you tick goes out with your page post.'),
            array('q' => 'Where do I connect my accounts?', 'a' => 'In Settings, under Integrations. Each platform connects with its own sign-in, except Bluesky, which uses an app password.'),
        );
        $items = array(); $pos = 1;
        foreach (IntegrationPages::PAGES as $s => $p) { $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'url' => SeoMeta::base() . '/integrations/' . $s, 'name' => $p['name']); }
        $jsonld = array(
            array('@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Creator Link Studio integrations', 'itemListElement' => $items),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Integrations', 'url' => '/integrations'))),
        );
        $this->page('integrations', array('hero_photo' => 'integrations', 'hero_sizes' => '100vw', 'path' => '/integrations', 'title' => self::INTEGRATIONS_TITLE, 'description' => self::INTEGRATIONS_DESC, 'jsonld' => $jsonld, 'no_guides' => true, 'no_band' => true),
            array('faq' => $faq));
    }

    const PRESS_TITLE = 'Press Kit: Logos, Boilerplate and Brand Colors';
    const PRESS_DESC  = 'Creator Link Studio press kit: company boilerplate, logo downloads in SVG and PNG, brand colors, product facts and link badges.';

    /** /press: boilerplate, logos, colors, facts, contact, link badges. */
    public function pressAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $this->page('press', array('hero_photo' => 'press', 'hero_sizes' => '100vw', 'path' => '/press', 'title' => self::PRESS_TITLE, 'description' => self::PRESS_DESC, 'no_guides' => true, 'no_band' => true));
    }

    const AI_ALT_TITLE = 'AI Influencer Tools Compared: Every Category Explained';
    const AI_ALT_DESC  = 'The kinds of AI influencer tools, from image and video generators to character training and publishing, what each one does, and where Creator Link Studio fits.';

    /** /alternatives: the categories of AI influencer tools and where the studio fits. No competitor is named. */
    public function alternativesHubAction(){
        if (count(Main::get_url()) > 1) { Errors::page_not_found(); return; }
        $site = Main::site_name();
        $faq = array(
            array('q' => 'What do I need to run an AI influencer?', 'a' => 'A consistent character, a way to make photos and video of them, captions, a publishing routine and a page where followers can find everything. Most people stitch several tools together for this.'),
            array('q' => 'Why does a consistent face matter?', 'a' => 'Followers notice when a character looks different from post to post. Character training on a set of reference photos keeps the same face across images and video.'),
            array('q' => 'Should AI influencer posts be labelled?', 'a' => 'Yes. Several platforms ask for AI content to be disclosed, and some have their own label for it. ' . $site . ' sets that label on TikTok and YouTube and adds a disclosure line to the caption elsewhere.'),
            array('q' => 'Where does ' . $site . ' fit?', 'a' => 'It covers the character, images, video, voice, publishing to nine platforms and a link-in-bio page, in one account. Picking it depends on whether you want those pieces in one place.'),
        );
        $jsonld = array(SeoMeta::faq($faq), SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'AI influencer tools compared', 'url' => '/alternatives'))));
        $this->page('ai-alternatives', array('hero_photo' => 'aichar-a', 'hero_sizes' => '(max-width: 760px) 100vw, 52vw', 'path' => '/alternatives', 'title' => self::AI_ALT_TITLE, 'description' => self::AI_ALT_DESC, 'jsonld' => $jsonld, 'no_guides' => true, 'no_band' => true),
            array('faq' => $faq));
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
        $title = $q_on ? 'Creators Matching "' . $q . '"' : ($cat === '' ? 'Creator Directory: Find Creators to Follow' : $label . ' Creators to Follow');
        $show_n = $listed >= self::DIRECTORY_COUNT_MIN;
        $desc  = 'Browse ' . ($show_n ? $listed . ' ' : '') . ($cat === '' ? '' : strtolower($label) . ' ') . 'creators on ' . Main::site_name() . ' who chose to be listed: follow them and see their latest posts and links on their page.';
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
