<?php
/**
 * Head tags + JSON-LD for public pages. One array in, one HTML string out, so every
 * indexable page (landing, product pages, blog, directory, profiles) says the same things
 * the same way. Escaping happens here; callers pass plain strings.
 */
class SeoMeta {

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

    /** The publisher node reused by every schema block. */
    public static function org(): array {
        return array(
            '@type' => 'Organization',
            'name'  => self::site(),
            'url'   => self::base() . '/',
            'logo'  => array('@type' => 'ImageObject', 'url' => self::base() . '/images/android-chrome-192x192.png'),
        );
    }

    /** Keyword → path. Product pages link to each other with these; the content engine reuses the map. */
    public static function internal_links(): array {
        return array(
            'ai influencer'                        => '/features/character-generation',
            'ai character'                         => '/features/character-generation',
            'lora training'                        => '/features/character-generation',
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
            'mainEntityOfPage' => (string) $m['url'], 'url' => (string) $m['url'],
            'image' => (string) ($m['image'] ?? self::default_image()),
            'author' => array('@type' => 'Organization', 'name' => trim((string) ($m['author'] ?? '')) !== '' ? (string) $m['author'] : self::site() . ' team', 'url' => self::base() . '/'),
            'publisher' => self::org(),
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
        $out[] = '<meta name="robots" content="' . (!empty($m['noindex']) ? 'noindex, follow' : 'index, follow, max-image-preview:large') . '">';
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
        $out[] = '<meta name="twitter:title" content="' . $e($og_title) . '">';
        $out[] = '<meta name="twitter:description" content="' . $e($desc) . '">';
        $out[] = '<meta name="twitter:image" content="' . $e($image) . '">';
        foreach ((array) ($m['extra'] ?? array()) as $tag) { if (is_string($tag) && strpos($tag, '<') === 0) { $out[] = $tag; } }   // caller-escaped raw tags (e.g. profile:username)

        if (!empty($m['jsonld'])) {
            $j = $m['jsonld'];
            if (is_array($j) && (isset($j['@type']) || isset($j['@context']))) {
                $blocks = array($j);
            } else {
                $blocks = is_array($j) ? $j : array();
            }
            foreach ($blocks as $b) {
                if (!is_array($b)) { continue; }
                if (!isset($b['@context'])) { $b = array('@context' => 'https://schema.org') + $b; }
                $out[] = '<script type="application/ld+json">' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
            }
        }
        return "    " . implode("\n    ", $out) . "\n";
    }
}
