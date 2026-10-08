# SEO Content Engine (Sub-project B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `/blog` that publishes one Claude-drafted, human-approved article per day, indexed by search engines and LLMs, linked from every product page.

**Architecture:** Two tables (`seo_keywords` queue, `seo_articles`) behind two models. `SeoDrafter` builds the context, asks Claude for a JSON article, validates it with hard rules and saves it as `review`. A daily cron drafts the top queued keyword. Admin gets a Content tab (queue + review + published) and a full-page article editor with live preview. `BlogController` serves `/blog`, `/blog/<slug>` and `/blog/feed.xml` on the existing `public_page.php` layout via `View::public_page()`. Articles are appended to the sitemap, `llms.txt`, `llms-full.txt`, and a "From the guides" block on the product pages.

**Tech Stack:** PHP 8.2 custom MVC (`Model` base with `select/insert/update/delete`, PDO named params — a placeholder name may be used ONCE per query), MySQL 8 (DDL applied directly to dev; prod SQL handed to Daniel), `ClaudeService::chat()`, `SeoMeta`, jQuery + `ApiDataSvc.apiCall('post', action, data, cb)` for AJAX (never edit `public/js/api.data.js`), SweetAlert2 + toastr globally loaded, Font Awesome icons in the app shell, plain PHP CLI test scripts in `tests/`.

**Spec:** `docs/superpowers/specs/2026-09-21-seo-llm-growth-design.md` §3 (shared foundation), §5 (sub-project B), §8 (cross-cutting).

## Global Constraints

- **No git commits, no pushes.** Daniel commits and deploys. Lint every PHP file you touch with `/opt/homebrew/opt/php@8.2/bin/php -l`, every JS file with `node --check`.
- **DB access only through models** (`app/models/*Model.php` extending `Model`, using `parent::select/insert/update/delete`). Never `new Database()` or `prepare()` in a controller or service.
- **PDO named placeholders are single-use per query.** Bind the same value to `:a1` and `:a2` if it appears twice.
- **DDL:** apply to dev with `mysql --protocol=TCP -u casivo -p'<db_pass from app/config/app.ini [development]>' contentos`, and paste the identical SQL into the task report for prod. No migration system exists.
- **Design:** match the existing public pages (`public/css/public.css` + `landing.css` tokens `--ld-ink --ld-t2 --ld-muted --ld-violet --ld-violet7 --ld-hair --ld-line`). One accent (violet). No emoji anywhere. Button labels Title Case ("Publish", "Request Rewrite"); titles and body copy sentence case. No helper sentences under inputs. Admin UI uses the existing `adm-*` classes in `public/css/admin.css`.
- **Copy rules for generated articles:** no invented statistics, no competitor claims, no prices other than the ones in the context, no external links, no emoji, no "As an AI". Nothing publishes without an Admin clicking Publish.
- **Public pages:** every page has `<title>`, meta description, canonical, Open Graph, JSON-LD (via `SeoMeta::head()`), one CTA. Cache header `private, max-age=300` (set by the controller constructor, skipped while `$embedded`).
- **Routing:** `/blog/*` does not fit `/controller/action`; dispatch from `app/Bootstrap.php` like `PagesController::ROUTES`.
- **Auth:** all Admin endpoints check `Permissions::is_admin()`; the API base class already validates CSRF on POST (`X-CSRF-Token` header, meta tag `csrf-token`).
- **Cron:** scripts live in `cron/`, start with the `php_sapi_name() !== 'cli'` guard + `APPLICATION_ENV` default + the `spl_autoload_register` block copied from `cron/scheduler.php`. Dev runs them from `~/Library/LaunchAgents/com.creatorlinkstudio.*.plist`; prod crontab line format: `0 9 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_draft.php >> /var/www/creatorlinkstudio.com/www/cron/seo_draft.log 2>&1`.
- **Tests:** PHP CLI scripts in `tests/`, run as `APPLICATION_ENV=development /opt/homebrew/opt/php@8.2/bin/php -d display_errors=1 tests/<file>.php`; print `ok <label>` / `FAIL <label>` lines and exit 1 on any failure. Dev site is `http://framework.contentos.cvk`.
- Existing helpers you will call: `SeoMeta::base()`, `SeoMeta::site()`, `SeoMeta::org()`, `SeoMeta::breadcrumbs(array)`, `SeoMeta::faq(array)`, `SeoMeta::article(array)`, `SeoMeta::head(array)`, `SeoMeta::internal_links()`; `Main::site_name()`, `Main::get_base_domain()`, `Main::app_path()`, `Main::get_url()` (array of path segments, reads `$_GET['url']`); `Errors::page_not_found()`; `Permissions::is_admin()`; `Session::get('user_id')`; `Notify::many(array $ids, $category, $title, $body, $link, $icon)`; `ClaudeService::chat($system, array $messages, $max_tokens, $timeout, $effort)` → `array(ok, text, error)`; `ClaudeService::model()`; `PagesController::our_facts()`, `PagesController::pricing_rows()`, `PagesController::COMPETITORS`; `SeoController::public_pages()`; `View::public_page($view_file, array $meta, array $vars)`.

---

## File map

| File | Responsibility |
|---|---|
| `app/models/SeoKeywordsModel.php` (new) | keyword queue CRUD, next-queued pick, status transitions |
| `app/models/SeoArticlesModel.php` (new) | article CRUD, published listing/paging, related, view counting |
| `app/models/UsersModel.php` (modify) | `admin_ids()` |
| `libs/Classes/Markdown.php` (new) | allow-list Markdown → HTML renderer, plus `word_count()` |
| `libs/Classes/SeoDrafter.php` (new) | context, prompt, Claude call, validation, save-as-review, notify |
| `cron/seo_draft.php` (new) | daily driver |
| `app/controllers/BlogController.php` (new) | `/blog`, `/blog/<slug>`, `/blog/feed.xml`, admin preview |
| `app/views/pages/blog-index.php`, `app/views/pages/blog-article.php` (new) | public views |
| `libs/Layout/public_page.php` (modify) | "From the guides" block, Guides nav → `/blog`, RSS link |
| `libs/Layout/login_form.php` (modify) | Guides nav → `/blog` |
| `public/css/public.css` (modify) | blog + guides block styles |
| `app/Bootstrap.php` (modify) | `/blog` dispatch |
| `app/controllers/PagesController.php` (modify) | pass newest articles into `page()` |
| `app/controllers/SeoController.php` (modify) | sitemap, llms.txt, llms-full.txt include articles |
| `app/controllers/AdminController.php` (modify) | load Content tab data; `articleAction()` editor page |
| `app/views/admin/index.php` (modify), `app/views/admin/_content.php` (new), `app/views/admin/article.php` (new) | Content tab + editor |
| `public/js/admin-content.js` (new), `public/css/admin.css` (modify) | tab + editor behaviour and styles |
| `app/controllers/api/ApiSeoContentController.php` (new), `libs/Classes/ApiRoutes.php` (modify) | admin endpoints |
| `libs/Classes/SeoMeta.php` (modify), `app/views/profile/view.php` (modify) | profile head onto `SeoMeta::head()` |
| `tests/seo_markdown_test.php`, `tests/seo_drafter_validate_test.php`, `tests/seo_articles_model_test.php`, `tests/seo_blog_routes_test.php` (new); `tests/seo_crawl_files_test.php`, `tests/seo_profile_jsonld_test.php` (modify) | verification |

---

### Task 1: Tables and models

**Files:**
- Create: `app/models/SeoKeywordsModel.php`, `app/models/SeoArticlesModel.php`
- Modify: `app/models/UsersModel.php` (append one method before the final `}`)
- Test: `tests/seo_articles_model_test.php`

**Interfaces:**
- Produces: `SeoKeywordsModel::all(): array`, `next_queued(): ?array`, `get(int $id): ?array`, `add(string $keyword, ?int $volume, string $difficulty, int $priority): int`, `set_status(int $id, string $status, ?int $article_id = null, ?string $error = null): void`, `set_priority(int $id, int $priority): void`, `seed(array $rows): int`.
- Produces: `SeoArticlesModel::get(int $id): ?array`, `get_by_slug(string $slug, bool $published_only = true): ?array`, `slug_exists(string $slug, int $except_id = 0): bool`, `create(array $fields): int`, `update_fields(int $id, array $fields): void`, `published(int $limit, int $offset = 0): array`, `count_published(): int`, `newest_published(int $limit = 3): array`, `by_status(array $statuses): array`, `related(array $article, int $limit = 3): array`, `record_view(int $id, string $viewer_key): void`, `set_status(int $id, string $status, ?int $by = null): void`.
- Produces: `UsersModel::admin_ids(): array` of ints.

- [ ] **Step 1: Apply the DDL to dev**

```sql
CREATE TABLE seo_keywords (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  keyword     VARCHAR(160) NOT NULL,
  volume      INT UNSIGNED NULL,
  difficulty  ENUM('easy','doable','hard') NOT NULL DEFAULT 'doable',
  priority    INT NOT NULL DEFAULT 100,
  status      ENUM('queued','drafting','drafted','published','skipped') NOT NULL DEFAULT 'queued',
  article_id  INT UNSIGNED NULL,
  last_error  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_keyword (keyword),
  KEY idx_status_priority (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seo_articles (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug               VARCHAR(160) NOT NULL,
  title              VARCHAR(200) NOT NULL,
  meta_description   VARCHAR(200) NOT NULL DEFAULT '',
  excerpt            TEXT NULL,
  body_md            MEDIUMTEXT NOT NULL,
  body_html          MEDIUMTEXT NOT NULL,
  target_keyword     VARCHAR(160) NOT NULL DEFAULT '',
  secondary_keywords JSON NULL,
  faq                JSON NULL,
  cover_image_url    VARCHAR(500) NULL,
  reading_minutes    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status             ENUM('draft','review','published','archived') NOT NULL DEFAULT 'review',
  rewrite_note       TEXT NULL,
  model              VARCHAR(80) NULL,
  prompt_version     VARCHAR(20) NULL,
  views              INT UNSIGNED NOT NULL DEFAULT 0,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  published_at       DATETIME NULL,
  published_by       INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_status_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seo_page_views (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id  INT UNSIGNED NOT NULL,
  viewer_key  VARCHAR(64) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_view (article_id, viewer_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Run it against dev (`mysql --protocol=TCP -u casivo -p'…' contentos < ddl.sql`). Copy the SQL verbatim into your report under "Prod SQL".

- [ ] **Step 2: Write the failing model test**

`tests/seo_articles_model_test.php`:

```php
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
```

- [ ] **Step 3: Run it, expect a fatal "Class SeoKeywordsModel not found"**

- [ ] **Step 4: Write `app/models/SeoKeywordsModel.php`**

```php
<?php
/** Keyword queue for the SEO content engine (spec §5). One row per target keyword; status walks queued → drafting → drafted → published. */
class SeoKeywordsModel extends Model {
    public function __construct(){ parent::__construct(); }

    public function all(){
        return (array) parent::select(
            "SELECT k.*, a.slug AS article_slug, a.title AS article_title, a.status AS article_status
             FROM seo_keywords k LEFT JOIN seo_articles a ON a.id = k.article_id
             ORDER BY FIELD(k.status, 'drafting', 'queued', 'drafted', 'published', 'skipped'), k.priority ASC, k.id ASC");
    }

    public function get($id){
        $rows = parent::select("SELECT * FROM seo_keywords WHERE id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Lowest priority number wins; ties by age. */
    public function next_queued(){
        $rows = parent::select("SELECT * FROM seo_keywords WHERE status = 'queued' ORDER BY priority ASC, id ASC LIMIT 1");
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($keyword, $volume, $difficulty, $priority = 100){
        $keyword = trim(mb_substr((string) $keyword, 0, 160));
        if ($keyword === '') { return 0; }
        $existing = parent::select("SELECT id FROM seo_keywords WHERE keyword = :k", array('k' => $keyword));
        if (is_array($existing) && count($existing) === 1) { return (int) $existing[0]['id']; }
        $now = gmdate('Y-m-d H:i:s');
        return (int) parent::insert('seo_keywords', array(
            'keyword' => $keyword, 'volume' => $volume === null || $volume === '' ? null : (int) $volume,
            'difficulty' => in_array($difficulty, array('easy', 'doable', 'hard'), true) ? $difficulty : 'doable',
            'priority' => (int) $priority, 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now,
        ));
    }

    public function set_status($id, $status, $article_id = null, $error = null){
        $data = array('status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s'), 'last_error' => $error === null ? null : mb_substr((string) $error, 0, 255));
        if ($article_id !== null) { $data['article_id'] = (int) $article_id; }
        parent::update('seo_keywords', $data, 'id = :id', array('id' => (int) $id));
    }

    public function set_priority($id, $priority){
        parent::update('seo_keywords', array('priority' => (int) $priority, 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    /** $rows: array of array(keyword, volume|null, difficulty, priority). Returns how many were new. */
    public function seed(array $rows){
        $n = 0;
        foreach ($rows as $r) {
            $before = parent::select("SELECT id FROM seo_keywords WHERE keyword = :k", array('k' => $r[0]));
            if (is_array($before) && count($before)) { continue; }
            if ($this->add($r[0], $r[1], $r[2], $r[3]) > 0) { $n++; }
        }
        return $n;
    }
}
```

- [ ] **Step 5: Write `app/models/SeoArticlesModel.php`**

```php
<?php
/** Blog articles for the SEO content engine (spec §5). status: draft|review|published|archived. */
class SeoArticlesModel extends Model {
    public function __construct(){ parent::__construct(); }

    public function get($id){
        $rows = parent::select("SELECT * FROM seo_articles WHERE id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function get_by_slug($slug, $published_only = true){
        $sql = "SELECT * FROM seo_articles WHERE slug = :s" . ($published_only ? " AND status = 'published'" : '');
        $rows = parent::select($sql, array('s' => (string) $slug));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function slug_exists($slug, $except_id = 0){
        $rows = parent::select("SELECT id FROM seo_articles WHERE slug = :s AND id <> :x", array('s' => (string) $slug, 'x' => (int) $except_id));
        return is_array($rows) && count($rows) > 0;
    }

    public function create(array $f){
        $now = gmdate('Y-m-d H:i:s');
        $f['created_at'] = $now; $f['updated_at'] = $now;
        if (!isset($f['status'])) { $f['status'] = 'review'; }
        return (int) parent::insert('seo_articles', $f);
    }

    public function update_fields($id, array $f){
        $f['updated_at'] = gmdate('Y-m-d H:i:s');
        parent::update('seo_articles', $f, 'id = :id', array('id' => (int) $id));
    }

    /** published → sets published_at (first time) and published_by; anything else leaves the dates alone. */
    public function set_status($id, $status, $by = null){
        $row = $this->get($id);
        $data = array('status' => (string) $status, 'updated_at' => gmdate('Y-m-d H:i:s'));
        if ($status === 'published') {
            if (empty($row['published_at'])) { $data['published_at'] = gmdate('Y-m-d H:i:s'); }
            if ($by !== null) { $data['published_by'] = (int) $by; }
        }
        parent::update('seo_articles', $data, 'id = :id', array('id' => (int) $id));
    }

    public function published($limit = 12, $offset = 0){
        $limit = max(1, min(50, (int) $limit)); $offset = max(0, (int) $offset);
        return (array) parent::select(
            "SELECT id, slug, title, meta_description, excerpt, target_keyword, secondary_keywords, reading_minutes, cover_image_url, published_at, updated_at, views
             FROM seo_articles WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT $offset, $limit");
    }

    public function count_published(){
        $rows = parent::select("SELECT COUNT(*) AS c FROM seo_articles WHERE status = 'published'");
        return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }

    public function newest_published($limit = 3){ return $this->published($limit, 0); }

    public function by_status(array $statuses){
        $in = array(); $params = array(); $i = 0;
        foreach ($statuses as $s) { $i++; $in[] = ':s' . $i; $params['s' . $i] = (string) $s; }
        if (empty($in)) { return array(); }
        return (array) parent::select(
            "SELECT id, slug, title, meta_description, target_keyword, status, reading_minutes, views, model, rewrite_note, created_at, updated_at, published_at
             FROM seo_articles WHERE status IN (" . implode(',', $in) . ") ORDER BY updated_at DESC, id DESC", $params);
    }

    /** Up to $limit other published articles sharing a secondary keyword or the target keyword; newest fill the rest. */
    public function related(array $article, $limit = 3){
        $limit = max(1, min(6, (int) $limit));
        $terms = array_filter(array_map('strtolower', array_merge(array((string) ($article['target_keyword'] ?? '')), (array) json_decode((string) ($article['secondary_keywords'] ?? '[]'), true))));
        $pool = $this->published(60, 0);
        $scored = array();
        foreach ($pool as $p) {
            if ((int) $p['id'] === (int) ($article['id'] ?? 0)) { continue; }
            $pt = array_filter(array_map('strtolower', array_merge(array((string) $p['target_keyword']), (array) json_decode((string) $p['secondary_keywords'], true))));
            $p['_score'] = count(array_intersect($terms, $pt));
            $scored[] = $p;
        }
        usort($scored, function ($x, $y) { return ($y['_score'] <=> $x['_score']) ?: strcmp((string) $y['published_at'], (string) $x['published_at']); });
        return array_slice($scored, 0, $limit);
    }

    /** One view per (article, viewer_key); bumps the cached counter only on a new pair. */
    public function record_view($id, $viewer_key){
        $id = (int) $id; $viewer_key = substr((string) $viewer_key, 0, 64);
        if ($viewer_key === '' || $viewer_key === 'bot') { return; }   // crawlers never count
        $dupe = parent::select("SELECT id FROM seo_page_views WHERE article_id = :a AND viewer_key = :k", array('a' => $id, 'k' => $viewer_key));
        if (is_array($dupe) && count($dupe)) { return; }
        parent::insert('seo_page_views', array('article_id' => $id, 'viewer_key' => $viewer_key, 'created_at' => gmdate('Y-m-d H:i:s')));
        parent::sql("UPDATE seo_articles SET views = views + 1 WHERE id = :id", array('id' => $id));
    }
}
```

- [ ] **Step 6: Add `admin_ids()` to `UsersModel`** — before the class's final `}`:

```php
    /** Every active admin's user id (in-platform notifications for review queues). */
    public function admin_ids(){
        $rows = parent::select("SELECT user_id FROM user_accounts WHERE is_admin = 1 AND deleted = 0");
        $out = array();
        foreach ((array) $rows as $r) { $out[] = (int) $r['user_id']; }
        return $out;
    }
```

- [ ] **Step 7: Run the test — every line `ok`, `ALL OK`.** Lint all three PHP files.

---

### Task 2: Markdown renderer

**Files:**
- Create: `libs/Classes/Markdown.php`
- Test: `tests/seo_markdown_test.php`

**Interfaces:**
- Produces: `Markdown::render(string $md, array $allowed_paths = array()): string` — safe HTML. `Markdown::word_count(string $md): int`. `Markdown::links(string $md): array` — every `](target)` target found. `Markdown::headings(string $md, int $level): int` — count of headings at that level.

Rules: `#`/`##` → `<h2>`, `###` → `<h3>`, deeper → `<h3>`. Paragraphs, `-`/`*`/`1.` lists, `>` blockquote, pipe tables (header row + `---` separator row), `**bold**`, `*italic*`, `` `code` ``, `[text](url)`. A link renders as `<a>` only when its target is relative (`/…`) AND, if `$allowed_paths` is non-empty, its path (before `#`/`?`) is in the list; otherwise the link text is emitted without an anchor. Everything is HTML-escaped first; raw HTML in the source never survives.

- [ ] **Step 1: Write the failing test**

```php
<?php
if (php_sapi_name() !== 'cli') { exit(1); }
$root = dirname(__DIR__);
require_once "$root/libs/Classes/Markdown.php";
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
$md = "# Title\n\nIntro with **bold** and *em* and `code`.\n\n## Section\n\n- one\n- two\n\n1. first\n2. second\n\n> quoted\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\nSee [features](/features) and [evil](https://evil.example) and <script>alert(1)</script>.\n\n### Sub\n\nTail.";
$html = Markdown::render($md, array('/features'));
check('h1 demoted to h2',            strpos($html, '<h2>Title</h2>') !== false && strpos($html, '<h1') === false);
check('h2 kept',                     strpos($html, '<h2>Section</h2>') !== false);
check('h3 kept',                     strpos($html, '<h3>Sub</h3>') !== false);
check('bold/em/code inline',         strpos($html, '<strong>bold</strong>') !== false && strpos($html, '<em>em</em>') !== false && strpos($html, '<code>code</code>') !== false);
check('ul rendered',                 strpos($html, '<ul><li>one</li><li>two</li></ul>') !== false);
check('ol rendered',                 strpos($html, '<ol><li>first</li><li>second</li></ol>') !== false);
check('blockquote rendered',         strpos($html, '<blockquote><p>quoted</p></blockquote>') !== false);
check('table rendered',              strpos($html, '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>') !== false);
check('allowed internal link kept',  strpos($html, '<a href="/features">features</a>') !== false);
check('external link stripped',      strpos($html, 'evil.example') === false && strpos($html, '>evil<') === false && strpos($html, 'evil') !== false);
check('raw html escaped',            strpos($html, '<script') === false && strpos($html, '&lt;script&gt;') !== false);
check('links() lists targets',       Markdown::links($md) === array('/features', 'https://evil.example'));
check('word_count ignores markup',   Markdown::word_count("## Hi there\n\n**bold** word [link](/x)") === 5);
check('headings() counts h2',        Markdown::headings($md, 2) === 1);
check('no allowlist = any relative', strpos(Markdown::render("[a](/anything)"), '<a href="/anything">a</a>') !== false);
check('disallowed relative stripped', strpos(Markdown::render("[a](/nope)", array('/features')), '<a') === false);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it — fatal, class not found.**

- [ ] **Step 3: Write `libs/Classes/Markdown.php`**

```php
<?php
/**
 * Small allow-list Markdown renderer for model-written articles. Everything is escaped first; only the
 * constructs below are turned into tags. Links survive only when relative and (if a list is given) allowed.
 */
class Markdown {
    public static function render($md, array $allowed_paths = array()){
        $lines = preg_split("/\r\n|\r|\n/", (string) $md);
        $out = array(); $i = 0; $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') { $i++; continue; }
            // heading
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]) <= 2 ? 2 : 3;
                $out[] = "<h$level>" . self::inline($m[2], $allowed_paths) . "</h$level>"; $i++; continue;
            }
            // table: header row + separator row
            if (strpos($line, '|') !== false && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$i + 1])) {
                $head = self::cells($line); $i += 2; $rows = array();
                while ($i < $n && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== '') { $rows[] = self::cells($lines[$i]); $i++; }
                $h = '<table><thead><tr>';
                foreach ($head as $c) { $h .= '<th>' . self::inline($c, $allowed_paths) . '</th>'; }
                $h .= '</tr></thead><tbody>';
                foreach ($rows as $r) { $h .= '<tr>'; foreach ($r as $c) { $h .= '<td>' . self::inline($c, $allowed_paths) . '</td>'; } $h .= '</tr>'; }
                $out[] = $h . '</tbody></table>'; continue;
            }
            // unordered list
            if (preg_match('/^\s*[-*]\s+/', $line)) {
                $items = array();
                while ($i < $n && preg_match('/^\s*[-*]\s+(.*)$/', $lines[$i], $m)) { $items[] = '<li>' . self::inline($m[1], $allowed_paths) . '</li>'; $i++; }
                $out[] = '<ul>' . implode('', $items) . '</ul>'; continue;
            }
            // ordered list
            if (preg_match('/^\s*\d+[.)]\s+/', $line)) {
                $items = array();
                while ($i < $n && preg_match('/^\s*\d+[.)]\s+(.*)$/', $lines[$i], $m)) { $items[] = '<li>' . self::inline($m[1], $allowed_paths) . '</li>'; $i++; }
                $out[] = '<ol>' . implode('', $items) . '</ol>'; continue;
            }
            // blockquote
            if (preg_match('/^\s*>\s?/', $line)) {
                $buf = array();
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) { $buf[] = $m[1]; $i++; }
                $out[] = '<blockquote><p>' . self::inline(implode(' ', $buf), $allowed_paths) . '</p></blockquote>'; continue;
            }
            // paragraph: consecutive non-blank, non-block lines
            $buf = array();
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,6}\s|\s*[-*]\s+|\s*\d+[.)]\s+|\s*>)/', $lines[$i])
                   && !(strpos($lines[$i], '|') !== false && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$i + 1]))) {
                $buf[] = trim($lines[$i]); $i++;
            }
            if (!empty($buf)) { $out[] = '<p>' . self::inline(implode(' ', $buf), $allowed_paths) . '</p>'; }
            else { $i++; }
        }
        return implode("\n", $out);
    }

    private static function cells($line){
        $line = trim($line); $line = preg_replace('/^\|/', '', $line); $line = preg_replace('/\|$/', '', $line);
        return array_map('trim', explode('|', $line));
    }

    /** Escape, then apply inline marks. Links: relative + allowed → <a>; otherwise the text alone. */
    private static function inline($text, array $allowed_paths){
        $e = htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $e = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($allowed_paths) {
            $target = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $path = preg_replace('/[#?].*$/', '', $target);
            $ok = (strpos($target, '/') === 0 && strpos($target, '//') !== 0) && (empty($allowed_paths) || in_array($path, $allowed_paths, true));
            return $ok ? '<a href="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">' . $m[1] . '</a>' : $m[1];
        }, $e);
        $e = preg_replace('/`([^`]+)`/', '<code>$1</code>', $e);
        $e = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $e);
        $e = preg_replace('/(?<![*\w])\*([^*\s][^*]*?)\*(?![*\w])/', '<em>$1</em>', $e);
        return $e;
    }

    public static function links($md){
        preg_match_all('/\]\(([^)\s]+)\)/', (string) $md, $m);
        return array_values($m[1]);
    }

    public static function word_count($md){
        $t = preg_replace('/\]\([^)]*\)/', ']', (string) $md);          // drop link targets
        $t = preg_replace('/[#*`>|\-]+/', ' ', $t);                        // drop marks
        $t = preg_replace('/[\[\]]/', '', $t);
        return count(preg_split('/\s+/', trim($t), -1, PREG_SPLIT_NO_EMPTY));
    }

    public static function headings($md, $level){
        return preg_match_all('/^#{' . (int) $level . '}\s+\S/m', (string) $md);
    }
}
```

- [ ] **Step 4: Run the test until every check is `ok`.** If `word_count` differs by one because of the `-` in a hyphenated word, adjust the test string, not the rule (hyphens inside words are fine to split).

---

### Task 3: Drafter (context, prompt, validation, save)

**Files:**
- Create: `libs/Classes/SeoDrafter.php`
- Test: `tests/seo_drafter_validate_test.php`

**Interfaces:**
- Consumes: `SeoKeywordsModel`, `SeoArticlesModel`, `Markdown`, `ClaudeService::chat`, `UsersModel::admin_ids`, `Notify::many`, `PagesController::our_facts/pricing_rows/COMPETITORS`, `SeoController::public_pages`, `SeoMeta::internal_links`.
- Produces: `SeoDrafter::PROMPT_VERSION = 'v1'`; `SeoDrafter::allowed_paths(): array`; `SeoDrafter::validate(array $a, int $except_id = 0): array` (list of error strings, empty = valid); `SeoDrafter::draft(array $keyword_row, string $note = '', int $existing_article_id = 0): array` → `array('ok' => bool, 'article_id' => int, 'error' => string)`; `SeoDrafter::slugify(string): string`; `SeoDrafter::reading_minutes(string $md): int`.

- [ ] **Step 1: Write the failing validation test**

```php
<?php
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
$body = ''; for ($i = 1; $i <= 4; $i++) { $body .= "## Section $i\n\n" . str_repeat('word ', 320) . "See [features](/features).\n\n"; }
$good = array('title' => 'How creators price a membership tier', 'slug' => 'how-creators-price-a-membership-tier',
    'meta_description' => 'A practical guide to pricing membership tiers for creators, with the numbers that matter.',
    'excerpt' => 'Pricing tiers is mostly about the gap between them.', 'body_md' => $body,
    'faq' => array(array('q' => 'One?', 'a' => 'Yes.'), array('q' => 'Two?', 'a' => 'Yes.'), array('q' => 'Three?', 'a' => 'Yes.')),
    'secondary_keywords' => array('membership pricing', 'creator tiers'));
check('good article validates', SeoDrafter::validate($good) === array(), implode('; ', SeoDrafter::validate($good)));
$t = $good; $t['title'] = str_repeat('x', 71);                         check('title > 70 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['meta_description'] = str_repeat('x', 156);              check('meta > 155 rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['slug'] = 'Bad Slug!';                                   check('bad slug rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] = "## A\n\nshort";                            check('short body rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](https://example.com)";          check('external link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\n[x](/nowhere-at-all)";               check('unknown internal link rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nGreat 🚀";                            check('emoji rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] .= "\n\nAs an AI language model I think";     check('"As an AI" rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['faq'] = array(array('q' => 'One?', 'a' => 'Yes.'));    check('fewer than 3 faq rejected', SeoDrafter::validate($t) !== array());
$t = $good; $t['body_md'] = "## Only\n\n" . str_repeat('word ', 1300); check('fewer than 3 h2 rejected', SeoDrafter::validate($t) !== array());
check('slugify', SeoDrafter::slugify(" Hello, World! It's 2026 ") === 'hello-world-its-2026');
check('reading_minutes >= 1', SeoDrafter::reading_minutes('one two') === 1 && SeoDrafter::reading_minutes(str_repeat('w ', 900)) === 4);
check('allowed_paths has /features and /pricing', in_array('/features', SeoDrafter::allowed_paths(), true) && in_array('/pricing', SeoDrafter::allowed_paths(), true));
$parsed = SeoDrafter::parse_json("```json\n{\"title\":\"t\"}\n```");   check('parse_json strips fences', is_array($parsed) && $parsed['title'] === 't');
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it — fatal, class not found.**

- [ ] **Step 3: Write `libs/Classes/SeoDrafter.php`**

```php
<?php
/**
 * Drafts one blog article for a keyword with Claude, validates it against hard rules, and saves it for
 * Admin review (spec §5). Never publishes. One Claude call per draft, one retry on validation failure.
 */
class SeoDrafter {
    const PROMPT_VERSION = 'v1';
    const MIN_WORDS = 1000;
    const MAX_WORDS = 2000;

    /** Relative paths an article may link to: product pages + published articles. */
    public static function allowed_paths(): array {
        $paths = array('/', '/blog');
        foreach (SeoController::public_pages() as $p) { $paths[] = $p['path']; }
        foreach (SeoMeta::internal_links() as $k => $v) { $paths[] = is_array($v) ? (string) ($v['path'] ?? '') : (string) $v; }
        try { foreach ((new SeoArticlesModel())->published(200, 0) as $a) { $paths[] = '/blog/' . $a['slug']; } } catch (\Throwable $e) {}
        return array_values(array_unique(array_filter($paths)));
    }

    public static function slugify($s): string {
        $s = strtolower(trim((string) $s));
        $s = preg_replace("/['\x{2019}]/u", '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim(mb_substr($s, 0, 120), '-');
    }

    public static function reading_minutes($md): int {
        return max(1, (int) ceil(Markdown::word_count($md) / 230));
    }

    /** Accepts a raw model reply; strips ``` fences and returns the decoded object or null. */
    public static function parse_json($text){
        $t = trim((string) $text);
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);
        $d = json_decode($t, true);
        if (!is_array($d)) { $s = strpos($t, '{'); $e = strrpos($t, '}'); if ($s !== false && $e !== false && $e > $s) { $d = json_decode(substr($t, $s, $e - $s + 1), true); } }
        return is_array($d) ? $d : null;
    }

    /** Hard rules. Returns error strings; empty array = valid. */
    public static function validate(array $a, int $except_id = 0): array {
        $err = array();
        $title = trim((string) ($a['title'] ?? '')); $slug = (string) ($a['slug'] ?? ''); $meta = trim((string) ($a['meta_description'] ?? ''));
        $body = (string) ($a['body_md'] ?? ''); $faq = (array) ($a['faq'] ?? array());
        if ($title === '' || mb_strlen($title) > 70) { $err[] = 'title must be 1-70 characters'; }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) < 3 || strlen($slug) > 120) { $err[] = 'slug must be lowercase a-z 0-9 and hyphens, 3-120 chars'; }
        if ($meta === '' || mb_strlen($meta) > 155) { $err[] = 'meta_description must be 1-155 characters'; }
        $words = Markdown::word_count($body);
        if ($words < self::MIN_WORDS || $words > self::MAX_WORDS) { $err[] = "body must be " . self::MIN_WORDS . '-' . self::MAX_WORDS . " words (got $words)"; }
        if (Markdown::headings($body, 2) < 3) { $err[] = 'body needs at least 3 "## " sections'; }
        $allowed = self::allowed_paths();
        foreach (Markdown::links($body) as $l) {
            $path = preg_replace('/[#?].*$/', '', $l);
            if (strpos($l, '/') !== 0 || strpos($l, '//') === 0) { $err[] = "external link not allowed: $l"; }
            elseif (!in_array($path, $allowed, true)) { $err[] = "unknown internal link: $l"; }
        }
        $all = $title . ' ' . $meta . ' ' . $body . ' ' . json_encode($faq, JSON_UNESCAPED_UNICODE);
        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F000}-\x{1F2FF}]/u', $all)) { $err[] = 'emoji not allowed'; }
        if (preg_match('/\bas an ai\b/i', $all)) { $err[] = 'model self-reference ("As an AI") not allowed'; }
        if (preg_match('/<\/?[a-z][^>]*>/i', $body)) { $err[] = 'raw HTML not allowed in body'; }
        $n = 0; foreach ($faq as $f) { if (is_array($f) && trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== '') { $n++; } }
        if ($n < 3 || $n > 5) { $err[] = 'faq needs 3-5 question/answer pairs'; }
        try { if ($slug !== '' && (new SeoArticlesModel())->slug_exists($slug, $except_id)) { $err[] = 'slug already exists'; } } catch (\Throwable $e) {}
        return $err;
    }

    /** Facts Claude may use; nothing else is allowed to appear as a number or a claim. */
    private static function context(): string {
        $site = Main::site_name(); $base = SeoMeta::base();
        $lines = array("Product: $site ($base). A creator platform: one public page at $base/@handle with posts, membership tiers, pay-per-view posts, content bundles, services, events and tracked links; a studio that publishes to nine social networks with AI captions; an inbox with AI replies; Stripe Connect payouts to the creator's bank; fans pay memberships by card and everything else with a credit wallet (\$1 = 10 credits).");
        foreach (PagesController::our_facts() as $k => $v) { $lines[] = ucfirst($k) . ': ' . $v; }
        foreach (PagesController::pricing_rows() as $r) { if (is_array($r)) { $lines[] = 'Plan: ' . implode(' | ', array_map(function ($x) { return is_scalar($x) ? (string) $x : json_encode($x); }, $r)); } }
        $lines[] = 'Pages you may link to (relative paths only):';
        foreach (SeoController::public_pages() as $p) { $lines[] = '- ' . $p['path'] . ' — ' . $p['title'] . ': ' . $p['description']; }
        try {
            $recent = (new SeoArticlesModel())->published(8, 0);
            if (!empty($recent)) { $lines[] = 'Published articles you may link to (and must not repeat):'; foreach ($recent as $a) { $lines[] = '- /blog/' . $a['slug'] . ' — ' . $a['title'] . ': ' . (string) $a['excerpt']; } }
        } catch (\Throwable $e) {}
        $lines[] = 'Competitors you may name only as "other subscription platforms" — no fees, no claims: ' . implode(', ', array_column(PagesController::COMPETITORS, 'name')) . '.';
        return implode("\n", $lines);
    }

    private static function system_prompt(): string {
        $site = Main::site_name();
        return "You write practical, plain-English guides for the $site blog, read by independent creators who sell content, memberships and services online. Voice: direct, specific, second person, no hype, no filler, no emoji, sentence-case headings. Never invent statistics, studies, quotes, prices, fees or competitor facts; use only the facts in the context. Never mention being an AI. Mention $site naturally at most three times, only where it genuinely helps, and link to its pages using the relative paths given. No external links. Output ONLY a JSON object with keys: title (<=70 chars, sentence case), slug (lowercase-hyphenated, <=80 chars), meta_description (<=155 chars), excerpt (one or two sentences), body_md (Markdown, 1200-1800 words, at least four \"## \" sections, some \"### \" subsections, one relative link per section from the allowed list, no H1, no raw HTML), faq (array of 3-5 {\"q\",\"a\"} objects answering real search questions), secondary_keywords (array of 3-6 short phrases).";
    }

    /**
     * Draft for one keyword row. $note = Admin's rewrite instruction (appended to the request);
     * $existing_article_id = replace that article's content instead of creating a new row.
     */
    public static function draft(array $kw, string $note = '', int $existing_article_id = 0): array {
        $keywords = new SeoKeywordsModel(); $articles = new SeoArticlesModel();
        $kid = (int) $kw['id']; $keyword = (string) $kw['keyword'];
        $keywords->set_status($kid, 'drafting');
        $user = "Target keyword: \"$keyword\"" . (!empty($kw['volume']) ? " (about {$kw['volume']} searches/month)" : '') . ".\n\nContext (the only facts you may use):\n" . self::context();
        if ($note !== '') { $user .= "\n\nEditor's note for this rewrite: $note"; }
        $errors = array(); $data = null; $model = ClaudeService::model();
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $msg = $user . ($attempt === 2 && !empty($errors) ? "\n\nYour previous draft failed these checks; fix every one and return the full JSON again:\n- " . implode("\n- ", $errors) : '');
            $r = ClaudeService::chat(self::system_prompt(), array(array('role' => 'user', 'content' => $msg)), 8000, 240, 'medium');
            if (empty($r['ok'])) { $errors = array('Claude: ' . (string) ($r['error'] ?? 'request failed')); continue; }
            $data = self::parse_json($r['text']);
            if ($data === null) { $errors = array('reply was not valid JSON'); continue; }
            $data['slug'] = self::slugify((string) ($data['slug'] ?? $data['title'] ?? $keyword));
            if ($existing_article_id === 0) { $base = $data['slug']; $i = 2; while ((new SeoArticlesModel())->slug_exists($data['slug'])) { $data['slug'] = $base . '-' . $i++; } }
            $errors = self::validate($data, $existing_article_id);
            if (empty($errors)) { break; }
        }
        if (!empty($errors) || $data === null) {
            $keywords->set_status($kid, 'queued', null, implode('; ', $errors));
            return array('ok' => false, 'article_id' => 0, 'error' => implode('; ', $errors));
        }
        $fields = array(
            'slug' => $data['slug'], 'title' => trim((string) $data['title']), 'meta_description' => trim((string) $data['meta_description']),
            'excerpt' => trim((string) ($data['excerpt'] ?? '')), 'body_md' => (string) $data['body_md'],
            'body_html' => Markdown::render((string) $data['body_md'], self::allowed_paths()),
            'target_keyword' => $keyword, 'secondary_keywords' => json_encode(array_values((array) ($data['secondary_keywords'] ?? array())), JSON_UNESCAPED_UNICODE),
            'faq' => json_encode(array_values((array) $data['faq']), JSON_UNESCAPED_UNICODE),
            'reading_minutes' => self::reading_minutes((string) $data['body_md']), 'status' => 'review',
            'rewrite_note' => null, 'model' => $model, 'prompt_version' => self::PROMPT_VERSION,
        );
        if ($existing_article_id > 0) { $articles->update_fields($existing_article_id, $fields); $aid = $existing_article_id; }
        else { $aid = $articles->create($fields); }
        $keywords->set_status($kid, 'drafted', $aid, null);
        try {
            Notify::many((new UsersModel())->admin_ids(), 'system', 'New article ready for review', '"' . $fields['title'] . '" was drafted for "' . $keyword . '".', '/admin/article/' . $aid, 'fa-newspaper');
        } catch (\Throwable $e) { error_log('[seo] notify admins: ' . $e->getMessage()); }
        return array('ok' => true, 'article_id' => $aid, 'error' => '');
    }
}
```

Note: check `SeoMeta::internal_links()`'s return shape before relying on it (`sed -n 28,45p libs/Classes/SeoMeta.php`); the `is_array($v) ? … : …` line handles both a `keyword => path` map and a `keyword => array(path…)` map. If `Notify::many` rejects category `'system'`, use `'creator_activity'` (check `Notify::send` for the accepted list) and say so in the report.

- [ ] **Step 4: Run the validation test until `ALL OK`.** Do NOT call Claude in this test.

- [ ] **Step 5: Smoke the real drafter once from the CLI** (costs one Claude call, ~60-120 s):

```bash
cd /private/var/www/contentos.cvk/framework && APPLICATION_ENV=development /opt/homebrew/opt/php@8.2/bin/php -d display_errors=1 -r '
$root=getcwd(); spl_autoload_register(function($c) use ($root){ foreach(["$root/app/models/$c.php","$root/libs/Classes/$c.php","$root/app/controllers/$c.php","$root/app/$c.php"] as $s){ if(file_exists($s)){ require_once $s; return; } } });
$k=new SeoKeywordsModel(); $id=$k->add("how to price a subscription tier", 200, "doable", 1);
var_export(SeoDrafter::draft($k->get($id)));'
```

Expected: `'ok' => true` and a row in `seo_articles` with `status = review`. Record the article id and word count in the report. If it fails validation twice, paste the errors into the report and tighten the prompt wording (not the rules) until a draft passes.

---

### Task 4: Daily cron + keyword seed

**Files:**
- Create: `cron/seo_draft.php`, `~/Library/LaunchAgents/com.creatorlinkstudio.seo-draft.plist`

- [ ] **Step 1: Write `cron/seo_draft.php`**

```php
<?php
/**
 * SEO content engine — draft ONE article per run from the top queued keyword (spec §5).
 * Prod: 0 9 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_draft.php >> /var/www/creatorlinkstudio.com/www/cron/seo_draft.log 2>&1
 * Options: --keyword-id=N (draft that keyword regardless of status), --seed (insert the starter keyword list only).
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$opts = getopt('', array('keyword-id::', 'seed'));
$lock = fopen(sys_get_temp_dir() . '/cls-seo-draft.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('c'), " another run is active\n"; exit(0); }

$keywords = new SeoKeywordsModel();
$seed = array(
    array('how to monetize content as a creator', 1900, 'doable', 10), array('creator monetization platform', 880, 'hard', 20),
    array('onlyfans alternative', 6600, 'hard', 15), array('fanvue alternative', 720, 'doable', 15),
    array('how to sell pay-per-view content', 320, 'easy', 30), array('creator membership tiers', 260, 'easy', 30),
    array('link in bio for creators', 1300, 'doable', 40), array('best link in bio for creators', 590, 'doable', 45),
    array('ai influencer content', 480, 'doable', 50), array('creator payouts stripe', 140, 'easy', 60),
    array('how to price a subscription tier', 210, 'easy', 25), array('cross-post to social media from one place', 390, 'easy', 35),
    array('monetize digital content', 700, 'doable', 20), array('monetize online content', 590, 'doable', 22), array('online creator platform', 1000, 'hard', 24),
);
$added = $keywords->seed($seed);
if ($added > 0) { echo date('c'), " seeded $added keyword(s)\n"; }
if (isset($opts['seed'])) { exit(0); }

$kw = null;
if (!empty($opts['keyword-id'])) { $kw = $keywords->get((int) $opts['keyword-id']); }
else { $kw = $keywords->next_queued(); }
if (!$kw) { echo date('c'), " nothing queued\n"; exit(0); }
if (!ClaudeService::configured()) { echo date('c'), " Claude not configured\n"; exit(1); }

echo date('c'), " drafting \"{$kw['keyword']}\" (#{$kw['id']})\n";
$t0 = microtime(true);
$r  = SeoDrafter::draft($kw);
printf("%s %s in %.1fs%s\n", date('c'), $r['ok'] ? "drafted article #{$r['article_id']}" : 'FAILED', microtime(true) - $t0, $r['ok'] ? '' : ' — ' . $r['error']);
exit($r['ok'] ? 0 : 1);
```

- [ ] **Step 2: Run `--seed` in dev** and confirm `SELECT COUNT(*) FROM seo_keywords` ≥ 15. Run once without flags only if Task 3 Step 5 did not already leave a review article (one Claude call per run).

- [ ] **Step 3: Dev launchd agent** `~/Library/LaunchAgents/com.creatorlinkstudio.seo-draft.plist` — copy `com.creatorlinkstudio.social-metrics.plist`, change Label to `com.creatorlinkstudio.seo-draft`, program path to `cron/seo_draft.php`, replace `StartInterval` with `<key>StartCalendarInterval</key><dict><key>Hour</key><integer>9</integer><key>Minute</key><integer>0</integer></dict>`, logs to `/tmp/cls-seo-draft.log`. `launchctl load ~/Library/LaunchAgents/com.creatorlinkstudio.seo-draft.plist`. Put the prod crontab line (from the file header) in the report.

---

### Task 5: Public blog (controller, views, routing, styles)

**Files:**
- Create: `app/controllers/BlogController.php`, `app/views/pages/blog-index.php`, `app/views/pages/blog-article.php`
- Modify: `app/Bootstrap.php` (after the `PagesController::ROUTES` block), `public/css/public.css` (append), `libs/Layout/public_page.php` (nav Guides → `/blog`, RSS `<link>`), `libs/Layout/login_form.php` (nav Guides → `/blog`)
- Test: `tests/seo_blog_routes_test.php`

**Interfaces:**
- Consumes: `SeoArticlesModel`, `View::public_page`, `SeoMeta::*`, `Markdown` (already rendered into `body_html`).
- Produces: `BlogController::dispatch(array $url)`, `indexAction()`, `viewAction(string $slug)`, `feedAction()`, `public static $embedded` (same contract as `PagesController::$embedded`), `public static function render_article_html(array $article): string` is NOT needed — Task 7 uses `body_html` directly.
- Preview: `/blog/<slug>?preview=1` shows a non-published article to admins only (`Permissions::is_admin()`), with `noindex` and no view counting; everyone else gets 404.

- [ ] **Step 1: Write the failing route test**

```php
<?php
// Requires at least one published article in dev (Task 6 publishes one; until then run after manually setting status='published' on the smoke article).
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
function fetch($url){ $ch = curl_init($url); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20)); $r = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hl = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch); return array($code, substr($r, 0, $hl), substr($r, $hl)); }
list($c, $h, $b) = fetch($base . '/blog');
check('/blog 200', $c === 200);
check('/blog has title + canonical', preg_match('/<title>[^<]{5,70}<\/title>/', $b) && strpos($b, 'rel="canonical" href="' . $base . '/blog"') !== false);
check('/blog has CollectionPage JSON-LD', strpos($b, '"CollectionPage"') !== false);
check('/blog links product guides', strpos($b, 'href="/monetize-your-content"') !== false);
preg_match('/href="\/blog\/([a-z0-9-]+)"/', $b, $m); $slug = $m[1] ?? '';
check('/blog lists at least one article', $slug !== '');
list($c, $h, $b) = fetch($base . '/blog/' . $slug);
check('article 200', $c === 200);
check('article has Article + FAQPage + BreadcrumbList JSON-LD', strpos($b, '"Article"') !== false && strpos($b, '"BreadcrumbList"') !== false);
check('article has published_time', strpos($b, 'article:published_time') !== false);
check('article has one CTA', substr_count($b, 'class="pub-cta"') === 1);
check('article Cache-Control private', stripos($h, 'Cache-Control: private') !== false);
list($c) = fetch($base . '/blog/no-such-article-xyz');           check('unknown slug 404', $c === 404);
list($c) = fetch($base . '/blog/' . $slug . '/extra');            check('trailing segment 404', $c === 404);
list($c) = fetch($base . '/blog/' . strtoupper($slug));           check('uppercase slug 404', $c === 404);
list($c, $h, $b) = fetch($base . '/blog/feed.xml');
check('feed 200 xml', $c === 200 && stripos($h, 'Content-Type: application/rss+xml') !== false && strpos($b, '<rss') !== false && strpos($b, '<item>') !== false);
list($c, $h, $b) = fetch($base . '/blog?page=999');               check('empty page still 200 with noindex', $c === 200 && strpos($b, 'noindex') !== false);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: Run — `/blog` 404s (nothing routed).**

- [ ] **Step 3: Write `app/controllers/BlogController.php`**

```php
<?php
/**
 * Public blog for the SEO content engine: /blog (paged index), /blog/<slug>, /blog/feed.xml.
 * Dispatched from Bootstrap (URLs don't fit /controller/action). Rendered with View::public_page().
 */
class BlogController extends Controller {
    public $protected = 0;
    public static $embedded = false;
    const PER_PAGE = 12;

    public function __construct(){
        parent::__construct();
        if (!self::$embedded) { header('Cache-Control: private, max-age=300'); }
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
        $articles = new SeoArticlesModel();
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $total = $articles->count_published();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $rows  = $articles->published(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $path  = '/blog' . ($page > 1 ? '?page=' . $page : '');
        $title = 'Guides for creators' . ($page > 1 ? ' · page ' . $page : '');
        $desc  = 'Practical guides on monetizing content: memberships, pay-per-view, bundles, services, events, link in bio and payouts.';
        $items = array(); $pos = 1;
        foreach ($rows as $a) { $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'url' => SeoMeta::base() . '/blog/' . $a['slug'], 'name' => $a['title']); }
        $jsonld = array(
            SeoMeta::org(),
            array('@type' => 'CollectionPage', 'name' => $title, 'url' => SeoMeta::base() . $path, 'description' => $desc, 'isPartOf' => array('@type' => 'WebSite', 'name' => SeoMeta::site(), 'url' => SeoMeta::base() . '/'),
                  'mainEntity' => array('@type' => 'ItemList', 'itemListElement' => $items)),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Guides', 'url' => '/blog'))),
        );
        $guides = array();
        foreach (SeoController::public_pages() as $p) { if (in_array($p['path'], array('/monetize-your-content', '/best-creator-monetization-platforms'), true) || strpos($p['path'], '/compare/') === 0) { $guides[] = $p; } }
        $this->page('blog-index', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'website', 'jsonld' => $jsonld, 'noindex' => ($page > 1 && empty($rows)), 'no_guides' => true),
            array('articles' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total, 'guides' => $guides));
    }

    public function viewAction($slug){
        $articles = new SeoArticlesModel();
        $preview  = isset($_GET['preview']) && Permissions::is_admin();
        $a = $articles->get_by_slug($slug, !$preview);
        if (!$a || (!$preview && $a['status'] !== 'published')) { Errors::page_not_found(); return; }
        if (!$preview && !self::$embedded) { $articles->record_view((int) $a['id'], self::viewer_key()); }
        $faq  = (array) json_decode((string) ($a['faq'] ?? '[]'), true);
        $path = '/blog/' . $a['slug'];
        $published = !empty($a['published_at']) ? gmdate('c', strtotime($a['published_at'] . ' UTC')) : gmdate('c', strtotime($a['created_at'] . ' UTC'));
        $modified  = !empty($a['updated_at'])   ? gmdate('c', strtotime($a['updated_at'] . ' UTC'))   : $published;
        $jsonld = array(
            SeoMeta::article(array('headline' => $a['title'], 'description' => $a['meta_description'], 'url' => SeoMeta::base() . $path, 'published' => $published, 'modified' => $modified, 'image' => $a['cover_image_url'] ?: null)),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Guides', 'url' => '/blog'), array('name' => $a['title'], 'url' => $path))),
        );
        if (!empty($faq)) { $jsonld[] = SeoMeta::faq($faq); }
        $this->page('blog-article', array('path' => $path, 'title' => $a['title'], 'description' => $a['meta_description'], 'type' => 'article', 'published' => $published, 'modified' => $modified,
                'image' => $a['cover_image_url'] ?: null, 'jsonld' => $jsonld, 'noindex' => $preview, 'no_guides' => true),
            array('a' => $a, 'faq' => $faq, 'related' => $articles->related($a, 3), 'preview' => $preview));
    }

    public function feedAction(){
        $base = SeoMeta::base(); $site = SeoMeta::site();
        $rows = (new SeoArticlesModel())->published(20, 0);
        header('Content-Type: application/rss+xml; charset=utf-8');
        $x = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>', "\n";
        echo '<title>', $x($site . ' guides'), '</title><link>', $x($base . '/blog'), '</link><description>', $x('Guides for creators from ' . $site), '</description>';
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
```

`record_view` with viewer_key `'bot'`: make `record_view` return early when `$viewer_key === 'bot'` (add that one line to `SeoArticlesModel::record_view`).

- [ ] **Step 4: Route it in `app/Bootstrap.php`** — directly after the `PagesController::ROUTES` block:

```php
        // Public blog (SEO content engine): /blog, /blog/<slug>, /blog/feed.xml.
        if (isset($url[0]) && $url[0] === 'blog') {
            (new BlogController())->dispatch($url);
            return;
        }
```

- [ ] **Step 5: Views.** `app/views/pages/blog-index.php`:

```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<p class="pub-eyebrow">Guides</p>
<h1 class="pub-h1">Guides for creators</h1>
<p class="pub-lead">Practical, specific writing on selling memberships, pay-per-view, bundles, services and events, and on running it all from one page.</p>

<?php if (empty($articles)): ?>
<p class="pub-p">New guides are on the way. Start with the product guides below.</p>
<?php else: ?>
<div class="pub-cards">
    <?php foreach ($articles as $a): ?>
    <a class="pub-card" href="/blog/<?php echo $e($a['slug']); ?>">
        <span class="pub-card__meta"><?php echo $e(date('M j, Y', strtotime($a['published_at'] . ' UTC'))); ?> · <?php echo (int) $a['reading_minutes']; ?> min read</span>
        <span class="pub-card__title"><?php echo $e($a['title']); ?></span>
        <span class="pub-card__x"><?php echo $e($a['excerpt'] ?: $a['meta_description']); ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="pub-pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="ld-btn ld-btn--quiet" href="/blog<?php echo $page > 2 ? '?page=' . ($page - 1) : ''; ?>">Newer</a><?php endif; ?>
    <span class="pub-pager__n">Page <?php echo (int) $page; ?> of <?php echo (int) $pages; ?></span>
    <?php if ($page < $pages): ?><a class="ld-btn ld-btn--quiet" href="/blog?page=<?php echo $page + 1; ?>">Older</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<h2 class="pub-h2">Product guides</h2>
<ul class="pub-list">
    <?php foreach ($guides as $g): ?><li><a href="<?php echo $e($g['path']); ?>"><?php echo $e($g['title']); ?></a> — <?php echo $e($g['description']); ?></li><?php endforeach; ?>
</ul>

<section class="pub-cta">
    <div class="pub-cta__text"><strong>Sell from one page.</strong> Memberships, pay-per-view, services and events, with payouts to your bank.</div>
    <a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a>
</section>
```

`app/views/pages/blog-article.php`:

```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<?php if (!empty($preview)): ?><p class="pub-note pub-note--preview">Preview — this article is not published.</p><?php endif; ?>
<article class="pub-post">
    <p class="pub-eyebrow"><a href="/blog">Guides</a></p>
    <h1 class="pub-h1"><?php echo $e($a['title']); ?></h1>
    <p class="pub-post__meta"><?php echo $e(SeoMeta::site()); ?> team · <?php echo $e(date('M j, Y', strtotime(($a['published_at'] ?: $a['created_at']) . ' UTC'))); ?> · <?php echo (int) $a['reading_minutes']; ?> min read</p>
    <?php if (trim((string) $a['excerpt']) !== ''): ?><p class="pub-lead"><?php echo $e($a['excerpt']); ?></p><?php endif; ?>
    <div class="pub-body"><?php echo $a['body_html']; ?></div>
    <?php if (!empty($faq)): ?>
    <h2 class="pub-h2">Questions</h2>
    <div class="pub-faq">
        <?php foreach ($faq as $f): ?><details><summary><?php echo $e($f['q']); ?></summary><p><?php echo $e($f['a']); ?></p></details><?php endforeach; ?>
    </div>
    <?php endif; ?>
</article>
<?php if (!empty($related)): ?>
<h2 class="pub-h2">Keep reading</h2>
<div class="pub-cards pub-cards--3">
    <?php foreach ($related as $r): ?>
    <a class="pub-card" href="/blog/<?php echo $e($r['slug']); ?>"><span class="pub-card__title"><?php echo $e($r['title']); ?></span><span class="pub-card__x"><?php echo $e($r['excerpt'] ?: $r['meta_description']); ?></span></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<section class="pub-cta">
    <div class="pub-cta__text"><strong>Put it into practice.</strong> One page for memberships, pay-per-view, services and events, with payouts to your bank.</div>
    <a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a>
</section>
```

Check how `.pub-faq` is marked up on `/monetize-your-content` (`app/views/pages/monetize.php`) and match it exactly; if it uses different tags, mirror that file instead of the `<details>` snippet above.

- [ ] **Step 6: Styles** — append to `public/css/public.css`:

```css
/* Blog */
.pub-cards{ display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:20px; margin:8px 0 40px; }
.pub-cards--3{ grid-template-columns:repeat(3, minmax(0,1fr)); }
.pub-card{ display:flex; flex-direction:column; gap:8px; padding:22px; border:1px solid var(--ld-line); border-radius:12px; text-decoration:none; color:var(--ld-ink); background:#fff; transition:border-color .15s, transform .15s; }
.pub-card:hover{ border-color:var(--ld-violet); transform:translateY(-1px); }
.pub-card__meta{ font-size:.82rem; color:var(--ld-muted); }
.pub-card__title{ font-size:1.12rem; font-weight:700; letter-spacing:-.01em; line-height:1.3; }
.pub-card__x{ font-size:.95rem; color:var(--ld-t2); line-height:1.5; }
.pub-pager{ display:flex; align-items:center; gap:16px; margin:0 0 40px; }
.pub-pager__n{ color:var(--ld-muted); font-size:.9rem; margin-left:auto; }
.pub-post__meta{ color:var(--ld-muted); font-size:.92rem; margin:0 0 24px; }
.pub-body{ font-size:1.08rem; line-height:1.7; color:var(--ld-ink); }
.pub-body h2{ font-size:1.55rem; letter-spacing:-.02em; margin:48px 0 12px; }
.pub-body h3{ font-size:1.15rem; margin:28px 0 8px; }
.pub-body p, .pub-body ul, .pub-body ol{ margin:0 0 18px; }
.pub-body li{ margin:6px 0; }
.pub-body a{ color:var(--ld-violet7); }
.pub-body blockquote{ margin:24px 0; padding:4px 0 4px 20px; border-left:3px solid var(--ld-violet); color:var(--ld-t2); }
.pub-body table{ width:100%; border-collapse:collapse; margin:0 0 24px; font-size:.95rem; }
.pub-body th, .pub-body td{ text-align:left; padding:10px 12px; border-bottom:1px solid var(--ld-line); vertical-align:top; }
.pub-body th{ font-weight:700; }
.pub-body code{ font-size:.92em; background:#f2f2f6; padding:1px 5px; border-radius:4px; }
.pub-note--preview{ background:#fff4d6; border:1px solid #f0d58a; padding:10px 14px; border-radius:8px; }
.pub-guides{ margin:56px 0 0; padding-top:32px; border-top:1px solid var(--ld-line); }
.pub-guides__h{ font-size:.8rem; letter-spacing:.18em; text-transform:uppercase; color:#5b5b60; margin:0 0 14px; }
@media (max-width:640px){ .pub-cards, .pub-cards--3{ grid-template-columns:1fr; } }
```

- [ ] **Step 7: Nav + RSS.** In `libs/Layout/public_page.php` and `libs/Layout/login_form.php`, change `<a href="/monetize-your-content">Guides</a>` to `<a href="/blog">Guides</a>`. In `public_page.php` `<head>`, after the `SeoMeta::head` line, add `<link rel="alternate" type="application/rss+xml" title="<?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> guides" href="/blog/feed.xml">`. Grep `tests/` for `/monetize-your-content">Guides` and update any assertion.

- [ ] **Step 8: Publish the smoke article in dev for the test** (`UPDATE seo_articles SET status='published', published_at=UTC_TIMESTAMP() WHERE id=<smoke id>` — Task 6 gives Admin the button; this is only to unblock the route test) and run `tests/seo_blog_routes_test.php` until `ALL OK`. Screenshot `/blog` and the article at 1280 and 390 px with Playwright (`scratchpad/pw` has it installed) and critique against `/monetize-your-content`: same type scale, one accent, one CTA.

---

### Task 6: Admin Content tab, article editor, API

**Files:**
- Create: `app/views/admin/_content.php`, `app/views/admin/article.php`, `public/js/admin-content.js`, `app/controllers/api/ApiSeoContentController.php`
- Modify: `app/controllers/AdminController.php`, `app/views/admin/index.php`, `public/css/admin.css`, `libs/Classes/ApiRoutes.php`

**Interfaces:**
- Consumes: `SeoKeywordsModel`, `SeoArticlesModel`, `SeoDrafter::draft/validate/slugify/reading_minutes/allowed_paths`, `Markdown::render`, `Permissions::is_admin`.
- Produces API actions (all POST, admin-only, JSON via `jsonSuccess/jsonError`): `seo_keyword_add {keyword, volume, difficulty, priority}`, `seo_keyword_update {id, priority?, status? in queued|skipped}`, `seo_draft_now {keyword_id}` (synchronous; may take 2 min — set `set_time_limit(300)`), `seo_article_save {id, title, slug, meta_description, excerpt, body_md, faq (JSON string), secondary_keywords (comma list)}` → validates, re-renders `body_html`, returns `{errors:[]}` on soft failure (still saves the draft text; publish is what enforces), `seo_article_publish {id}` (validate must pass; sets published; keyword → published), `seo_article_unpublish {id}` (→ review), `seo_article_rewrite {id, note}` (runs `SeoDrafter::draft($kw, $note, $id)`), `seo_article_discard {id}` (→ archived; keyword → queued).

- [ ] **Step 1: API controller** `app/controllers/api/ApiSeoContentController.php`:

```php
<?php
/** Admin endpoints for the SEO content engine (keyword queue + article review). Routed by ApiRoutes; extends BaseApiController. */
class ApiSeoContentController extends BaseApiController {
    private function guard(){ if (!Permissions::is_admin()) { $this->jsonError('Admins only'); } }

    public function seo_keyword_addAction(){
        $this->guard();
        $id = (new SeoKeywordsModel())->add((string) ($this->post['keyword'] ?? ''), ($this->post['volume'] ?? '') === '' ? null : (int) $this->post['volume'], (string) ($this->post['difficulty'] ?? 'doable'), (int) ($this->post['priority'] ?? 100));
        if ($id <= 0) { $this->jsonError('Enter a keyword'); }
        $this->jsonSuccess(['id' => $id, 'keyword' => (new SeoKeywordsModel())->get($id)]);
    }

    public function seo_keyword_updateAction(){
        $this->guard();
        $m = new SeoKeywordsModel(); $id = (int) ($this->post['id'] ?? 0); $k = $m->get($id);
        if (!$k) { $this->jsonError('Keyword not found'); }
        if (isset($this->post['priority'])) { $m->set_priority($id, (int) $this->post['priority']); }
        if (isset($this->post['status']) && in_array($this->post['status'], ['queued', 'skipped'], true)) { $m->set_status($id, (string) $this->post['status']); }
        $this->jsonSuccess(['keyword' => $m->get($id)]);
    }

    public function seo_draft_nowAction(){
        $this->guard();
        set_time_limit(300);
        $m = new SeoKeywordsModel(); $k = $m->get((int) ($this->post['keyword_id'] ?? 0));
        if (!$k) { $this->jsonError('Keyword not found'); }
        if ($k['status'] === 'drafting') { $this->jsonError('A draft is already running for this keyword'); }
        $r = SeoDrafter::draft($k);
        if (empty($r['ok'])) { $this->jsonError('Draft failed: ' . $r['error']); }
        $this->jsonSuccess(['article_id' => $r['article_id'], 'url' => '/admin/article/' . $r['article_id']]);
    }

    /** Normalise the editor's fields into the shape SeoDrafter::validate() and the table expect. */
    private function fields_from_post(): array {
        $faq = json_decode((string) ($this->post['faq'] ?? '[]'), true);
        $faq = is_array($faq) ? array_values(array_filter($faq, function ($f) { return is_array($f) && trim((string) ($f['q'] ?? '')) !== ''; })) : [];
        $sec = array_values(array_filter(array_map('trim', explode(',', html_entity_decode((string) ($this->post['secondary_keywords'] ?? ''), ENT_QUOTES, 'UTF-8')))));
        return [
            'title' => trim(html_entity_decode((string) ($this->post['title'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'slug' => SeoDrafter::slugify((string) ($this->post['slug'] ?? '')),
            'meta_description' => trim(html_entity_decode((string) ($this->post['meta_description'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'excerpt' => trim(html_entity_decode((string) ($this->post['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'body_md' => html_entity_decode((string) ($this->post['body_md'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'faq' => $faq, 'secondary_keywords' => $sec,
        ];
    }

    public function seo_article_saveAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $f = $this->fields_from_post();
        $errors = SeoDrafter::validate($f, $id);
        $m->update_fields($id, [
            'title' => $f['title'], 'slug' => $f['slug'] !== '' ? $f['slug'] : $a['slug'], 'meta_description' => $f['meta_description'], 'excerpt' => $f['excerpt'],
            'body_md' => $f['body_md'], 'body_html' => Markdown::render($f['body_md'], SeoDrafter::allowed_paths()),
            'faq' => json_encode($f['faq'], JSON_UNESCAPED_UNICODE), 'secondary_keywords' => json_encode($f['secondary_keywords'], JSON_UNESCAPED_UNICODE),
            'reading_minutes' => SeoDrafter::reading_minutes($f['body_md']),
        ]);
        $this->jsonSuccess(['errors' => $errors, 'slug' => $m->get($id)['slug'], 'message' => empty($errors) ? 'Saved' : 'Saved with ' . count($errors) . ' issue(s)']);
    }

    public function seo_article_publishAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $errors = SeoDrafter::validate(['title' => $a['title'], 'slug' => $a['slug'], 'meta_description' => $a['meta_description'], 'body_md' => $a['body_md'], 'faq' => json_decode((string) $a['faq'], true) ?: []], $id);
        if (!empty($errors)) { $this->jsonError('Fix before publishing: ' . implode('; ', $errors), ['errors' => $errors]); }
        $m->set_status($id, 'published', (int) Session::get('user_id'));
        $this->link_keyword($a, 'published', $id);
        $this->jsonSuccess(['url' => '/blog/' . $a['slug'], 'message' => 'Published']);
    }

    public function seo_article_unpublishAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $m->set_status($id, 'review'); $this->link_keyword($a, 'drafted', $id);
        $this->jsonSuccess(['message' => 'Back in review']);
    }

    public function seo_article_rewriteAction(){
        $this->guard();
        set_time_limit(300);
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $note = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($note === '') { $this->jsonError('Say what should change'); }
        $km = new SeoKeywordsModel();
        $rows = $km->all(); $kw = null; foreach ($rows as $r) { if ((int) $r['article_id'] === $id) { $kw = $r; break; } }
        if (!$kw) { $kid = $km->add($a['target_keyword'], null, 'doable', 100); $kw = $km->get($kid); }
        $m->update_fields($id, ['rewrite_note' => $note]);
        $r = SeoDrafter::draft($kw, $note, $id);
        if (empty($r['ok'])) { $this->jsonError('Rewrite failed: ' . $r['error']); }
        $this->jsonSuccess(['message' => 'Rewritten — review the new draft']);
    }

    public function seo_article_discardAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $m->set_status($id, 'archived'); $this->link_keyword($a, 'queued', null);
        $this->jsonSuccess(['message' => 'Discarded']);
    }

    private function link_keyword(array $a, string $status, $article_id): void {
        $km = new SeoKeywordsModel();
        foreach ($km->all() as $r) { if ((int) $r['article_id'] === (int) $a['id']) { $km->set_status((int) $r['id'], $status, $article_id === null ? 0 : $article_id); return; } }
    }
}
```

`set_status(..., 0)` clears `article_id` to 0 rather than NULL — acceptable; document it in the report. Register in `libs/Classes/ApiRoutes.php` next to the admin group:

```php
        'ApiSeoContentController' => [
            'seo_keyword_add', 'seo_keyword_update', 'seo_draft_now', 'seo_article_save', 'seo_article_publish',
            'seo_article_unpublish', 'seo_article_rewrite', 'seo_article_discard',
        ],
```

Check `BaseApiController`/`ApiRoutes` for how controller files are located (autoload by class name from `app/controllers/api/`) and that `$this->post` is the cleaned POST array — both are already used by `ApiAdminController`.

- [ ] **Step 2: AdminController.** In `indexAction()` before `$this->view->render();` add:

```php
        $art = new SeoArticlesModel();
        $this->view->seo_keywords  = (new SeoKeywordsModel())->all();
        $this->view->seo_review    = $art->by_status(array('review', 'draft'));
        $this->view->seo_published = $art->by_status(array('published'));
        $this->view->seo_archived  = count($art->by_status(array('archived')));
```

Add the editor page:

```php
    /** /admin/article/<id> — full-page editor for one article (Content tab → Edit). */
    public function articleAction(){
        if (!Permissions::is_admin()) { header('Location: /'); exit; }
        $url = Main::get_url();
        $a = (new SeoArticlesModel())->get((int) ($url[2] ?? 0));
        if (!$a) { Errors::page_not_found(); return; }
        $this->view->article  = $a;
        $this->view->faq      = (array) json_decode((string) ($a['faq'] ?? '[]'), true);
        $this->view->allowed  = SeoDrafter::allowed_paths();
        $this->view->errors   = SeoDrafter::validate(array('title' => $a['title'], 'slug' => $a['slug'], 'meta_description' => $a['meta_description'], 'body_md' => $a['body_md'], 'faq' => $this->view->faq), (int) $a['id']);
        $this->view->render();
    }
```

Confirm how `render()` picks the view file (`libs/Classes/View.php` `layout_file()` / controller+action → `app/views/admin/article.php`) and that the admin layout (`site_header.php` shell) is used; mirror whatever `indexAction` relies on.

- [ ] **Step 3: Content tab in `app/views/admin/index.php`.** Add a tab button after Users: `<button type="button" class="adm-tab" data-panel="content"><i class="fa-solid fa-newspaper"></i> Content<?php if (count($this->seo_review) > 0): ?> <b class="adm-tab__badge"><?php echo count($this->seo_review); ?></b><?php endif; ?></button>` and, after the last `</section>` panel, `<?php include __DIR__ . '/_content.php'; ?>`. Add `<script src="/js/admin-content.js?v=<?php echo @filemtime(Main::app_path().'/public/js/admin-content.js'); ?>"></script>` where `admin.js` is included.

`app/views/admin/_content.php`:

```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<section class="adm-sec adm-panel" data-panel="content">
    <div class="adm-sec__head">
        <h2 class="adm-sec__title">Review queue</h2>
        <span class="adm-sec__meta"><?php echo count($this->seo_review); ?> waiting</span>
    </div>
    <?php if (empty($this->seo_review)): ?>
        <div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-newspaper"></i></span><p class="adm-empty__t">Nothing to review</p><p class="adm-empty__x">The daily draft lands here. Use Draft Now on a keyword to make one right away.</p></div>
    <?php else: ?>
    <div class="adm-table adm-table--content">
        <div class="adm-table__head"><span>Article</span><span>Keyword</span><span>Drafted</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($this->seo_review as $a): ?>
            <div class="adm-crow" data-article="<?php echo (int) $a['id']; ?>">
                <div class="adm-ucell"><a class="adm-crow__title" href="/admin/article/<?php echo (int) $a['id']; ?>"><?php echo $e($a['title']); ?></a><span class="adm-crow__sub">/blog/<?php echo $e($a['slug']); ?> · <?php echo (int) $a['reading_minutes']; ?> min</span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($a['target_keyword']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e(date('M j, H:i', strtotime($a['updated_at'] . ' UTC'))); ?></div>
                <div class="adm-ucell adm-ucell--act"><a class="adm-btn" href="/admin/article/<?php echo (int) $a['id']; ?>">Review</a></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="adm-sec__head adm-sec__head--gap">
        <h2 class="adm-sec__title">Keyword queue</h2>
        <form class="adm-kwadd" id="admKwAdd">
            <input type="text" name="keyword" placeholder="Keyword" maxlength="160" required>
            <input type="number" name="volume" placeholder="Volume" min="0">
            <select name="difficulty"><option value="easy">Easy</option><option value="doable" selected>Doable</option><option value="hard">Hard</option></select>
            <input type="number" name="priority" placeholder="Priority" value="100" min="1">
            <button type="submit" class="adm-btn adm-btn--ok">Add Keyword</button>
        </form>
    </div>
    <div class="adm-table adm-table--keywords">
        <div class="adm-table__head"><span>Keyword</span><span>Volume</span><span>Difficulty</span><span>Priority</span><span>Status</span><span></span></div>
        <div class="adm-table__body" id="admKeywords">
            <?php foreach ($this->seo_keywords as $k): ?>
            <div class="adm-krow" data-keyword="<?php echo (int) $k['id']; ?>" data-status="<?php echo $e($k['status']); ?>">
                <div class="adm-ucell"><?php echo $e($k['keyword']); ?><?php if ($k['article_slug']): ?><a class="adm-crow__sub" href="/admin/article/<?php echo (int) $k['article_id']; ?>"><?php echo $e($k['article_title']); ?></a><?php endif; ?><?php if ($k['last_error']): ?><span class="adm-crow__err" title="<?php echo $e($k['last_error']); ?>">Last draft failed</span><?php endif; ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $k['volume'] === null ? '—' : number_format((int) $k['volume']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e(ucfirst($k['difficulty'])); ?></div>
                <div class="adm-ucell"><input class="adm-kprio" type="number" value="<?php echo (int) $k['priority']; ?>" min="1" aria-label="Priority"></div>
                <div class="adm-ucell"><span class="adm-tag adm-tag--<?php echo $e($k['status']); ?>"><?php echo $e(ucfirst($k['status'])); ?></span></div>
                <div class="adm-ucell adm-ucell--act">
                    <?php if ($k['status'] === 'queued'): ?><button type="button" class="adm-btn" data-kw-action="draft">Draft Now</button><button type="button" class="adm-btn" data-kw-action="skip">Skip</button>
                    <?php elseif ($k['status'] === 'skipped'): ?><button type="button" class="adm-btn" data-kw-action="requeue">Requeue</button>
                    <?php elseif ($k['status'] === 'drafting'): ?><span class="adm-crow__sub">Drafting…</span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="adm-sec__head adm-sec__head--gap">
        <h2 class="adm-sec__title">Published</h2>
        <span class="adm-sec__meta"><?php echo count($this->seo_published); ?> live · <?php echo (int) $this->seo_archived; ?> archived</span>
    </div>
    <div class="adm-table adm-table--content">
        <div class="adm-table__head"><span>Article</span><span>Keyword</span><span>Views</span><span></span></div>
        <div class="adm-table__body">
            <?php foreach ($this->seo_published as $a): ?>
            <div class="adm-crow" data-article="<?php echo (int) $a['id']; ?>">
                <div class="adm-ucell"><a class="adm-crow__title" href="/blog/<?php echo $e($a['slug']); ?>" target="_blank" rel="noopener"><?php echo $e($a['title']); ?></a><span class="adm-crow__sub"><?php echo $e(date('M j, Y', strtotime($a['published_at'] . ' UTC'))); ?></span></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo $e($a['target_keyword']); ?></div>
                <div class="adm-ucell adm-ucell--muted"><?php echo number_format((int) $a['views']); ?></div>
                <div class="adm-ucell adm-ucell--act"><a class="adm-btn" href="/admin/article/<?php echo (int) $a['id']; ?>">Edit</a><button type="button" class="adm-btn" data-art-action="unpublish">Unpublish</button></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($this->seo_published)): ?><p class="adm__none">Nothing published yet.</p><?php endif; ?>
        </div>
    </div>
</section>
```

- [ ] **Step 4: Editor view `app/views/admin/article.php`** (rendered inside the app shell like `index.php`; check whether `index.php` starts with a wrapper `<div class="adm">` and CSS link and replicate it):

```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $a = $this->article; ?>
<link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path().'/public/css/admin.css'); ?>">
<div class="adm adm--editor" id="admEditor" data-article="<?php echo (int) $a['id']; ?>" data-slug="<?php echo $e($a['slug']); ?>">
    <div class="adm__head">
        <div>
            <a class="adm-back" href="/admin"><i class="fa-solid fa-arrow-left"></i> Content</a>
            <h1 class="adm__title"><?php echo $e($a['title']); ?></h1>
            <p class="adm__sub"><span class="adm-tag adm-tag--<?php echo $e($a['status']); ?>"><?php echo $e(ucfirst($a['status'])); ?></span> · keyword “<?php echo $e($a['target_keyword']); ?>” · <?php echo $e($a['model']); ?></p>
        </div>
        <div class="adm-editor__acts">
            <button type="button" class="adm-btn" data-ed="save">Save</button>
            <button type="button" class="adm-btn" data-ed="rewrite">Request Rewrite</button>
            <?php if ($a['status'] === 'published'): ?><button type="button" class="adm-btn" data-ed="unpublish">Unpublish</button>
            <?php else: ?><button type="button" class="adm-btn adm-btn--ok" data-ed="publish">Publish</button><?php endif; ?>
            <button type="button" class="adm-btn adm-btn--danger" data-ed="discard">Discard</button>
        </div>
    </div>
    <ul class="adm-issues" id="admIssues"<?php if (empty($this->errors)): ?> hidden<?php endif; ?>>
        <?php foreach ($this->errors as $err): ?><li><?php echo $e($err); ?></li><?php endforeach; ?>
    </ul>
    <div class="adm-editor">
        <form class="adm-editor__form" id="admArticleForm">
            <label>Title<input type="text" name="title" maxlength="70" value="<?php echo $e($a['title']); ?>"></label>
            <label>Slug<input type="text" name="slug" maxlength="120" value="<?php echo $e($a['slug']); ?>"></label>
            <label>Meta description<input type="text" name="meta_description" maxlength="155" value="<?php echo $e($a['meta_description']); ?>"></label>
            <label>Excerpt<textarea name="excerpt" rows="2"><?php echo $e($a['excerpt']); ?></textarea></label>
            <label>Secondary keywords<input type="text" name="secondary_keywords" value="<?php echo $e(implode(', ', (array) json_decode((string) $a['secondary_keywords'], true))); ?>"></label>
            <label>Body (Markdown)<textarea name="body_md" rows="28" spellcheck="true"><?php echo $e($a['body_md']); ?></textarea></label>
            <div class="adm-faq" id="admFaq">
                <div class="adm-faq__head"><span>Questions</span><button type="button" class="adm-btn" data-faq-add>Add Question</button></div>
                <?php foreach ($this->faq as $f): ?>
                <div class="adm-faq__row"><input type="text" placeholder="Question" value="<?php echo $e($f['q']); ?>" data-faq-q><textarea rows="2" placeholder="Answer" data-faq-a><?php echo $e($f['a']); ?></textarea><button type="button" class="adm-btn adm-btn--danger" data-faq-del aria-label="Remove"><i class="fa-solid fa-xmark"></i></button></div>
                <?php endforeach; ?>
            </div>
            <p class="adm-editor__links">Links allowed: <?php echo $e(implode('  ', $this->allowed)); ?></p>
        </form>
        <div class="adm-editor__preview">
            <div class="adm-editor__prevhead"><span>Preview</span><a href="/blog/<?php echo $e($a['slug']); ?>?preview=1" target="_blank" rel="noopener">Open</a></div>
            <iframe id="admPreview" src="/blog/<?php echo $e($a['slug']); ?>?preview=1" title="Article preview"></iframe>
        </div>
    </div>
</div>
<script src="/js/admin-content.js?v=<?php echo @filemtime(Main::app_path().'/public/js/admin-content.js'); ?>"></script>
```

- [ ] **Step 5: `public/js/admin-content.js`** (jQuery is global; `ApiDataSvc.apiCall('post', action, data, cb)` returns a JSON string):

```js
/* Admin › Content: keyword queue + article editor (SEO content engine). */
(function () {
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function ok(msg) { if (window.toastr) { toastr.success(msg); } }
    function bad(msg) { if (window.toastr) { toastr.error(msg || 'Something went wrong'); } }
    function confirmAction(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#5b4be0', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed ? (r.value === undefined ? true : r.value) : false; });
    }

    /* ---- Keyword queue (Content tab) ---- */
    var kwAdd = document.getElementById('admKwAdd');
    if (kwAdd) {
        kwAdd.addEventListener('submit', function (e) {
            e.preventDefault();
            var f = new FormData(kwAdd);
            ApiDataSvc.apiCall('post', 'seo_keyword_add', { keyword: f.get('keyword'), volume: f.get('volume'), difficulty: f.get('difficulty'), priority: f.get('priority') }, function (r) {
                var o = parse(r); if (!o || !o.success) { return bad(o && o.message); }
                ok('Keyword added'); window.location.reload();
            });
        });
    }
    var kws = document.getElementById('admKeywords');
    if (kws) {
        kws.addEventListener('change', function (e) {
            var inp = e.target.closest('.adm-kprio'); if (!inp) { return; }
            var id = parseInt(inp.closest('.adm-krow').getAttribute('data-keyword'), 10);
            ApiDataSvc.apiCall('post', 'seo_keyword_update', { id: id, priority: inp.value }, function (r) { var o = parse(r); o && o.success ? ok('Priority saved') : bad(o && o.message); });
        });
        kws.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-kw-action]'); if (!btn) { return; }
            var row = btn.closest('.adm-krow'); var id = parseInt(row.getAttribute('data-keyword'), 10); var action = btn.getAttribute('data-kw-action');
            if (action === 'skip' || action === 'requeue') {
                ApiDataSvc.apiCall('post', 'seo_keyword_update', { id: id, status: action === 'skip' ? 'skipped' : 'queued' }, function (r) { var o = parse(r); if (o && o.success) { window.location.reload(); } else { bad(o && o.message); } });
                return;
            }
            if (action === 'draft') {
                btn.disabled = true; btn.textContent = 'Drafting…';
                ApiDataSvc.apiCall('post', 'seo_draft_now', { keyword_id: id }, function (r) {
                    var o = parse(r);
                    if (o && o.success) { ok('Draft ready'); window.location = o.url; }
                    else { btn.disabled = false; btn.textContent = 'Draft Now'; bad(o && o.message); }
                });
            }
        });
    }
    document.querySelectorAll('[data-art-action="unpublish"]').forEach(function (b) {
        b.addEventListener('click', function () {
            var id = parseInt(b.closest('.adm-crow').getAttribute('data-article'), 10);
            confirmAction({ title: 'Unpublish this article?', text: 'It goes back to the review queue and drops out of the sitemap.', confirmButtonText: 'Unpublish' }).then(function (yes) {
                if (!yes) { return; }
                ApiDataSvc.apiCall('post', 'seo_article_unpublish', { id: id }, function (r) { var o = parse(r); if (o && o.success) { window.location.reload(); } else { bad(o && o.message); } });
            });
        });
    });

    /* ---- Editor ---- */
    var ed = document.getElementById('admEditor');
    if (!ed) { return; }
    var id = parseInt(ed.getAttribute('data-article'), 10);
    var form = document.getElementById('admArticleForm');
    var issues = document.getElementById('admIssues');
    var preview = document.getElementById('admPreview');
    function faqJson() {
        var out = [];
        document.querySelectorAll('#admFaq .adm-faq__row').forEach(function (row) {
            var q = row.querySelector('[data-faq-q]').value.trim(), a = row.querySelector('[data-faq-a]').value.trim();
            if (q || a) { out.push({ q: q, a: a }); }
        });
        return JSON.stringify(out);
    }
    function showIssues(list) {
        issues.innerHTML = '';
        (list || []).forEach(function (t) { var li = document.createElement('li'); li.textContent = t; issues.appendChild(li); });
        issues.hidden = !(list && list.length);
    }
    function save(cb) {
        var f = new FormData(form);
        ApiDataSvc.apiCall('post', 'seo_article_save', { id: id, title: f.get('title'), slug: f.get('slug'), meta_description: f.get('meta_description'), excerpt: f.get('excerpt'), secondary_keywords: f.get('secondary_keywords'), body_md: f.get('body_md'), faq: faqJson() }, function (r) {
            var o = parse(r); if (!o || !o.success) { return bad(o && o.message); }
            showIssues(o.errors); ok(o.message);
            if (o.slug) { ed.setAttribute('data-slug', o.slug); preview.src = '/blog/' + o.slug + '?preview=1&t=' + Date.now(); }
            if (cb) { cb(o); }
        });
    }
    document.getElementById('admFaq').addEventListener('click', function (e) {
        if (e.target.closest('[data-faq-add]')) {
            var row = document.createElement('div'); row.className = 'adm-faq__row';
            row.innerHTML = '<input type="text" placeholder="Question" data-faq-q><textarea rows="2" placeholder="Answer" data-faq-a></textarea><button type="button" class="adm-btn adm-btn--danger" data-faq-del aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>';
            this.appendChild(row); return;
        }
        var del = e.target.closest('[data-faq-del]'); if (del) { del.closest('.adm-faq__row').remove(); }
    });
    ed.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ed]'); if (!btn) { return; }
        var action = btn.getAttribute('data-ed');
        if (action === 'save') { return save(); }
        if (action === 'publish') {
            save(function (o) {
                if (o.errors && o.errors.length) { return bad('Fix the issues listed before publishing'); }
                ApiDataSvc.apiCall('post', 'seo_article_publish', { id: id }, function (r) { var p = parse(r); if (p && p.success) { ok('Published'); window.location = p.url; } else { showIssues(p && p.errors); bad(p && p.message); } });
            }); return;
        }
        if (action === 'unpublish') {
            ApiDataSvc.apiCall('post', 'seo_article_unpublish', { id: id }, function (r) { var p = parse(r); p && p.success ? window.location.reload() : bad(p && p.message); }); return;
        }
        if (action === 'rewrite') {
            confirmAction({ title: 'Request a rewrite', input: 'textarea', inputPlaceholder: 'What should change', inputValidator: function (v) { return v && v.trim() ? undefined : 'Say what should change'; }, confirmButtonText: 'Rewrite' }).then(function (note) {
                if (!note) { return; }
                btn.disabled = true; btn.textContent = 'Rewriting…';
                ApiDataSvc.apiCall('post', 'seo_article_rewrite', { id: id, note: note }, function (r) { var p = parse(r); if (p && p.success) { window.location.reload(); } else { btn.disabled = false; btn.textContent = 'Request Rewrite'; bad(p && p.message); } });
            }); return;
        }
        if (action === 'discard') {
            confirmAction({ title: 'Discard this article?', text: 'It is archived and the keyword goes back into the queue.', confirmButtonText: 'Discard', confirmButtonColor: '#e5484d' }).then(function (yes) {
                if (!yes) { return; }
                ApiDataSvc.apiCall('post', 'seo_article_discard', { id: id }, function (r) { var p = parse(r); if (p && p.success) { window.location = '/admin'; } else { bad(p && p.message); } });
            });
        }
    });
})();
```

- [ ] **Step 6: Styles** — append to `public/css/admin.css`:

```css
/* Content tab + editor */
.adm-sec__head--gap{ margin-top:40px; }
.adm-table--content .adm-table__head, .adm-table--content .adm-crow{ display:grid; grid-template-columns:minmax(0,2.4fr) minmax(0,1.2fr) 120px 200px; gap:12px; align-items:center; }
.adm-table--keywords .adm-table__head, .adm-table--keywords .adm-krow{ display:grid; grid-template-columns:minmax(0,2fr) 90px 100px 90px 110px 190px; gap:12px; align-items:center; }
.adm-crow, .adm-krow{ padding:12px 16px; border-top:1px solid #ecebf2; }
.adm-crow__title{ font-weight:600; color:#1c1830; text-decoration:none; display:block; }
.adm-crow__sub{ display:block; font-size:.8rem; color:#8a8797; margin-top:2px; }
.adm-crow__err{ display:block; font-size:.8rem; color:#c2334d; margin-top:2px; }
.adm-kprio{ width:72px; padding:.35rem .5rem; border:1px solid #e2e0ea; border-radius:6px; font:inherit; }
.adm-kwadd{ display:flex; gap:8px; align-items:center; }
.adm-kwadd input, .adm-kwadd select{ padding:.45rem .6rem; border:1px solid #e2e0ea; border-radius:6px; font:inherit; font-size:.88rem; }
.adm-kwadd input[name=keyword]{ width:240px; } .adm-kwadd input[type=number]{ width:90px; }
.adm-tag--queued{ background:#eef0ff; color:#4636c4; } .adm-tag--drafting{ background:#fff4d6; color:#8a6a00; } .adm-tag--drafted, .adm-tag--review{ background:#e8f7ee; color:#1f7a45; } .adm-tag--published{ background:#e8f7ee; color:#1f7a45; } .adm-tag--skipped, .adm-tag--archived{ background:#f1f0f5; color:#6b6779; }
.adm-back{ display:inline-flex; gap:6px; align-items:center; color:#6b6779; text-decoration:none; font-size:.85rem; margin-bottom:6px; }
.adm-editor__acts{ display:flex; gap:8px; align-items:center; }
.adm-issues{ margin:0 0 16px; padding:12px 16px 12px 32px; background:#fff1f2; border:1px solid #f5c2c7; border-radius:8px; color:#9f1d2f; font-size:.9rem; }
.adm-editor{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:24px; align-items:start; }
.adm-editor__form label{ display:block; font-size:.82rem; font-weight:600; color:#4b4757; margin:0 0 14px; }
.adm-editor__form input, .adm-editor__form textarea{ display:block; width:100%; margin-top:4px; padding:.55rem .7rem; border:1px solid #e2e0ea; border-radius:8px; font:inherit; font-size:.95rem; }
.adm-editor__form textarea[name=body_md]{ font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.88rem; line-height:1.55; }
.adm-faq__head{ display:flex; justify-content:space-between; align-items:center; font-size:.82rem; font-weight:600; color:#4b4757; margin:8px 0; }
.adm-faq__row{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.6fr) 40px; gap:8px; margin-bottom:8px; }
.adm-faq__row input, .adm-faq__row textarea{ margin:0; }
.adm-editor__links{ font-size:.78rem; color:#8a8797; word-break:break-all; }
.adm-editor__preview{ position:sticky; top:84px; }
.adm-editor__prevhead{ display:flex; justify-content:space-between; font-size:.82rem; font-weight:600; color:#4b4757; margin-bottom:8px; }
.adm-editor__preview iframe{ width:100%; height:calc(100vh - 160px); border:1px solid #e2e0ea; border-radius:10px; background:#fff; }
@media (max-width:1100px){ .adm-editor{ grid-template-columns:1fr; } .adm-editor__preview{ position:static; } .adm-editor__preview iframe{ height:70vh; } }
```

- [ ] **Step 7: Verify** in dev as the admin (forge a session: see `scratchpad/forge_session.php` pattern — `session.save_path` is `/var/folders/.../T`, Apache runs as danielglauber; or log in normally). `/admin` → Content tab lists the smoke article and ≥15 keywords. Editor: Save with a too-long title shows the issue list; Publish is refused while issues exist; fix, Publish → redirected to `/blog/<slug>` 200. Unpublish from the Published table returns it to review. Draft Now on one keyword produces a second review article (one Claude call). Screenshot the tab and the editor at 1280 px; critique against the other admin tabs (same table density, one accent).

---

### Task 7: Sitemap, llms.txt, llms-full.txt, product-page guides block

**Files:**
- Modify: `app/controllers/SeoController.php`, `app/controllers/PagesController.php` (`page()`), `libs/Layout/public_page.php`
- Test: `tests/seo_crawl_files_test.php` (add checks)

- [ ] **Step 1: Add checks** to `tests/seo_crawl_files_test.php` (there must be ≥1 published article in dev):

```php
check('sitemap lists /blog',              strpos($sitemap, '<loc>' . $base . '/blog</loc>') !== false);
check('sitemap lists an article',         preg_match('#<loc>' . preg_quote($base, '#') . '/blog/[a-z0-9-]+</loc>#', $sitemap) === 1);
check('llms.txt has Guides section',      strpos($llms, '## Guides') !== false && strpos($llms, '/blog/') !== false);
check('llms-full has an article heading', preg_match('/^## .+\nURL: ' . preg_quote($base, '/') . '\/blog\/[a-z0-9-]+$/m', $full) === 1);
```

Use whatever variable names the file already uses for the fetched bodies; read it first.

- [ ] **Step 2: SeoController.** In `sitemapAction()` after the `public_pages()` loop:

```php
        try {
            $articles = new SeoArticlesModel();
            $urls[] = array('loc' => $base . '/blog', 'changefreq' => 'daily', 'priority' => '0.8');
            foreach ($articles->published(500, 0) as $a) {
                $urls[] = array('loc' => $base . '/blog/' . $a['slug'], 'lastmod' => gmdate('Y-m-d', strtotime(($a['updated_at'] ?: $a['published_at']) . ' UTC')), 'changefreq' => 'monthly', 'priority' => '0.7');
            }
        } catch (\Throwable $e) { error_log('[seo] sitemap articles: ' . $e->getMessage()); }
```

In `llmsAction()` after the `## Product` list:

```php
        try {
            $recent = (new SeoArticlesModel())->published(20, 0);
            if (!empty($recent)) {
                $l[] = ''; $l[] = '## Guides';
                foreach ($recent as $a) { $l[] = '- [' . $a['title'] . '](' . $base . '/blog/' . $a['slug'] . '): ' . ($a['meta_description'] ?: (string) $a['excerpt']); }
                $l[] = '- [All guides](' . $base . '/blog) · [RSS](' . $base . '/blog/feed.xml)';
            }
        } catch (\Throwable $e) {}
```

In `llmsFullAction()` after the `public_pages()` loop, before the size cap:

```php
        try {
            foreach ((new SeoArticlesModel())->published(200, 0) as $s) {
                $a = (new SeoArticlesModel())->get((int) $s['id']);
                $text = self::html_to_text('<main><h1>' . htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') . '</h1>' . $a['body_html'] . '</main>');
                if ($text === '') { continue; }
                $out[] = '## ' . $a['title']; $out[] = 'URL: ' . $base . '/blog/' . $a['slug']; $out[] = ''; $out[] = $text; $out[] = '';
            }
        } catch (\Throwable $e) { error_log('[seo] llms-full articles: ' . $e->getMessage()); }
```

Check `html_to_text()`'s exact expectation of the `<main>` wrapper (`sed -n 160,200p app/controllers/SeoController.php`) and adapt the wrapper string so headings demote the same way product pages do.

- [ ] **Step 3: Guides block on product pages.** In `PagesController::page()` before `unset($meta['path'])`:

```php
        try { $meta['guides'] = (new SeoArticlesModel())->newest_published(3); } catch (\Throwable $e) { $meta['guides'] = array(); }
```

In `libs/Layout/public_page.php`, after `<?php require $public_view_file; ?>` and still inside `.pub-wrap`:

```php
<?php if (empty($public_meta['no_guides']) && !empty($public_meta['guides'])): ?>
            <aside class="pub-guides" aria-labelledby="pub_guides_h">
                <p class="pub-guides__h" id="pub_guides_h">From the guides</p>
                <div class="pub-cards pub-cards--3">
                    <?php foreach ($public_meta['guides'] as $g): ?>
                    <a class="pub-card" href="/blog/<?php echo htmlspecialchars($g['slug'], ENT_QUOTES, 'UTF-8'); ?>"><span class="pub-card__title"><?php echo htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8'); ?></span><span class="pub-card__x"><?php echo htmlspecialchars($g['excerpt'] ?: $g['meta_description'], ENT_QUOTES, 'UTF-8'); ?></span></a>
                    <?php endforeach; ?>
                </div>
            </aside>
<?php endif; ?>
```

`SeoMeta::head()` ignores unknown keys (`guides`, `no_guides`), so passing them through `$public_meta` is safe — confirm by reading `head()` once more; if it iterates all keys, strip the two before calling it.

- [ ] **Step 4: Run** `tests/seo_crawl_files_test.php`, `tests/seo_routes_test.php`, `tests/seo_blog_routes_test.php` — all `ALL OK`. Load `/features` in a browser: the three-card guides block sits above the footer, styled like the blog index cards.

---

### Task 8: Profile page onto `SeoMeta::head()`

**Files:**
- Modify: `libs/Classes/SeoMeta.php` (`head()`), `app/views/profile/view.php` (lines 28-80: the `<title>` through the JSON-LD `<script>`)
- Test: `tests/seo_profile_jsonld_test.php` (existing) + `tests/seo_meta_test.php` (add 3 checks)

**Interfaces:**
- Produces: `SeoMeta::head()` accepts `type => 'profile'`, `twitter_card => 'summary'|'summary_large_image'`, `extra => array('<meta …>', …)` (raw tags, already escaped by the caller), and `og_title` (overrides the `<title>` text used for og/twitter titles).

- [ ] **Step 1: Add to `tests/seo_meta_test.php`**

```php
$html = SeoMeta::head(array('title' => 'Dana (@dana) · ' . SeoMeta::site(), 'og_title' => 'Dana', 'description' => 'bio', 'url' => SeoMeta::base() . '/@dana', 'type' => 'profile', 'twitter_card' => 'summary', 'extra' => array('<meta property="profile:username" content="dana">'), 'jsonld' => array('@type' => 'ProfilePage', 'url' => SeoMeta::base() . '/@dana')));
check('profile og:type',        strpos($html, '<meta property="og:type" content="profile">') !== false);
check('og_title override',      strpos($html, '<meta property="og:title" content="Dana">') !== false && strpos($html, '<title>Dana (@dana) · ') !== false);
check('twitter card + extra',   strpos($html, 'name="twitter:card" content="summary"') !== false && strpos($html, 'profile:username') !== false);
```

- [ ] **Step 2: Run — 3 FAILs.**

- [ ] **Step 3: Extend `head()`**: allow `'profile'` in the `in_array` type list; `$og_title = trim((string) ($m['og_title'] ?? '')) ?: $full;` and use `$og_title` for `og:title` and `twitter:title`; `$card = in_array($m['twitter_card'] ?? '', array('summary', 'summary_large_image'), true) ? $m['twitter_card'] : 'summary_large_image';` for `twitter:card`; after the twitter lines: `foreach ((array) ($m['extra'] ?? array()) as $tag) { if (is_string($tag) && strpos($tag, '<') === 0) { $out[] = $tag; } }`.

- [ ] **Step 4: Refactor the profile view.** Keep the PHP block that builds `$seo_url`, `$seo_image`, `$seo_desc`, `$seo_person`, `$seo_ld`, `$created_ts`. Delete the hand-written `<title>`, canonical, description, every `og:*`, `profile:username`, every `twitter:*` and the `<script type="application/ld+json">`. In their place:

```php
    <?php echo SeoMeta::head(array(
        'title' => $page_title, 'og_title' => $display_name, 'description' => $seo_desc, 'url' => $seo_url, 'type' => 'profile', 'image' => $seo_image,
        'twitter_card' => $has_avatar ? 'summary' : 'summary_large_image',
        'extra' => array('<meta property="profile:username" content="' . htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') . '">'),
        'jsonld' => $seo_ld,
    )); ?>
```

`$page_title` already contains the site name, so `head()` won't double-suffix. `head()` adds `<meta name="robots" content="index, follow, …">` — the profile had none, which is equivalent. Keep `CSRF::meta()` and the favicon/preconnect links where they are.

- [ ] **Step 5: Run `tests/seo_profile_jsonld_test.php` and `tests/seo_meta_test.php`** — all `ok`. `curl -s http://framework.contentos.cvk/@admin | grep -c 'og:title'` must be exactly 1 (no duplicates), and `og:type` must be `profile`.

---

### Task 9: Final verification and hand-off report

- [ ] Run every test: `for t in tests/seo_*_test.php tests/blocks_test.php; do APPLICATION_ENV=development /opt/homebrew/opt/php@8.2/bin/php -d display_errors=1 $t | tail -1; done` — every file `ALL OK` (or zero `FAIL` lines for the older curl tests).
- [ ] Lint: `for f in $(/usr/bin/git status --short | awk '{print $2}' | grep '\.php$'); do /opt/homebrew/opt/php@8.2/bin/php -l $f; done`; `node --check public/js/admin-content.js`.
- [ ] Lighthouse mobile on `/blog/<slug>` in dev: `npx lighthouse http://framework.contentos.cvk/blog/<slug> --only-categories=performance,seo,accessibility,best-practices --chrome-flags=--headless=new --quiet --output=json --output-path=<scratchpad>/lh_blog.json` — SEO 100, accessibility ≥ 95.
- [ ] Report for Daniel: prod SQL (three CREATE TABLEs), the prod crontab line, the list of changed/new files from `git status`, the first article's title, and the reminder that `--seed` runs automatically on the first prod cron.

---

## Self-review notes

- Spec §5 coverage: tables ✔ (T1), seed list ✔ (T4), drafting job steps 1-5 ✔ (T3/T4; step 6 cover image = static OG default, no fal), Admin Content tab queue/review/published with Publish / Request Rewrite / Discard ✔ (T6), public `/blog`, `/blog/<slug>`, RSS, Article + FAQPage + BreadcrumbList, author card ("team" in meta line + `SeoMeta::article` author), reading time, related, one CTA ✔ (T5), allow-list Markdown ✔ (T2), view counting server-side with bots excluded ✔ (T5), sitemap/llms/llms-full ✔ (T7), product pages link newest articles ✔ (T7), profile onto SeoMeta ✔ (T8).
- Names used consistently: `SeoArticlesModel::published/newest_published/by_status/related/record_view/set_status`, `SeoKeywordsModel::add/get/all/next_queued/set_status/set_priority/seed`, `SeoDrafter::draft/validate/slugify/reading_minutes/allowed_paths/parse_json`, `Markdown::render/links/word_count/headings`, `BlogController::dispatch/indexAction/viewAction/feedAction`.
- Open risk: `SeoMeta::internal_links()` return shape and `Notify::send`'s accepted categories are read at implementation time (called out inline in T3).
