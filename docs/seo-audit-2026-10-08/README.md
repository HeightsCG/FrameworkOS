# SEO, LLM exposure and growth audit, 2026-10-08

Audited: https://www.creatorlinkstudio.com (production), read-only, plus the code in this checkout. Four passes ran in parallel (technical SEO, content and conversion, LLM exposure, growth instrumentation and production readiness); their full reports are the other files in this folder. This page is the consolidated, prioritized view.

Goal stated by Daniel: attract users, build the user base, start subscriptions, run a real production system.

## Where things stand

The plumbing built in September works: valid sitemap and robots, canonicals everywhere, one H1 per page, unique titles and descriptions, valid JSON-LD on every page, real 404s, gzip, long-cached assets, Lighthouse SEO 100, all AI crawlers allowed, llms.txt and llms-full.txt present and link-clean, GA4 with the full funnel event map, Search Console verified, TLS and TTFB fine. The blog engine publishes one specific, well-linked article a day.

What stops growth today is not crawlability. It is three things (two earlier items were resolved or corrected the same day; items 3 and the code half of 5 were fixed in dev on 2026-10-08, see P0):

1. ~~Email authentication~~ Resolved 2026-10-08: DKIM (selector `20260625142328pm`) and Return-Path were already verified in Postmark; Daniel added DMARC (`p=none`, reports to support@), MX (Microsoft 365) and root SPF the same day. All five records verified from an external resolver. Only a periodic re-check remains.
2. ~~Bing has zero pages indexed~~ Corrected: the site was verified, the sitemap submitted 2026-09-23 and crawled 2026-10-06 (39 URLs), and home and /pricing are confirmed indexed. Zero clicks and impressions is a ranking problem (authority, off-site presence), not an indexing one.
3. ~~The site says its fee is "3% to 20%"~~ Fixed in dev 2026-10-08 (one fee sentence everywhere, nightly drift check). Was on all 8 compare pages, the best-of page, llms-full and 15 blog articles, because the fee range includes the Free tier that cannot sell. Two of the highest-intent articles still quote the retired $99/$249/$399 plans.
4. **There is no proof anywhere**: zero product screenshots, no About or Contact page, no founder, no social profiles, and the only listed creators are demo accounts, which also feed the home page.
5. **The creator path is seven screens long** with no intent captured at signup, ~~the verify link logs nobody in~~ (fixed in dev 2026-10-08), and a Free creator can do nothing until they pay $49.

## P0, do this week

| # | Item | Owner | Effort | Detail |
|---|---|---|---|---|
| 1 | Email DNS | verified | done | DKIM, Return-Path, DMARC, MX, SPF all resolve (2026-10-08). Re-check quarterly; move DMARC to `p=quarantine` after two clean weeks. |
| 2 | Bing | verified | done | Site verified, sitemap crawled, home and /pricing indexed. Ranking, not indexing, is the gap: off-site listings and content below. |
| 3 | Fee message: "10% on Creator, 3% on Studio" | done in dev (2026-10-08) | done | `PagesController::fee_short()` / `fee_sentence()` feed pricing, features, compare, best-of, home, llms.txt, the drafter brief and the /features/payouts card; `our_facts()` uses selling non-retired tiers only. `cron/seo_fact_check.php --fix-fee` swept the fee range out of the articles on dev; run it on prod after deploy. |
| 4 | Rewrite the two articles with retired prices | done in dev (2026-10-08) | done | `cron/seo_fact_check.php` (nightly, `30 9 * * *`) flags retired plan names, fee ranges and stale plan prices in articles and public pages, notifies admins; `--fix-fee` applies the exact fee phrase, `--rewrite` redrafts a flagged article through Claude. Run `--rewrite` on prod for the two articles. |
| 5 | Hide demo accounts from directory, sitemap and home feed; drop "from 2 creators" | done in dev (2026-10-08) | done | New `user_accounts.is_demo` flag (`sql/2026-10-08_is_demo.sql`, run on prod): excluded from directory, sitemap, feed, search; profile noindex; directory counts hidden below 10; Mark Demo button on the admin user page. |
| 6 | Sitemap must not list custom-domain creators (302 off-site) | pushed 2026-10-08 | done | `UsersModel::list_public_creators()` excludes active custom-domain creators and empty profiles; verified on prod: 41 URLs, all 200. |
| 7 | Verify link signs the user in and lands creators on the next step | done in dev (2026-10-08) | done | `verify_emailAction` signs the user in (MFA and forced-reset honored) and redirects: creators to /setup, fans to where they signed up from; used and expired links get their own message with a resend form. |
| 8 | Register rate limit + honeypot; check the verification send result instead of discarding it | done in dev (2026-10-08) | done | 5 signups per IP and 3 per email per hour, off-screen honeypot field, failed verification send surfaces a resend option and notifies admins. |

## P1 batch, built in dev 2026-10-08 (second pass)

| # | Item | Status | Detail |
|---|---|---|---|
| 1 | Home hero and pricing | done in dev | H1 "Sell Memberships, Posts and Services From One Page.", fee subhead from `fee_short()`, plan strip from PlanTiers, home meta = `SeoMeta::brand_tagline()`, pricing roles (Free "Get a page", Creator "Get discovered", Studio "Get promoted") in `PagesController::PLAN_ROLES`. No typed prices. |
| 2 | Creator profile pages | done in dev | Site nav + footer on logged-out profiles (absolute links on custom domains), "Create Your Page Free" → `/?auth=register&ref=<handle>&role=creator`, creator dashboard Audience tab shows referred signups + paid, posts/tiers were already server-rendered, og:image was already the avatar, thin profiles already out of the sitemap. |
| 3 | Signup flow | done in dev | Register = email + password (+ Google); `?plan ?role ?ref` carried through email and Google via the `cls_signup` cookie (30 d) and stored on `user_accounts` (`signup_plan`, `signup_role`, `referred_by_creator_id`; `sql/2026-10-08_signup_params.sql`); role=creator shows the Creator Agreement checkbox and lands on /setup → billing with the plan preselected. |
| 4 | Brand and LLM text | done in dev | `SeoMeta::brand_description()` in Organization, SoftwareApplication, llms.txt, footer; `brand_tagline()` as home meta; llms.txt "## Facts" with six brand-named sentences, Terms/Privacy under "## Optional"; llms-full cleaned (home first, legal last, no duplicate H2s, button lines, relative links or empty cells). |
| 5 | IndexNow canonical host | done on prod | 39 sitemap URLs re-pinged on www 2026-10-08 14:49 UTC, key file 200 on www. |
| 6 | Admin Growth tab | done in dev | 7/30/90 days: per-day and by-source signups, verified %, creators %, paid %, referred; referred signups list. Admin tab row now wraps. |
| 7 | Production readiness | done in dev | `GET /health`, `cron/error_digest.php` (07:45), `cron/db_backup.php` (03:30, S3 `backups/db/<env>/`), `cron_runs` + Jobs box on /admin (`sql/2026-10-08_cron_runs.sql`), `docs/ops.md` (crontab, logs, restore drill done on dev), `docs/ops/vhost-creatorlinkstudio.conf` (headers, single-hop redirects; run `sudo apache2ctl configtest` first). |

Open decisions for Daniel from this batch: "Free: Get a page" vs Free having no public page; the profile footer prints plan prices to fans; usernames derive from the email local part at signup.

## P1, next two weeks

Technical
- Stable public thumbnails for published SFW media (home feed, directory, profiles): WebP at display size, real alt text, long cache; signed URLs only for gated media. Today every image is a 1-hour signed S3 URL, uncacheable and unindexable. 2-3 days.
- Mobile LCP 5.4-6.3 s on every page type: inline hero CSS, defer the three blocking scripts on profiles, drop Font Awesome from the public profile, blog covers as WebP ≤80 KB with fetchpriority and srcset. 1-2 days, target 90+.
- Apache vhost: HSTS, nosniff, Referrer-Policy, one X-Frame-Options; single-hop redirect without `:443`; 301 `/index.php*` and `/compare`; `/studio` logged-out → `/`. Daniel deploys; validate the config first (an .htaccess slip took the dev site down in August).
- Organization `sameAs` is empty on every page; `SeoMeta::SOCIAL_PROFILES` is an empty constant. Fill after the profiles exist (below).

Content and conversion
- Home hero: H1 with the keyword and the promise ("Sell Memberships, Posts and Services From One Page."), subhead with the fee, a pricing strip under the hero, meta description matching the page. Highest lift per hour.
- Proof: six real screenshots across /features, each feature sub-page and the home tabs; founder note; About and Contact pages; Person author on articles. 1-2 days.
- /onlyfans-alternatives and /fanvue-alternatives list pages for the head terms (6,600/mo), repointing the article and the vs page. 1-2 days.
- Missing landing pages: memberships, pay-per-view, link-in-bio, publishing, services-and-events, custom domains; rename character-generation → /features/ai-influencer with a keyword-led title. 3-5 days.
- Creator profile pages: nav, footer, "Create your page" link, server-rendered posts and tiers, avatar og:image; thin profiles out of the sitemap. 2 days.
- Signup: email + password + Google only, carry `?plan=` and `?role=`, land creators on /setup. 1 day (auth, Daniel's call).
- Blog engine: re-cluster keywords so pricing articles stop linking to AI influencers, citation allow-list, variable length, 40-60 new keywords (the queue has about two weeks left). 1 day.

LLM exposure
- llms-full.txt clean-up in `SeoController::html_to_text()`: duplicate H2s, glued Q/A text, 35 button lines, empty plan-matrix cells, relative links, legal text in the middle, home page missing. Half a day.
- One canonical brand description used by the home meta, Organization, llms.txt, footer and SoftwareApplication (five different ones today). 1 h.
- Six quotable sentences (fee, OnlyFans alternative, AI influencer, payouts, live events and 1:1 video, link in bio), exact text in llm-exposure.md. 2-3 h.
- Off-site presence, in order: LinkedIn, X, Wikidata, Product Hunt, Crunchbase, G2, Capterra, YouTube (the 35 walkthrough videos exist). Founder time over a week.

Production
- Confirm Stripe live webhook deliveries are 2xx in the dashboard (an unsigned POST returned 500 in one probe).
- /health endpoint plus an external uptime monitor; Sentry or a daily error-log digest; nightly DB dump to S3 with a restore test; docs/ops.md with the prod crontab and a last-run panel on /admin.
- Admin Growth tab: signups, verified %, became-creator %, paid % per day and by source (the data is already in user_accounts).
- Memberships need Stripe Connect fully onboarded before a fan can subscribe; say so on /pricing and in the checklist, or hold-and-release.
- No trial and no annual plan; a 14-day Creator trial or "build before you pay" for Free creators.

## P2
See the four reports: pricing meta description length, static lastmod from controller mtime, Clarity cookies hurting Best Practices, uppercase URL 404s, profile a11y, CSS minification, Offers without billing period, Organization contactPoint, WebSite SearchAction, article length uniformity, cover palette, login_attempts pruning, session lifetime.

## Needs Daniel, not code
After the 2026-10-08 deploy:
1. Run `sql/2026-10-08_is_demo.sql` on prod, then check the demo account shows "Demo" on its /admin user page (mark any other demo accounts there).
2. `cd framework && APPLICATION_ENV=production php cron/seo_fact_check.php` to see the prod drift, then `--fix-fee` (no AI cost) and `--rewrite` (Claude cost, one call per flagged article; the two retired-price articles are expected).
3. Crontab: `30 9 * * * cd /path/to/framework && APPLICATION_ENV=production php cron/seo_fact_check.php --quiet` (nightly check; admins get an in-app notice when something drifts).
4. `APPLICATION_ENV=production php cron/indexnow_sitemap.php` once, then confirm a `[indexnow] ... accepted (202)` line in the PHP error log on the next article publish. Optional: set `indexnow_key` in app.ini to pin the key (otherwise it is derived and served at /<key>.txt).
5. Bing Webmaster Tools: URL Submission for /, /pricing, /features and the alternative pages.
6. Review the new copy: register success message, "link expired" / "already used" wording on the verify page.
Still open from the audit: payout facts for /features/payouts (minimum, timing, countries); About page facts (entity name, founder, Florida); the eight listings; screenshots approval; the signup and trial decisions.

## Suggested 30-day order
Week 1: P0 1-8 (all done in dev 2026-10-08; IndexNow added: pings on article publish/discard, profile save and first post, `cron/indexnow_sitemap.php` after deploy), home hero + pricing strip, brand description constant, quotable sentences.
Week 2: screenshots, About/Contact, profile footer and nav, llms-full clean-up, security headers, Growth tab, /health + monitor + backups.
Week 3: /onlyfans-alternatives and /fanvue-alternatives, thumbnails pipeline, LCP work.
Week 4: missing feature pages, niche pages, blog engine v2, signup flow and trial.
