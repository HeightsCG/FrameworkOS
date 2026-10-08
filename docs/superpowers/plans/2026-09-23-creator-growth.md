# Creator growth: tracking, article pipeline, indexing, acquisition pages

**Goal:** more creator signups. Build on the existing daily article engine and automated posts; never change or remove them.

**Order:** 2 (article pipeline) → 3 (indexing) → 4 (acquisition pages) → 1 (tracking). Publishing better and getting it indexed pays off first; tracking measures it.

Referrals (the old item 5) are out of scope.

## What already exists (reuse, don't rebuild)
- **Article SEO:** `BlogController::article()` already emits `SeoMeta::article()` JSON-LD, canonical, OG/Twitter, published/modified. Missing only `author`.
- **Sitemap:** `SeoController::sitemapAction()` is generated per request and already lists `/`, product pages, public creator profiles, `/blog` and every published article. "Regenerate on publish" is already true; it needs the IndexNow ping.
- **robots.txt:** `SeoController::robotsAction()` already disallows `/account`, `/admin`, `/api`, `/dashboard`, `/inbox` and friends, and points at the sitemap.
- **Comparisons:** `/compare/<slug>` already renders from `PagesController::COMPETITORS`, each claim with a source URL and checked date.
- **Age gate:** there is none on marketing pages. Adult content is a per-fan setting (`adult_content_enabled`), so nothing blocks Googlebot today. Item 3 is verification only.
- **Drafting:** `SeoDrafter::draft()` writes, validates and (since 2026-09-22) auto-publishes; `Markdown::render()` allows only relative links from `SeoDrafter::allowed_paths()`.
- **Fees:** every money path reads `Plan::fee_percent($creator)` — the single choke point a founding rate must override.

## 2. Article pipeline
- **SQL** `sql/2026-09-23_article_growth.sql`: `seo_articles` + `cluster VARCHAR(40)`, `author VARCHAR(80)`; `seo_keywords` + `cluster VARCHAR(40)`.
- **Clusters** as a const in `SeoDrafter`: `ai-influencer-monetization`, `ai-dm-chatter`, `lora-character-training`, `platform-comparisons`, `creator-payouts`. Seed keywords get a cluster; the drafter inherits the keyword's.
- **Duplicate guard** (`SeoArticlesModel::similar()`): before writing, compare the new primary keyword and title against every published article — exact keyword match, normalised-title match, and a token-overlap score over ~0.8. On a hit, re-prompt once with the clashing titles listed and "pick a different angle"; if it still clashes, skip the keyword (status `skipped`, reason recorded) so the day's run ends clean instead of publishing a near-duplicate.
- **Internal links:** `SeoDrafter::link_targets($cluster, $exclude_id)` returns 3–5 published same-cluster articles (newest first, falling back to any cluster) plus one feature page for that cluster. Those paths are appended to `allowed_paths()` for the render, and the prompt requires ≥3 article links and exactly 1 feature-page link. `validate()` enforces the counts.
- **CTA block:** appended after render by `SeoDrafter`, not written by Claude, so it is identical everywhere: a `.gd-cta` block with a heading, one line, and a button linking `/?auth=register` (opens the signup modal). Add `.gd-cta` styles to `public/css/public.css`.
- **Author/date:** `author` defaults to the site name; `SeoMeta::article()` gains `author`, and `blog-article.php` shows "By … · updated …".

## 3. Indexing
- **Canonical host in PHP, not .htaccess.** In `Bootstrap`, before routing: if the host is not `www.` or the request is http (behind the proxy header), 301 to `https://www.<domain><uri>`. Dev hosts and CLI are exempt. Rationale: an .htaccess edit took the live site down on 2026-08; a PHP redirect needs no server config and is testable on dev.
- **Self-referencing canonical, query stripped:** `SeoMeta::head()` already prints the passed `url`; make the default strip the query string so `/?auth=register` canonicals to `/`. Pages that legitimately paginate keep their own url.
- **IndexNow:** `libs/Classes/IndexNow.php` — a key file served at `/<key>.txt` (via `SeoController`), `ping(array $urls)` posting to `api.indexnow.org`, never throwing, logging failures. Called from `SeoDrafter` after publish and from `ApiSeoContentController` publish/unpublish, with the article URL plus `/blog` and `/sitemap.xml`.
- **Verify:** sitemap URL count vs published articles + pages + public profiles; non-www and http redirect once to the www https URL; `curl -A Googlebot` on `/`, `/features`, `/pricing`, a `/compare/*` and an article returns 200 with `index, follow`.

## 4. Acquisition pages
- **Feature pages** as `PagesController::ROUTES` entries rendering one shared view from a data file, `app/config/feature_pages.php`: `/features/character-generation`, `/features/dm-agent`, `/features/payouts`. Each: hero, how it works, what you get, FAQ, signup CTA. Added to `SeoController::public_pages()` so sitemap, llms.txt and internal links pick them up.
- **Comparisons from data:** move `COMPETITORS` into `app/config/competitors/<slug>.php` (one file per competitor, same shape: value + source + checked per row). `PagesController::competitors()` loads and caches them; adding a competitor becomes a new file, no code.
- **Founding offer** `/founding`: terms in `app/config/` defaults, overridable from an **Admin → Founding Offer** panel (fee rate, lock months, free credits, cohort cap, live toggle) stored in a `settings`-style table row; page shows remaining spots from the claimed count. Signup from the page tags the creator: `user_accounts.founding_cohort`, `founding_fee_percent`, `founding_until`. `Plan::fee_percent()` returns the locked rate while `founding_until` is in the future. Free credits are granted on first plan purchase.

## 1. Tracking (GA4)
- **First-touch attribution:** `public/js/landing.js` stores `utm_*`, `gclid` and `document.referrer` in a first-party cookie (90 days) on first visit; the register form posts them; `ApiAuthController::registerAction()` saves them to new `user_accounts` columns (`acq_source`, `acq_medium`, `acq_campaign`, `acq_term`, `acq_content`, `acq_referrer`, `acq_landing`).
- **Events** fired from code (the signup is a modal, so page views won't do): `creator_signup_started` when the register dialog opens, `creator_signup_completed` on a successful register response, `creator_onboarding_completed` from `/setup` when the last required step completes, `fan_signup`, `purchase` (credit purchase and unlock, with value), `subscribe` (plan and membership). One helper `window.CLSTrack(event, params)` in `site.js`/`landing.js` guarding on `gtag`.
- **Verify:** GA4 DebugView shows each event; new rows in `user_accounts` carry source/medium/campaign.

## Verification (all items)
- `tests/seo_routes_test.php`, `seo_meta_test.php`, `seo_crawl_files_test.php`, `seo_pricing_test.php`, `seo_blog_routes_test.php` keep passing; extend routes test with the new pages.
- New: `tests/growth_dupe_test.php` (duplicate guard), `tests/growth_links_test.php` (every published article has ≥3 internal links, 1 feature link, a CTA).
- A real drafter run on dev produces an article with the CTA, links and cluster, publishes, and pings IndexNow (staging key).
- Rich Results Test on one new article; screenshots of `/founding` and the three feature pages for Daniel.

## Deploy notes
- SQL: `sql/2026-09-23_article_growth.sql`, `sql/2026-09-23_attribution.sql`, `sql/2026-09-23_founding_offer.sql`.
- IndexNow key file must deploy with the app.
- The canonical-host redirect changes every URL on the site: verify on dev, then watch the first prod requests.
