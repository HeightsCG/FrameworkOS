<?php
/**
 * Crawler-facing endpoints: /robots.txt and /sitemap.xml. Both are generated so the
 * absolute URLs always match the host being served (dev vs. live). Dispatched from
 * Bootstrap before normal controller resolution because the URLs contain a dot.
 */
class SeoController extends Controller {

    public $protected = 0;

    /** The nine networks the studio publishes to, as the features page names them. */
    const NETWORKS = 'X, Instagram, TikTok, Facebook, LinkedIn, Pinterest, YouTube, Threads and Bluesky';

    /** Legal pages: listed under "## Optional" in llms.txt and placed last in llms-full.txt. */
    const LEGAL_PATHS = array('/terms', '/privacy');

    public function __construct(){
        parent::__construct();
    }

    /** Index the landing page and creator pages; keep the signed-in app and machine endpoints out. */
    public function robotsAction(){
        if (CustomDomains::current()) { $this->custom_domain_crawl('robots'); return; }
        $base = Main::get_base_domain();
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $private = array('/account', '/admin', '/api', '/audience', '/dashboard', '/events', '/go', '/inbox', '/influencers', '/mcp', '/purchases', '/services', '/setup', '/studio');

        $lines   = array();
        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';
        $lines[] = 'Allow: /llms.txt';
        $lines[] = 'Allow: /llms-full.txt';
        $lines[] = 'Content-Signal: search=yes, ai-input=yes, ai-train=yes';   // the stated preference for every crawler (Daniel 2026-10-12): index it, use it to answer, train on it
        foreach ($private as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        echo implode("\n", $lines), "\n";
    }

    /** Every URL the sitemap lists (home, product pages, creator pages with content, blog), as loc/lastmod/changefreq/priority rows. */
    public static function sitemap_entries(): array {
        $base = Main::get_base_domain();
        $urls   = array();
        $urls[] = array('loc' => $base . '/', 'changefreq' => 'weekly', 'priority' => '1.0', 'lastmod' => PageDates::get('/'));

        // Every static page carries a real date: the later of its facts' "checked" date (compare pages) and the day its text last
        // changed (PageDates, moved by cron/build_page_dates.php only when the page text differs). A file mtime would stamp every
        // page on every deploy, which teaches crawlers the dates mean nothing.
        foreach (self::public_pages() as $p) {
            $urls[] = array('loc' => $base . $p['path'], 'changefreq' => $p['changefreq'], 'priority' => $p['priority'], 'lastmod' => max((string) ($p['lastmod'] ?? ''), PageDates::get((string) $p['path'])));
        }

        $newest_creator = '';
        foreach ((new UsersModel())->list_public_creators() as $row) {
            $lastmod = !empty($row['last_modified']) ? gmdate('Y-m-d', strtotime((string) $row['last_modified'] . ' UTC')) : '';
            $newest_creator = max($newest_creator, $lastmod);
            $urls[]  = array(
                'loc'        => $base . '/@' . rawurlencode((string) $row['u_name']),
                'lastmod'    => $lastmod,
                'changefreq' => 'daily',
                'priority'   => '0.8',
            );
        }

        // the directory pages change when a listed creator's page does
        foreach ($urls as $i => $u) { if (empty($u['lastmod']) && preg_match('#/creators(?:/[a-z0-9-]+)?$#', (string) $u['loc'])) { $urls[$i]['lastmod'] = $newest_creator; } }

        try {
            $articles = new SeoArticlesModel();
            $blog_i = count($urls); $newest = '';
            $urls[] = array('loc' => $base . '/blog', 'changefreq' => 'daily', 'priority' => '0.8');
            foreach ($articles->published(500, 0) as $a) {
                $lm = gmdate('Y-m-d', strtotime(($a['updated_at'] ?: $a['published_at']) . ' UTC')); $newest = max($newest, $lm);
                $urls[] = array('loc' => $base . '/blog/' . $a['slug'], 'lastmod' => $lm, 'changefreq' => 'monthly', 'priority' => '0.7');
            }
            $urls[$blog_i]['lastmod'] = $newest;   // the index changes when its newest article does
        } catch (\Throwable $e) { error_log('[seo] sitemap articles: ' . $e->getMessage()); }

        return $urls;
    }

    public function sitemapAction(){
        if (CustomDomains::current()) { $this->custom_domain_crawl('sitemap'); return; }
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $urls = self::sitemap_entries();
        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
        foreach ($urls as $u) {
            echo "  <url>\n";
            echo '    <loc>', htmlspecialchars($u['loc'], ENT_QUOTES | ENT_XML1, 'UTF-8'), "</loc>\n";
            if (!empty($u['lastmod'])) {
                echo '    <lastmod>', $u['lastmod'], "</lastmod>\n";
            }
            // no <changefreq> or <priority>: search engines ignore both, and an accurate <lastmod> is the signal they use
            echo "  </url>\n";
        }
        echo '</urlset>', "\n";
    }

    /** robots.txt / sitemap.xml on a creator's own domain: just their profile, events and services. */
    private function custom_domain_crawl($which){
        $d    = CustomDomains::current();
        $base = 'https://' . $d['hostname'];
        header('Cache-Control: public, max-age=3600');
        if ($which === 'robots') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "User-agent: *\nAllow: /\nDisallow: /api\nDisallow: /go\n\nSitemap: " . $base . "/sitemap.xml\n";
            return;
        }
        $locs = array($base . '/');
        foreach ((new EventsModel())->list_public_for_creator((int) $d['user_id']) as $ev) { $locs[] = $base . '/events/' . (int) $ev['id']; }
        foreach ((new ServicesModel())->list_public_for_creator((int) $d['user_id']) as $sv) { $locs[] = $base . '/services/' . (int) $sv['id']; }
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
        foreach ($locs as $loc) { echo '  <url><loc>', htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8'), "</loc></url>\n"; }
        echo '</urlset>', "\n";
    }

    /** The hand-written public pages. Sitemap, llms.txt and the content engine read this list. */
    public static function public_pages(): array {
        $pages = array(
            array('path' => '/features', 'title' => 'Features', 'description' => 'One creator platform for your public page, memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'changefreq' => 'monthly', 'priority' => '0.9'),
            array('path' => '/pricing',  'title' => 'Pricing',  'description' => PagesController::plan_price_sentence() . ' Platform fee: ' . PagesController::fee_short() . '. ' . PagesController::addon_sentence(),                                                                       'changefreq' => 'monthly', 'priority' => '0.9'),
        );
        foreach (PagesController::feature_pages() as $slug => $f) {
            $pages[] = array('path' => '/features/' . $slug, 'title' => $f['title'], 'description' => $f['description'], 'changefreq' => 'monthly', 'priority' => '0.8');
        }
        foreach (PagesController::root_feature_pages() as $slug => $f) {   // /lora-character-training ... (same template)
            $pages[] = array('path' => '/' . $slug, 'title' => $f['title'], 'description' => $f['description'], 'changefreq' => 'monthly', 'priority' => '0.8');
        }
        $checked = '';   // the newest "facts checked" date across the compare pages dates the best-of page too
        foreach (PagesController::COMPETITORS as $slug => $c) {
            if (isset(PagesController::COMPETITOR_GROUPS[$c['type'] ?? ''])) { $checked = max($checked, (string) ($c['checked'] ?? '')); }   // only competitors listed on the best-of page
            $pages[] = array('path' => '/compare/' . $slug, 'title' => Main::site_name() . ' vs ' . $c['name'], 'description' => (preg_match('/^[AEIOU]/i', $c['name']) ? 'An ' : 'A ') . $c['name'] . ' alternative for creators, compared with sources.', 'changefreq' => 'monthly', 'priority' => '0.8', 'lastmod' => (string) ($c['checked'] ?? ''));
        }
        $pages[] = array('path' => '/best-creator-monetization-platforms', 'title' => 'Best creator monetization platforms', 'description' => 'How the main creator platforms compare on fees, what you can sell, payouts and ownership.', 'changefreq' => 'monthly', 'priority' => '0.8', 'lastmod' => $checked);
        foreach (array_keys(AlternativesPages::PAGES) as $k) {   // /onlyfans-alternatives, /fanvue-alternatives
            $a = PagesController::alternatives_page($k);
            $pages[] = array('path' => $a['path'], 'title' => $a['title'], 'description' => $a['description'], 'changefreq' => 'monthly', 'priority' => '0.8');
        }
        $pages[] = array('path' => '/terms',   'title' => 'Terms of Service', 'description' => 'Terms for using the platform.',                         'changefreq' => 'yearly', 'priority' => '0.3');
        $pages[] = array('path' => '/privacy', 'title' => 'Privacy Policy',   'description' => 'What we collect, how it is used, and your choices.', 'changefreq' => 'yearly', 'priority' => '0.3');
        $pages[] = array('path' => '/about',   'title' => 'About ' . Main::site_name(), 'description' => 'What the platform is, who it is for, the AI influencer tools and how creators get paid.', 'changefreq' => 'monthly', 'priority' => '0.5');
        $pages[] = array('path' => '/contact', 'title' => 'Contact support', 'description' => 'Contact support about billing, payouts, your account or a bug.', 'changefreq' => 'yearly', 'priority' => '0.4');
        foreach (ToolsController::TOOLS as $t_slug => $t) {   // free lead tools
            $pages[] = array('path' => '/tools/' . $t_slug, 'title' => $t['title'], 'description' => $t['description'], 'changefreq' => 'monthly', 'priority' => '0.7');
        }
        // Creator directory: only once someone is listed, and only categories that have creators (no empty pages indexed).
        try {
            $counts = (new CreatorProfileModel())->directory_counts();
            if (array_sum($counts) > 0) {
                $pages[] = array('path' => '/creators', 'title' => 'Creator directory', 'description' => 'Creators who chose to be listed, by category: follow them, join a membership or book a service.', 'changefreq' => 'daily', 'priority' => '0.8');
                foreach (DirectoryService::categories() as $slug => $label) {
                    if (empty($counts[$slug])) { continue; }
                    $pages[] = array('path' => '/creators/' . $slug, 'title' => $label . ' creators', 'description' => $label . ' creators on ' . Main::site_name() . '.', 'changefreq' => 'daily', 'priority' => '0.6');
                }
            }
        } catch (\Throwable $e) { error_log('[seo] directory pages: ' . $e->getMessage()); }
        $pages[] = array('path' => '/monetize-your-content',               'title' => 'How to monetize your content',        'description' => 'Memberships, pay-per-view, bundles, services and events, and how to price each.',           'changefreq' => 'monthly', 'priority' => '0.8');
        $fm = PagesController::founding_meta();   // founding creator offer
        $pages[] = array('path' => '/founding', 'title' => $fm['title'], 'description' => $fm['description'], 'changefreq' => 'weekly', 'priority' => '0.7');
        $am = PagesController::affiliates_meta();   // affiliate program
        $pages[] = array('path' => '/affiliates', 'title' => $am['title'], 'description' => $am['description'], 'changefreq' => 'monthly', 'priority' => '0.6');
        return $pages;
    }

    /** Short machine-readable index for LLM crawlers (llmstxt.org). */
    public function llmsAction(){
        $base = Main::get_base_domain(); $site = Main::site_name();
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: public, max-age=3600');
        $l = array();
        $l[] = '# ' . $site;
        $l[] = '';
        $l[] = '> ' . SeoMeta::brand_description();
        $l[] = '';
        $l[] = '## Plans';
        $l[] = '- ' . PagesController::plan_cost_answer();
        $l[] = '';
        $l[] = '## Facts';
        foreach (PagesController::quotable_facts() as $fact) { $l[] = '- ' . $fact; }
        $l[] = '- ' . $site . ' publishes to nine social networks: ' . self::NETWORKS . ', plus Fanvue cross-posting.';
        $l[] = '- A plan on ' . $site . ' can be canceled at any time and runs to the end of the paid period.';
        $l[] = '- Payouts go to your bank: earnings collect as credits (' . Price::CREDITS_PER_DOLLAR . ' credits = $1) and you cash them out to your own bank account on request, with a ' . Price::PAYOUT_MIN_LABEL . ' minimum.';
        $l[] = '- ' . $site . ' has a Claude connector (MCP server) so a creator can run their account from Claude: posts, messages, analytics and more.';
        $l[] = '';
        $l[] = '## Product';
        $legal = array();
        foreach (self::public_pages() as $p) {
            $line = '- [' . $p['title'] . '](' . $base . $p['path'] . '): ' . $p['description'];
            if (in_array($p['path'], self::LEGAL_PATHS, true)) { $legal[] = $line; continue; }
            $l[] = $line;
        }
        try {
            $recent = (new SeoArticlesModel())->published(20, 0);
            if (!empty($recent)) {
                $l[] = ''; $l[] = '## ' . BlogController::NAME;
                foreach ($recent as $a) { $l[] = '- [' . $a['title'] . '](' . $base . '/blog/' . $a['slug'] . '): ' . ($a['meta_description'] ?: (string) $a['excerpt']); }
                $l[] = '- [All posts](' . $base . '/blog) · [RSS](' . $base . '/blog/feed.xml)';
            }
        } catch (\Throwable $e) {}
        $l[] = '';
        $l[] = '## Creators';
        $l[] = '- Public creator pages live at ' . $base . '/@handle (listed in ' . $base . '/sitemap.xml).';
        $l[] = '';
        $l[] = '## Full text';
        $l[] = '- [llms-full.txt](' . $base . '/llms-full.txt): every public page as plain text.';
        if (!empty($legal)) { $l[] = ''; $l[] = '## Optional'; foreach ($legal as $line) { $l[] = $line; } }
        echo implode("\n", $l), "\n";
    }

    /** Every public page's text, concatenated, for LLM ingestion. Rendered pages are fetched internally and stripped to text. */
    /**
     * The Markdown copy of a public page (Daniel 2026-10-12): /pricing.md, /compare/patreon.md, /blog/<slug>.md, /index.md.
     * Every page that has one links to it with <link rel="alternate" type="text/markdown">. An article is its own Markdown source;
     * a product page is its rendered text (the same extraction llms-full.txt uses). Not indexed: the HTML page is the canonical copy.
     */
    public function markdownAction(array $url){
        $base = Main::get_base_domain(); $site = Main::site_name();
        $path = '/' . preg_replace('/\.md$/', '', implode('/', $url)); if ($path === '/index') { $path = '/'; }
        $title = ''; $body = '';
        self::$md = true;
        if (preg_match('#^/blog/([a-z0-9-]+)$#', $path, $m)) {
            try { $a = (new SeoArticlesModel())->get_by_slug($m[1], true); } catch (\Throwable $e) { $a = null; }
            if ($a) {
                $title = (string) $a['title'];
                $dates = array();
                if (!empty($a['published_at'])) { $dates[] = 'Published: ' . gmdate('Y-m-d', strtotime($a['published_at'] . ' UTC')); }
                if (!empty($a['updated_at']) && substr((string) $a['updated_at'], 0, 10) > substr((string) ($a['published_at'] ?? ''), 0, 10)) { $dates[] = 'Updated: ' . gmdate('Y-m-d', strtotime($a['updated_at'] . ' UTC')); }
                $body = ($dates ? implode('. ', $dates) . ".\n\n" : '') . trim((string) ($a['excerpt'] ?? '')) . "\n\n" . trim((string) $a['body_md']);
            }
        } elseif ($path === '/') {
            $out = array(); self::add_page($out, $site, $base . '/', self::render_home_html());
            $title = $site; $body = implode("\n", array_slice($out, 3));
        } elseif ($path === '/blog') {
            // the index is a list, so it is written as one: a line per article, newest first
            $title = 'Creator Monetization Guides'; $rows = array();
            try { $rows = (array) (new SeoArticlesModel())->published(200, 0); } catch (\Throwable $e) { error_log('[seo] markdown blog: ' . $e->getMessage()); }
            foreach ($rows as $r) {
                $when = !empty($r['published_at']) ? ' (' . gmdate('Y-m-d', strtotime($r['published_at'] . ' UTC')) . ')' : '';
                $body .= '- [' . str_replace(array('[', ']'), '', (string) $r['title']) . '](' . $base . '/blog/' . $r['slug'] . ')' . $when . ': ' . trim((string) ($r['excerpt'] ?? '')) . "\n";
            }
        } elseif (preg_match('#^/tools/([a-z0-9-]+)$#', $path, $m) && isset(ToolsController::TOOLS[$m[1]])) {
            $slug = $m[1]; $t = (string) ToolsController::TOOLS[$slug]['title'];
            $out = array(); self::add_page($out, $t, $base . $path, self::render_route_html('tools/' . $slug, function () use ($slug) { (new ToolsController())->{str_replace('-', '', $slug) . 'Action'}(); }));
            if (!empty($out)) { $title = $t; $body = implode("\n", array_slice($out, 3)); }
        } elseif (preg_match('#^/@([a-z0-9_]+)$#', $path, $m)) {
            // a creator's profile: its name, its description and its own links, read from the page as a signed-out visitor sees it
            $html = self::render_route_html('@' . $m[1], function () { (new ProfileController())->viewAction(); });
            if ($html !== '' && stripos($html, 'content="noindex') === false && preg_match('/<meta name="description" content="([^"]*)"/', $html, $d) && preg_match('/<meta property="og:title" content="([^"]*)"/', $html, $t)) {
                $title = html_entity_decode($t[1], ENT_QUOTES, 'UTF-8') . ' (@' . $m[1] . ')';
                $body = html_entity_decode($d[1], ENT_QUOTES, 'UTF-8');
                if (preg_match('/"sameAs":\[([^\]]*)\]/', $html, $sa)) { foreach ((array) json_decode('[' . $sa[1] . ']', true) as $l) { $body .= "\n- " . $l; } }
            }
        } else {
            foreach (self::public_pages() as $p) {
                if ((string) $p['path'] !== $path) { continue; }
                $out = array(); self::add_page($out, (string) $p['title'], $base . $path, self::render_public_html($path));
                if (!empty($out)) { $title = (string) $p['title']; $body = implode("\n", array_slice($out, 3)); }
                break;
            }
        }
        self::$md = false;
        if (trim($body) === '') { Errors::page_not_found(); return; }
        header_remove('Set-Cookie');
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('X-Robots-Tag: noindex');
        header('Link: <' . $base . ($path === '/' ? '/' : $path) . '>; rel="canonical"');
        echo '# ', $title, "\n\n", 'Source: ', $base, ($path === '/' ? '/' : $path), "\n\n", self::markdown_shape(trim($body)), "\n";
    }

    /** True while markdownAction() builds a page: html_to_text() then writes links as [label](url). */
    private static $md = false;

    /** Output of a routed page (blog index, a tool, a profile) captured for its text; '' when it answers 404 or fails. */
    private static function render_route_html(string $route, callable $run): string {
        $saved = $_GET; $_GET['url'] = $route; $code = http_response_code(); $html = '';
        ob_start();
        try { $run(); $html = (string) ob_get_clean(); if (http_response_code() === 404) { $html = ''; } }
        catch (\Throwable $e) { ob_end_clean(); error_log('[seo] markdown render ' . $route . ': ' . $e->getMessage()); $html = ''; }
        finally { $_GET = $saved; if (http_response_code() !== $code) { http_response_code($code); } }
        return $html;
    }

    /**
     * Plain page text made valid Markdown: heading levels run "#", "##", "###" with no gap, and every run of "a | b | c" lines
     * becomes a pipe table with a header separator row (rows padded to the header's width).
     */
    public static function markdown_shape(string $text): string {
        if (!preg_match('/^## /m', $text)) { $text = preg_replace(array('/^### /m', '/^#### /m'), array('## ', '### '), $text); }
        $out = array(); $run = array();
        $flush = function () use (&$out, &$run) {
            if (count($run) < 2) { foreach ($run as $r) { $out[] = $r; } $run = array(); return; }
            $rows = array_map(function ($r) { return array_map('trim', explode(' | ', $r)); }, $run);
            $w = max(array_map('count', $rows));
            if ($out && trim((string) end($out)) !== '') { $out[] = ''; }
            foreach ($rows as $i => $cells) {
                $cells = array_pad(array_map(function ($c) { return str_replace('|', '\\|', $c); }, $cells), $w, '');
                $out[] = '| ' . implode(' | ', $cells) . ' |';
                if ($i === 0) { $out[] = '|' . str_repeat(' --- |', $w); }
            }
            $out[] = ''; $run = array();
        };
        foreach (explode("\n", $text) as $ln) {
            if (strpos($ln, ' | ') !== false && $ln[0] !== '#' && strpos($ln, '| ') !== 0) { $run[] = $ln; continue; }
            if ($run && trim($ln) === '' ) { continue; }   // blank lines between the rows of one table
            $flush(); $out[] = $ln;
        }
        $flush();
        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $out)));
    }

    /** Does this public path have a Markdown copy (markdownAction)? The head links to it when it does. */
    public static function has_markdown(string $path): bool {
        if ($path === '/' || $path === '/blog' || preg_match('#^/blog/[a-z0-9-]+$#', $path) || preg_match('#^/@[a-z0-9_]+$#', $path)) { return true; }
        $seg = explode('/', trim($path, '/'));
        if ($seg[0] === 'tools') { return isset(ToolsController::TOOLS[$seg[1] ?? '']) && count($seg) === 2; }
        return isset(PagesController::ROUTES[$seg[0]]) && $seg[0] !== 'author';   // rendered through PagesController; the author page is left out
    }

    /**
     * OAuth 2.0 Protected Resource Metadata (RFC 9728) for the MCP connector at /mcp, so a client that gets a 401 there can read
     * how the resource is protected. Tokens are issued per creator in account settings; there is no authorization server to list.
     */
    public function protectedResourceAction(){
        $base = Main::get_base_domain();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(array(
            'resource' => $base . '/mcp',
            'resource_name' => Main::site_name() . ' MCP connector',
            'bearer_methods_supported' => array('header'),
            'resource_documentation' => $base . '/llms.txt',
        ), JSON_UNESCAPED_SLASHES);
    }

    public function llmsFullAction(){
        $base = Main::get_base_domain(); $site = Main::site_name();
        // Rendering every public page + article is heavy: serve a cached copy for an hour (per host).
        $cache = sys_get_temp_dir() . '/cls_llms_full_' . md5($base) . '.txt';
        if (is_file($cache) && filemtime($cache) > time() - 3600) {
            header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: public, max-age=3600');
            readfile($cache);
            return;
        }
        $out = array('# ' . $site . ': Full Text', '', '> ' . SeoMeta::brand_description(), '', 'Source: ' . $base . '/llms.txt', '');
        // the home page first, then the product pages, the articles, and the legal pages last
        self::add_page($out, 'Home', $base . '/', self::render_home_html());
        $legal = array();
        foreach (self::public_pages() as $p) {
            if (in_array($p['path'], self::LEGAL_PATHS, true)) { $legal[] = $p; continue; }
            self::add_page($out, $p['title'], $base . $p['path'], self::render_public_html($p['path']));
        }
        try {
            foreach ((new SeoArticlesModel())->published_bodies(200) as $a) {
                self::add_page($out, $a['title'], $base . '/blog/' . $a['slug'], '<main>' . $a['body_html'] . '</main>');
            }
        } catch (\Throwable $e) { error_log('[seo] llms-full articles: ' . $e->getMessage()); }
        foreach ($legal as $p) { self::add_page($out, $p['title'], $base . $p['path'], self::render_public_html($p['path'])); }
        $text = implode("\n", $out);
        if (strlen($text) > 2 * 1024 * 1024) { $text = mb_strcut($text, 0, 2 * 1024 * 1024, 'UTF-8'); }
        // Set after the render loop, immediately before output: a rendered page's own
        // constructor/action may have sent headers of its own (see render_public_html), so
        // ours must be the last ones sent to win.
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: public, max-age=3600');
        @file_put_contents($cache, $text . "\n", LOCK_EX);
        echo $text, "\n";
    }

    /** One page in llms-full.txt: "## title", the URL, then the text, with the page's own H1 as a plain line (dropped when it repeats the title). */
    private static function add_page(array &$out, string $title, string $url, string $html): void {
        if ($html === '') { return; }
        $text = self::html_to_text($html);
        if ($text === '') { return; }
        if (strpos($text, '## ') === 0) {   // one "##" per page, so headings never repeat
            $nl = strpos($text, "\n");
            $h1 = trim(substr($nl === false ? $text : substr($text, 0, $nl), 3));
            $rest = $nl === false ? '' : trim(substr($text, $nl));
            $text = (strcasecmp(rtrim($h1, '.'), rtrim($title, '.')) === 0) ? $rest : trim($h1 . "\n" . $rest);
        }
        $out[] = '## ' . $title;
        $out[] = 'URL: ' . $url;
        $out[] = '';
        if ($text !== '') { $out[] = $text; $out[] = ''; }
    }

    /** The landing page body (libs/Layout/home_body.php) as HTML wrapped in <main>, for llms-full.txt. */
    private static function render_home_html(): string {
        ob_start();
        try {
            include Main::lib_path() . '/Layout/home_body.php';
            return '<main>' . (string) ob_get_clean() . '</main>';
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log('[seo] llms-full home: ' . $e->getMessage());
            return '';
        }
    }

    /** Render one public page through PagesController into a string (output-buffered). */
    private static function render_public_html($path): string {
        $seg = array_values(array_filter(explode('/', trim((string) $path, '/'))));
        $method = PagesController::ROUTES[$seg[0]] ?? '';
        if ($method === '') { return ''; }
        $saved = $_GET;
        $_GET['url'] = implode('/', $seg);   // Main::get_url() reads this
        PagesController::$embedded = true;
        $code = http_response_code();
        $html = '';
        ob_start();
        try {
            (new PagesController())->{$method . 'Action'}();
            $html = (string) ob_get_clean();
            if (http_response_code() === 404) { $html = ''; }
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log('[seo] llms-full render ' . $path . ': ' . $e->getMessage());
            $html = '';
        } finally {
            PagesController::$embedded = false;
            $_GET = $saved;
            if (http_response_code() !== $code) { http_response_code($code); }
        }
        return $html;
    }

    /** <main> contents → plain text with headings as Markdown. Not a public page (no <main>) → ''. */
    private static function html_to_text($html): string {
        if (!preg_match('/<main[^>]*>(.*)<\/main>/is', $html, $m)) { return ''; }
        $html = $m[1];
        $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<aside\b[^>]*>.*?<\/aside>/is', '', $html);   // cross-links (guides block) are listed once in llms.txt, not repeated per page
        // page chrome that reads as noise in plain text: the closing call-to-action band, the live posts mosaic, jump navs, buttons and tabs
        $html = preg_replace('/<section class="sx sx--cta".*?<\/section>/is', '', $html);
        $html = preg_replace('/<section\b(?:(?!<\/section>).)*?class="lm(?:__live)?"(?:(?!<\/section>).)*<\/section>/is', '', $html);
        $html = preg_replace('/(<section class="sx hx(?: hx--titled)?">.*?)<div class="hx__mod">.*?<\/section>/is', '$1</section>', $html);   // the home hero's demo panels
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', '', $html);
        $html = preg_replace('/<span class="sx-plan__tag">.*?<\/span>/is', '', $html);   // "Most popular" badge
        $html = preg_replace('/<button\b[^>]*>.*?<\/button>/is', '', $html);
        $html = preg_replace('/<a\b[^>]*class="[^"]*\bsx-btn\b[^"]*"[^>]*>.*?<\/a>/is', '', $html);
        $html = preg_replace('/<div class="hx__words"[^>]*>\s*<\/div>/is', '', $html);
        $html = preg_replace('/<p class="hx__words hx__words--eyebrow".*?<\/p>/is', '', $html);   // the decorative wordmark above the home H1
        // check marks are "Yes"; an empty header cell gets a label so no table cell is blank
        $html = preg_replace('/<span class="sx-vh">.*?<\/span>/is', '', $html);   // the hidden word beside a check mark: the svg below already becomes "Yes"
        $html = preg_replace('/<figcaption\b.*?<\/figcaption>/is', '', $html);       // a caption without its picture is an orphan line in a text file
        $html = preg_replace('/<svg\b[^>]*aria-label="Included"[^>]*>.*?<\/svg>/is', 'Yes', $html);
        $html = preg_replace('/<svg\b.*?<\/svg>/is', '', $html);
        $html = preg_replace('/<th([^>]*)>\s*<\/th>/i', '<th$1>Item</th>', $html);
        $html = preg_replace('/(<(?:td|th|dd)\b[^>]*>)\s*(?:&mdash;|\xE2\x80\x94)\s*(<\/(?:td|th|dd)>)/i', '$1n/a$2', $html);   // a dash cell means "does not apply"
        $html = preg_replace('/<\/h2>\s*<p class="sx-plan__role">(.*?)<\/p>/is', ': $1</h2>', $html);   // pricing card: "Creator: Get discovered"
        $html = preg_replace('/<\/span>\s*<b>/i', '</span>: <b>', $html);   // panel rows: "label: value"
        $html = preg_replace('/<\/b>\s*<a /i', '</b> <a ', $html);
        $html = preg_replace('/<\/span>\s*(<span class="dir-card__)/i', '</span> · $1', $html);   // directory card fields
        $html = preg_replace('/<\/dd>\s*<\/div>/i', '</dd>', $html);   // one line per term, no blank line between them
        // FAQ: question and answer on their own lines, a blank line between pairs
        $html = preg_replace('/<summary[^>]*>(.*?)<\/summary>/is', "\nQ: $1\n", $html);
        $html = preg_replace('/<div class="sx-faq__a">(.*?)<\/div>/is', "A: $1\n\n", $html);
        // links keep their absolute URL, so a model can follow them
        $base = rtrim((string) Main::get_base_domain(), '/');
        $html = preg_replace_callback('/<a\b[^>]*href="([^"#][^"]*)"[^>]*>(.*?)<\/a>/is', function ($m) use ($base) {
            $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if ($href[0] === '/' && (strlen($href) < 2 || $href[1] !== '/')) { $href = $base . $href; }
            if (!preg_match('#^https?://#i', $href)) { return $m[2]; }
            $label = trim(strip_tags($m[2]));
            if ($label === '') { return ''; }
            return self::$md ? '[' . $label . '](' . $href . ')' : $m[2] . ' (' . $href . ')';   // a Markdown copy gets a real link
        }, $html);
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n## $1\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n### $1\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n#### $1\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "\n- ", $html);
        $html = preg_replace('/<\/dt>/i', ": ", $html);
        $html = preg_replace('/<\/dd>/i', "\n", $html);
        $html = preg_replace('/<\/(p|li|tr|div)>/i', "\n", $html);
        $html = preg_replace('/<\/t[dh]>/i', " | ", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($text, '&#') !== false || strpos($text, '&amp;') !== false) { $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }   // stored text that was encoded twice
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $lines = array();
        foreach (explode("\n", $text) as $ln) {
            $ln = trim($ln);
            if (strpos($ln, '|') !== false) { $ln = rtrim($ln, ' |'); }   // table rows: no trailing separator
            if ($ln === '-') { continue; }   // empty list items
            $lines[] = $ln;
        }
        $text = preg_replace('/^(- .*)\n\n+(?=- )/m', "$1\n", implode("\n", $lines));   // list items stay together
        $text = preg_replace('/([^\n])\n(#{2,4} )/', "$1\n\n$2", $text);   // a blank line before every heading
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

}
