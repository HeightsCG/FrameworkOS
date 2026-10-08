# Technical SEO — www.creatorlinkstudio.com (production, audited 2026-10-08)

## Technical SEO — summary

The crawl plumbing is in good shape: robots.txt is sane and points at a valid sitemap, all 42 sitemap URLs but one return 200 with a self-referencing canonical and `index, follow`, trailing-slash / query-string / case variants of handles all canonicalise correctly, 404s are real 404s with `noindex`, HTML and assets are gzip'd, static assets carry 1-year cache headers with ETags, and every public page has one H1, viewport, `lang="en"`, OG/Twitter tags with a reachable 1200x630 og:image, and parseable JSON-LD. Lighthouse mobile gives SEO 100 / Accessibility 92–100 on all four pages tested.

The gaps are (1) a sitemap entry that 302s off-site (`/@lexivaughn` → `lexivaughn.com`), (2) the creator profile surface is thin and partly un-indexable: profiles have 54–70 words, the sitemap holds only 4 and the directory only 2 creators, and post/feed thumbnails are signed S3 URLs that expire in 1 hour (so the home page's own images cannot be indexed or cached), (3) lab LCP of 5.4–6.3 s on mobile for every page type (text LCP blocked by CSS/font, and a 183 KB un-optimised JPEG on blog articles), (4) no HSTS / `X-Content-Type-Options`, a double redirect chain on `http://apex`, and `Location` headers that carry `:443`, (5) `Organization.sameAs` is empty everywhere and blog articles have an Organization author instead of a Person, and (6) third-party cookies (Microsoft Clarity/Bing) on every public page, which Lighthouse Best Practices flags (77).

## Findings

**[P0] Sitemap lists a URL that redirects off-site** — `https://www.creatorlinkstudio.com/@lexivaughn` returns `302 → https://lexivaughn.com/` (custom-domain creator). Sitemaps must only contain final, 200, self-canonical URLs; Google reports this as "Redirect" in Coverage and it dilutes trust in the whole file. Evidence: `curl -I /@lexivaughn` → 302; `UsersModel::list_public_creators()` (`app/models/UsersModel.php:119`) has no custom-domain exclusion, and `SeoController::sitemapAction()` (`app/controllers/SeoController.php:50-58`) emits every paid creator. Fix: exclude creators whose `CustomDomains::canonical_profile_url()` is off-host (or emit their custom-domain URL only in that domain's own sitemap, which already exists at `https://lexivaughn.com/sitemap.xml`). ~1 h.

**[P0] Home page and profile images use signed S3 URLs that expire in 1 hour** — `/` has 5 `<img>` tags, all pointing at `content-os-bucket.s3…/vault/29/media/551/thumb.jpg?X-Amz-Expires=3600&X-Amz-Signature=…`; `/@johnturnstile` has 81 signed URLs. Every crawl sees a different URL, Google Images cannot index them, the browser cannot cache them across visits, the OG/Article image can never point at them, and the home page ships 5 images with empty `alt`. Lighthouse also flags the 60 KB home thumbnail as 37 KB oversized. Fix: serve public (SFW, published) thumbnails from the public `creator/*` prefix (already public-read per S3Service) with stable URLs, 16:9/1:1 WebP at display size, and real `alt` from the post title; keep signing for gated media only. 1–2 days.

**[P1] Mobile LCP 5.4–6.3 s on every page type (Lighthouse mobile, simulated 4G)** — home 5.4 s (perf 79), /pricing 5.4 s (78), /blog/fanvue-alternative 6.3 s (76), /@summerlane 6.2 s (70). FCP is fine (1.0–1.3 s), CLS ≈ 0, TBT ≤ 70 ms, TTFB 100–170 ms, so this is render-path, not server. Causes seen in the traces:
- LCP element on home/pricing/profile is a text paragraph (`p.hx__lead`, `p.sx-hero__lead`, `p.pf-empty__text`) delayed by render-blocking CSS (`sx.css` 12.4 KB gz + `landing.css` 10.5 KB gz + `public.css` 5.2 KB gz; Lighthouse "Est savings 140–190 ms") and the Inter variable font swap.
- Blog article LCP is the cover `<img class="gd-posthead__img">`: 1024x768 JPEG, 183 KB, no `srcset`, no WebP, no `fetchpriority="high"`; Lighthouse "image-delivery: Est savings 165 KB".
- Profile page loads `jquery.min.js`, `api.data.js`, `csrf-retry.js` as plain blocking `<script src>` in `<head>` (home/public pages use `defer`), plus Font Awesome 6.5.1 CSS from cdnjs (18.7 KB unused, two webfonts without `font-display`, "Est savings 160 ms") and FCP is 3.3 s.
- 32 KB of Bootstrap CSS is unused on every page (loaded via the print-media trick, so not blocking, but still 32 KB).
- gtag.js 74 KB unused JS on every page.
Fix: inline the above-the-fold CSS for the hero (or split `sx.css`), `defer` the three profile scripts, drop Font Awesome from the public profile (inline the ≤10 SVG icons used), serve blog covers as WebP ≤ 80 KB with `width/height` + `fetchpriority="high"` and `srcset`. Expect LCP ≈ 2.5–3 s and Performance 90+. 1–2 days.

**[P1] Security/edge headers missing: no HSTS, no `X-Content-Type-Options`, duplicated `X-Frame-Options`** — Every response: no `strict-transport-security`, no `x-content-type-options: nosniff`, and `x-frame-options: SAMEORIGIN, SAMEORIGIN` (sent twice). HSTS is also what lets Chrome skip the `http://` hop. Lighthouse Best Practices 77 on all pages (the deduction is third-party cookies + inspector issues, but headers are part of the same checklist). Fix in the Apache vhost (Daniel's domain — do not touch from the app): `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"`, `Header always set X-Content-Type-Options nosniff`, remove the duplicate XFO. ~30 min, after validating config.

**[P1] Redirect chain and `:443` in `Location`** — `http://creatorlinkstudio.com/` → `301 https://creatorlinkstudio.com:443/` → `301 https://www.creatorlinkstudio.com:443/` → 200 (two hops). `http://www…/pricing` → `301 https://www.creatorlinkstudio.com:443/pricing`. The explicit `:443` is technically valid but every link checker and some crawlers report it as a non-canonical host, and the chain costs a round-trip on every typed/old link. Fix: one rule `http://(apex|www)` and `https://apex` → `https://www.creatorlinkstudio.com$1` using `%{HTTP_HOST}` literal without `%{SERVER_PORT}`. ~30 min.

**[P1] `/index.php` and `/index.php/<anything>` are 200 copies of the home page** — `/index.php` → 200, 55,681 bytes, canonical `/`; `/index.php/pricing` → 200 and ALSO renders the home page with canonical `/` (not the pricing page). `/studio` (disallowed in robots) also renders the home page with 200 and canonical `/`. Canonical saves the index, but these are crawlable duplicate entry points, and `/index.php/pricing` is misleading. Fix: 301 `/index.php(/.*)?` → `/` (or the path) at the vhost; make `/studio` logged-out return a 302 to `/` like `/dashboard` does. ~1 h.

**[P1] Organization `sameAs` is empty on every page (confirmed)** — The Organization node in the JSON-LD on `/`, `/features`, `/pricing`, all compare pages, blog and legal pages has no `sameAs`; there is no `publisher.sameAs` on Article either. Google uses it to connect the brand entity to social profiles for the Knowledge Panel. Fix: add the real brand profiles (X, Instagram, TikTok, YouTube, LinkedIn, Crunchbase) in `SeoMeta` Organization builder once; the memory note says sameAs was "added 2026-09-25" but production shows none, so either the list is empty in config or the deploy did not carry it. ~1 h.

**[P1] Creator profiles are thin and barely linked** — `/@summerlane` = 54 words, 1 internal link, every panel is an empty-state ("When Summer Lane shares something, it'll show up here", "hasn't set up membership tiers"), generic og:image (the brand card, not the creator), `twitter:card=summary_large_image` with that brand card; `/@danglauber` = 70 words, bio "this is my bio page". `/creators` lists 2 creators, `/creators/fitness` 1 ("Browse 1 fitness creator"), and the sitemap lists only 4 creator URLs. Google will index these as doorway-like thin pages or not at all. Fix (product + SEO): (a) only sitemap/list profiles with ≥1 published post or ≥1 tier/service and a bio ≥ 40 words; (b) render the latest N public post titles/excerpts, tier names/prices and services server-side (the ProfilePage/Person JSON-LD already exists); (c) use the creator avatar as og:image at 1200x630 (currently 512x512 avatar on Dan/Demo, brand card on Summer). 1–2 days.

**[P1] Profile sub-paths are 200 duplicates** — `/@summerlane/events`, `/services`, `/posts`, `/links`, `?tab=services` all return 200 with identical title and canonical `/@summerlane`. Canonical handles it, but `/events` and `/services` for a creator are exactly the pages fans search for ("<creator> events"); they deserve their own canonical, title and ItemList/Event/Service JSON-LD, or should 301 to the tab anchor. Fix: either make them real pages (own canonical/title/H1) or 301 to `/@handle#tab`. ~0.5 day.

**[P2] Blog Article author is an Organization, no Person, no `publisher.logo` check** — `Article.author = {"@type":"Organization","name":"Creator Link Studio"}` on all articles and `name: "Creator Link Studio team"` on compare pages. Valid, but Google's helpful-content and E-E-A-T signals favour a named Person with `url`/`sameAs`; `datePublished`/`dateModified`/`image` are present and ISO. Fix: add a Person author (founder) with a `/about` or profile URL. ~2 h.

**[P2] Pricing meta description is 184 chars, privacy 87** — `/pricing` description truncates at ~155 in SERPs ("…Studio lowers the take"). Others are 99–155 chars and all unique (no duplicates across the 21 pages checked). Fix: trim pricing to ≤155. ~10 min.

**[P2] Blog listing and feed cap at 17 because only 17 articles are `status='published'`** — Brief says 40 articles; `/blog`, `/blog/feed.xml` (20 max) and the sitemap (500 max) all agree on 17, lastmod spread 2026-09-22…10-08 (one per day; 4 updated today). Nothing is lost, but if the other ~23 exist as drafts the daily engine is not publishing them. Verify `seo_articles.status` counts. 30 min.

**[P2] Static-page `lastmod` is the mtime of `PagesController.php`** — All 21 static pages show `2026-09-28` because `SeoController.php:48` uses `filemtime(PagesController.php)`; the home URL has no lastmod. Every deploy that touches the controller will bump all of them, which trains Google to ignore the signal. Fix: per-page constant date (or omit lastmod on static pages). ~30 min.

**[P2] Third-party cookies / Clarity on every public page** — Lighthouse `third-party-cookies` lists 8 cookies (CLID, SM, MUID from clarity.ms / bing.com) and `inspector-issues` on all 4 pages → Best Practices 77. Not a ranking factor, but Chrome's third-party-cookie phase-out makes Clarity session replay progressively blind, and the EU cookie-consent angle is unhandled. Decide whether Clarity is worth it on public pages; if yes, load it after consent / after interaction. ~2 h.

**[P2] `/compare` (index) 404s** — the brief and internal convention call it `/compare`, but the hub lives at `/best-creator-monetization-platforms`; `/compare` and `/compare/` return 404. Add a 301 `/compare` → `/best-creator-monetization-platforms` so typed/shared links and any external mention resolve. ~10 min.

**[P2] Uppercase paths 404 rather than redirect** — `/Pricing`, `/COMPARE/FANVUE` → 404 (handles are case-insensitive: `/@DanGlauber` → 200 canonical lowercase). Fine for Google; a `RewriteMap tolower` 301 would recover mistyped links. Low priority.

**[P2] Profile page accessibility** — `/@summerlane` Lighthouse a11y 92: `color-contrast` on `.pf-btn span` and `.pf-tab`, and `landmark-one-main` (no `<main>` on the profile layout; `grep '<main'` = 0). Heading jump h1→h3 on `/@johnturnstile`. ~1 h.

**[P2] CSS not minified** — `landing.css` 42 KB raw / 10 KB gz, `sx.css` 63 KB / 12 KB gz, `profile.css` 50 KB / 11 KB gz; Lighthouse "unminified-css ~5 KB". Minify at deploy. ~1 h.

## Per-page table (production, 2026-10-08)

| Page | Status | Title (len) | Desc len | Canonical | Robots | H1 | OG image | JSON-LD | Words | Int. links | Imgs / no-alt |
|---|---|---|---|---|---|---|---|---|---|---|---|
| / | 200 | Creator Link Studio: Creator Monetization Platform (50) | 113 | self | (none = index) | 1 "Create.Share.Earn." | brand 1200x630 ok | WebSite, Organization, SoftwareApplication+3 Offers, FAQPage(5), WebPage | 921 | 42 | 5 / 5 (signed S3) |
| /features | 200 | Features: Memberships, Pay-Per-View and AI Tools for Creators (61) | 121 | self | index | 1 | ok | Organization, SoftwareApplication, FAQPage(5), BreadcrumbList(2) | 958 | 35 | 0 |
| /features/character-generation | 200 | AI character generation · Creator Link Studio (45) | 119 | self | index | 1 | ok | FAQPage(5), BreadcrumbList(3) | 749 | 39 | 0 |
| /features/dm-agent | 200 | AI DM agent for your inbox · Creator Link Studio (48) | 118 | self | index | 1 | ok | FAQPage(4), BreadcrumbList(3) | 650 | 39 | 0 |
| /features/payouts | 200 | Creator payouts · Creator Link Studio (37) | 106 | self | index | 1 | ok | FAQPage(4), BreadcrumbList(3) | 623 | 39 | 0 |
| /pricing | 200 | Pricing: Free $0, Creator $49, Studio $199 per Month (52) | **184** | self | index | 1 | ok | Organization, SoftwareApplication+Offers, FAQPage(6), BreadcrumbList(2) | 891 | 35 | 0 |
| /best-creator-monetization-platforms | 200 | Best Creator Monetization Platforms (2026) · Creator Link Studio (64) | 122 | self | index | 1 | ok | Article, ItemList, FAQPage(5), BreadcrumbList, Organization | 1136 | 53 | 0 |
| /compare/fanvue | 200 | Creator Link Studio vs Fanvue: Fees and Features Compared (57) | 101 | self | index | 1 | ok | Article, FAQPage(4), BreadcrumbList, Organization | 678 | 35 (7 ext, all nofollow) | 0 |
| /compare/onlyfans | 200 | … vs OnlyFans: Fees and Features Compared (59) | 104 | self | index | 1 | ok | same | 697 | 35 (7 ext nofollow) | 0 |
| /compare/patreon | 200 | … vs Patreon: Fees and Features Compared (58) | 102 | self | index | 1 | ok | same | 817 | 35 (7 ext nofollow) | 0 |
| /monetize-your-content | 200 | How to monetize your content · Creator Link Studio (50) | 116 | self | index | 1 | ok | Article, FAQPage(4), BreadcrumbList, Organization | 723 | 36 | 1 / 1 |
| /creators | 200 | Creator Directory: Find Creators to Follow and Support (54) | 120 | self | index | 1 | ok | CollectionPage, ItemList(2), BreadcrumbList, WebSite | 324 | 38 | 2 / 0 |
| /creators/fitness | 200 | Fitness Creators to Follow and Support · Creator Link Studio (60) | 99 | self | index | 1 | ok | CollectionPage, ItemList(1), BreadcrumbList(3) | 303 | 37 | 1 / 0 |
| /blog | 200 | Blog · Creator Link Studio (26) | 118 | self | index | 1 | ok | CollectionPage, ItemList, BreadcrumbList, Organization, WebSite; rel=alternate RSS | 1137 | 56 | 0 |
| /blog/fanvue-alternative | 200 | Fanvue alternative: how to compare and pick the right one (57) | 150 | self | index | 1 | cover 1024x768 JPEG 183 KB | Article (Org author, dates, image), FAQPage(5), BreadcrumbList(3) | 2523 | 47 | 4 / 1 (cover alt="") |
| /blog/how-to-price-a-subscription-tier | 200 | How to price a subscription tier without guessing (49) | 155 | self | index | 1 | cover JPEG | same | 2506 | 47 | 4 / 1 |
| /@danglauber | 200 | DanGlauber (@danglauber) · Creator Link Studio (46) | 120 | self | index | 1 | avatar 512x512 | ProfilePage→Person, InteractionCounter, Place, WebSite | **70** | **1** | 1 / 1 |
| /@summerlane | 200 | Summer Lane (@summerlane) · Creator Link Studio (47) | 100 | self | index | 1 | brand card (not creator) | ProfilePage→Person | **54** | **1** | 1 / 1 |
| /@johnturnstile | 200 | Demo (@johnturnstile) · Creator Link Studio (43) | 109 | self | index | 1 (h1→h3 skip) | avatar 512x512 | ProfilePage→Person, Place | 585 | 3 | 2 (81 signed S3 URLs) |
| /@lexivaughn | **302 → lexivaughn.com** | — | — | — | — | — | — | — | — | — | — |
| /terms | 200 | Terms of Service · Creator Link Studio (38) | 116 | self | index | 1 | ok | Organization | 1862 | 33 | 0 |
| /privacy | 200 | Privacy Policy · Creator Link Studio (36) | **87** | self | index | 1 | ok | Organization | 1355 | 32 | 0 |

All pages: `lang="en"`, viewport present, no hreflang (single language, fine), `twitter:card=summary_large_image` (profiles: `summary`), all JSON-LD blocks parse, required fields present for Organization/WebSite/SoftwareApplication(offers, applicationCategory, operatingSystem)/FAQPage/Article(headline, datePublished, author, image)/BreadcrumbList/ProfilePage(mainEntity Person). Meta descriptions are unique across all 21 pages.

## Crawl controls (section 1 detail)

- robots.txt 200, `Sitemap:` line present, disallows only app areas; `cache-control: public, max-age=3600` but also sets a `PHPSESSID` cookie and `pragma: no-cache`/`expires: 1981` (session_start on robots/sitemap; harmless, but a CDN would not cache it).
- sitemap.xml: valid XML (xmllint), 43 `<url>`: 1 home + 20 static + 4 profiles + /blog + 17 articles. 42/43 return 200 with self-canonical and `index`; 1 (`/@lexivaughn`) is 302. No noindex pages in the sitemap. No 4xx/5xx. lastmod present on all but home.
- Variants: `http://apex` → 2 hops; `https://apex` → 301 www; `http://www` → 301 https. Trailing slash (`/pricing/`, `/features/`, `/blog/`) → 200 with canonical to the slashless URL (no redirect). `?utm_source=x` → 200 canonical clean. `/index.php` and `/index.php/pricing` → 200 home copy (see P1). Uppercase paths 404; uppercase handles 200 with lowercase canonical.
- `/blog?page=2`, `/creators?page=2` → 404 (correct: 17 articles < PER_PAGE 24; 2 creators < 24); `BlogController` sets noindex on search results and empty pages; `/blog/feed.xml` is valid RSS (17 items, cap 20) and linked with `rel=alternate` from `/blog`.
- 404: `/this-page-does-not-exist`, `/blog/nope`, `/@nobodyhere123`, `/creators/nope` → real HTTP 404, `noindex, nofollow`, branded page with H1 and a home link (2.4 KB, no nav). No soft-404s found.
- `/dashboard` logged-out → 302 `/`; `/studio` logged-out → 200 home copy.

## Performance (section 3 detail, Lighthouse 12 mobile, simulated 4G, headless Chrome)

| Page | Perf | SEO | A11y | BP | FCP | LCP | TBT | CLS | Bytes | Requests |
|---|---|---|---|---|---|---|---|---|---|---|
| / | 79 | 100 | 100 | 77 | 1.0 s | 5.4 s | 50 ms | 0.004 | 456 KB | 24 |
| /pricing | 78 | 100 | 100 | 77 | 1.2 s | 5.4 s | 70 ms | 0 | 393 KB | 23 |
| /blog/fanvue-alternative | 76 | 100 | 100 | 77 | 1.3 s | 6.3 s | 60 ms | 0 | 575 KB | 24 |
| /@summerlane | 70 | 100 | 92 | 77 | 3.3 s | 6.2 s | 40 ms | 0 | 534 KB | 20 |

- Server TTFB (root document) 100–170 ms. HTML gzip'd (55 KB → 12–14 KB). HTML `cache-control: no-store` on home/profile, `private, max-age=300` on blog articles.
- Assets: `/css/*.css`, `/js/*.js` → gzip, `cache-control: max-age=31536000`, ETag, Last-Modified, cache-busted `?v=`. Font `inter-latin-var.woff2` 48 KB, preloaded, 1-year cache. `/favicon.ico` 16.6 KB **without** cache-control. S3 blog cover: no `Cache-Control` at all (S3 default), JPEG 183 KB 1024x768.
- Render-blocking: `sx.css` + `landing.css` (+ `public.css` on inner pages), ~140–190 ms est.; Bootstrap/Toastr CSS via print-media swap (non-blocking, but 32 KB unused). Profile: jQuery/api.data.js/csrf-retry.js blocking in head, Font Awesome CSS + 2 webfonts without `font-display`.
- Third parties: gtag.js (74 KB unused), Microsoft Clarity (+ bing.com cookie sync) on every public page.
- Image formats: JPEG only (blog covers, thumbnails, avatars); no WebP/AVIF, no `srcset` anywhere on the pages checked.

## Indexability of what matters (section 4)

- Creator profiles: crawlable, `index, follow`, in sitemap (4 of them; only paid, verified, active creators — `Plan::paid_sql`). One of the four redirects off-site. Content is thin (see P1).
- Blog articles: crawlable, all 17 published in sitemap + RSS, Article + FAQ + Breadcrumb JSON-LD, daily lastmod.
- /creators: 2 creators, no pagination needed yet; `?page=2` → 404 (correct behaviour, but when it paginates the links must be plain `<a href="?page=2">` — not verified since no page 2 exists).
- Soft-404s: none. 404 page: proper status + noindex.
- Headers: **no HSTS**, **no X-Content-Type-Options**, XFO duplicated, gzip on HTML and text assets (no brotli), HTTP/2 on www.

## Structured data for rich results (section 5)

- FAQPage: present on home (5), features (5), feature sub-pages (4–5), pricing (6), compare pages (4), best-of (5), monetize (4), blog articles (5). Note Google now shows FAQ rich results only for authoritative government/health sites (since Aug 2023); harmless, but expect no FAQ snippet.
- Article (compare/best-of/blog): headline, datePublished, dateModified, image, author present; author is `Organization` ("Creator Link Studio team" / "Creator Link Studio") — valid, weaker E-E-A-T.
- BreadcrumbList: on every inner page, 2–3 items, correct order.
- Organization: `sameAs` **MISSING on every page** (confirmed in 21 pages' JSON-LD). Logo ImageObject present.
- SoftwareApplication: present on home/features/pricing with 3 Offers (0.00 / 49.00 / 199.00 USD, url → /pricing), applicationCategory and operatingSystem set — fine. Google will not show a price snippet for SoftwareApplication without `aggregateRating`, so no rich result expected there either.
- ProfilePage → Person with `@id`, alternateName, url, image, InteractionCounter — correct shape. Summer Lane's `image` is the brand card.
- No `VideoObject`, no `Event`/`Service`/`Product` on profile sub-pages (events/services exist in the product but are not exposed to crawlers).
- Nothing Google would flag as an error; warnings would be: Article author without URL, Organization without sameAs, FAQPage not eligible.

## What is already good

- robots.txt + sitemap wiring, valid XML, per-article lastmod, custom-domain creators get their own robots/sitemap.
- Self-referencing canonicals on every page, correct on trailing-slash, query-string and case variants of handles; `max-image-preview:large`.
- Real 404s (status + noindex), no soft-404s, noindex on blog search/empty pages, app areas disallowed.
- One H1 per page, unique titles ≤ 64 chars, unique descriptions, OG/Twitter complete, og:image 1200x630 reachable with width/height tags.
- Valid JSON-LD everywhere (WebSite, Organization, SoftwareApplication+Offers, FAQ, Article, Breadcrumb, ProfilePage/Person, CollectionPage/ItemList).
- Compare pages cite sources with `rel="nofollow"` external links (7 each).
- gzip + 1-year immutable caching + ETags on CSS/JS/font; Inter font preloaded; scripts deferred on public pages; CLS ≈ 0; TBT ≤ 70 ms; TTFB ≤ 170 ms; Lighthouse SEO 100 and a11y 100 on product pages.
- RSS feed with rel=alternate; llms.txt (8.9 KB) and llms-full.txt (234 KB) served as text/plain.

## Quick wins (≤ 1 day each)

1. Drop custom-domain creators from the main sitemap (P0, 1 h) — removes the only sitemap error.
2. Apache: HSTS + nosniff + dedupe XFO; single-hop host/scheme redirect without `:443`; 301 `/index.php*` and `/compare`; `/studio` logged-out → 302 `/` (P1/P2, ~2 h total, Daniel's vhost) — cleaner canonical signals, Best Practices up.
3. Add Organization `sameAs` and a Person author with URL (P1/P2, 2 h) — brand entity/Knowledge Panel eligibility.
4. Trim the /pricing description to ≤ 155 chars; lengthen /privacy (10 min).
5. Blog cover pipeline: emit WebP ≤ 80 KB + `fetchpriority="high"` + `srcset`, set S3 `Cache-Control: public, max-age=31536000` on `creator/blog/*` (half day) — blog LCP 6.3 s → ~3 s.
6. Profile page: `defer` the three scripts, replace Font Awesome with inline SVG, add `<main>`, fix the two contrast failures (half day) — profile FCP 3.3 s → ~1.2 s, a11y 100.
7. Per-page static `lastmod` instead of controller mtime (30 min).

## Bigger bets (≥ 2 days)

1. **Stable, public, optimised thumbnails for published SFW media** (home feed, directory, profiles): WebP at display size with real `alt`, served from the public prefix with long cache; keep signed URLs for gated media. Unlocks Google Images for every creator post, fixes home-page image caching, and is the precondition for any profile content to rank. 2–3 days.
2. **Make creator profiles rank-worthy**: server-rendered latest posts (title/excerpt/date), tiers with prices, services and events with their own canonical sub-pages and Event/Service/Offer JSON-LD; avatar-based 1200x630 og:image; sitemap/directory inclusion only when the profile has content. This is the surface that scales with every new creator (currently 4 profiles / 2 directory entries). 3–5 days.
3. **Critical-CSS split for the public layout** (hero CSS inline, rest async; minify at deploy; drop Bootstrap from public pages where only a few utilities are used) — takes text-LCP pages from 5.4 s to ≈ 2.5 s on throttled mobile. 2 days.

Artifacts: `scratchpad/seo/technical/` — `sitemap_urls.txt`, `pages/*.html|h` (raw fetches), `pages_report.json`, `page_table.md`, `lh/*.json` (Lighthouse), `assets.txt`, `feed.xml`.
