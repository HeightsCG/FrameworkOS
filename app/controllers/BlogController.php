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
        if (!self::$embedded) { header('Cache-Control: private, max-age=300'); }
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
        $title = ($q !== '') ? ('Search: ' . $q . ' · ' . self::NAME) : (self::NAME . ($page > 1 ? ', page ' . $page : ''));
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
        $this->page('blog-index', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'website', 'jsonld' => $jsonld, 'noindex' => ($q !== '' || ($page > 1 && empty($rows))), 'no_guides' => true, 'no_band' => true, 'sections' => true),
            array('articles' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total, 'guides' => $guides, 'q' => $q));
    }

    public function viewAction($slug){
        $articles = new SeoArticlesModel();
        $preview  = isset($_GET['preview']) && Permissions::is_admin();
        try { $a = $articles->get_by_slug($slug, !$preview); } catch (\Throwable $e) { error_log('[seo] blog article: ' . $e->getMessage()); $a = null; }
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
            SeoMeta::article(array('headline' => $a['title'], 'description' => $a['meta_description'], 'author' => (string) ($a['author'] ?? ''), 'url' => SeoMeta::base() . $path, 'published' => $published, 'modified' => $modified, 'image' => $a['cover_image_url'] ?: null)),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => self::NAME, 'url' => '/blog'), array('name' => $a['title'], 'url' => $path))),
        );
        if (!empty($faq)) { $jsonld[] = SeoMeta::faq($faq); }
        $anch = self::anchor_headings((string) $a['body_html']);
        $this->page('blog-article', array('path' => $path, 'title' => $a['title'], 'description' => $a['meta_description'], 'type' => 'article', 'published' => $published, 'modified' => $modified,
                'image' => $a['cover_image_url'] ?: null, 'jsonld' => $jsonld, 'noindex' => $preview, 'no_guides' => true, 'no_band' => true),
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
