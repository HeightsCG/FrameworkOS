# SEO and LLM growth engine — design

**Date:** 2026-09-21 · **Owner:** Daniel · **Status:** draft for review

## 1. Goal

Make creatorlinkstudio.com show up, and be cited, when people and LLMs ask about monetizing content, creator platforms, and alternatives to Fanvue and OnlyFans. Today the site has no organic search traffic and nothing ranking (GrandRanker audit, 2026-09-21). The plumbing exists (dynamic robots/sitemap, `llms.txt`, Open Graph and JSON-LD on the landing page and creator profiles, noindex on the app). What is missing is indexable content and a system that keeps producing and measuring it.

Decisions taken with Daniel:
- Built-in blog with **AI drafting and human approval** (nothing publishes without Admin approval).
- **One article per day** drafted into the review queue.
- Creator directory that is **opt-in and safe-for-work only**.
- LLM visibility: `llms-full.txt` + structured data everywhere, **comparison / best-of pages**, **rank tracking in Admin** (Google Search Console API + nightly LLM prompt probes). Public API/MCP docs page: not now.
- Google Search Console property already exists; connect via OAuth from Admin.

Not in scope: paid ads, backlink outreach, changing the landing page's kinetic design (see landing-page rules), indexing adult content.

## 2. Sub-projects and build order

| # | Sub-project | Ships | Depends on |
|---|---|---|---|
| A | Product pages (`/features`, `/pricing`, `/compare/*`, best-of) | first, ~1 day | nothing |
| B | Content engine (`/blog`, keyword queue, daily Claude draft, Admin approval) | second | shared public-page layout from A |
| C | Creator directory (`/creators`, categories, opt-in flag) | third | nothing hard; uses A's layout |
| D | Rank tracking (Search Console OAuth + LLM probes, Admin "SEO" tab) | fourth | B's keyword table |

Each ships independently. Implementation plans are written per sub-project.

## 3. Shared foundation (built with A)

**Public marketing layout** `libs/Layout/public_page.php`: the landing page's header/footer and type system, one content column, no app chrome, no `noindex`. Every public page gets: `<title>`, meta description, canonical, Open Graph + Twitter, `article:published_time` where relevant, JSON-LD block, Google Analytics include, and a single CTA to `/` (sign up). Design rules: match the landing page (restraint, one accent, Title Case buttons, no emoji, no helper text).

**`SeoMeta` helper** (`libs/Classes/SeoMeta.php`): builds head tags + JSON-LD from an array (`type`, `title`, `description`, `url`, `image`, `published`, `modified`, `faq[]`, `breadcrumbs[]`). Used by A, B, C and the existing profile page (refactor its inline tags to use it).

**Sitemap** (`SeoController::sitemapAction`): add `<url>` entries for product pages, published articles (`lastmod` = updated_at), directory index + category pages, and listed creator profiles. Unlisted/adult profiles are excluded. Keep the generation dynamic (no static files).

**`llms.txt` / `llms-full.txt`**: `llms.txt` becomes generated (`SeoController::llmsAction`) from a short hand-written intro + links to every product page and the 20 newest articles. `llms-full.txt` concatenates the full text of product pages and articles (Markdown), capped at 2 MB, regenerated on publish. Both allowed in robots.

**Internal linking**: articles link to product pages by keyword (a small map in `SeoMeta::internal_links()`), product pages link to 3 newest related articles, directory pages link to profiles. Every public page has breadcrumbs (JSON-LD `BreadcrumbList`).

## 4. Sub-project A — product pages

Hand-written once by Claude in this session, reviewed by Daniel, kept as PHP views under `app/views/pages/` served by `PagesController` (`public $protected = 0`).

| URL | Target search | Schema |
|---|---|---|
| `/features` | online creator platform, creator monetization platform | `Product` + `FAQPage` |
| `/pricing` | creator platform pricing, how much does X cost | `Product` with `Offer`s from `PlanTiers` (never hard-coded prices) |
| `/compare/fanvue` | fanvue alternative | `Article` + comparison table |
| `/compare/onlyfans` | onlyfans alternative | `Article` + comparison table |
| `/best-creator-monetization-platforms` | best creator monetization platforms, monetize content | `Article` + `ItemList` |
| `/monetize-your-content` | monetize content, monetize online content, monetize digital content | `Article` + `FAQPage` |

Rules: no fabricated competitor facts; comparison rows cite the competitor's public pricing page with a "checked on" date; where we don't know, say so. Prices and take rates come from `PlanTiers` so pages can't drift from billing.

## 5. Sub-project B — content engine

### Data
```
seo_keywords   id, keyword, volume, difficulty ('easy'|'doable'|'hard'), priority, status ('queued'|'drafting'|'drafted'|'published'|'skipped'),
               article_id NULL, created_at, updated_at
seo_articles   id, slug UNIQUE, title, meta_description, excerpt, body_md, body_html, target_keyword, secondary_keywords JSON,
               faq JSON [{q,a}], cover_image_url, reading_minutes, status ('draft'|'review'|'published'|'archived'),
               model, prompt_version, created_at, updated_at, published_at, published_by
```
Seed `seo_keywords` with the audit's five plus: onlyfans alternative, fanvue alternative, how to sell pay-per-view content, creator membership tiers, link in bio for creators, AI influencer content, creator payouts stripe, how to price a subscription tier, cross-post to social media from one place, best link in bio for creators.

### Drafting job — `cron/seo_draft.php`, daily
1. Pick the highest-priority `queued` keyword. Mark `drafting`.
2. Build context: brand identity (voice, tagline, description), the product pages' text, the three most related published articles (titles + excerpts, for internal links and to avoid repetition), the feature list, `PlanTiers` facts.
3. Ask Claude (`ClaudeService::chat`, effort `medium`, model = configured default with the existing Sonnet fallback) for a JSON object: `title`, `slug`, `meta_description` (≤155 chars), `excerpt`, `body_md` (1,200–1,800 words, H2/H3, one internal link per section from the allowed link map, no external links), `faq` (3–5 Q&As), `secondary_keywords`. System prompt forbids invented statistics, competitor claims, and prices not in the context.
4. Validate: slug unique, word count in range, no links outside the allowed map, no emoji, no "As an AI". Failures re-prompt once, then the keyword is marked `queued` again and the run logs why.
5. Save as `review`. Notify admins in-platform ("New article ready for review") via `Notify::send`.
6. Cover image: optional. If enabled, generate with the existing fal image pipeline from a fixed, brand-safe abstract prompt; otherwise use the Open Graph default. Never AI influencer imagery on blog posts.

Cost: one Claude call per day plus one on retry. Well inside the platform's own key budget; not billed to creator AI credits.

### Admin — new **Content** tab in `/admin`
- Queue table: keyword, volume, difficulty, status, article link. Add / reorder / skip keywords. "Draft Now" button runs the job for one keyword.
- Review list: articles in `review` with preview (rendered exactly like the public page), a Markdown editor for the body, editable title/slug/meta/FAQ, buttons **Publish**, **Request Rewrite** (with a note that is appended to the prompt), **Discard**.
- Published list with unpublish/archive, view counts (from `post_views`-style `seo_page_views` table, counted server-side on render, bots excluded by user-agent).

### Public
- `/blog` index (paginated, newest first, category chips from `secondary_keywords`), `/blog/<slug>` article page with `Article` + `FAQPage` + `BreadcrumbList` JSON-LD, author card ("Creator Link Studio team"), reading time, related articles, one CTA.
- `/blog/feed.xml` RSS.
- Markdown rendered server-side with a small allowlist renderer (headings, paragraphs, lists, links, bold/italic, blockquote, tables). No raw HTML from the model.

## 6. Sub-project C — creator directory

- Settings → Creator Profile gains a switch **"List me in the Creator Directory"** (`creator_profiles.directory_listed`, default 0) with one line: "Your public page appears in the directory and in search engines."
- Eligibility, evaluated at render: `directory_listed = 1` AND avatar and cover assets are not adult/blocked AND creator has ≥1 published `on_cls` post that is not adult AND account is active and not suspended. Adult-content creators can still list, but only if their *profile* media is SFW; their adult posts remain gated as today.
- `/creators` (grid of cards: avatar, display name, handle, one-line bio, category chips, follower count), `/creators/<category>` where categories are derived from the top brand keyword per creator, normalised through a small synonyms map (fitness, music, cooking, gaming, beauty, art, education, lifestyle, business, other). Paginated, `CollectionPage` + `ItemList` JSON-LD.
- Profile pages of listed creators: indexable (remove noindex where present, ensure canonical), added to sitemap. Unlisted: `noindex, follow`.
- Sort: verified first, then recent activity. No pay-to-rank.

## 7. Sub-project D — rank tracking

### Google Search Console
- Admin → SEO tab → **Connect Search Console**: OAuth 2.0 (Google Cloud project, Search Console API scope `webmasters.readonly`). Tokens stored in a new `seo_gsc_auth` table (one row: property URL, access token, refresh token, expires_at), encrypted at rest with the same encrypt/decrypt helper `FanvueAccountsModel` uses for Fanvue OAuth tokens. Connect/disconnect buttons.
- Nightly `cron/seo_rank.php`: pull last 3 days of query + page rows (clicks, impressions, ctr, position) into `seo_rank_daily (date, query, page, clicks, impressions, ctr, position)`. Also pull per-URL index coverage for our sitemap URLs (URL Inspection API, rate-limited; sample 50/day).
- Admin table: target keywords (from `seo_keywords`) with current position, 7- and 28-day change, impressions, clicks; top landing pages; "not indexed" list with reason.

### LLM probes
- `seo_llm_prompts` (prompt text, active) seeded with ~10 questions ("best platform to monetize content as a creator", "fanvue alternatives", "how do creators sell pay-per-view content", "tools with an MCP server for creators", …).
- Nightly, for each active prompt, ask **OpenAI** (existing key, `gpt-4o-mini` or current small model) and **Claude** (existing key) with a neutral system prompt; store the full answer and whether "Creator Link Studio" / "creatorlinkstudio.com" appears, and its rank among named products. Table `seo_llm_results (date, prompt_id, provider, mentioned, rank, answer)`.
- Admin: mention rate per provider over 30 days, per-prompt drilldown with the latest answer text. Perplexity/Gemini providers are stubs behind a config key.

## 8. Cross-cutting

- **Routing**: `PagesController`, `BlogController`, `CreatorsController` are ordinary controllers (auto-routed by name). `/compare/<x>` is `PagesController::compareAction($x)`.
- **Caching**: public pages are cheap DB reads; add `Cache-Control: public, max-age=300` and ETag on blog/product/directory pages. Sitemap and llms files are generated per request (small) with 10-minute in-memory cache in `tmp/`.
- **Analytics**: existing GA include; plus server-side `seo_page_views` for Admin.
- **Robots**: allow `/blog`, `/creators`, `/compare`, `/features`, `/pricing`, `/llms-full.txt`; existing disallows unchanged.
- **Security**: article Markdown is sanitised; slugs are `[a-z0-9-]`; Admin actions require `Permissions::is_admin()`; GSC tokens encrypted at rest (Fanvue helper) and never sent to the browser.
- **Failure modes**: Claude outage → job logs and retries next day (existing retry/fallback in `ClaudeService`); GSC token expiry → Admin shows "Reconnect"; LLM probe failure → row recorded with `error`.

## 9. Success measures (Admin SEO tab, first 90 days)

- ≥ 25 published articles, all indexed.
- ≥ 5 target keywords in Google top 10 (Search Console position).
- LLM mention rate ≥ 30% on the seeded prompts for at least one provider.
- Directory: ≥ 20 listed creators, each profile indexed.

## 10. Open questions

- Blog cover images: fal-generated abstract art (small cost, nicer social cards) or the static OG image? Default: static until Daniel says otherwise.
- Author byline: "Creator Link Studio team" vs Daniel's name (E-E-A-T benefit, but personal exposure). Default: team.
- Google Cloud project for the Search Console OAuth client: Daniel creates it and pastes client id/secret into `app.ini` (`gsc_client_id`, `gsc_client_secret`).
