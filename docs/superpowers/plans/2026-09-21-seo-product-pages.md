# SEO Sub-project A: Public Pages, SeoMeta, Landing Performance — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give creatorlinkstudio.com indexable, schema-rich product pages (`/features`, `/pricing`, `/compare/fanvue`, `/compare/onlyfans`, `/best-creator-monetization-platforms`, `/monetize-your-content`), a reusable public-page layout + `SeoMeta` helper, generated `llms.txt`/`llms-full.txt`, sitemap coverage, and fix the Search Console and Lighthouse defects found on 2026-09-21.

**Architecture:** A `SeoMeta` class turns a small array into `<head>` tags and JSON-LD. A `public_page.php` layout (landing nav + footer, one content column) renders any public view with that head. `PagesController` serves the six pages via a static route map in `Bootstrap` (URLs don't fit the `/controller/action` convention). `SeoController` gains `/llms.txt` and `/llms-full.txt` generation and lists the new pages in the sitemap. Landing-page performance fixes are pure `<head>` reordering plus CSS.

**Tech Stack:** PHP 8.2 MVC (custom, no framework), MySQL, jQuery 4, existing `landing.css` tokens, JSON-LD (schema.org), Lighthouse CLI via `npx lighthouse` for verification. No test framework exists: tests are PHP CLI assertion scripts under `tests/` run with `php tests/<file>.php`, plus `curl` against the dev host `http://framework.contentos.cvk`.

**Spec:** `docs/superpowers/specs/2026-09-21-seo-llm-growth-design.md` (sections 3 and 4; plus the two audit findings below).

## Global Constraints

- **Never `git commit` or push.** Daniel commits and deploys. Every "commit" step in this plan is replaced by "report files changed"; edit, lint (`php -l`, `node --check`), verify, stop.
- Prices, take rates and limits come from `PlanTiers` and `StripeService::get_plans()`. Never hard-code a dollar amount or percentage for our own plans in a view.
- Competitor facts appear only in the `COMPETITORS` data array with a `source` URL and `checked` date (`2026-09-21`); no other competitor claims anywhere.
- Design: match the landing page (`landing.css` tokens `--ld-*`), one accent, Title Case buttons, sentence-case headings, no emoji, no helper text, no product screenshots on the landing page itself.
- Every public page: `<title>` ≤ 60 chars, meta description ≤ 155 chars, canonical, Open Graph + Twitter, JSON-LD, GA include (`libs/Layout/google_analytics.php`), single CTA to `/`.
- Public pages must **not** carry `noindex`. Signed-in app pages keep it.
- DB: no schema changes in this sub-project.
- Dev host for verification: `http://framework.contentos.cvk`.
- Deferred to sub-project B (content engine): refactoring `app/views/profile/view.php` to build its head via `SeoMeta::head()`, and "product pages link to the 3 newest articles" (no articles exist yet). CLI PHP: `APPLICATION_ENV=development /opt/homebrew/opt/php@8.2/bin/php`.

---

### Task 1: Fix the two Search Console / Lighthouse defects on existing pages

**Files:**
- Modify: `app/views/profile/view.php:63` (JSON-LD `dateCreated`)
- Modify: `libs/Layout/login_form.php:735` and `:778` (uncrawlable anchors), `:565` region in `public/css/landing.css` (footer contrast)
- Test: `tests/seo_profile_jsonld_test.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new. Standalone fixes.

Background: Search Console reports *Invalid datetime value for "dateCreated"* on `/@lexivaughn`. The view emits `gmdate('Y-m-d', strtotime(creator_since . ' UTC'))`; Google's ProfilePage guidance expects a full ISO 8601 datetime with offset, and when `creator_since` is not parseable `strtotime` returns `false` → `1970-01-01`. Lighthouse flags `<a class="cos-link show_login" tabindex="0">` (no `href`) as uncrawlable and `.ld-foot__note` (`#6e6e73` on the footer background) as low-contrast.

- [ ] **Step 1: Write the failing test**

Create `tests/seo_profile_jsonld_test.php`:

```php
<?php
// Runs the profile view's dateCreated logic the way view.php does and asserts ISO 8601 with offset.
$cases = array('2026-09-01 14:02:11' => true, '' => false, 'not a date' => false, null => false);
$fail = 0;
foreach ($cases as $in => $expect_set) {
    $ld = array();
    $ts = ($in !== null && $in !== '') ? strtotime((string) $in . ' UTC') : false;
    if ($ts !== false) { $ld['dateCreated'] = gmdate('c', $ts); }
    $set = isset($ld['dateCreated']);
    $ok  = ($set === $expect_set) && (!$set || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $ld['dateCreated']));
    echo ($ok ? 'ok   ' : 'FAIL ') . var_export($in, true) . ' => ' . ($set ? $ld['dateCreated'] : '(unset)') . "\n";
    if (!$ok) { $fail++; }
}
// The view must use gmdate('c') and guard strtotime === false.
$view = file_get_contents(__DIR__ . '/../app/views/profile/view.php');
$uses_c = strpos($view, "gmdate('c'") !== false && strpos($view, "gmdate('Y-m-d', strtotime((string) \$user['creator_since']") === false;
echo ($uses_c ? 'ok   ' : 'FAIL ') . "view.php emits dateCreated with gmdate('c')\n";
if (!$uses_c) { $fail++; }
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/seo_profile_jsonld_test.php`
Expected: last line `FAIL view.php emits dateCreated with gmdate('c')`, exit code 1.

- [ ] **Step 3: Fix the view**

In `app/views/profile/view.php` replace line 63:

```php
        if (!empty($user['creator_since'])) { $seo_ld['dateCreated'] = gmdate('Y-m-d', strtotime((string) $user['creator_since'] . ' UTC')); }
```
with
```php
        // Google wants a full ISO 8601 datetime with offset; skip the field when the value can't be parsed.
        $created_ts = !empty($user['creator_since']) ? strtotime((string) $user['creator_since'] . ' UTC') : false;
        if ($created_ts !== false) { $seo_ld['dateCreated'] = gmdate('c', $created_ts); }
```

- [ ] **Step 4: Run the test again**

Run: `php tests/seo_profile_jsonld_test.php` → all `ok`, exit 0. Then `php -l app/views/profile/view.php`.

- [ ] **Step 5: Make the "Back to Sign In" links crawlable-safe**

In `libs/Layout/login_form.php` lines 735 and 778, change both
```html
<a class="cos-link show_login" tabindex="0">Back to Sign In</a>
```
to
```html
<button type="button" class="cos-link show_login">Back to Sign In</button>
```
Then in `public/css/landing.css` add after the existing `.cos-link` rule (search `cos-link`; if none exists, add at the end of the auth-dialog section):
```css
button.cos-link{ background:none; border:0; padding:0; font:inherit; color:inherit; cursor:pointer; text-decoration:underline; }
```
Check `public/js/landing.js` for `.show_login` handlers: they bind on the class, so a `<button>` keeps working (`grep -n "show_login" public/js/landing.js`).

- [ ] **Step 6: Fix footer contrast**

In `public/css/landing.css` line 565 change `.ld-foot__note` color from `var(--ld-muted)` to `#5b5b60` (contrast ≥ 4.5:1 on the footer's `#f7f7f9`/white background; verify the footer background variable in the `.ld-foot` rule and pick `#55555a` if it is pure white).

- [ ] **Step 7: Verify in the browser**

Load `http://framework.contentos.cvk/` logged out (private window). Click "Forgot password" then "Back to Sign In": the sign-in card returns. View source of `http://framework.contentos.cvk/@admin`: the JSON-LD `dateCreated` looks like `2026-09-01T14:02:11+00:00`. Paste that URL into Google's Rich Results Test (https://search.google.com/test/rich-results) using the live domain after deploy; no "Invalid datetime" warning.

- [ ] **Step 8: Report**

Files changed: `app/views/profile/view.php`, `libs/Layout/login_form.php`, `public/css/landing.css`, new `tests/seo_profile_jsonld_test.php`. Tell Daniel to click **Validate Fix** in Search Console → Enhancements → Profile page after deploy.

---

### Task 2: Landing page performance (LCP 5.3s → target < 2.5s)

**Files:**
- Modify: `libs/Layout/login_form.php:1-15` and `:88-97` (head order, meta, script attributes)
- Modify: `public/css/landing.css` (font-face)
- Create: `public/fonts/` (Inter woff2 subset, 400/500/600/700/800)
- Test: Lighthouse CLI run (see Step 7)

**Interfaces:**
- Consumes: nothing.
- Produces: the head pattern that `public_page.php` (Task 4) copies.

Lighthouse (mobile, 2026-09-21): performance 71, LCP 5.3s, render-blocking 1,330ms from Google Fonts CSS (1,017ms), Bootstrap CSS (1,102ms), toastr CSS, jQuery, `landing.css`; 890ms redirect `https://creatorlinkstudio.com/` → `www`; bfcache blocked by `no-store`; 73 KiB unused gtag.

- [ ] **Step 1: Self-host Inter**

Download the Latin subset of Inter (weights 400, 500, 600, 700, 800) as woff2 from https://fonts.google.com/specimen/Inter (or `npx -y google-fonts-helper` output) into `public/fonts/inter-latin-400.woff2` … `inter-latin-800.woff2`. Add to the top of `public/css/landing.css`:

```css
@font-face{ font-family:'Inter'; font-style:normal; font-weight:400; font-display:swap; src:url('/fonts/inter-latin-400.woff2') format('woff2'); unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD; }
@font-face{ font-family:'Inter'; font-style:normal; font-weight:500; font-display:swap; src:url('/fonts/inter-latin-500.woff2') format('woff2'); }
@font-face{ font-family:'Inter'; font-style:normal; font-weight:600; font-display:swap; src:url('/fonts/inter-latin-600.woff2') format('woff2'); }
@font-face{ font-family:'Inter'; font-style:normal; font-weight:700; font-display:swap; src:url('/fonts/inter-latin-700.woff2') format('woff2'); }
@font-face{ font-family:'Inter'; font-style:normal; font-weight:800; font-display:swap; src:url('/fonts/inter-latin-800.woff2') format('woff2'); }
```

- [ ] **Step 2: Rewrite the landing `<head>` asset block**

In `libs/Layout/login_form.php` replace lines 88–96 (preconnects, Google Fonts link, Bootstrap CSS, toastr CSS, landing.css, jQuery, toastr JS, api.data.js) with:

```php
    <link rel="preload" href="/fonts/inter-latin-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/inter-latin-800.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/landing.css'); ?>">
    <!-- Bootstrap + toastr are only needed by the auth dialog: load them without blocking first paint. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css"></noscript>
    <script defer src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script defer src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path().'/public/js/api.data.js'); ?>"></script>
```

The inline `<script>` that follows (line 97+) uses `$` and `toastr` at parse time. Wrap its body so it runs after the deferred scripts:
```js
document.addEventListener('DOMContentLoaded', function () {
    // …existing inline code unchanged…
});
```
(Deferred scripts execute before `DOMContentLoaded`, in order, so `$`, `toastr` and `ApiDataSvc` exist by then.) Also find where `landing.js` is included (search `landing.js` in the file) and add `defer`.

- [ ] **Step 3: Remove the anti-cache metas on the landing page**

Delete lines 7–8 of `libs/Layout/login_form.php`:
```html
    <meta http-equiv="Cache-Control" content="no-store, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
```
The landing page is served only to signed-out visitors; the logged-in shell keeps its own headers. This unblocks back/forward cache.

- [ ] **Step 4: Lint and smoke-test the auth flow**

`php -l libs/Layout/login_form.php`. Load `http://framework.contentos.cvk/` in a private window: kinetic intro renders, "Sign In" opens the dialog with Bootstrap floating labels styled, a wrong password shows a toastr error. Check the console has no `$ is not defined`.

- [ ] **Step 5: Redirect and cache headers (Daniel runs these; do not edit live server config)**

Write the following into the report for Daniel. The apex→www hop costs 890ms on mobile, and static assets have no `Cache-Control`.

Apache (`.htaccess` on the live site — Daniel edits, after `apachectl configtest` on his box):
```apache
# One hop: http/https apex -> https://www
RewriteCond %{HTTP_HOST} ^creatorlinkstudio\.com$ [NC]
RewriteRule ^(.*)$ https://www.creatorlinkstudio.com/$1 [R=301,L]
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

# Static assets are cache-busted with ?v=mtime, so cache them for a year
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/css "access plus 1 year"
  ExpiresByType application/javascript "access plus 1 year"
  ExpiresByType font/woff2 "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/svg+xml "access plus 1 year"
</IfModule>
```
Verification for Daniel: `curl -sI http://creatorlinkstudio.com/ | grep -i location` shows `https://www.creatorlinkstudio.com/` directly; `curl -sI https://www.creatorlinkstudio.com/css/landing.css | grep -i cache-control` shows `max-age=31536000`.

- [ ] **Step 6: Lighthouse before/after on dev**

Run from the scratchpad:
```bash
npx -y lighthouse http://framework.contentos.cvk/ --output=json --output-path=./lh_after.json --quiet --chrome-flags="--headless=new" --only-categories=performance,seo,accessibility,best-practices --form-factor=mobile --screenEmulation.mobile --throttling-method=simulate
python3 -c "import json;d=json.load(open('lh_after.json'));print({k:round(v['score']*100) for k,v in d['categories'].items()});a=d['audits'];print(a['largest-contentful-paint']['displayValue'],a['render-blocking-insight'].get('displayValue'))"
```
Expected: performance ≥ 85, render-blocking savings < 400ms, `crawlable-anchors` and `color-contrast` pass, `bf-cache` passes (dev has no redirect hop, so the redirect audit is only measurable on live).

- [ ] **Step 7: Report**

Files changed: `libs/Layout/login_form.php`, `public/css/landing.css`, new `public/fonts/*.woff2`. Include the Apache block from Step 5 and the before/after scores.

---

### Task 3: `SeoMeta` helper

**Files:**
- Create: `libs/Classes/SeoMeta.php`
- Test: `tests/seo_meta_test.php`

**Interfaces:**
- Produces:
  - `SeoMeta::head(array $m): string` — returns the HTML for title, description, canonical, robots, Open Graph, Twitter, and a JSON-LD `<script>`. `$m` keys: `title` (string, required), `description` (string, required), `url` (absolute, required), `type` ('website'|'article'|'product', default 'website'), `image` (absolute URL, default `{base}/images/og-image.png`), `published`/`modified` (ISO 8601 or '' ), `noindex` (bool, default false), `jsonld` (array|array-of-arrays, optional; emitted as-is).
  - `SeoMeta::base(): string` — `Main::get_base_domain()` without trailing slash.
  - `SeoMeta::org(): array` — the `Organization` node used everywhere.
  - `SeoMeta::breadcrumbs(array $items): array` — `[['name'=>'Home','url'=>'/'], …]` → `BreadcrumbList`.
  - `SeoMeta::faq(array $qa): array` — `[['q'=>…,'a'=>…], …]` → `FAQPage`.
  - `SeoMeta::internal_links(): array` — `keyword => path` map used by Task 4 pages and later by the content engine.

- [ ] **Step 1: Write the failing test**

Create `tests/seo_meta_test.php`:

```php
<?php
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });

$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }

$html = SeoMeta::head(array(
    'title' => 'Features', 'description' => 'What the studio does.', 'url' => SeoMeta::base() . '/features',
    'type' => 'product', 'jsonld' => SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Features', 'url' => '/features'))),
));
check('title tag',            strpos($html, '<title>Features · ' ) !== false);
check('description',          strpos($html, '<meta name="description" content="What the studio does.">') !== false);
check('canonical',            strpos($html, '<link rel="canonical" href="' . SeoMeta::base() . '/features">') !== false);
check('no noindex by default', strpos($html, 'noindex') === false);
check('og:type product',      strpos($html, '<meta property="og:type" content="product">') !== false);
check('twitter card',         strpos($html, 'twitter:card') !== false);
check('jsonld breadcrumb',    strpos($html, '"BreadcrumbList"') !== false && strpos($html, 'application/ld+json') !== false);
check('escapes quotes',       strpos(SeoMeta::head(array('title' => 'A "q" & b', 'description' => 'x', 'url' => 'https://x/')), '&quot;q&quot; &amp; b') !== false);
check('noindex when asked',   strpos(SeoMeta::head(array('title' => 't', 'description' => 'd', 'url' => 'https://x/', 'noindex' => true)), 'noindex, follow') !== false);

$faq = SeoMeta::faq(array(array('q' => 'Q1?', 'a' => 'A1.')));
check('faq schema', $faq['@type'] === 'FAQPage' && $faq['mainEntity'][0]['acceptedAnswer']['text'] === 'A1.');
check('org has name+url', SeoMeta::org()['@type'] === 'Organization' && !empty(SeoMeta::org()['url']));
check('internal links map', SeoMeta::internal_links()['pricing'] === '/pricing' && SeoMeta::internal_links()['fanvue alternative'] === '/compare/fanvue');
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `APPLICATION_ENV=development /opt/homebrew/opt/php@8.2/bin/php tests/seo_meta_test.php`
Expected: fatal "Class SeoMeta not found" (exit ≠ 0).

- [ ] **Step 3: Implement `libs/Classes/SeoMeta.php`**

```php
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
            'image' => (string) ($m['image'] ?? (self::base() . '/images/og-image.png')),
            'author' => array('@type' => 'Organization', 'name' => self::site() . ' team', 'url' => self::base() . '/'),
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
        $title = trim((string) $m['title']);
        $full  = ($title === '' || $title === $site) ? $site : ($title . ' · ' . $site);
        $desc  = trim((string) $m['description']);
        $url   = (string) $m['url'];
        $type  = in_array($m['type'] ?? 'website', array('website', 'article', 'product'), true) ? $m['type'] : 'website';
        $image = (string) ($m['image'] ?? (self::base() . '/images/og-image.png'));

        $out   = array();
        $out[] = '<title>' . $e($full) . '</title>';
        $out[] = '<meta name="description" content="' . $e($desc) . '">';
        $out[] = '<link rel="canonical" href="' . $e($url) . '">';
        $out[] = '<meta name="robots" content="' . (!empty($m['noindex']) ? 'noindex, follow' : 'index, follow, max-image-preview:large') . '">';
        $out[] = '<meta property="og:type" content="' . $type . '">';
        $out[] = '<meta property="og:site_name" content="' . $e($site) . '">';
        $out[] = '<meta property="og:title" content="' . $e($full) . '">';
        $out[] = '<meta property="og:description" content="' . $e($desc) . '">';
        $out[] = '<meta property="og:url" content="' . $e($url) . '">';
        $out[] = '<meta property="og:image" content="' . $e($image) . '">';
        $out[] = '<meta property="og:locale" content="en_US">';
        if (!empty($m['published'])) { $out[] = '<meta property="article:published_time" content="' . $e($m['published']) . '">'; }
        if (!empty($m['modified']))  { $out[] = '<meta property="article:modified_time" content="' . $e($m['modified']) . '">'; }
        $out[] = '<meta name="twitter:card" content="summary_large_image">';
        $out[] = '<meta name="twitter:title" content="' . $e($full) . '">';
        $out[] = '<meta name="twitter:description" content="' . $e($desc) . '">';
        $out[] = '<meta name="twitter:image" content="' . $e($image) . '">';

        if (!empty($m['jsonld'])) {
            $blocks = isset($m['jsonld']['@type']) || isset($m['jsonld']['@context']) ? array($m['jsonld']) : (array) $m['jsonld'];
            foreach ($blocks as $b) {
                if (!isset($b['@context'])) { $b = array('@context' => 'https://schema.org') + $b; }
                $out[] = '<script type="application/ld+json">' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
            }
        }
        return "    " . implode("\n    ", $out) . "\n";
    }
}
```

- [ ] **Step 4: Run the test**

`APPLICATION_ENV=development /opt/homebrew/opt/php@8.2/bin/php tests/seo_meta_test.php` → all `ok`, exit 0. `php -l libs/Classes/SeoMeta.php`.

- [ ] **Step 5: Report** — new `libs/Classes/SeoMeta.php`, `tests/seo_meta_test.php`.

---

### Task 4: Public page layout, `PagesController`, route map

**Files:**
- Create: `libs/Layout/public_page.php`, `public/css/public.css`, `app/controllers/PagesController.php`
- Modify: `libs/Classes/View.php` (add `public_page()`), `app/Bootstrap.php:40-45` (static route map)
- Test: `tests/seo_routes_test.php` (curl-based)

**Interfaces:**
- Consumes: `SeoMeta::head()`, `SeoMeta::org()`, `SeoMeta::breadcrumbs()`.
- Produces:
  - `View::public_page(string $view_file, array $meta, array $vars = array()): void` — renders `libs/Layout/public_page.php`, which prints the head via `SeoMeta::head($meta)`, the landing nav, the view file, and the footer. `$vars` are extracted into the view scope.
  - `PagesController` methods `featuresAction`, `pricingAction`, `compareAction`, `bestPlatformsAction`, `monetizeAction`, each calling `$this->view->public_page(Main::app_path() . '/app/views/pages/<name>.php', $meta, $vars)`.
  - `PagesController::ROUTES` (`const`) — `array('features' => 'features', 'pricing' => 'pricing', 'compare' => 'compare', 'best-creator-monetization-platforms' => 'bestPlatforms', 'monetize-your-content' => 'monetize')`, read by Bootstrap.
  - `PagesController::COMPETITORS` — data array (Task 7 fills it).

- [ ] **Step 1: Write the failing route test**

Create `tests/seo_routes_test.php`:
```php
<?php
$base  = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$paths = array('/features', '/pricing', '/compare/fanvue', '/compare/onlyfans', '/best-creator-monetization-platforms', '/monetize-your-content');
$fail  = 0;
foreach ($paths as $p) {
    $ch = curl_init($base . $p);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15));
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $ok = $code === 200
        && preg_match('/<title>[^<]{10,70}<\/title>/', $body)
        && strpos($body, 'rel="canonical" href="' . $base . $p . '"') !== false
        && strpos($body, 'application/ld+json') !== false
        && strpos($body, 'noindex') === false
        && preg_match('/<h1[^>]*>/', $body);
    echo ($ok ? 'ok   ' : 'FAIL ') . $p . ' (' . $code . ")\n";
    if (!$ok) { $fail++; }
}
$ch = curl_init($base . '/compare/nope'); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo ($code === 404 ? 'ok   ' : 'FAIL ') . "/compare/nope → 404 ($code)\n"; if ($code !== 404) { $fail++; }
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run it to verify it fails**

`php tests/seo_routes_test.php` → every path FAIL (currently 404 or app shell).

- [ ] **Step 3: Add `View::public_page()`**

In `libs/Classes/View.php` add after `verify_email()`:
```php
    /** Render a public, indexable page with the landing-page chrome. $meta feeds SeoMeta::head(). */
    public function public_page(string $view_file, array $meta, array $vars = array()): void
    {
        $file = Main::lib_path() . '/Layout/public_page.php';
        if (!file_exists($file) || !file_exists($view_file)) { Errors::page_not_found(); return; }
        $public_view_file = $view_file;
        $public_meta      = $meta;
        extract($vars, EXTR_SKIP);
        require $file;
    }
```

- [ ] **Step 4: Create `libs/Layout/public_page.php`**

```php
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/images/android-chrome-192x192.png">
<?php echo SeoMeta::head($public_meta); ?>
    <link rel="preload" href="/fonts/inter-latin-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/inter-latin-700.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/landing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/landing.css'); ?>">
    <link rel="stylesheet" href="/css/public.css?v=<?php echo @filemtime(Main::app_path().'/public/css/public.css'); ?>">
</head>
<body class="ld pub">
    <header class="ld-nav">
        <div class="ld-wrap ld-nav__inner">
            <a class="ld-brand" href="/">
                <span class="ld-brand__mark" aria-hidden="true"></span>
                <span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <nav class="pub-nav" aria-label="Site">
                <a href="/features">Features</a>
                <a href="/pricing">Pricing</a>
                <a href="/monetize-your-content">Guides</a>
            </nav>
            <div class="ld-nav__actions">
                <a class="ld-btn ld-btn--quiet" href="/?auth=login">Sign In</a>
                <a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a>
            </div>
        </div>
    </header>

    <main class="pub-main">
        <div class="ld-wrap pub-wrap">
<?php require $public_view_file; ?>
        </div>
    </main>

    <footer class="ld-foot">
        <div class="ld-wrap ld-foot__inner">
            <span class="ld-brand"><span class="ld-brand__mark" aria-hidden="true"></span><span class="ld-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span></span>
            <nav class="pub-foot__links" aria-label="Footer">
                <a href="/features">Features</a><a href="/pricing">Pricing</a><a href="/compare/fanvue">vs Fanvue</a><a href="/compare/onlyfans">vs OnlyFans</a><a href="/best-creator-monetization-platforms">Best platforms</a><a href="/llms.txt">llms.txt</a>
            </nav>
            <span class="ld-foot__note">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </footer>
</body>
</html>
```
The `/?auth=login` and `/?auth=register` links: in `public/js/landing.js`, at init, add
```js
var authParam = new URLSearchParams(location.search).get('auth');
if (authParam === 'login' || authParam === 'register') { document.querySelector('[data-auth="' + authParam + '"]').click(); }
```
so the landing page opens the right dialog when arriving from a public page.

- [ ] **Step 5: Create `public/css/public.css`**

```css
/* Public marketing pages (features, pricing, compare, guides) — extends landing.css tokens */
.pub-nav{ display:flex; gap:22px; margin-left:auto; margin-right:22px; }
.pub-nav a{ font-size:.95rem; font-weight:500; color:var(--ld-muted); text-decoration:none; }
.pub-nav a:hover{ color:var(--ld-ink, #1d1d1f); }
.pub-main{ padding:72px 0 96px; }
.pub-wrap{ max-width:820px; }
.pub-wrap--wide{ max-width:1080px; }
.pub-eyebrow{ font-size:.8rem; letter-spacing:.18em; text-transform:uppercase; color:var(--ld-muted); margin:0 0 14px; }
.pub-h1{ font-size:clamp(2.2rem, 5vw, 3.4rem); line-height:1.05; letter-spacing:-.03em; margin:0 0 18px; }
.pub-lead{ font-size:1.2rem; line-height:1.55; color:#3c3c43; margin:0 0 40px; max-width:62ch; }
.pub-h2{ font-size:1.6rem; letter-spacing:-.02em; margin:56px 0 14px; }
.pub-h3{ font-size:1.15rem; margin:28px 0 8px; }
.pub-p{ font-size:1.05rem; line-height:1.65; color:#3c3c43; margin:0 0 16px; max-width:66ch; }
.pub-p a{ color:var(--ld-accent, #5b4be0); text-decoration:underline; text-decoration-color:rgba(91,75,224,.35); }
.pub-list{ padding-left:1.2rem; margin:0 0 16px; color:#3c3c43; line-height:1.6; }
.pub-table{ width:100%; border-collapse:collapse; margin:20px 0 8px; font-size:.98rem; }
.pub-table th,.pub-table td{ text-align:left; padding:12px 14px; border-bottom:1px solid rgba(20,18,40,.1); vertical-align:top; }
.pub-table th{ font-size:.8rem; letter-spacing:.06em; text-transform:uppercase; color:var(--ld-muted); }
.pub-table--compare td:first-child{ font-weight:600; }
.pub-note{ font-size:.86rem; color:var(--ld-muted); margin:0 0 24px; }
.pub-cta{ margin:64px 0 0; padding:36px; border:1px solid rgba(20,18,40,.1); border-radius:14px; display:flex; align-items:center; justify-content:space-between; gap:24px; flex-wrap:wrap; }
.pub-cta__text{ font-size:1.25rem; font-weight:600; letter-spacing:-.01em; }
.pub-faq{ margin:0; padding:0; list-style:none; }
.pub-faq li{ padding:18px 0; border-top:1px solid rgba(20,18,40,.1); }
.pub-faq h3{ font-size:1.05rem; margin:0 0 6px; }
.pub-faq p{ margin:0; color:#3c3c43; line-height:1.6; }
.pub-plans{ display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:18px; margin:28px 0; }
.pub-plan{ border:1px solid rgba(20,18,40,.1); border-radius:14px; padding:24px; }
.pub-plan--featured{ border-color:var(--ld-accent, #5b4be0); }
.pub-plan__name{ font-weight:700; font-size:1.1rem; }
.pub-plan__tag{ color:var(--ld-muted); margin:4px 0 14px; font-size:.95rem; }
.pub-plan__price{ font-size:2.2rem; font-weight:800; letter-spacing:-.03em; }
.pub-plan__price small{ font-size:.95rem; font-weight:500; color:var(--ld-muted); }
.pub-plan dl{ margin:18px 0 0; }
.pub-plan dl div{ display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-top:1px solid rgba(20,18,40,.07); font-size:.95rem; }
.pub-plan dt{ color:var(--ld-muted); }
.pub-plan dd{ margin:0; font-weight:600; }
.pub-foot__links{ display:flex; gap:16px; flex-wrap:wrap; }
.pub-foot__links a{ font-size:.85rem; color:#5b5b60; text-decoration:none; }
@media (max-width:640px){ .pub-nav{ display:none; } .pub-main{ padding:40px 0 64px; } .pub-cta{ padding:24px; } }
```
Check `landing.css` `:root` for the ink/accent variable names (`grep -n "^\s*--ld-" public/css/landing.css | head`) and replace the fallbacks `--ld-ink`/`--ld-accent` with the real names.

- [ ] **Step 6: Create `app/controllers/PagesController.php`**

```php
<?php
/**
 * Public, indexable product pages: /features, /pricing, /compare/<competitor>,
 * /best-creator-monetization-platforms, /monetize-your-content. Routed from Bootstrap via
 * ROUTES (the URLs don't fit /controller/action). Rendered with View::public_page().
 */
class PagesController extends Controller {

    public $protected = 0;

    /** URL first segment => method (without "Action"). */
    const ROUTES = array(
        'features'                            => 'features',
        'pricing'                             => 'pricing',
        'compare'                             => 'compare',
        'best-creator-monetization-platforms' => 'bestPlatforms',
        'monetize-your-content'               => 'monetize',
    );

    /** Competitor facts used by /compare/* and the best-of page. Every row has a source + checked date. Filled in Task 7. */
    const COMPETITORS = array();

    public function __construct(){
        parent::__construct();
        header('Cache-Control: public, max-age=300');
    }

    private function page($view, array $meta, array $vars = array()){
        $meta['url'] = SeoMeta::base() . $meta['path'];
        unset($meta['path']);
        $this->view->public_page(Main::app_path() . '/app/views/pages/' . $view . '.php', $meta, $vars);
    }

    public function featuresAction(){ $this->page('features', array('path' => '/features', 'title' => 'Features', 'description' => 'One creator platform for your public page, memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'type' => 'product')); }
    public function pricingAction(){ $this->page('pricing', array('path' => '/pricing', 'title' => 'Pricing', 'description' => 'Three monthly plans. Every plan includes the whole platform; the take rate falls as you grow.', 'type' => 'product')); }
    public function bestPlatformsAction(){ $this->page('best-platforms', array('path' => '/best-creator-monetization-platforms', 'title' => 'Best creator monetization platforms', 'description' => 'How the main creator platforms compare on fees, ownership, content types and payouts, with an honest place for Creator Link Studio.', 'type' => 'article')); }
    public function monetizeAction(){ $this->page('monetize', array('path' => '/monetize-your-content', 'title' => 'How to monetize your content', 'description' => 'Memberships, pay-per-view, bundles, services and events: the five ways creators get paid, and how to price each one.', 'type' => 'article')); }

    public function compareAction(){
        $url  = Main::get_url();
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) ($url[1] ?? '')));
        if (!isset(self::COMPETITORS[$slug])) { Errors::page_not_found(); return; }
        $c = self::COMPETITORS[$slug];
        $this->page('compare', array('path' => '/compare/' . $slug, 'title' => Main::site_name() . ' vs ' . $c['name'], 'description' => 'A ' . $c['name'] . ' alternative for creators: fees, content types, payouts and ownership compared, with sources.', 'type' => 'article'), array('slug' => $slug, 'c' => $c));
    }
}
```
Task 5–8 add the `jsonld` entries to each `$meta` when the page content exists.

- [ ] **Step 7: Route in Bootstrap**

In `app/Bootstrap.php`, directly after the robots/sitemap block (line 45), add:
```php
        // Public product pages (URLs don't fit /controller/action): see PagesController::ROUTES.
        if (isset($url[0]) && isset(PagesController::ROUTES[$url[0]])) {
            $method = PagesController::ROUTES[$url[0]] . 'Action';
            (new PagesController())->$method();
            return;
        }
```

- [ ] **Step 8: Temporary placeholder views so routes resolve, then run the route test**

Create `app/views/pages/features.php`, `pricing.php`, `compare.php`, `best-platforms.php`, `monetize.php` each containing only `<h1 class="pub-h1"><?php echo htmlspecialchars($public_meta['title'], ENT_QUOTES, 'UTF-8'); ?></h1>` (Tasks 5–8 replace these). Fill `COMPETITORS` minimally: `'fanvue' => array('name' => 'Fanvue'), 'onlyfans' => array('name' => 'OnlyFans')`.

Run `php -l` on every new PHP file, then `php tests/seo_routes_test.php` → all `ok`, `/compare/nope → 404`.

- [ ] **Step 9: Browser check**

Open `http://framework.contentos.cvk/features` logged out and logged in (must render the public layout in both cases, not the app shell). Nav links work; "Sign In" lands on `/` with the sign-in dialog open.

- [ ] **Step 10: Report** — new `libs/Layout/public_page.php`, `public/css/public.css`, `app/controllers/PagesController.php`, `app/views/pages/*.php`, `tests/seo_routes_test.php`; modified `libs/Classes/View.php`, `app/Bootstrap.php`, `public/js/landing.js`.

---

### Task 5: `/features` page

**Files:**
- Modify: `app/views/pages/features.php` (replace placeholder), `app/controllers/PagesController.php` (`featuresAction` jsonld)

**Interfaces:**
- Consumes: `SeoMeta::faq()`, `SeoMeta::breadcrumbs()`, `SeoMeta::org()`, `PlanTiers::INCLUDED`, `PlanTiers::TIERS`.

- [ ] **Step 1: Write the view**

`app/views/pages/features.php`:
```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $tiers = PlanTiers::TIERS; ?>
<p class="pub-eyebrow">Creator platform</p>
<h1 class="pub-h1">Everything a creator sells, from one page.</h1>
<p class="pub-lead"><?php echo $e(Main::site_name()); ?> is an online creator platform: one public page with memberships, pay-per-view posts, bundles, events, services and tracked links, a studio that publishes to your socials, and payouts through Stripe. You keep your audience and your content.</p>

<h2 class="pub-h2" id="page">Your public page</h2>
<p class="pub-p">Fans land on <strong>yourhandle</strong> at our domain. It shows your posts, membership tiers, services, events and links, with your brand colors. Free posts are open to everyone; subscriber and pay-per-view posts show blurred with a lock until a fan joins or unlocks.</p>

<h2 class="pub-h2" id="memberships">Memberships and tiers</h2>
<p class="pub-p">Create as many tiers as you like, each with its own price, billing interval, trial and perks. Every post can target one tier or several, so a "Supporter" post and an "Inner circle" post live on the same page. Discount codes and free trials are built in.</p>

<h2 class="pub-h2" id="ppv">Pay-per-view, bundles, services and events</h2>
<p class="pub-p">Sell a single post for a set price, group library media into a bundle, take bookings for a service, or sell seats to an event. Fans pay with a credit wallet, so a $7 unlock is one tap, and you earn the net amount in your balance.</p>

<h2 class="pub-h2" id="studio">The studio</h2>
<p class="pub-p">Upload once. Write the caption with AI in your brand voice, choose who can see it, and publish to your page and to X, Instagram, TikTok, Facebook, LinkedIn, Pinterest, YouTube, Threads and Bluesky at the same time. Schedule ahead, run automations, and read engagement from every platform in one analytics view.</p>

<h2 class="pub-h2" id="inbox">Inbox with AI replies</h2>
<p class="pub-p">Fan messages arrive in one inbox. AI drafts replies in your voice and waits for your approval until you let it send on its own. Welcome messages go out automatically to new followers and subscribers, with media and a price if you want.</p>

<h2 class="pub-h2" id="payouts">Payouts and ownership</h2>
<p class="pub-p">Payouts run through Stripe Connect to your own bank account. Your subscriber list, content and brand are yours to export. The platform fee is a flat percentage that falls as your plan grows; see <a href="/pricing">pricing</a>.</p>

<h2 class="pub-h2">Included on every plan</h2>
<ul class="pub-list"><?php foreach (PlanTiers::INCLUDED as $i): ?><li><?php echo $i; ?></li><?php endforeach; ?></ul>

<h2 class="pub-h2">Questions</h2>
<ul class="pub-faq">
<?php foreach ($faq as $qa): ?><li><h3><?php echo $e($qa['q']); ?></h3><p><?php echo $e($qa['a']); ?></p></li><?php endforeach; ?>
</ul>

<div class="pub-cta"><span class="pub-cta__text">Create your page in a few minutes.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
```

- [ ] **Step 2: Pass FAQ + schema from the controller**

Replace `featuresAction` in `PagesController`:
```php
    public function featuresAction(){
        $faq = array(
            array('q' => 'Do I need my own website?', 'a' => 'No. Your public page lives at our domain under your handle and includes your posts, tiers, services, events and links.'),
            array('q' => 'Can I keep posting to my social accounts?', 'a' => 'Yes. The studio publishes each post to your page and to any connected social accounts at the same time, and pulls their engagement back into analytics.'),
            array('q' => 'Who owns my content and subscriber list?', 'a' => 'You do. Media, posts and your audience list can be exported at any time.'),
            array('q' => 'How do I get paid?', 'a' => 'Payouts go through Stripe Connect to your bank account. Fans pay with a credit wallet for unlocks and by card for memberships.'),
            array('q' => 'Is adult content allowed?', 'a' => 'Yes, within the content policy. Adult posts are only shown to fans who opt in, and every upload is checked automatically.'),
        );
        $jsonld = array(
            array('@type' => 'Product', 'name' => Main::site_name(), 'description' => 'Creator platform with memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'brand' => SeoMeta::org(), 'url' => SeoMeta::base() . '/features'),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Features', 'url' => '/features'))),
        );
        $this->page('features', array('path' => '/features', 'title' => 'Features', 'description' => 'One creator platform for your public page, memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'type' => 'product', 'jsonld' => $jsonld), array('faq' => $faq));
    }
```

- [ ] **Step 3: Verify**

`php -l` both files. `php tests/seo_routes_test.php` still all `ok`. Open `/features` in the browser at desktop and 390px wide (DevTools device toolbar): headline, sections, FAQ, one primary CTA. View source: two `application/ld+json` blocks parse in https://validator.schema.org/ (paste the source).

- [ ] **Step 4: Report** — `app/views/pages/features.php`, `app/controllers/PagesController.php`.

---

### Task 6: `/pricing` page from live plan data

**Files:**
- Modify: `app/views/pages/pricing.php`, `app/controllers/PagesController.php` (`pricingAction`)
- Test: `tests/seo_pricing_test.php`

**Interfaces:**
- Consumes: `StripeService::get_plans()` (returns rows with `product_name`, `amount` cents, `interval`, `price_id`), `PlanTiers::all()` / `PlanTiers::match($name)` / `PlanTiers::ROWS` / `PlanTiers::fmt_limit()` / `PlanTiers::INCLUDED`.
- Produces: `PagesController::pricing_rows(): array` — `[['tier' => tierArray, 'amount' => int cents|null, 'interval' => 'month'|null], …]` ordered by tier rank, one row per tier even when Stripe returns nothing (amount null → "Contact us" is NOT shown; instead the price cell reads "See plans in your account"). Reused later by the content engine's context builder.

- [ ] **Step 1: Write the failing test**

`tests/seo_pricing_test.php`:
```php
<?php
$root = dirname(__DIR__); putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$rows = PagesController::pricing_rows();
$fail = 0; function check($l, $c) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $l . "\n"; if (!$c) { $fail++; } }
check('one row per tier', count($rows) === count(PlanTiers::all()));
$ranks = array_map(function ($r) { return $r['tier']['rank']; }, $rows);
check('ordered by rank', $ranks === array_values(array_unique($ranks)) && $ranks === (function ($a) { sort($a); return $a; })($ranks));
foreach ($rows as $r) { check($r['tier']['key'] . ' amount int-or-null', $r['amount'] === null || is_int($r['amount'])); }
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run it** → fatal: undefined method `pricing_rows`.

- [ ] **Step 3: Implement `pricing_rows()` and the action**

In `PagesController`:
```php
    /** One row per tier, in rank order, with the live Stripe monthly price when available. */
    public static function pricing_rows(): array {
        $by_tier = array();
        foreach ((array) StripeService::get_plans() as $p) {
            $key = PlanTiers::match((string) ($p['product_name'] ?? ''));
            if ($key === '' || (($p['interval'] ?? 'month') !== 'month')) { continue; }
            if (!isset($by_tier[$key])) { $by_tier[$key] = $p; }
        }
        $rows = array();
        foreach (PlanTiers::all() as $tier) {
            $p = $by_tier[$tier['key']] ?? null;
            $rows[] = array('tier' => $tier, 'amount' => $p ? (int) $p['amount'] : null, 'interval' => $p ? (string) $p['interval'] : null);
        }
        usort($rows, function ($a, $b) { return $a['tier']['rank'] <=> $b['tier']['rank']; });
        return $rows;
    }

    public function pricingAction(){
        $rows = self::pricing_rows();
        $faq  = array(
            array('q' => 'Is there a free plan?', 'a' => 'No. Every plan includes the whole platform and unlimited fans; the plans differ in take rate, seats, AI credits, automations and storage.'),
            array('q' => 'What is the platform take rate?', 'a' => 'A flat percentage of what fans pay you, set by your plan and shown on this page. It falls as you move up.'),
            array('q' => 'Can I change plans later?', 'a' => 'Yes, up or down at any time from Billing. Changes prorate.'),
            array('q' => 'Are there payment processing fees on top?', 'a' => 'Stripe processing fees apply to card payments as with any platform; they are separate from the take rate.'),
        );
        $offers = array();
        foreach ($rows as $r) {
            if ($r['amount'] === null) { continue; }
            $offers[] = array('@type' => 'Offer', 'name' => $r['tier']['name'], 'price' => number_format($r['amount'] / 100, 2, '.', ''), 'priceCurrency' => 'USD', 'url' => SeoMeta::base() . '/pricing', 'availability' => 'https://schema.org/InStock');
        }
        $jsonld = array(
            array('@type' => 'Product', 'name' => Main::site_name() . ' plans', 'brand' => SeoMeta::org(), 'url' => SeoMeta::base() . '/pricing', 'offers' => $offers),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Pricing', 'url' => '/pricing'))),
        );
        $this->page('pricing', array('path' => '/pricing', 'title' => 'Pricing', 'description' => 'Three monthly plans. Every plan includes the whole platform; the take rate falls as you grow.', 'type' => 'product', 'jsonld' => $jsonld), array('rows' => $rows, 'faq' => $faq));
    }
```

- [ ] **Step 4: Write the view**

`app/views/pages/pricing.php`:
```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; ?>
<div class="pub-wrap--wide">
<p class="pub-eyebrow">Pricing</p>
<h1 class="pub-h1">Three plans. The fee falls as you grow.</h1>
<p class="pub-lead">Monthly, cancel anytime. Every plan includes the whole platform and unlimited fans, tiers and social connections. The rows below are the only things that differ.</p>

<div class="pub-plans">
<?php foreach ($rows as $r): $t = $r['tier']; ?>
    <div class="pub-plan<?php echo !empty($t['recommended']) ? ' pub-plan--featured' : ''; ?>">
        <div class="pub-plan__name"><?php echo $e($t['name']); ?></div>
        <p class="pub-plan__tag"><?php echo $e($t['tagline']); ?></p>
        <div class="pub-plan__price"><?php if ($r['amount'] !== null): ?>$<?php echo number_format($r['amount'] / 100, ($r['amount'] % 100 === 0) ? 0 : 2); ?> <small>/ month</small><?php else: ?><small>See plans in your account</small><?php endif; ?></div>
        <dl>
        <?php foreach (PlanTiers::ROWS as $row): ?>
            <div><dt><?php echo $e($row['label']); ?></dt><dd><?php echo $e(PlanTiers::fmt_limit($row['key'], $t['limits'][$row['key']] ?? 0)); ?></dd></div>
        <?php endforeach; ?>
        </dl>
    </div>
<?php endforeach; ?>
</div>
<p class="pub-note">Included on every plan: <?php echo implode(' · ', PlanTiers::INCLUDED); ?>. Stripe card-processing fees are separate from the take rate.</p>

<h2 class="pub-h2">Questions</h2>
<ul class="pub-faq"><?php foreach ($faq as $qa): ?><li><h3><?php echo $e($qa['q']); ?></h3><p><?php echo $e($qa['a']); ?></p></li><?php endforeach; ?></ul>

<div class="pub-cta"><span class="pub-cta__text">Pick a plan after you sign up. Nothing to pay until then.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
</div>
```

- [ ] **Step 5: Verify**

`php tests/seo_pricing_test.php` → all ok (dev Stripe test mode returns the three products). `php tests/seo_routes_test.php` ok. Browser: three cards, live prices, featured border on the recommended tier, no hard-coded numbers in the view (`grep -n '\$[0-9]' app/views/pages/pricing.php` returns nothing).

- [ ] **Step 6: Report** — `app/views/pages/pricing.php`, `app/controllers/PagesController.php`, `tests/seo_pricing_test.php`.

---

### Task 7: `/compare/fanvue` and `/compare/onlyfans`

**Files:**
- Modify: `app/controllers/PagesController.php` (`COMPETITORS`, `compareAction` jsonld), `app/views/pages/compare.php`

**Interfaces:**
- Produces: `PagesController::COMPETITORS[slug]` = `['name', 'url', 'checked' => 'YYYY-MM-DD', 'fee' => ['value' => string, 'source' => url], 'payout' => ['value','source'], 'content' => ['value','source'], 'ownership' => ['value','source'], 'socials' => ['value','source'], 'ai' => ['value','source'], 'summary' => string]`. Reused by Task 8.

- [ ] **Step 1: Verify competitor facts (do this before writing them)**

Open each source URL and confirm the values below on 2026-09-21. If a value differs, use what the page says; if a page no longer states it, set `value` to `'Not published'` and keep the source URL.

| | Fanvue | OnlyFans |
|---|---|---|
| Fee | 15% platform fee (20% for the first 3 months per public pricing) — source `https://www.fanvue.com/pricing` | 20% of creator earnings — source `https://onlyfans.com/help` (Creator earnings / payouts help article) |
| Payout | Bank transfer, Paxum; schedule per help center — source Fanvue help | Bank transfer, e-wallets; 7-day hold (varies by region) — source OnlyFans help |
| Content types | Posts, PPV, tips, DMs, AI-creator features — source Fanvue site | Posts, PPV, tips, DMs, live streams — source OnlyFans site |
| Cross-posting to socials | Not offered — source Fanvue features page | Not offered — source OnlyFans features |
| AI replies / captions | AI tools for chat per Fanvue site (state exactly what the page says) | Not offered |

- [ ] **Step 2: Fill `COMPETITORS`**

```php
    const COMPETITORS = array(
        'fanvue' => array(
            'name' => 'Fanvue', 'url' => 'https://www.fanvue.com', 'checked' => '2026-09-21',
            'summary' => 'Fanvue is a subscription and pay-per-view platform for creators with a focus on AI creators. It does not publish to your social accounts or give you a link-in-bio style page with services and events.',
            'fee'       => array('value' => '15% (20% for the first three months)', 'source' => 'https://www.fanvue.com/pricing'),
            'payout'    => array('value' => 'Bank transfer or Paxum', 'source' => 'https://help.fanvue.com'),
            'content'   => array('value' => 'Posts, pay-per-view, tips, messages', 'source' => 'https://www.fanvue.com'),
            'socials'   => array('value' => 'No cross-posting', 'source' => 'https://www.fanvue.com'),
            'ai'        => array('value' => 'AI chat assistance', 'source' => 'https://www.fanvue.com'),
            'ownership' => array('value' => 'Audience export not published', 'source' => 'https://help.fanvue.com'),
        ),
        'onlyfans' => array(
            'name' => 'OnlyFans', 'url' => 'https://onlyfans.com', 'checked' => '2026-09-21',
            'summary' => 'OnlyFans is the largest subscription platform for creators. It has the biggest audience and the strictest dependence on its own app: no cross-posting, no services or events, and a flat 20% fee at every size.',
            'fee'       => array('value' => '20% at every earnings level', 'source' => 'https://onlyfans.com/help'),
            'payout'    => array('value' => 'Bank transfer and e-wallets, after a holding period', 'source' => 'https://onlyfans.com/help'),
            'content'   => array('value' => 'Posts, pay-per-view, tips, messages, live streams', 'source' => 'https://onlyfans.com'),
            'socials'   => array('value' => 'No cross-posting', 'source' => 'https://onlyfans.com'),
            'ai'        => array('value' => 'Not offered', 'source' => 'https://onlyfans.com'),
            'ownership' => array('value' => 'Audience export not published', 'source' => 'https://onlyfans.com/help'),
        ),
    );
```
Update values to match what Step 1 found.

- [ ] **Step 3: Our side of the table comes from code**

Add to `PagesController`:
```php
    /** Our column of the comparison table, derived from PlanTiers so it can't drift. */
    public static function our_facts(): array {
        $tiers = PlanTiers::all(); $fees = array();
        foreach ($tiers as $t) { $fees[] = (int) $t['limits']['fee_percent']; }
        return array(
            'fee'       => min($fees) . '% to ' . max($fees) . '% by plan (falls as you grow)',
            'payout'    => 'Stripe Connect to your bank account',
            'content'   => 'Posts, pay-per-view, bundles, memberships with tiers, services, events, links',
            'socials'   => 'Publishes to 9 social networks from one studio',
            'ai'        => 'AI captions, AI inbox replies, AI influencers',
            'ownership' => 'Export your audience and media any time',
        );
    }
```

- [ ] **Step 4: The view**

`app/views/pages/compare.php`:
```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $us = PagesController::our_facts(); $site = Main::site_name();
$rows = array('fee' => 'Platform fee', 'payout' => 'Payouts', 'content' => 'What you can sell', 'socials' => 'Social publishing', 'ai' => 'AI tools', 'ownership' => 'Your audience'); ?>
<p class="pub-eyebrow"><?php echo $e($c['name']); ?> alternative</p>
<h1 class="pub-h1"><?php echo $e($site); ?> vs <?php echo $e($c['name']); ?></h1>
<p class="pub-lead"><?php echo $e($c['summary']); ?> <?php echo $e($site); ?> is built for creators who want one page for everything they sell and a studio that publishes everywhere. Here is how the two compare, with sources.</p>

<table class="pub-table pub-table--compare">
    <thead><tr><th></th><th><?php echo $e($site); ?></th><th><?php echo $e($c['name']); ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $k => $label): ?>
        <tr><td><?php echo $e($label); ?></td><td><?php echo $e($us[$k]); ?></td><td><?php echo $e($c[$k]['value']); ?> <a class="pub-src" href="<?php echo $e($c[$k]['source']); ?>" rel="nofollow noopener" target="_blank">source</a></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="pub-note"><?php echo $e($c['name']); ?> details checked on <?php echo $e(date('F j, Y', strtotime($c['checked']))); ?> from the linked pages. If something has changed, tell us and we will update it.</p>

<h2 class="pub-h2">When <?php echo $e($c['name']); ?> is the better fit</h2>
<p class="pub-p"><?php echo $c['name'] === 'OnlyFans' ? 'If most of your audience already pays on OnlyFans and you do not plan to sell services, events or bundles, staying there avoids moving anyone.' : 'If you only sell subscriptions and messages and want the lowest setup effort, Fanvue does that well.'; ?></p>

<h2 class="pub-h2">When <?php echo $e($site); ?> is the better fit</h2>
<ul class="pub-list">
    <li>You post to several social networks and want one studio that publishes to all of them and reports engagement back.</li>
    <li>You sell more than subscriptions: pay-per-view posts, bundles, services, events and tracked links from one page.</li>
    <li>You want AI to draft captions and DM replies in your voice, with your approval.</li>
    <li>You want a fee that falls as you grow rather than a flat rate. See <a href="/pricing">pricing</a>.</li>
</ul>

<h2 class="pub-h2">Moving over</h2>
<p class="pub-p">Keep your <?php echo $e($c['name']); ?> page live while you set up. Publish to both from the studio, put your new page in every bio, and let fans move at their own pace. Memberships and pay-per-view work from day one; payouts start as soon as Stripe finishes verifying you.</p>

<div class="pub-cta"><span class="pub-cta__text">Try it alongside <?php echo $e($c['name']); ?>.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
```
Add to `public/css/public.css`: `.pub-src{ font-size:.78rem; color:var(--ld-muted); margin-left:6px; }`.

- [ ] **Step 5: JSON-LD in `compareAction`**

Replace the `$this->page(...)` call in `compareAction` with:
```php
        $path = '/compare/' . $slug; $title = Main::site_name() . ' vs ' . $c['name'];
        $desc = 'A ' . $c['name'] . ' alternative for creators: fees, content types, payouts and ownership compared, with sources.';
        $jsonld = array(
            SeoMeta::article(array('headline' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $path, 'published' => '2026-09-21T00:00:00+00:00', 'modified' => gmdate('c', filemtime(Main::app_path() . '/app/controllers/PagesController.php')))),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Compare', 'url' => '/compare/' . $slug), array('name' => $c['name'], 'url' => $path))),
        );
        $this->page('compare', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld), array('slug' => $slug, 'c' => $c));
```

- [ ] **Step 6: Verify**

`php -l`, `php tests/seo_routes_test.php`. Browser: both compare pages render the table with source links opening in a new tab; `/compare/nope` is 404. Every competitor cell has a source link (grep the rendered HTML for `pub-src` count = 6 per page).

- [ ] **Step 7: Report** — `PagesController.php`, `compare.php`, `public.css`, plus the fact-check outcome from Step 1.

---

### Task 8: `/best-creator-monetization-platforms` and `/monetize-your-content`

**Files:**
- Modify: `app/views/pages/best-platforms.php`, `app/views/pages/monetize.php`, `PagesController.php` (both actions' jsonld)

**Interfaces:**
- Consumes: `PagesController::COMPETITORS`, `PagesController::our_facts()`, `SeoMeta::article/faq/breadcrumbs`, `SeoMeta::internal_links()`.

- [ ] **Step 1: Best-of view**

`app/views/pages/best-platforms.php`:
```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $us = PagesController::our_facts(); $site = Main::site_name(); ?>
<p class="pub-eyebrow">Guide</p>
<h1 class="pub-h1">Best creator monetization platforms in <?php echo date('Y'); ?></h1>
<p class="pub-lead">There is no single best platform; there is the best fit for how you sell. This guide compares the main options on fee, what you can sell, payouts, social publishing and ownership. We build <?php echo $e($site); ?>, and we say so where it matters.</p>

<h2 class="pub-h2">How to judge a platform</h2>
<ul class="pub-list">
    <li><strong>Fee.</strong> Flat 20% is the norm. A fee that falls as you grow matters once you pass a few thousand a month.</li>
    <li><strong>What you can sell.</strong> Subscriptions only, or also pay-per-view, bundles, services and events.</li>
    <li><strong>Where your fans are.</strong> A platform that publishes to your socials brings people in; one that doesn't makes you do it by hand.</li>
    <li><strong>Ownership.</strong> Can you export your audience and media if you leave?</li>
    <li><strong>Payout path.</strong> Stripe, bank, e-wallet, and how long the hold is.</li>
</ul>

<h2 class="pub-h2"><?php echo $e($site); ?></h2>
<p class="pub-p">One public page with memberships, pay-per-view, bundles, services, events and links, plus a studio that publishes to nine social networks and an inbox with AI replies. <?php echo $e($us['fee']); ?>. <?php echo $e($us['payout']); ?>. <a href="/features">See the features</a> and <a href="/pricing">pricing</a>.</p>

<?php foreach (PagesController::COMPETITORS as $slug => $c): ?>
<h2 class="pub-h2"><?php echo $e($c['name']); ?></h2>
<p class="pub-p"><?php echo $e($c['summary']); ?> Fee: <?php echo $e($c['fee']['value']); ?>. Payouts: <?php echo $e($c['payout']['value']); ?>. <a href="/compare/<?php echo $e($slug); ?>">Full comparison with sources</a>.</p>
<?php endforeach; ?>

<h2 class="pub-h2">Patreon, Ko-fi and Buy Me a Coffee</h2>
<p class="pub-p">Good for tips and simple memberships around a podcast, newsletter or open-source project. None of them sell pay-per-view posts, services or events, and none publish to your social accounts. Fees are published on their pricing pages and range from a few percent to around 12% plus processing.</p>

<h2 class="pub-h2">Which one</h2>
<ul class="pub-list">
    <li>Subscriptions and messages only, largest existing audience: OnlyFans.</li>
    <li>Subscriptions with AI-creator features: Fanvue.</li>
    <li>Tips and light memberships for a creative project: Patreon or Ko-fi.</li>
    <li>Everything you sell on one page, published to all your socials, fee that falls as you grow: <?php echo $e($site); ?>.</li>
</ul>

<div class="pub-cta"><span class="pub-cta__text">See it with your own page.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
```

- [ ] **Step 2: Monetize guide view**

`app/views/pages/monetize.php`:
```php
<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $site = Main::site_name(); ?>
<p class="pub-eyebrow">Guide</p>
<h1 class="pub-h1">How to monetize your content</h1>
<p class="pub-lead">Five ways creators get paid, what each one is good for, and how to price it. Everything here works on <?php echo $e($site); ?>, and most of it works anywhere.</p>

<h2 class="pub-h2" id="memberships">1. Memberships</h2>
<p class="pub-p">A monthly price for ongoing access. Start with two tiers, not five: an entry tier priced where a fan says yes without thinking, and a higher tier for the people who want more of you. Put most posts on the entry tier and save a few for the top. Raise prices for new members only.</p>
<h2 class="pub-h2" id="pay-per-view">2. Pay-per-view posts</h2>
<p class="pub-p">One post, one price, for everyone including members. Best for your strongest single pieces. Price by effort and scarcity, not length, and never lower than your entry tier's monthly price divided by four; otherwise members feel penalised.</p>
<h2 class="pub-h2" id="bundles">3. Bundles</h2>
<p class="pub-p">Group past media into a set at a discount to the sum of the parts. Bundles turn your back catalogue into a product and are the easiest upsell after someone unlocks a single post.</p>
<h2 class="pub-h2" id="services">4. Services</h2>
<p class="pub-p">Custom content, shout-outs, coaching, reviews. Fixed price, clear scope, a delivery window you can keep. Services are where a small audience earns the most per fan.</p>
<h2 class="pub-h2" id="events">5. Events</h2>
<p class="pub-p">Live sessions, Q&amp;As, watch parties, workshops. Sell seats ahead of time, cap the room, and record it for a bundle afterwards.</p>

<h2 class="pub-h2">Pricing in one paragraph</h2>
<p class="pub-p">Entry tier: the price of a coffee where most of your fans live. Top tier: three to five times that. Pay-per-view: a quarter of the entry tier or more. Services: your hourly worth times the time it really takes, then add a third. Start higher than feels comfortable; you can add a discount code, you cannot easily raise a price.</p>

<h2 class="pub-h2">Getting fans to the page</h2>
<p class="pub-p">Publish everywhere and point back to one place. A studio that posts to your socials and your page at once, with the paid version behind the lock, does the promotion for you every time you publish. See how <a href="/features">the studio</a> handles it.</p>

<h2 class="pub-h2">Questions</h2>
<ul class="pub-faq"><?php foreach ($faq as $qa): ?><li><h3><?php echo $e($qa['q']); ?></h3><p><?php echo $e($qa['a']); ?></p></li><?php endforeach; ?></ul>

<div class="pub-cta"><span class="pub-cta__text">Set up your tiers in an afternoon.</span><a class="ld-btn ld-btn--primary" href="/?auth=register">Create Your Account</a></div>
```

- [ ] **Step 3: Controller schema for both**

Replace `bestPlatformsAction` and `monetizeAction`:
```php
    public function bestPlatformsAction(){
        $path = '/best-creator-monetization-platforms'; $title = 'Best creator monetization platforms in ' . date('Y');
        $desc = 'How the main creator platforms compare on fees, what you can sell, payouts, social publishing and ownership, with sources.';
        $items = array(array('@type' => 'ListItem', 'position' => 1, 'name' => Main::site_name(), 'url' => SeoMeta::base() . '/features'));
        $pos = 2; foreach (self::COMPETITORS as $slug => $c) { $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $c['name'], 'url' => SeoMeta::base() . '/compare/' . $slug); }
        $jsonld = array(
            SeoMeta::article(array('headline' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $path, 'published' => '2026-09-21T00:00:00+00:00')),
            array('@type' => 'ItemList', 'name' => $title, 'itemListElement' => $items),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Best creator monetization platforms', 'url' => $path))),
        );
        $this->page('best-platforms', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld));
    }

    public function monetizeAction(){
        $path = '/monetize-your-content'; $title = 'How to monetize your content';
        $desc = 'Memberships, pay-per-view, bundles, services and events: the five ways creators get paid, and how to price each one.';
        $faq = array(
            array('q' => 'How many membership tiers should I have?', 'a' => 'Two to start: an easy entry tier and one higher tier. Add a third only when fans ask for something in between.'),
            array('q' => 'Should members get pay-per-view posts free?', 'a' => 'No. Pay-per-view is for your strongest single pieces and is priced for everyone. Put your regular content on the tiers.'),
            array('q' => 'How do I price a service?', 'a' => 'Your hourly worth times the real time it takes, plus a third for revisions and messages. Fixed price, fixed scope, a delivery window you can keep.'),
            array('q' => 'Can I run all five on one page?', 'a' => 'Yes. On ' . Main::site_name() . ' memberships, pay-per-view, bundles, services and events all live on your public page and share one wallet for fans.'),
        );
        $jsonld = array(
            SeoMeta::article(array('headline' => $title, 'description' => $desc, 'url' => SeoMeta::base() . $path, 'published' => '2026-09-21T00:00:00+00:00')),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => 'Monetize your content', 'url' => $path))),
        );
        $this->page('monetize', array('path' => $path, 'title' => $title, 'description' => $desc, 'type' => 'article', 'jsonld' => $jsonld), array('faq' => $faq));
    }
```

- [ ] **Step 4: Verify**

`php -l` all; `php tests/seo_routes_test.php` all ok. Both pages in browser at desktop and 390px; anchors `#memberships`, `#pay-per-view` scroll correctly (they're the targets in `SeoMeta::internal_links()`). Paste each page source into https://validator.schema.org/ : Article + FAQPage (+ ItemList) with no errors.

- [ ] **Step 5: Report** — the two views and `PagesController.php`.

---

### Task 9: Sitemap, robots, `llms.txt`, `llms-full.txt`

**Files:**
- Modify: `app/controllers/SeoController.php`, `app/Bootstrap.php:40-45`
- Delete: `public/llms.txt` (replaced by the generated route; the static file would shadow it via `.htaccess` `!-f`)
- Test: `tests/seo_crawl_files_test.php`

**Interfaces:**
- Produces: `SeoController::public_pages(): array` — `[['path','title','description','changefreq','priority'], …]` for the six pages, read by the sitemap, llms and (later) the content engine.
- Produces: `SeoController::llmsAction()`, `SeoController::llmsFullAction()`.

- [ ] **Step 1: Failing test**

`tests/seo_crawl_files_test.php`:
```php
<?php
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
function get($u) { $ch = curl_init($u); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15)); $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $t = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch); return array($c, $b, $t); }
$fail = 0; function check($l, $c) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $l . "\n"; if (!$c) { $fail++; } }
list($c, $b) = get("$base/sitemap.xml");
foreach (array('/features', '/pricing', '/compare/fanvue', '/compare/onlyfans', '/best-creator-monetization-platforms', '/monetize-your-content') as $p) { check("sitemap has $p", strpos($b, "<loc>$base$p</loc>") !== false); }
list($c, $b) = get("$base/robots.txt");
check('robots allows llms-full', strpos($b, 'Allow: /llms-full.txt') !== false);
check('robots still disallows /admin', strpos($b, 'Disallow: /admin') !== false);
list($c, $b, $t) = get("$base/llms.txt");
check('llms.txt 200 text/plain', $c === 200 && strpos($t, 'text/plain') !== false);
check('llms.txt lists pricing', strpos($b, "$base/pricing") !== false);
list($c, $b, $t) = get("$base/llms-full.txt");
check('llms-full 200 text/plain', $c === 200 && strpos($t, 'text/plain') !== false);
check('llms-full has features heading', strpos($b, '# Features') !== false || strpos($b, '## Features') !== false);
check('llms-full under 2MB', strlen($b) < 2 * 1024 * 1024);
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run** → sitemap and llms checks FAIL.

- [ ] **Step 3: Implement in `SeoController`**

Add to the class:
```php
    /** The hand-written public pages. Sitemap, llms.txt and the content engine read this list. */
    public static function public_pages(): array {
        return array(
            array('path' => '/features',                            'title' => 'Features',                              'description' => 'One creator platform for your public page, memberships, pay-per-view, events, services, links, cross-posting and payouts.', 'changefreq' => 'monthly', 'priority' => '0.9'),
            array('path' => '/pricing',                             'title' => 'Pricing',                               'description' => 'Three monthly plans; the take rate falls as you grow.',                                                                       'changefreq' => 'monthly', 'priority' => '0.9'),
            array('path' => '/compare/fanvue',                      'title' => 'Creator Link Studio vs Fanvue',         'description' => 'A Fanvue alternative for creators, compared with sources.',                                                                 'changefreq' => 'monthly', 'priority' => '0.8'),
            array('path' => '/compare/onlyfans',                    'title' => 'Creator Link Studio vs OnlyFans',       'description' => 'An OnlyFans alternative for creators, compared with sources.',                                                              'changefreq' => 'monthly', 'priority' => '0.8'),
            array('path' => '/best-creator-monetization-platforms', 'title' => 'Best creator monetization platforms',   'description' => 'How the main creator platforms compare on fees, what you can sell, payouts and ownership.',                                  'changefreq' => 'monthly', 'priority' => '0.8'),
            array('path' => '/monetize-your-content',               'title' => 'How to monetize your content',          'description' => 'Memberships, pay-per-view, bundles, services and events, and how to price each.',                                            'changefreq' => 'monthly', 'priority' => '0.8'),
        );
    }

    /** Short machine-readable index for LLM crawlers (llmstxt.org). */
    public function llmsAction(){
        $base = Main::get_base_domain(); $site = Main::site_name();
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: public, max-age=3600');
        $l = array();
        $l[] = '# ' . $site;
        $l[] = '';
        $l[] = '> ' . $site . ' is a creator platform: one public page with memberships, pay-per-view posts, bundles, services, events and tracked links, a studio that publishes to nine social networks with AI captions, an inbox with AI replies, and Stripe payouts. Plans are monthly; the platform take rate falls as the plan grows.';
        $l[] = '';
        $l[] = '## Product';
        foreach (self::public_pages() as $p) { $l[] = '- [' . $p['title'] . '](' . $base . $p['path'] . '): ' . $p['description']; }
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
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: public, max-age=3600');
        $out = array('# ' . $site . ' — full text', '', 'Source: ' . $base . '/llms.txt', '');
        foreach (self::public_pages() as $p) {
            $html = self::render_public_html($p['path']);
            if ($html === '') { continue; }
            $out[] = '## ' . $p['title'];
            $out[] = 'URL: ' . $base . $p['path'];
            $out[] = '';
            $out[] = self::html_to_text($html);
            $out[] = '';
        }
        $text = implode("\n", $out);
        if (strlen($text) > 2 * 1024 * 1024) { $text = substr($text, 0, 2 * 1024 * 1024); }
        echo $text, "\n";
    }

    /** Render one public page through PagesController into a string (output-buffered). */
    private static function render_public_html($path): string {
        $seg = array_values(array_filter(explode('/', trim((string) $path, '/'))));
        $method = PagesController::ROUTES[$seg[0]] ?? '';
        if ($method === '') { return ''; }
        $saved = $_GET; $saved_url = $_GET['url'] ?? null;
        $_GET['url'] = implode('/', $seg);   // Main::get_url() reads this
        ob_start();
        try { (new PagesController())->{$method . 'Action'}(); } catch (\Throwable $e) { error_log('[seo] llms-full render ' . $path . ': ' . $e->getMessage()); }
        $html = (string) ob_get_clean();
        $_GET = $saved; if ($saved_url !== null) { $_GET['url'] = $saved_url; }
        return $html;
    }

    /** <main> contents → plain text with headings as Markdown. */
    private static function html_to_text($html): string {
        if (preg_match('/<main[^>]*>(.*)<\/main>/is', $html, $m)) { $html = $m[1]; }
        $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n# $1\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n## $1\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n### $1\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "\n- ", $html);
        $html = preg_replace('/<\/(p|li|tr|div)>/i', "\n", $html);
        $html = preg_replace('/<\/t[dh]>/i', " | ", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }
```
Check how `Main::get_url()` reads the path (`grep -n "function get_url" -A6 libs/Classes/Main.php`); if it reads `$_GET['url']` the render helper works as written, otherwise adapt the assignment to whatever it reads.

Update `robotsAction`: after `Allow: /llms.txt` add `$lines[] = 'Allow: /llms-full.txt';`. Update `sitemapAction`: after the landing `$urls[]` line add
```php
        foreach (self::public_pages() as $p) {
            $urls[] = array('loc' => $base . $p['path'], 'changefreq' => $p['changefreq'], 'priority' => $p['priority'],
                            'lastmod' => gmdate('Y-m-d', filemtime(Main::app_path() . '/app/controllers/PagesController.php')));
        }
```

- [ ] **Step 4: Route the two text files in Bootstrap**

Replace the robots/sitemap block in `app/Bootstrap.php` with:
```php
        // Crawler endpoints: generated so absolute URLs match the served host.
        $crawl = array('robots.txt' => 'robotsAction', 'sitemap.xml' => 'sitemapAction', 'llms.txt' => 'llmsAction', 'llms-full.txt' => 'llmsFullAction');
        if (isset($url[0]) && isset($crawl[$url[0]])) {
            (new SeoController())->{$crawl[$url[0]]}();
            return;
        }
```
Delete `public/llms.txt` (`git rm` is Daniel's; just delete the file and list it in the report) so `.htaccess`'s `!-f` condition doesn't serve the stale static copy.

- [ ] **Step 5: Verify**

`php -l` both files. `php tests/seo_crawl_files_test.php` → all ok. `curl -s http://framework.contentos.cvk/llms-full.txt | head -40` reads as clean Markdown-ish text with the Features heading and no HTML tags. `curl -s http://framework.contentos.cvk/sitemap.xml | xmllint --noout -` prints nothing (valid XML).

- [ ] **Step 6: Report** — `SeoController.php`, `Bootstrap.php`, deleted `public/llms.txt`, new test. Remind Daniel: after deploy, resubmit the sitemap in Search Console (Indexing → Sitemaps) and request indexing for the six URLs via URL Inspection.

---

### Task 10: Final verification and hand-off

**Files:** none new.

- [ ] **Step 1: Run every test**

```bash
for t in tests/seo_profile_jsonld_test.php tests/seo_meta_test.php tests/seo_pricing_test.php tests/seo_routes_test.php tests/seo_crawl_files_test.php; do echo "== $t"; APPLICATION_ENV=development /opt/homebrew/opt/php@8.2/bin/php $t || echo "FAILED $t"; done
```
Expected: every line `ok`, no `FAILED`.

- [ ] **Step 2: Lighthouse on `/features` and `/` (dev, mobile)**

Same command as Task 2 Step 6 for `http://framework.contentos.cvk/features`. Expected: performance ≥ 90, SEO 100, accessibility ≥ 95, best practices ≥ 95. Record the four scores for both URLs.

- [ ] **Step 3: Screenshots for Daniel**

Desktop and 390px screenshots of `/features`, `/pricing`, `/compare/fanvue`, saved to the scratchpad and attached to the report. Self-critique against the landing page: same type, one accent, no stray colors, Title Case buttons, no emoji.

- [ ] **Step 4: Report**

One message: files changed (full list), test output summary, Lighthouse before/after, the Apache block for Daniel (Task 2 Step 5), the Search Console follow-ups (Validate Fix for Profile page; resubmit sitemap; request indexing for six URLs), and the fact-check outcome for competitor rows.
