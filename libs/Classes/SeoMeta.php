<?php
/**
 * Head tags + JSON-LD for public pages. One array in, one HTML string out, so every
 * indexable page (landing, product pages, blog, directory, profiles) says the same things
 * the same way. Escaping happens here; callers pass plain strings.
 */
class SeoMeta {

    /** Titles longer than this lose the " · Creator Link Studio" suffix (Google truncates at about 60 characters, and the SEO audit scores 30-60). */
    const TITLE_MAX = 60;

    public static function base(): string {
        return rtrim((string) Main::get_base_domain(), '/');
    }

    /** The default share card. Versioned by file time: social apps cache previews by URL, so a new card needs a new URL. */
    public static function default_image(): string {
        $f = Main::app_path() . '/public/images/og-image.png';
        return self::base() . '/images/og-image.png' . (is_file($f) ? '?v=' . filemtime($f) : '');
    }

    public static function site(): string {
        return (string) Main::site_name();
    }

    /**
     * The short brand line (home meta description): the hero promise and the fee, from config. Kept within
     * 160 characters; the last sentence goes first if the fee text ever makes it longer.
     */
    public static function brand_tagline(): string {
        $s = 'Sell memberships, posts and services from one page. ' . PagesController::fee_short() . '.';
        $full = $s . ' Publish to nine social networks with AI and get paid to your bank.';
        return mb_strlen($full) <= 160 ? $full : $s;
    }

    /**
     * The canonical brand description (Organization, SoftwareApplication, llms.txt, footer): what the platform is,
     * then the plans with price and fee from PlanTiers, so no number is ever typed here.
     */
    public static function brand_description(bool $with_plans = true): string {
        $host = preg_replace('#^https?://(www\.)?#', '', self::base());
        $plans = array();
        $lead = self::site() . ' is a creator monetization platform: one public page at ' . $host . '/@handle for memberships, pay-per-view posts, bundles, services and live events, a studio that publishes to nine social networks with AI captions, AI influencers and an AI-assisted inbox, and payouts to your bank.';
        if (!$with_plans) { return $lead; }   // creator profiles show no plan prices
        foreach (PlanTiers::offered() as $t) {   // PlanTiers directly, not PagesController: org() also runs where controllers are not loaded
            $price = (int) ($t['price'] ?? 0);
            $plans[] = $price > 0
                ? $t['name'] . ' $' . number_format($price) . '/month (' . (int) $t['limits']['fee_percent'] . '% platform fee)'
                : $t['name'] . ' $' . number_format($price);
        }
        return $lead . ' Plans: ' . implode(', ', $plans) . '.';
    }

    /** The publisher node reused by every schema block. */
    /**
     * The brand's own social profiles (full URLs), label => url. They become Organization sameAs (how Google
     * and AI assistants tie the site to its accounts) and the footer's social links. Empty = neither shows.
     */
    const SOCIAL_PROFILES = array(
        'X' => 'https://x.com/CreatorLinkStud',
        'Instagram' => 'https://www.instagram.com/creatorlinkstudio2026/',
        'TikTok' => 'https://www.tiktok.com/@creatorlinkstudio',
        'Threads' => 'https://www.threads.com/@creatorlinkstudio2026',
        'YouTube' => 'https://www.youtube.com/@CreatorLinkStudio',
        'LinkedIn' => 'https://www.linkedin.com/company/creator-link-studio',
        'Product Hunt' => 'https://www.producthunt.com/products/creator-link-studio',
        'Crunchbase' => 'https://www.crunchbase.com/organization/creator-link-studio',
        'G2' => 'https://www.g2.com/products/creator-link-studio/reviews',
    );

    /** "@CreatorLinkStud" from the X profile above (twitter:site on every page), or '' when there is no X account. */
    public static function x_handle(): string {
        $url = (string) (self::SOCIAL_PROFILES['X'] ?? '');
        return preg_match('#^https?://(?:www\.)?(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})/?$#', $url, $m) ? '@' . $m[1] : '';
    }

    /**
     * A WebPage node for a page that has no Article: name, description, url, part of the WebSite. Pages that only carried
     * FAQPage / BreadcrumbList / Organization read as "no page markup" to crawlers that look for WebPage or Article.
     */
    /**
     * One Organization and one WebSite for the whole site (SEO audit 2026-10-12): each is a single node with a stable @id, every
     * other node points at it by reference, and no page nests a second copy. head() adds the full nodes to any page that
     * references them, so a reference never dangles.
     */
    public static function org_id(): string { return self::base() . '/#org'; }
    public static function website_id(): string { return self::base() . '/#website'; }
    public static function org_ref(): array { return array('@type' => 'Organization', '@id' => self::org_id()); }
    public static function website_ref(): array { return array('@type' => 'WebSite', '@id' => self::website_id()); }
    public static function website(): array {
        return array('@type' => 'WebSite', '@id' => self::website_id(), 'name' => self::site(), 'url' => self::base() . '/', 'inLanguage' => 'en-US', 'publisher' => self::org_ref());
    }

    public static function webpage(array $m): array {
        $url = (string) ($m['url'] ?? '');
        $w = array(
            '@type' => 'WebPage',
            '@id'   => $url . '#webpage',
            'url'   => $url,
            'name'  => trim((string) ($m['title'] ?? '')),
            'description' => trim((string) ($m['description'] ?? '')),
            'isPartOf' => self::website_ref(),
            'inLanguage' => 'en-US',
            'publisher' => self::org_ref(),
        );
        if (!empty($m['page_modified'])) { $w['dateModified'] = (string) $m['page_modified']; }   // commercial pages: the last date their facts were checked or changed
        if (!empty($m['image'])) { $w['primaryImageOfPage'] = array('@type' => 'ImageObject', 'url' => (string) $m['image']); }
        return $w;
    }

    /** The article author (Daniel, 2026-10-12): one Person, "Dr. Glauber", with the author page at AUTHOR_PATH, linked only from the byline. Never a first name, no photo, no location. */
    const AUTHOR_NAME = 'Dr. Glauber';
    const AUTHOR_PATH = '/author/dr-glauber';
    const AUTHOR_ROLE = 'Leads the Creator Link Studio team';
    /** The bio paragraphs on the author page, Daniel's words verbatim (2026-10-12). */
    const AUTHOR_BIO = array(
        'Dr. Glauber leads the Creator Link Studio team and reviews what the blog publishes. He is CEO of Heights Consulting Group, a cybersecurity consulting firm, with more than 30 years in technology and over 500 executive engagements. He is the author of Cybersecurity in the Age of AI.',
    );
    /** The experience lines on the author page, Daniel's words verbatim (2026-10-12). */
    const AUTHOR_EXPERIENCE = array(
        'Doctor of Management in Information Systems and Technology.',
        'CEO, Heights Consulting Group.',
        'Author, Cybersecurity in the Age of AI.',
        'Leads product, security and editorial review at Creator Link Studio.',
    );
    /** The byline on every article, once, at the top. */
    public static function byline(): string { return 'The ' . self::site() . ' team, led by ' . self::AUTHOR_NAME; }

    /**
     * The author node on every blog Article: a Person named AUTHOR_NAME, url AUTHOR_PATH (the author page), worksFor the Organization
     * (by @id reference, never a nested copy). The Person carries no sameAs (Daniel's call).
     */
    public static function author(): array {
        return array('@type' => 'Person', '@id' => self::base() . self::AUTHOR_PATH . '#person', 'name' => self::AUTHOR_NAME, 'url' => self::base() . self::AUTHOR_PATH, 'jobTitle' => self::AUTHOR_ROLE, 'worksFor' => self::org_ref());   // no sameAs key: the Person has no public profiles (Daniel's call), and an empty list says nothing
    }

    public static function org(): array {
        $org = array(
            '@type' => 'Organization',
            '@id'   => self::org_id(),
            'name'  => self::site(),
            'url'   => self::base() . '/',
            'logo'  => array('@type' => 'ImageObject', 'url' => self::base() . '/images/android-chrome-512x512.png', 'width' => 512, 'height' => 512),
            'parentOrganization' => array('@type' => 'Organization', 'name' => 'Heights Consulting Group LLC'),   // stated on /about and /contact
            'description' => self::brand_description(),
            'contactPoint' => array('@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => 'support@creatorlinkstudio.com', 'availableLanguage' => 'en'),
        );
        if (!empty(self::SOCIAL_PROFILES)) { $org['sameAs'] = array_values(self::SOCIAL_PROFILES); }
        return $org;
    }

    /** Keyword → path. Product pages link to each other with these; the content engine reuses the map. */
    public static function internal_links(): array {
        return array(
            'ai influencer'                        => '/features/ai-influencer',
            'ai character'                         => '/features/ai-influencer',
            'lora training'                        => '/lora-character-training',
            'ai dm'                                => '/features/dm-agent',
            'ai chat'                              => '/features/dm-agent',
            'automated messages'                   => '/features/dm-agent',
            'creator payouts'                      => '/features/payouts',
            'get paid'                             => '/features/payouts',
            'creator platform'                     => '/features',
            'online creator platform'              => '/features',
            'creator monetization platform'        => '/features',
            'pricing'                              => '/pricing',
            'platform fee'                         => '/pricing',
            'fanvue alternative'                   => '/compare/fanvue',
            'onlyfans alternative'                 => '/compare/onlyfans',
            'best creator monetization platforms'  => '/best-creator-monetization-platforms',
            'link in bio'                          => '/features#page',
            'monetize content'                     => '/monetize-your-content',
            'monetize your content'                => '/monetize-your-content',
            'pay-per-view'                         => '/monetize-your-content#pay-per-view',
            'membership tiers'                     => '/monetize-your-content#memberships',
            'sign up'                              => '/',
        );
    }

    public static function breadcrumbs(array $items): array {
        $list = array(); $i = 1;
        foreach ($items as $it) {
            $url = (string) $it['url'];
            if ($url !== '' && $url[0] === '/') { $url = self::base() . $url; }
            $list[] = array('@type' => 'ListItem', 'position' => $i++, 'name' => (string) $it['name'], 'item' => $url);
        }
        return array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list);
    }

    public static function faq(array $qa): array {
        $main = array();
        foreach ($qa as $p) {
            $main[] = array('@type' => 'Question', 'name' => (string) $p['q'],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => (string) $p['a']));
        }
        return array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $main);
    }

    /** Article schema for guides/comparisons. $m: headline, description, url, published, modified, image. */
    public static function article(array $m): array {
        $a = array(
            '@context' => 'https://schema.org', '@type' => 'Article',
            'headline' => (string) $m['headline'], 'description' => (string) $m['description'],
            '@id' => (string) $m['url'] . '#article',
            'mainEntityOfPage' => array('@type' => 'WebPage', '@id' => (string) $m['url']), 'url' => (string) $m['url'],
            'image' => (string) ($m['image'] ?? self::default_image()),
            'author' => !empty($m['person']) ? self::author() : self::org_ref(),   // blog articles: the Person (AUTHOR_NAME); product pages keep the Organization, so the name stays on the four allowed places
            'publisher' => self::org_ref(),
            'inLanguage' => 'en-US',
        );
        if (!empty($m['published'])) { $a['datePublished'] = (string) $m['published']; }
        if (!empty($m['modified']))  { $a['dateModified']  = (string) $m['modified']; }
        return $a;
    }

    public static function head(array $m): string {
        $e     = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $site  = self::site();
        $title = trim((string) ($m['title'] ?? ''));
        $full  = ($title === '') ? $site : ((stripos($title, $site) !== false) ? $title : ($title . ' · ' . $site));
        if ($title !== '' && mb_strlen($full) > self::TITLE_MAX) { $full = $title; }   // Google cuts long titles off; keep the page's own words, drop the brand
        $desc  = trim((string) ($m['description'] ?? ''));
        $url   = (string) ($m['url'] ?? '');
        $req   = (string) ($m['type'] ?? 'website');
        $type  = in_array($req, array('website', 'article', 'product', 'profile'), true) ? $req : 'website';
        $og_title = trim((string) ($m['og_title'] ?? '')); if ($og_title === '') { $og_title = $full; }
        $card  = in_array((string) ($m['twitter_card'] ?? ''), array('summary', 'summary_large_image'), true) ? (string) $m['twitter_card'] : 'summary_large_image';
        $image = (string) ($m['image'] ?? self::default_image());

        $out   = array();
        $out[] = '<title>' . $e($full) . '</title>';
        $out[] = '<meta name="description" content="' . $e($desc) . '">';
        $out[] = '<link rel="canonical" href="' . $e($url) . '">';
        $robots = trim((string) ($m['robots'] ?? ''));   // e.g. 'noindex, nofollow' for demo profiles
        $out[] = '<meta name="robots" content="' . ($robots !== '' ? $e($robots) : (!empty($m['noindex']) ? 'noindex, follow' : 'index, follow, max-image-preview:large')) . '">';
        $out[] = '<meta property="og:type" content="' . $type . '">';
        $out[] = '<meta property="og:site_name" content="' . $e($site) . '">';
        $out[] = '<meta property="og:title" content="' . $e($og_title) . '">';
        $out[] = '<meta property="og:description" content="' . $e($desc) . '">';
        $out[] = '<meta property="og:url" content="' . $e($url) . '">';
        $out[] = '<meta property="og:image" content="' . $e($image) . '">';
        $out[] = '<meta property="og:locale" content="en_US">';
        if (!empty($m['published'])) { $out[] = '<meta property="article:published_time" content="' . $e($m['published']) . '">'; }
        if (!empty($m['modified']))  { $out[] = '<meta property="article:modified_time" content="' . $e($m['modified']) . '">'; }
        $out[] = '<meta name="twitter:card" content="' . $card . '">';
        if (self::x_handle() !== '') { $out[] = '<meta name="twitter:site" content="' . $e(self::x_handle()) . '">'; }
        $out[] = '<meta name="twitter:title" content="' . $e($og_title) . '">';
        $out[] = '<meta name="twitter:description" content="' . $e($desc) . '">';
        $out[] = '<meta name="twitter:image" content="' . $e($image) . '">';
        // The page's largest image, fetched early (SEO audit 2026-10-12: a hero background and an article cover on another host were found late). 'preload_image' is the URL; 'preload_srcset' and 'preload_sizes' mirror the <img> when it has them.
        $pre = trim((string) ($m['preload_image'] ?? ''));
        if ($pre !== '') {
            $host = (string) parse_url($pre, PHP_URL_HOST);
            if ($host !== '' && $host !== (string) parse_url(self::base(), PHP_URL_HOST)) { $out[] = '<link rel="preconnect" href="https://' . $e($host) . '">'; }
            $out[] = '<link rel="preload" as="image" href="' . $e($pre) . '"' . (!empty($m['preload_srcset']) ? ' imagesrcset="' . $e($m['preload_srcset']) . '" imagesizes="' . $e((string) ($m['preload_sizes'] ?? '100vw')) . '"' : '') . ' fetchpriority="high">';
        }
        foreach ((array) ($m['extra'] ?? array()) as $tag) { if (is_string($tag) && strpos($tag, '<') === 0) { $out[] = $tag; } }   // caller-escaped raw tags (e.g. profile:username)

        if (!empty($m['jsonld'])) {
            $j = $m['jsonld'];
            if (is_array($j) && (isset($j['@type']) || isset($j['@context']))) {
                $blocks = array($j);
            } else {
                $blocks = is_array($j) ? $j : array();
            }
            // a page that points at the site's Organization or WebSite by @id also carries the full node, once
            $all = json_encode($blocks, JSON_UNESCAPED_SLASHES);
            $defines = function ($id) use ($blocks) { foreach ($blocks as $b) { if (is_array($b) && (string) ($b['@id'] ?? '') === $id && count($b) > 2) { return true; } } return false; };
            if (strpos($all, '"' . self::org_id() . '"') !== false && !$defines(self::org_id())) { $blocks[] = self::org(); }
            if (strpos($all, '"' . self::website_id() . '"') !== false && !$defines(self::website_id())) { $blocks[] = self::website(); }
            foreach ($blocks as $b) {
                if (!is_array($b)) { continue; }
                if (!isset($b['@context'])) { $b = array('@context' => 'https://schema.org') + $b; }
                $out[] = '<script type="application/ld+json">' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
            }
        }
        return "    " . implode("\n    ", $out) . "\n";
    }
}
