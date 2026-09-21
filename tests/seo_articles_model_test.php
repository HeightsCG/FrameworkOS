<?php
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$k = new SeoKeywordsModel(); $a = new SeoArticlesModel();
$kw = 'zz test keyword ' . time();
$kid = $k->add($kw, 120, 'easy', 5);
check('keyword added', $kid > 0);
$next = $k->next_queued();
check('next_queued is the highest priority queued row (lowest number)', $next && (int) $next['id'] === $kid);
$k->set_status($kid, 'drafting');
check('status drafting', $k->get($kid)['status'] === 'drafting');

$slug = 'zz-test-article-' . time();
$aid = $a->create(array(
    'slug' => $slug, 'title' => 'Test article', 'meta_description' => 'desc', 'excerpt' => 'ex',
    'body_md' => "## One\n\nHello world.", 'body_html' => '<h2>One</h2><p>Hello world.</p>',
    'target_keyword' => $kw, 'secondary_keywords' => json_encode(array('a', 'b')), 'faq' => json_encode(array()),
    'reading_minutes' => 1, 'status' => 'review', 'model' => 'test', 'prompt_version' => 'v1',
));
check('article created', $aid > 0);
check('slug_exists true', $a->slug_exists($slug));
check('slug_exists false for except_id', !$a->slug_exists($slug, $aid));
check('get_by_slug hides unpublished by default', $a->get_by_slug($slug) === null);
check('get_by_slug shows unpublished when asked', $a->get_by_slug($slug, false) !== null);
check('in review list', in_array($aid, array_map('intval', array_column($a->by_status(array('review')), 'id')), true));
$before = $a->count_published();
$a->set_status($aid, 'published', 1);
check('count_published +1', $a->count_published() === $before + 1);
check('published_at set', !empty($a->get($aid)['published_at']));
check('newest_published contains it', in_array($aid, array_map('intval', array_column($a->newest_published(50), 'id')), true));
$a->record_view($aid, 'k1'); $a->record_view($aid, 'k1'); $a->record_view($aid, 'k2');
check('views deduped by viewer_key', (int) $a->get($aid)['views'] === 2);
$k->set_status($kid, 'published', $aid);
check('keyword linked to article', (int) $k->get($kid)['article_id'] === $aid);
check('admin_ids returns ints', count((new UsersModel())->admin_ids()) >= 1 && is_int((new UsersModel())->admin_ids()[0]));

// cleanup
$a->delete_all('seo_page_views', 'article_id = :a', array('a' => $aid));
$a->delete_all('seo_articles', 'id = :a', array('a' => $aid));
$k->delete_all('seo_keywords', 'id = :k', array('k' => $kid));
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
