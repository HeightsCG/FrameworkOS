# LLM / AI-search exposure — summary

Crawler access is wide open and correct: robots.txt has no bot-specific rules, and all 19 AI/search user agents (GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, Claude-SearchBot, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Googlebot, bingbot, CCBot, Applebot-Extended, Bytespider, Amazonbot, DuckAssistBot, cohere-ai, meta-externalagent) get identical 200 responses on /, /pricing and /llms.txt (same byte counts: 55681 / 55130 / 8891), so there is no UA blocking at CDN/WAF/.htaccess level. llms.txt and llms-full.txt exist, are spec-shaped and link-clean (every URL resolves 200), and JSON-LD on every tested page parses as valid JSON. The two things that actually stop AI engines from citing the brand today are off-site: **Bing has zero pages of creatorlinkstudio.com indexed** (so ChatGPT search, Copilot and DuckDuckGo-backed answers cannot retrieve it), and **the brand exists nowhere else on the web** (LinkedIn, X, GitHub, YouTube, Product Hunt, Wikipedia, Wikidata, Bluesky, TikTok, Pinterest all confirmed absent; Organization has no `sameAs`). On-site, the biggest content error is the fee claim: 21 places in llms-full (all 8 compare pages + best-of) say "3% to 20% by plan", while /pricing shows Free with no take rate and says Free cannot sell, so a model will summarise the fee as "up to 20%", the same as OnlyFans/Fanvue. llms-full.txt is also noisy (duplicate headings, glued Q/A text, 35 button lines, an unreadable plan matrix, relative links, 20 KB of legal text) and omits the home page.

Working files: `/private/tmp/claude-501/-private-var-www-contentos-cvk-framework/c3d975e0-2c50-4653-a6a2-e8bdd8fa45cb/scratchpad/seo/llm/` (robots.txt, llms.txt, llms-full.txt, sitemap.xml, pages/*.html|txt, offsite/, bing*.html, ddg.html, ld.py, totext.py).

## Findings

**[P0] Bing index is empty, so ChatGPT search / Copilot / DuckDuckGo cannot cite the site** — `bing.com/search?q=site:creatorlinkstudio.com` returned "There are no results" (10 filler results from purseblog/jlaforums, 0 from the domain); `q="Creator Link Studio"` returned creator.co, Roblox, Merriam-Webster, Wikipedia; 0 results from the domain. DuckDuckGo served a bot challenge (skipped; it is Bing-backed anyway). OpenAI's search and Microsoft Copilot retrieve from the Bing index; Perplexity also leans on it. Googlebot access is fine, but nothing in this audit shows Bing has ever crawled the site. Fix: verify the site in Bing Webmaster Tools (import from Google Search Console takes 5 minutes), submit `/sitemap.xml`, and add IndexNow (generate a key, serve `/<key>.txt`, POST the sitemap URLs once, then ping on each blog publish from SeoDrafter). Effort: 1–2 hours; indexing follows in days.

**[P0] The public fee claim contradicts the pricing page and makes the platform look as expensive as OnlyFans** — `PagesController::our_facts()` (app/controllers/PagesController.php:361-364) computes `min($fees) . '% to ' . max($fees)` over `PlanTiers::all()`, which includes Free (fee_percent 20, cannot sell) and the retired Pro (5%). Result: "Creator Link Studio 3% to 20% by plan (falls as you grow)" appears on all 8 /compare/* pages, /best-creator-monetization-platforms, and 21 times in llms-full.txt, right next to "OnlyFans 20%" and "Fanvue 20%". Meanwhile /pricing shows "Platform take rate: —" for Free and "Upgrade to Creator or Studio to sell", and the /pricing FAQ answer to "What is the platform take rate?" contains no number at all ("A flat percentage ... shown on this page"). llms.txt's Plans line also has no fee number ("the platform take rate falls as the plan grows"). An LLM asked "platform fee Creator Link Studio" will answer "3% to 20%" or "not stated". Fix: in `our_facts()` use only selling, non-retired tiers (`price > 0 && empty($t['retired'])`) → "10% on Creator, 3% on Studio"; put the numbers into `plan_cost_answer()`, the pricing FAQ answer and the llms.txt Plans line (see exact sentences under Answer-readiness). Effort: 1 hour.

**[P1] The brand has no off-site footprint, and Organization has no `sameAs`** — direct-URL probe results (browser UA, then Googlebot UA for the 403s):
- 404 / confirmed absent: linkedin.com/company/creator-link-studio, x.com/creatorlinkstud, x.com/creatorlinkstudio, github.com/creatorlinkstudio, youtube.com/@creatorlinkstudio, producthunt.com/products/creator-link-studio (404 under Googlebot UA), en.wikipedia.org/wiki/Creator_Link_Studio, Wikidata search ("no results"), bsky.app (API: "Profile not found"), tiktok.com/@creatorlinkstudio ("Couldn't find this account"), pinterest.com/creatorlinkstudio ("not found").
- 403 bot-blocked, unverified (almost certainly absent given the rest): Crunchbase, G2, Capterra, GetApp, Software Advice, Trustpilot, AlternativeTo, SaaSworthy, Medium.
- Inconclusive (login walls): instagram.com/creatorlinkstudio, threads.com/@creatorlinkstudio, reddit.com/r/creatorlinkstudio.
`libs/Classes/SeoMeta.php:32-34` `SOCIAL_PROFILES` is an empty array, so no page emits `sameAs` and the footer shows no social links. AI engines weight entity confirmation from LinkedIn/Crunchbase/Wikidata/G2 heavily when deciding whether a brand is real enough to recommend; today the only source for "Creator Link Studio" is the site itself. Fix: create the 8 listings below, then fill `SOCIAL_PROFILES` (one constant → sameAs + footer). Effort: listings ~1 day of founder time spread over a week; code 10 minutes.

**[P1] llms-full.txt is noisy and omits the home page** — 234,175 bytes (73 KB gzipped), text/plain, 1-hour cache, not truncated (cap is 2 MB), 57 H2 / 222 H3 / 263 H4. Problems measured in the file:
- Every page emits two H2s (the `public_pages()` title, then the page H1 converted to `##` by `html_to_text`), giving 10 exact duplicate H2 pairs ("## Creator Link Studio vs Fanvue" twice, etc.).
- 65 lines where FAQ question and answer are glued without a separator ("What is the platform take rate?A flat percentage…", "Creator Link Studio3% to 20% by plan") because `<dt>/<dd>` and `<summary>`/`<details>` and the compare `<div>` rows get no separator.
- 35 button lines ("Get StartedSee Pricing", "Start with Creator", "Join Free"), the tab strip "Your pageWays to get paidPublishingInbox and audienceAnalyticsPayouts and team", and 34 empty "- " bullets.
- The /pricing plan matrix renders check marks as empty cells (`Pay-per-view posts | — | | |`, 28 rows), so a model cannot tell which plan has which feature; "Nine social networks" appears 9 times as "nine" and 8 as "9".
- 10 relative links (`/pricing`, `/compare/fanvue`, …) inside article bodies that a model cannot resolve; only 17 absolute URLs across 17 articles.
- Terms + Privacy are included in full (lines 1049-1357, ~20 KB) in the middle of the product facts; Anthropic, Stripe and Cloudflare llms files include no legal text.
- The landing page (the only page with "Creator Link Studio is a creator platform for…" and the home FAQ) is not in `public_pages()`, so it is missing from llms-full entirely (0 hits for "Create. Share. Earn" or "creator platform for monetizing").
- Directory section leaks a demo account ("Demo @johnturnstile … 1 follower") and a double-encoded `won&#039;t`.
- No Last-Modified / ETag on either file; llms.txt is `Cache-Control: private` while llms-full is `public`; all three machine files set a PHPSESSID cookie and send `Expires: 1981` + `Pragma: no-cache` alongside `max-age=3600` (PHP session cache limiter), which caches like "never cache" at shared caches.
Fix in `SeoController::html_to_text()` / `llmsFullAction()`: drop the title line when the H1 matches; add "\n" after `</dt>`, `</summary>`, `</dd>` and the compare row cells; strip `<nav>`, `.tabs`, `<a class="btn…">`/`<button>`; replace check-mark SVGs/`<span class="check">` with "Yes" and "—" stays; absolutise `href`s before `strip_tags` (or append " (URL)"); move Terms/Privacy to the end under "## Optional" or drop them; render the landing page's `<main>` first; exclude demo accounts; send `Last-Modified` and `session_cache_limiter('')`/no session on robots/llms/sitemap. Effort: half a day.

**[P1] Answer-readiness: 4 of 10 likely questions have no quotable, brand-named sentence** — see the table below; the gaps are platform fee (numbers absent), AI influencer (page never says "AI influencer" or the brand in the lead), live events / 1:1 video calls (built-in video rooms are not mentioned anywhere public: 0 hits for "video call", "video room" or "1:1" across all pages and llms-full), and link-in-bio with payments (phrase appears only in the Linktree compare intro describing Linktree, and in a demo post caption on the home feed). Effort: 2–3 hours of copy, plus a half-day if a `/features/link-in-bio` page is added.

**[P1] Five different one-line brand descriptions** — AI engines build the entity summary from the most repeated sentence; today there are five candidates:
1. Home `<meta description>`, og:description and WebPage.description (libs/Layout/login_form.php:12,54): "Creator Link Studio brings your profiles, content, subscriptions, payouts, and revenue into one simple workspace." — ContentOS-era wording, no memberships/pay-per-view/AI.
2. Organization.description (SeoMeta.php:41): "A creator monetization platform: one public page for memberships, pay-per-view, bundles, services and events, a studio that publishes to social networks, and payouts to your bank."
3. Home hero sentence: "Creator Link Studio is a creator platform for monetizing your content: memberships, pay-per-view posts, and tracked links on one public page, with payouts to your bank."
4. llms.txt blockquote: "...one public page with memberships, pay-per-view posts, bundles, services, events and tracked links, a studio that publishes to nine social networks with AI captions, an inbox with AI replies, and payouts to your bank."
5. Footer (public_footer.php): "One page for your memberships, pay-per-view posts, services and events, with a studio that publishes everywhere and payouts to your bank."
Also: SoftwareApplication.name is "Creator Link Studio" on home/features but "Creator Link Studio plans" on /pricing; logo is android-chrome-512 on home and -192 elsewhere; WebPage.name on home is "Creator Link Studio: Create. Share. Earn." while `<title>` is "Creator Link Studio: Creator Monetization Platform". Title tags, og:site_name, Organization.name, llms.txt H1 and footer all agree on the name "Creator Link Studio" (good). Fix: one constant (e.g. `SeoMeta::TAGLINE`) used by login_form meta, Organization, llms.txt, footer and the SoftwareApplication description; recommended text under "Brand description" below. Effort: 1 hour.

**[P2] Schema gaps that cost precision rather than eligibility** — Offers carry `price` and `priceCurrency` but no billing period (no `priceSpecification`/`UnitPriceSpecification` with `billingDuration`/`unitCode: "MON"`), so a model reading schema alone sees "Creator 49.00 USD" with no "per month"; Organization has no `contactPoint` (support@creatorlinkstudio.com is only in /terms) and no `foundingDate`/`founder`; WebSite has no `SearchAction` (optional, the site does have public search); Article author is `Organization "Creator Link Studio"` everywhere (acceptable; a Person author/founder with a bio page would help E-E-A-T on the 17 blog posts and 9 comparison articles); no `aggregateRating` (correct to omit). FAQPage markup is present and the Q/A text is visible on the page (checked on home, pricing, features, compare, blog). Effort: 1–2 hours.

**[P2] llms.txt structure vs the proposal** — H1, blockquote, H2 sections with `[title](url): description` lists: compliant. Missing: an "## Optional" section (Terms/Privacy sit under "## Product"); no fee numbers or limits (seats, AI influencers, credits, storage, payout facts) in the file itself, so a model that reads only llms.txt (the common case) cannot answer fee/limit questions; the MCP/Claude connector (a strong, citable differentiator for AI engines) is absent; blog list is capped at the 20 newest with an "All posts" link (fine). Content-Type text/plain is correct. Compared with Anthropic/Stripe/Cloudflare, those files list docs with crisp descriptions; for a product site the equivalent is a "## Facts" block. Effort: 1 hour (add a Facts section generated from PlanTiers).

**[P2] Blog count: 17 live, brief says 40** — sitemap, /blog listing, feed.xml and llms.txt all show exactly 17 published articles (`SeoArticlesModel::published()` filters `status = 'published'`). If the engine has written ~40, 23 are stuck in draft/review and are invisible to every engine; worth a `SELECT status, COUNT(*) FROM seo_articles GROUP BY status`. Effort: 10 minutes to check.

**[P2] /compare returns 404** — the brief lists /compare as the best-of page; live it is /best-creator-monetization-platforms (200, linked from llms.txt and footer). `/compare` and `/compare/` are 404 with no redirect; models and people who truncate the URL hit a dead end. Add a 301 from /compare to /best-creator-monetization-platforms. Effort: 15 minutes.

**[P2] Demo/test accounts are the public directory** — /creators lists "Demo @johnturnstile" (bio: "Food, travel and everyday wellness…", 1 follower) and the home "Latest Posts" feed shows the same demo handles; the directory meta says "from 2 creators". AI engines summarising "who uses Creator Link Studio" will see a demo. Hide accounts flagged demo/internal from the directory, feed and sitemap until real creators opt in. Effort: 1 hour.

## Answer-readiness (10 questions)

| # | Question | Best page today | Verdict | Gap |
|---|---|---|---|---|
| 1 | best OnlyFans alternative with lower fees | /compare/onlyfans, /blog/how-to-choose-an-onlyfans-alternative | Weak | Fee shown as "3% to 20%" (not lower than OnlyFans' 20%); "Why Choose Creator Link Studio" has bullets, no fee sentence with the brand name |
| 2 | what does Creator Link Studio cost | /pricing | Good | Title, meta and FAQ carry "Free $0, Creator $49, Studio $199"; add the sentence under the H1 too (lead paragraph has no numbers) |
| 3 | platform fee Creator Link Studio | /pricing FAQ, /features/payouts FAQ | Missing | Both FAQ answers say "a percentage … shown on the pricing page" with no number |
| 4 | can I create an AI influencer and sell content | /features/character-generation | Weak | Page never uses "AI influencer" in H1/lead and never names the brand; plan inclusion (1 on Creator, 10 on Studio) only on /pricing |
| 5 | Fanvue vs Creator Link Studio | /compare/fanvue | Good | Article + FAQ + sourced table; only the fee range is wrong |
| 6 | how do payouts work | /features/payouts | OK | "Connect your bank once … cash out whenever your balance is above the minimum" — the minimum, timing, method and countries are unstated |
| 7 | is there a free plan | /pricing FAQ | Good | "Is there a free plan? Yes. Everyone starts with a Free account…" quotable and visible |
| 8 | sell live events / 1:1 video calls as a creator | /features ("Events: Sell seats to live sessions."), /monetize-your-content | Missing | One line each; built-in video rooms (waiting room, chat, screen share) never mentioned; "1:1" absent |
| 9 | link in bio that takes payments | /compare/linktree (describes Linktree), home ("tracked links") | Missing | No sentence says Creator Link Studio *is* a link-in-bio page that takes payments |
| 10 | what is Creator Link Studio | Home hero | Good | Self-contained, brand-named; but meta description and Organization text say something else (see brand finding) |

Sentences to add (verbatim, placed right under the named heading so extractors pick them up):

- /pricing, first paragraph under the H1 and as the FAQ answer to "What is the platform take rate?": "Creator Link Studio's platform fee is 10% of each sale on the Creator plan ($49/month) and 3% on the Studio plan ($199/month). Free accounts are for fans and cannot sell. Card-processing fees are separate." Reuse the same sentence in llms.txt's Plans line and in `plan_cost_answer()` (home FAQ).
- /compare/onlyfans (and the other 7 compare pages via `our_facts()`), fee cell and a sentence under "Why Choose Creator Link Studio": "Creator Link Studio is an OnlyFans alternative with a lower platform fee: 10% on the Creator plan and 3% on Studio, versus the 20% OnlyFans is reported to keep, and it sells services, live events, bundles and pay-per-view from one page while publishing to nine social networks."
- /features/character-generation, lead under the H1: "On Creator Link Studio you can create an AI influencer: train a character once from 10–50 photos or a text description, generate photos and short videos of the same person in about a minute, and sell them as pay-per-view posts, paid messages or membership content from your page. The Creator plan includes one AI influencer (extra slots $15/month, up to 5), the Studio plan includes ten."
- /features/payouts, under "Cash Out to Your Bank": "Payouts on Creator Link Studio: every sale (memberships, unlocks, bundles, services, events) lands in one balance in credits (10 credits = $1), net of your plan's fee. Once the balance is above $[minimum], cash out to your bank account at any time; transfers arrive in [N] business days, and Creator Link Studio charges no per-payout fee." (Daniel fills minimum and N.)
- /features, replace the one-line "Events" and "Services" cards with: "Creator Link Studio lets you sell tickets to live events and book 1:1 sessions, and hosts the call itself: every paid event or service gets a built-in video room with a waiting room, chat, screen share and host controls, so fans join from their purchase page without Zoom or a separate link." Add the same under /monetize-your-content "Events".
- /features (new "Link in bio" card, or a new /features/link-in-bio page): "Creator Link Studio is a link-in-bio page that takes payments: your page at creatorlinkstudio.com/@handle holds tracked links plus memberships, pay-per-view posts, bundles, services and events, fans pay by card or credit wallet, and earnings pay out to your bank." Add "link in bio" to `SeoMeta::internal_links()`.
- Home (`login_form.php`) meta description and llms.txt blockquote: the canonical description below.

## Structured data check (live, parsed)

- Home: one `@graph` with Organization (`@id #org`, name, url, logo 512px, description; no sameAs, no contactPoint), WebSite (no SearchAction), WebPage, SoftwareApplication (BusinessApplication, Web, 4 Offers: Free 0.00, Creator 49.00, Studio 199.00, add-on 15.00 USD, no billing period), FAQPage (5 Q, visible on page). Valid.
- /pricing: SoftwareApplication "Creator Link Studio plans" + 4 Offers, FAQPage (6 Q), BreadcrumbList. Valid. og:type product.
- /features: SoftwareApplication + Offers, FAQPage (5), BreadcrumbList. Valid.
- /features/*: FAQPage + BreadcrumbList only (no SoftwareApplication/Article; acceptable).
- /compare/*: Article (author Organization "Creator Link Studio", publisher Organization, datePublished 2026-09-21, dateModified 2026-09-28, "details checked on September 21/25, 2026" in body), FAQPage (4), BreadcrumbList. Valid.
- /best-creator-monetization-platforms: Article + ItemList (9) + FAQPage (5) + BreadcrumbList. Valid.
- /blog: Organization (logo 192px) + CollectionPage + BreadcrumbList; /blog/<slug>: Article (S3 cover image, author Organization) + FAQPage (5) + BreadcrumbList. Valid.
- /creators: CollectionPage + ItemList (2) + BreadcrumbList. /@handle: ProfilePage with Person mainEntity. Valid.

## Off-site: the 8 to create first (in this order) and the description to use everywhere

1. Bing Webmaster Tools + IndexNow (prerequisite, not a listing).
2. LinkedIn company page `linkedin.com/company/creator-link-studio` (free; strongest entity signal for Copilot/Perplexity).
3. X `@creatorlinkstudio` (handle is free; both probed handles 404).
4. Wikidata item: instance of "software as a service"/"website", official website, inception 2026, country, developer (Google Knowledge Graph and most LLM entity linkers read Wikidata; 20 minutes, no notability bar like Wikipedia).
5. Product Hunt product page + launch (ChatGPT and Perplexity cite PH pages for "alternatives to X" questions).
6. Crunchbase organization profile (free tier).
7. G2 and Capterra vendor listings (free; AI engines quote these for pricing and "category" facts; put the exact plan prices there).
8. YouTube channel `@creatorlinkstudio` with the existing 35 walkthrough videos (Gemini/AI Overviews surface YouTube heavily; the videos already exist).
Then: GitHub org with a public README for the MCP connector, Trustpilot claim, AlternativeTo entry (list as alternative to Fanvue, OnlyFans, Patreon, Linktree, Stan), Instagram/TikTok/Threads handles. Fill `SeoMeta::SOCIAL_PROFILES` after each so sameAs and the footer update.

Canonical brand description (use verbatim for every listing, the meta description, Organization.description, the llms.txt blockquote and the footer):

"Creator Link Studio is a creator monetization platform: one public page at creatorlinkstudio.com/@handle for memberships, pay-per-view posts, bundles, services and live events, a studio that publishes to nine social networks with AI captions, AI influencers and an AI-assisted inbox, and payouts to your bank. Plans: Free $0, Creator $49/month (10% platform fee), Studio $199/month (3% platform fee)."

Short form (≤160 characters, for listing taglines and `<meta description>`): "Creator Link Studio: sell memberships, pay-per-view, services and live events from one page, publish to nine social networks with AI, get paid to your bank."

## What is already good

- No UA-based blocking anywhere; robots.txt allows all crawlers (Google-Extended, GPTBot, ClaudeBot, CCBot, etc. unmentioned = allowed), explicitly allows /llms.txt and /llms-full.txt, and lists the sitemap. http→https and apex→www both 301 cleanly for /llms.txt.
- llms.txt follows the proposal (H1, blockquote, H2 link lists with descriptions); all 42 linked URLs return 200; prices in it match PlanTiers (Free $0, Creator $49, Studio $199; add-on $15, up to 5).
- llms-full.txt is served as text/plain, gzip-compresses to 73 KB, is cached for an hour and is far under the 2 MB cap; it carries the pricing table, every compare table with sources and "checked on" dates, every FAQ and all 17 articles.
- Every JSON-LD block on 14 tested pages is valid JSON; FAQPage text is visible on-page; Article/compare pages have datePublished/dateModified and sourced claims; SoftwareApplication (not Product) is the right choice.
- Brand *name* is consistent everywhere (title tags, og:site_name, Organization.name, llms.txt H1, footer, ProfilePage).
- Compare pages are honest ("reported", sources, "we build Creator Link Studio, and we say so"), which AI engines reward.

## Quick wins (≤1 day each)

1. Bing Webmaster Tools + sitemap + IndexNow — unlocks ChatGPT search/Copilot/DDG retrieval entirely (1–2 h).
2. Fix `our_facts()` fee range to "10% on Creator, 3% on Studio" and put the numbers in the pricing FAQ, home FAQ and llms.txt — removes the "up to 20%" reading on 30+ surfaces (1 h).
3. One canonical description constant used in login_form meta, Organization, llms.txt, footer, SoftwareApplication (1 h).
4. Add the six quotable sentences above (fee, OnlyFans alternative, AI influencer, payouts, events/1:1 video, link in bio) (2–3 h).
5. Add "## Facts" to llms.txt (fees, limits, payout, nine networks by name, MCP connector) and move Terms/Privacy to "## Optional" (1 h).
6. Create LinkedIn, X, Wikidata, Crunchbase, YouTube; fill `SOCIAL_PROFILES` for sameAs + footer (half a day of founder time).
7. 301 /compare → /best-creator-monetization-platforms; hide demo accounts from directory/feed/sitemap (1 h).
8. Check `seo_articles` status counts: if 23 articles sit in draft/review, publishing them more than doubles the citable corpus (10 min to check).

## Bigger bets (≥2 days)

1. llms-full.txt clean-up in `SeoController::html_to_text()` (dedupe headings, Q/A separators, strip buttons/tabs, "Yes" for check marks, absolute URLs, landing page first, legal last, Last-Modified) — turns a 234 KB file an LLM half-understands into the single best source for the brand (half a day to a day, with before/after diff of the file).
2. Product Hunt launch + G2/Capterra listings with reviews from the first real creators — these pages are what ChatGPT and Perplexity actually quote for "best X alternative" questions; without third-party listings the site will keep losing those answers to Fanvue/Fansly/Patreon (1–2 days plus review outreach).
3. A public `/features/link-in-bio` page and a `/features/live-events` (video rooms, 1:1 bookings) page, each with FAQPage and the quotable lead sentence, plus a founder Person page used as Article author — fills the two unanswered intents and gives E-E-A-T a human (2–3 days).
4. Offer `priceSpecification` with monthly billing, Organization `contactPoint`/`founder`, WebSite `SearchAction` — small precision gains for models that read schema (half a day, bundled with 1).
