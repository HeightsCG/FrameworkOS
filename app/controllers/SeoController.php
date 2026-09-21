<?php
/**
 * Crawler-facing endpoints: /robots.txt and /sitemap.xml. Both are generated so the
 * absolute URLs always match the host being served (dev vs. live). Dispatched from
 * Bootstrap before normal controller resolution because the URLs contain a dot.
 */
class SeoController extends Controller {

    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    /** Index the landing page and creator pages; keep the signed-in app and machine endpoints out. */
    public function robotsAction(){
        $base = Main::get_base_domain();
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $private = array('/account', '/admin', '/api', '/audience', '/dashboard', '/events', '/go', '/inbox', '/influencers', '/mcp', '/purchases', '/services', '/setup', '/studio');

        $lines   = array();
        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';
        $lines[] = 'Allow: /llms.txt';
        $lines[] = 'Allow: /llms-full.txt';
        foreach ($private as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        echo implode("\n", $lines), "\n";
    }

    public function sitemapAction(){
        $base = Main::get_base_domain();
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $urls   = array();
        $urls[] = array('loc' => $base . '/', 'changefreq' => 'weekly', 'priority' => '1.0');

        foreach (self::public_pages() as $p) {
            $urls[] = array('loc' => $base . $p['path'], 'changefreq' => $p['changefreq'], 'priority' => $p['priority'],
                            'lastmod' => gmdate('Y-m-d', filemtime(Main::app_path() . '/app/controllers/PagesController.php')));
        }

        foreach ((new UsersModel())->list_public_creators() as $row) {
            $lastmod = !empty($row['last_modified']) ? gmdate('Y-m-d', strtotime((string) $row['last_modified'] . ' UTC')) : '';
            $urls[]  = array(
                'loc'        => $base . '/@' . rawurlencode((string) $row['u_name']),
                'lastmod'    => $lastmod,
                'changefreq' => 'daily',
                'priority'   => '0.8',
            );
        }

        try {
            $articles = new SeoArticlesModel();
            $urls[] = array('loc' => $base . '/blog', 'changefreq' => 'daily', 'priority' => '0.8');
            foreach ($articles->published(500, 0) as $a) {
                $urls[] = array('loc' => $base . '/blog/' . $a['slug'], 'lastmod' => gmdate('Y-m-d', strtotime(($a['updated_at'] ?: $a['published_at']) . ' UTC')), 'changefreq' => 'monthly', 'priority' => '0.7');
            }
        } catch (\Throwable $e) { error_log('[seo] sitemap articles: ' . $e->getMessage()); }

        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
        foreach ($urls as $u) {
            echo "  <url>\n";
            echo '    <loc>', htmlspecialchars($u['loc'], ENT_QUOTES | ENT_XML1, 'UTF-8'), "</loc>\n";
            if (!empty($u['lastmod'])) {
                echo '    <lastmod>', $u['lastmod'], "</lastmod>\n";
            }
            echo '    <changefreq>', $u['changefreq'], "</changefreq>\n";
            echo '    <priority>', $u['priority'], "</priority>\n";
            echo "  </url>\n";
        }
        echo '</urlset>', "\n";
    }

    /** The hand-written public pages. Sitemap, llms.txt and the content engine read this list. */
    public static function public_pages(): array {
        $pages = array(
            array('path' => '/features', 'title' => 'Features', 'description' => 'One creator platform for your public page, memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'changefreq' => 'monthly', 'priority' => '0.9'),
            array('path' => '/pricing',  'title' => 'Pricing',  'description' => 'Three monthly plans; the take rate falls as you grow.',                                                                       'changefreq' => 'monthly', 'priority' => '0.9'),
        );
        foreach (PagesController::COMPETITORS as $slug => $c) {
            $pages[] = array('path' => '/compare/' . $slug, 'title' => Main::site_name() . ' vs ' . $c['name'], 'description' => (preg_match('/^[AEIOU]/i', $c['name']) ? 'An ' : 'A ') . $c['name'] . ' alternative for creators, compared with sources.', 'changefreq' => 'monthly', 'priority' => '0.8');
        }
        $pages[] = array('path' => '/best-creator-monetization-platforms', 'title' => 'Best creator monetization platforms', 'description' => 'How the main creator platforms compare on fees, what you can sell, payouts and ownership.', 'changefreq' => 'monthly', 'priority' => '0.8');
        $pages[] = array('path' => '/monetize-your-content',               'title' => 'How to monetize your content',        'description' => 'Memberships, pay-per-view, bundles, services and events, and how to price each.',           'changefreq' => 'monthly', 'priority' => '0.8');
        return $pages;
    }

    /** Short machine-readable index for LLM crawlers (llmstxt.org). */
    public function llmsAction(){
        $base = Main::get_base_domain(); $site = Main::site_name();
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: private, max-age=3600');
        $l = array();
        $l[] = '# ' . $site;
        $l[] = '';
        $l[] = '> ' . $site . ' is a creator platform: one public page with memberships, pay-per-view posts, bundles, services, events and tracked links, a studio that publishes to nine social networks with AI captions, an inbox with AI replies, and payouts to your bank. Plans are monthly; the platform take rate falls as the plan grows.';
        $l[] = '';
        $l[] = '## Product';
        foreach (self::public_pages() as $p) { $l[] = '- [' . $p['title'] . '](' . $base . $p['path'] . '): ' . $p['description']; }
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
        echo implode("\n", $l), "\n";
    }

    /** Every public page's text, concatenated, for LLM ingestion. Rendered pages are fetched internally and stripped to text. */
    public function llmsFullAction(){
        $base = Main::get_base_domain(); $site = Main::site_name();
        $out = array('# ' . $site . ' — full text', '', 'Source: ' . $base . '/llms.txt', '');
        foreach (self::public_pages() as $p) {
            $html = self::render_public_html($p['path']);
            if ($html === '') { continue; }
            $text = self::html_to_text($html);
            if ($text === '') { continue; }
            $out[] = '## ' . $p['title'];
            $out[] = 'URL: ' . $base . $p['path'];
            $out[] = '';
            $out[] = $text;
            $out[] = '';
        }
        try {
            foreach ((new SeoArticlesModel())->published_bodies(200) as $a) {
                $text = self::html_to_text('<main>' . $a['body_html'] . '</main>');   // the '## title' + URL lines above are the heading
                if ($text === '') { continue; }
                $out[] = '## ' . $a['title']; $out[] = 'URL: ' . $base . '/blog/' . $a['slug']; $out[] = ''; $out[] = $text; $out[] = '';
            }
        } catch (\Throwable $e) { error_log('[seo] llms-full articles: ' . $e->getMessage()); }
        $text = implode("\n", $out);
        if (strlen($text) > 2 * 1024 * 1024) { $text = mb_strcut($text, 0, 2 * 1024 * 1024, 'UTF-8'); }
        // Set after the render loop, immediately before output: a rendered page's own
        // constructor/action may have sent headers of its own (see render_public_html), so
        // ours must be the last ones sent to win.
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: private, max-age=3600');
        echo $text, "\n";
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
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n## $1\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n### $1\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n#### $1\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "\n- ", $html);
        $html = preg_replace('/<\/dt>/i', ": ", $html);
        $html = preg_replace('/<\/dd>/i', "\n", $html);
        $html = preg_replace('/<\/(p|li|tr|div)>/i', "\n", $html);
        $html = preg_replace('/<\/t[dh]>/i', " | ", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

}
