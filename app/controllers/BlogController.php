<?php
/**
 * Public blog for the SEO content engine: /blog (paged index), /blog/<slug>, /blog/feed.xml.
 * Dispatched from Bootstrap (URLs don't fit /controller/action). Rendered with View::public_page().
 */
class BlogController extends Controller {
    public $protected = 0;
    public static $embedded = false;
    const PER_PAGE = 24;
    /** What the /blog section is called everywhere (nav, page title, breadcrumbs, feed, llms.txt, product-page block). */
    const NAME = 'Blog';

    public function __construct(){
        parent::__construct();
        if (!self::$embedded) { Session::cache_header(300); }
    }

    /** Short topic label for covers and the article header, from the target keyword and title. */
    public static function topic(array $a): string {
        $k = strtolower((string) ($a['target_keyword'] ?? '') . ' ' . (string) ($a['title'] ?? ''));
        $map = array('pay-per-view' => 'Pay-per-view', 'ppv' => 'Pay-per-view', 'link in bio' => 'Link in bio', 'payout' => 'Payouts', 'bundle' => 'Bundles',
                     'service' => 'Services', 'event' => 'Events', 'price' => 'Pricing', 'pricing' => 'Pricing', 'tier' => 'Memberships', 'membership' => 'Memberships',
                     'subscription' => 'Memberships', 'cross-post' => 'Social', 'social' => 'Social', 'ai ' => 'AI', 'onlyfans' => 'Platforms', 'fanvue' => 'Platforms', 'monetiz' => 'Getting paid');
        foreach ($map as $needle => $label) { if (strpos($k, $needle) !== false) { return $label; } }
        return 'Guide';
    }

    /**
     * The next step beside an article (2026-10-15): the free tool and the product page that fit its topic (topic()), so a reader
     * can put the guide to work. Returns array(tool slug, feature path, feature label).
     */
    public static function next_step(array $a): array {
        $t = self::topic($a);
        $map = array(
            'AI'           => array('ai-influencer-persona', '/features/ai-influencer', 'AI influencer'),
            'Social'       => array('ai-disclosure-caption-checker', '/features/publishing', 'publishing'),
            'Link in bio'  => array('ai-influencer-bio-generator', '/features/link-in-bio', 'link in bio'),
            'Pay-per-view' => array('fan-questions', '/features/pay-per-view', 'pay-per-view'),
            'Memberships'  => array('fan-questions', '/features/memberships', 'memberships'),
            'Pricing'      => array('fan-questions', '/pricing', 'pricing'),
            'Bundles'      => array('fan-questions', '/monetize-your-content', 'ways to get paid'),
            'Services'     => array('fan-questions', '/features/services-and-events', 'services and events'),
            'Events'       => array('fan-questions', '/features/services-and-events', 'services and events'),
            'Payouts'      => array('fan-questions', '/features/payouts', 'payouts'),
            'Platforms'    => array('fan-questions', '/best-creator-monetization-platforms', 'the platform comparison'),
            'Getting paid' => array('fan-questions', '/monetize-your-content', 'ways to get paid'),
        );
        return $map[$t] ?? array('fan-questions', '/features', 'every feature');
    }

    /** Give every <h2> in rendered article HTML an id and return [html, toc]. Headings come from Markdown::render (already escaped). */
    private static function anchor_headings(string $html): array {
        $toc = array(); $used = array();
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/s', function ($m) use (&$toc, &$used) {
            $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
            $id = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-'); if ($id === '') { $id = 'section'; }
            $base = $id; $n = 2; while (isset($used[$id])) { $id = $base . '-' . $n++; } $used[$id] = true;
            $toc[] = array('id' => $id, 'text' => $text);
            return '<h2 id="' . $id . '">' . $m[1] . '</h2>';
        }, $html);
        return array($html, $toc);
    }

    public function dispatch(array $url){
        $seg = (string) ($url[1] ?? '');
        if (count($url) > 2) { Errors::page_not_found(); return; }
        if ($seg === '') { $this->indexAction(); return; }
        if ($seg === 'feed.xml') { $this->feedAction(); return; }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $seg)) { Errors::page_not_found(); return; }
        $this->viewAction($seg);
    }

    private function page($view, array $meta, array $vars = array()){
        $meta['url'] = SeoMeta::base() . $meta['path'];
        unset($meta['path']);
        // one WebPage node unless the page is an Article (crawlers that look for WebPage/Article read FAQPage-only markup as none)
        $types = array_map(function ($b) { return is_array($b) ? (string) ($b['@type'] ?? '') : ''; }, (array) ($meta['jsonld'] ?? array()));
        if (!in_array('WebPage', $types, true)) { $meta['jsonld'][] = SeoMeta::webpage($meta); }   // an Article points at it as mainEntityOfPage; a typed page node is merged with it (SeoMeta::merge_page_node)
        $this->view->public_page(Main::app_path() . '/app/views/pages/' . $view . '.php', $meta, $vars);
    }

    public function indexAction(){
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $q     = trim(preg_replace('/\s+/', ' ', mb_substr((string) ($_GET['q'] ?? ''), 0, 80)));
        $total = 0; $rows = array();
        try {
            $articles = new SeoArticlesModel();
            if ($q !== '') {
                $total = $articles->count_search($q);
                $rows  = $articles->search($q, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            } else {
                $total = $articles->count_published();
                $rows  = $articles->published(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            }
        } catch (\Throwable $e) { error_log('[seo] blog index: ' . $e->getMessage()); $total = 0; $rows = array(); }
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($q === '' && $page > 1 && $page > $pages) { Errors::page_not_found(); return; }
        // Search results are for people, not search engines: noindex, canonical stays /blog.
        $path  = ($q !== '') ? '/blog' : ('/blog' . ($page > 1 ? '?page=' . $page : ''));
        $title = ($q !== '') ? ('Search: ' . $q . ' · ' . self::NAME) : (self::NAME . ': Creator Monetization Guides' . ($page > 1 ? ', page ' . $page : ''));   // 30-60 chars with the brand suffix
        $desc  = 'Practical guides on monetizing content: memberships, pay-per-view, bundles, services, events, link in bio and payouts.';
        $items = array(); $pos = 1;
        foreach ($rows as $a) { $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'url' => SeoMeta::base() . '/blog/' . $a['slug'], 'name' => $a['title']); }
        $jsonld = array(
            SeoMeta::org(),
            array('@type' => 'CollectionPage', 'name' => $title, 'url' => SeoMeta::base() . $path, 'description' => $desc, 'isPartOf' => array('@type' => 'WebSite', 'name' => SeoMeta::site(), 'url' => SeoMeta::base() . '/'),
                  'mainEntity' => array('@type' => 'ItemList', 'itemListElement' => $items)),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => self::NAME, 'url' => '/blog'))),
        );
        $guides = array();
        foreach (SeoController::public_pages() as $p) { if (in_array($p['path'], array('/monetize-your-content', '/best-creator-monetization-platforms'), true) || strpos($p['path'], '/compare/') === 0) { $guides[] = $p; } }
        // The featured guide's cover is the index's largest image on page one (2026-10-15): preload it like an article cover.
        $pre = array();
        if ($q === '' && $page === 1 && !empty($rows)) {
            $fcw = trim((string) ($rows[0]['cover_webp_url'] ?? ''));
            if ($fcw !== '') { $pre = array('preload_image' => $fcw, 'preload_srcset' => PublicThumbService::cover_small($fcw) . ' 480w, ' . $fcw . ' 960w', 'preload_sizes' => '(max-width: 900px) 100vw, 640px'); }
            elseif (trim((string) ($rows[0]['cover_image_url'] ?? '')) !== '') { $pre = array('preload_image' => (string) $rows[0]['cover_image_url']); }
        }
        $this->page('blog-index', $pre + array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'website', 'jsonld' => $jsonld, 'noindex' => ($q !== '' || ($page > 1 && empty($rows))), 'no_guides' => true, 'no_band' => true, 'sections' => true),
            array('articles' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total, 'guides' => $guides, 'q' => $q));
    }

    public function viewAction($slug){
        $articles = new SeoArticlesModel();
        $preview  = isset($_GET['preview']) && Permissions::is_admin();
        try { $a = $articles->get_by_slug($slug, false); } catch (\Throwable $e) { error_log('[seo] blog article: ' . $e->getMessage()); $a = null; }   // any status: an archived slug may carry a 301, the checks below keep the rest 404
        if ($a && !$preview && $a['status'] !== 'published' && trim((string) ($a['redirect_to'] ?? '')) !== '') {   // merged into another article: permanent redirect
            header('Location: /blog/' . rawurlencode((string) $a['redirect_to']), true, 301); exit;
        }
        if (!$a || (!$preview && $a['status'] !== 'published')) { Errors::page_not_found(); return; }
        if (!$preview && !self::$embedded) {
            try { $articles->record_view((int) $a['id'], self::viewer_key()); } catch (\Throwable $e) { error_log('[seo] record_view: ' . $e->getMessage()); }   // counting a view never breaks the page
        }
        $faq = array();
        foreach ((array) json_decode((string) ($a['faq'] ?? '[]'), true) as $f) {
            if (is_array($f) && trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== '') { $faq[] = array('q' => (string) $f['q'], 'a' => (string) $f['a']); }
        }
        $path = '/blog/' . $a['slug'];
        $published = !empty($a['published_at']) ? gmdate('c', strtotime($a['published_at'] . ' UTC')) : gmdate('c', strtotime($a['created_at'] . ' UTC'));
        $modified  = !empty($a['updated_at'])   ? gmdate('c', strtotime($a['updated_at'] . ' UTC'))   : $published;
        $jsonld = array(
            SeoMeta::article(array('headline' => $a['title'], 'description' => $a['meta_description'], 'person' => true, 'url' => SeoMeta::base() . $path, 'published' => $published, 'modified' => $modified, 'image' => $a['cover_image_url'] ?: null)),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => self::NAME, 'url' => '/blog'), array('name' => $a['title'], 'url' => $path))),
        );
        if (!empty($faq)) { $jsonld[] = SeoMeta::faq($faq); }
        $anch = self::anchor_headings((string) $a['body_html']);
        $anch[0] = preg_replace(array('#<table>#', '#</table>#'), array('<div class="gd-table"><table>', '</table></div>'), $anch[0]);   // a scroll box per table, so a wide one never runs off a phone (SEO audit 2026-10-12)
        $anch[0] = SeoDrafter::place_cta($anch[0], (int) $a['id']);   // the signup aside sits at a different point per article (end, after the first section, before the last)
        $cw = trim((string) ($a['cover_webp_url'] ?? ''));   // the cover is the largest element on the page: preload it, with the same candidates the <img> offers
        $this->page('blog-article', array('preload_image' => $cw !== '' ? $cw : (string) ($a['cover_image_url'] ?? ''), 'preload_srcset' => $cw !== '' ? PublicThumbService::cover_small($cw) . ' 480w, ' . $cw . ' 960w' : '', 'preload_sizes' => '(max-width: 900px) 100vw, 340px', 'path' => $path, 'title' => $a['title'], 'description' => $a['meta_description'], 'type' => 'article', 'published' => $published, 'modified' => $modified,
                'image' => $a['cover_image_url'] ?: null, 'page_modified' => $modified, 'jsonld' => $jsonld, 'noindex' => $preview, 'no_guides' => true, 'no_band' => true),   // the view has its one <article>
            array('a' => $a, 'faq' => $faq, 'related' => $articles->related($a, 3), 'preview' => $preview, 'body' => $anch[0], 'toc' => $anch[1], 'topic' => self::topic($a)));
    }

    public function feedAction(){
        $base = SeoMeta::base(); $site = SeoMeta::site();
        $rows = (new SeoArticlesModel())->published(20, 0);
        header('Content-Type: application/rss+xml; charset=utf-8');
        $x = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>', "\n";
        echo '<title>', $x($site . ' ' . strtolower(self::NAME)), '</title><link>', $x($base . '/blog'), '</link><description>', $x(self::NAME . ' from ' . $site . ': pricing and selling for creators'), '</description>';
        echo '<atom:link href="', $x($base . '/blog/feed.xml'), '" rel="self" type="application/rss+xml"/>', "\n";
        foreach ($rows as $a) {
            echo '<item><title>', $x($a['title']), '</title><link>', $x($base . '/blog/' . $a['slug']), '</link><guid isPermaLink="true">', $x($base . '/blog/' . $a['slug']), '</guid>';
            echo '<pubDate>', gmdate('D, d M Y H:i:s', strtotime($a['published_at'] . ' UTC')), ' GMT</pubDate><description>', $x($a['excerpt'] ?: $a['meta_description']), '</description></item>', "\n";
        }
        echo '</channel></rss>', "\n";
    }

    /** Bots don't count; humans are deduped per day by ip+ua hash (no cookie). */
    private static function viewer_key(): string {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|lighthouse|headless|curl|wget|python|GPTBot|ClaudeBot|Bytespider/i', $ua)) { return 'bot'; }
        return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua . '|' . gmdate('Y-m-d')), 0, 40);
    }
}
