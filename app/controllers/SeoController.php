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

        $private = array('/account', '/admin', '/api', '/audience', '/dashboard', '/events', '/go', '/mcp', '/purchases', '/services', '/studio');

        $lines   = array();
        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';
        $lines[] = 'Allow: /llms.txt';
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

        foreach ((new UsersModel())->list_public_creators() as $row) {
            $lastmod = !empty($row['last_modified']) ? gmdate('Y-m-d', strtotime((string) $row['last_modified'] . ' UTC')) : '';
            $urls[]  = array(
                'loc'        => $base . '/@' . rawurlencode((string) $row['u_name']),
                'lastmod'    => $lastmod,
                'changefreq' => 'daily',
                'priority'   => '0.8',
            );
        }

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

}
