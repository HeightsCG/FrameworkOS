# Content, Keywords & Conversion — www.creatorlinkstudio.com (audited 2026-10-08, production, read-only)

## Summary
The public site is structurally complete (18 product/legal pages in the sitemap, 8 compare pages with dated sources, 17 daily blog articles, FAQ/Article/Breadcrumb schema everywhere) but it is **unproven and under-explained**: there is not a single product screenshot, testimonial, creator count, founder name, About or Contact page on the whole site, the only two listed creators are "Demo (@johnturnstile)" and one other, and Organization `sameAs` is empty. The money message contradicts itself: /pricing says Free cannot sell (take rate "—"), while every compare page, the best-of page, llms.txt and 15 blog articles advertise "3% to 20% by plan" or "20% on the free plan", and two live articles (including the one targeting "onlyfans alternative", the highest-volume keyword in the queue at 6,600/mo) still quote the retired $99/$249/$399 Creator/Pro/Studio pricing with worked arithmetic. The blog engine is good for an automated engine (specific, no fluff, daily cadence with no gaps, 7–10 internal links per article, no text duplication), but it has no bylines, no external citations by design, three near-identical "AI influencer money" articles, four LoRA-training articles aimed at hobbyists, and every non-AI article force-links to /features/character-generation because of a cluster-mapping bug. Conversion: the home H1 "Create. Share. Earn." carries no meaning or keyword, pricing is not visible on the home page, all CTAs (including "Start with Creator") open the same 5-field register modal, and a creator must then verify email, sign in and find Billing before seeing anything they came for.

## Findings

**[P0] Two live articles still quote retired pricing ($99 / $249 / $399, "Pro", 50/150/300 credits)** — /blog/how-to-choose-an-onlyfans-alternative (Sep 23; 5 stale passages: "Creator is $99 a month at 10%, Pro is $249 a month at 5%, and Studio is $399 at 3%", full crossover arithmetic "Pro beats Creator ... around $3,000 a month", "AI credits ... 50 a month on Creator, 150 on Pro, 300 on Studio") and /blog/how-to-monetize-content-as-a-creator (Sep 22; "the Creator plan is $99 a month ... Pro is $249 at 5%, and Studio is $399 at 3%"). /pricing says Creator $49 / Studio $199, 500 / 3,000 credits, no Pro. These are the two oldest articles and the two highest-intent ones (the OnlyFans one targets the #1 keyword by volume). Any creator who reads them and then sees /pricing will assume the site is unmaintained or deceptive. Fix: rewrite both via the admin editor (SeoDrafter::draft with `existing_article_id` and an editor note "update every price to the current plans; remove Pro") — 1 hour. Then add a guard: a nightly check that greps published `body_md`/`faq` for `\$\d+` and plan names not present in `SeoDrafter::context()` and flags the article (2–3 hours).

**[P0] The fee message contradicts itself across the site (and headlines "20%", the same number as OnlyFans/Fanvue)** — /pricing: Free take rate "—", "To sell, upgrade to Creator or Studio"; every /compare/* page, /best-creator-monetization-platforms, llms.txt and the SeoDrafter facts block: "Creator Link Studio: 3% to 20% by plan (falls as you grow)"; 15 of 17 articles: "20% on the free plan". Source: `app/controllers/PagesController.php:360-364` `our_facts()` takes min/max `fee_percent` over `PlanTiers::all()`, which includes Free (`libs/Classes/PlanTiers.php:83`, fee_percent 20). Result: on the comparison tables the one row that should win ("3–10%, from $49/mo") reads as "up to 20%", exactly OnlyFans' number, and the blog teaches a Free-selling tier that /pricing says does not exist. Fix: decide the truth (memory says Free = signup account with no creator tools, legacy Free creators grandfathered), then make `our_facts()` use selling tiers only ("3% to 10% by plan, from $49/mo"), add "Free accounts follow and buy; selling needs Creator or Studio" to `SeoDrafter::context()`, and rewrite the "20% on the free plan" sentences in the 15 articles (or accept them if Free does sell at 20%, in which case /pricing must say so). 2–4 hours.

**[P0] The only visible social proof is test data** — /creators lists 2 creators (meta description literally says "from 2 creators"); one is "Demo (@johnturnstile)" with H1 "Demo AI", description "Some content on this page is AI-generated" and a public event "Sep29 TEST Ended". The same two accounts feed the home page "Latest posts" mosaic (5 presigned S3 thumbnails) and are 2 of the 4 profiles in sitemap.xml. A cautious creator reads this as "nobody is here". Fix: exclude the demo account from directory, sitemap and the home feed (flag on the account, 1 hour); suppress the "from N creators" count below 10; until there are ≥10 real listed creators, drop "Creator directory" from the footer and the "Latest posts" section from home (or seed them with 5–10 real onboarded creators). 1–2 hours code, plus recruiting.

**[P1] Zero product screenshots on any product page** — `<img>` count excluding icons: /features 0, /pricing 0, /features/character-generation 0 (an image-generation feature with no generated image), /features/dm-agent 0, /features/payouts 0, all /compare/* 0, /monetize-your-content 1 (cover). Every page that ranks for "creator monetization platform", "OnlyFans alternative", "Fanvue alternative" (Fanvue, Fansly, Passes, Patreon, Beacons, Stan) shows the product in the first screen. The home hero's right-hand "Upload Once, Use Everywhere" card is a CSS mock, not the product. Fix: 6 WebP screenshots (public page with locked post, post editor audience selector, pricing/tiers settings, wallet/payout balance, inbox with an AI draft awaiting approval, influencer grid) placed in the /features hero, each feature sub-page "What You Get" block, and the home Create/Share/Earn tabs. 1 day.

**[P1] No About, Contact, team, address, press or social profiles (E-E-A-T)** — /about and /contact return 404; the only contact is support@creatorlinkstudio.com inside Terms §16 and Privacy §15; no postal address (Terms only says Florida law, AAA arbitration in Orange County); `SeoMeta::SOCIAL_PROFILES` is an empty array (`libs/Classes/SeoMeta.php:30-33`) so Organization `sameAs` never renders and the footer has no social links; blog `author` is the Organization, no byline, no author page. What a cautious creator checks before connecting a bank: who runs this, where, how long, how to reach a human, what others say, payout schedule/minimum. None of it is answerable today. Fix: /about (founder name and photo, Florida, year, what the company is, how payouts work, support hours, email), footer "About" and "Contact", fill `SOCIAL_PROFILES` (X, Instagram, LinkedIn, TikTok even if new), a Person author on articles with a short bio and `author` → Person schema. 1 day.

**[P1] Home page fails the 5-second test and carries no keyword in the H1** — H1 "Create. Share. Earn." (3 words, two of them greyed out at 1366px), then a second slogan "Keep It All Connected.", then the only explanatory sentence in grey body text. The `<title>` targets "Creator Monetization Platform" but neither H1 nor subhead does; the meta description "brings your profiles, content, subscriptions, payouts, and revenue into one simple workspace" (libs/Layout/login_form.php:12) is generic SaaS copy that does not match the page. Pricing is not on the home page except in the last FAQ answer. Fix: see "Five changes" below. 2–4 hours.

**[P1] Creator profile pages are dead ends** — /@summerlane: 61 words, 0 internal links; /@johnturnstile: 2 internal links, both to its own event/link. No site nav, no footer, no "Create your own page" link. Linktree, Beacons and Stan put a branded "Join" link on every creator page; it is their main acquisition loop and link graph. Fix: a small fixed footer line on public profiles "Made with Creator Link Studio — create your page" (hidden for Studio custom domains if desired), plus breadcrumbs/Organization schema. 2 hours.

**[P1] "OnlyFans alternative" (6,600/mo) is served by the wrong page type and is cannibalized** — /compare/onlyfans is a one-to-one "vs" page (706 words, title "Creator Link Studio vs OnlyFans"), while the query intent is a list of alternatives; the blog article /blog/how-to-choose-an-onlyfans-alternative targets the same keyword (seo_keywords id 6) and the best-of page also lists OnlyFans. Same pattern for fanvue (page + article). Fix: build /onlyfans-alternatives ("Best OnlyFans Alternatives in 2026: Fees, Payouts, What You Can Sell", 8-platform table from the existing compare data, CLS first with honest pros/cons, a migration section) and link the blog article and /compare/onlyfans to it; keep /compare/onlyfans for the brand-vs query. 1 day.

**[P1] Compare pages are thin for their query** — 687–853 words each, a 2-row fee table, a 6-row feature table, two bullet lists and 4 FAQs; no screenshots, no pricing calculator, no migration steps, no creator quotes; "Why Choose X" lists are generic. The dated source links (7 per page, Sep 21/25 2026) are the strongest element and should be kept. Fix: add a "what you keep at $1k / $5k / $20k a month" worked table (the arithmetic already exists in the blog), a 5-step "move without losing fans" section, 2 screenshots, and a "who should NOT switch" paragraph. 2–3 hours per page or one shared template change.

**[P1] Blog cluster mapping forces an AI-influencer link into every monetization article** — seo_keywords rows for "how to price a subscription tier", "how to monetize content as a creator", "monetize digital content", "how to sell pay-per-view content", "creator membership tiers" are all in cluster `ai-influencer-monetization` (dev DB ids 3, 4, 8, 9, 15), whose required feature link is /features/character-generation (`libs/Classes/SeoDrafter.php:12-18`). That is why the 30-day plan and pricing articles each carry a "Where an AI character fits" section. It dilutes topical relevance and reads as product-shoehorning. Fix: add clusters `pricing-and-selling` → /monetize-your-content and `memberships-ppv` → /features (or new feature pages), re-tag the keywords. 1 hour.

**[P1] Keyword gaps: the product's core purchase intents have no page** — no page for memberships/"sell content subscriptions", pay-per-view/"creator paywall", link-in-bio-with-payments, services/coaching, events/tickets, social publishing, custom domains or any niche ("fitness creators" has only a 313-word directory page). See the gap table. Fix: 6–8 feature/landing pages from the outline below. 3–5 days.

**[P1] Signup asks for more than the minimum and loses plan intent** — register modal: First name, Last name, Email, Password, Confirm password (5 fields) plus Google; "Start with Creator" / "Start with Studio" on /pricing and every "Get Started" all open the same modal with no plan or role carried through (all `href="/?auth=register"`); after submit the account is hard-gated on email verification ("check your email to verify your account, then sign in", ApiAuthController.php:66) and then the creator must find Billing to pick a plan. Creator path: landing → modal (1) → form (2) → email link (3) → sign in (4) → Billing → plan → setup (5–6). Fix: drop Confirm password and merge name into one optional field; add `?plan=creator|studio` and `?role=creator` to the register links and store them so the first signed-in page is /setup or Billing with the plan preselected; after the verify link, sign the user in directly. 3–4 hours.

**[P2] Blog engine details** — (a) all 17 articles are 1,829–2,058 words although the prompt asks for 1,200–1,800 and `MAX_WORDS` is 2,000 (Markdown::word_count must count differently from the rendered page); (b) `validate()` forbids every external link, so money/tax articles can never cite a source (hurts E-E-A-T); allow a short allow-list of authoritative domains (IRS, Stripe docs are out per rule, but platform help centres already used on compare pages are fine); (c) /blog/ai-chat-for-creators has no cover (falls back to og-image.png); (d) /blog/how-to-make-money-with-an-ai-influencer has " · Creator Link Studio" in its `<title>` while the other 16 do not; (e) cover-image prompt still uses the retired lavender/violet palette (SeoDrafter.php cover_motif/make_cover: "#8273f8, #5b4be0") while the site is orange — approved per memory, but the blog band and covers are now the only violet on the site; (f) `/blog?q=…` category chips are search pages (noindex, canonical /blog): fine, but there are no indexable category hubs; (g) in the dev queue three strong keywords ("flux lora training" 1,300/mo, "monetize online content" 590, "automated dm replies for creators") failed on "Claude request failed (network)" and were pushed +50 priority to the back of the queue (SeoDrafter.php draft(): transient errors get the same penalty as bad drafts) — network errors should not change priority; (h) topic overlap: three articles on "AI influencer money" (Sep 24, Sep 25, Oct 8) and two on "monetize content" (Sep 22, Oct 1) plus the 30-day plan; `duplicates()` compares titles only, so synonyms pass; add an intent check at seed time; (i) 4 of 17 articles are LoRA/Flux training guides for people who train models themselves — fine as top-of-funnel but they attract hobbyists, not paying creators; cap the cluster at ~15% of output.

**[P2] Feature sub-page titles are not keyword-led** — "AI character generation · Creator Link Studio", "AI DM agent for your inbox · Creator Link Studio", "Creator payouts · Creator Link Studio" (sentence case, brand-first weight). Searchers type "AI influencer generator", "AI chatter for OnlyFans", "creator payout". Rename /features/character-generation → /features/ai-influencer (301), title "AI Influencer Generator: Train One Character, Sell Unlimited Photos and Video".

**[P2] /features/payouts does not answer the payout questions** — 629 words, no schedule, minimum, countries, currency, hold period or chargeback policy (compare pages state exactly these for competitors: "7-day pending period", "bank transfer, crypto"). Add a facts table.

**[P2] The best-of page claims "with sources" but has 0 external links** — /best-creator-monetization-platforms: "Figures come from each platform's own pricing, help or legal pages, linked and dated on its comparison page"; the sub-pages do link. Add the source link per row (they already exist in PagesController::COMPETITORS).

## Page inventory (production, 2026-10-08; words = page text incl. ~300 words of nav/footer/auth-modal chrome)

| URL | Target query (from title/H1) | Title / H1 / URL agree? | Words | Answers query above the fold? | CTAs (primary count) | Proof | Missing vs top-ranking pages |
|---|---|---|---|---|---|---|---|
| / | "creator monetization platform" (title only) | No: H1 "Create.Share.Earn.", tag "Keep It All Connected." | 927 | No: product explained in 3rd text block | Get Started ×3, Join Free, See Pricing (→ same modal) | none | screenshots, price, creator count, testimonials, founder |
| /features | "features memberships pay-per-view AI tools" | Yes | 966 | Partly (one sentence) | 2 Get Started | none | screenshots per block, depth (36 H3s of 1–2 sentences) |
| /pricing | "pricing" | Yes | 900 | Yes (clear cards, "Keep 90%") | 3 "Start with …" → same modal | fee table, FAQ | calculator vs 20% platforms, screenshots, annual option, FAQ on payout timing |
| /features/character-generation | "AI character generation" (low volume) | H1 "One Character. Unlimited Photos and Video." | 756 | Yes | 2 | none | sample images/video, training time, cost per image, consistency proof |
| /features/dm-agent | "AI DM agent" | Yes | 660 | Yes | 2 | none | example conversation, approval screenshot |
| /features/payouts | "creator payouts" | Yes | 629 | Partly | 2 | none | schedule, minimum, countries, fees table |
| /compare/{fanvue,onlyfans,patreon,fansly,kofi,linktree,beacons,stan} | "X alternative" / "vs X" | Yes (title "vs", desc "alternative") | 687–853 | Yes (fee table first) | 2 | 7 dated source links each | list intent, screenshots, calculator, migration steps, quotes |
| /best-creator-monetization-platforms | "best creator monetization platforms 2026" | Yes | 1,145 | Yes | 2 | fee table, ItemList | source links, screenshots, per-platform pros/cons depth |
| /monetize-your-content | "how to monetize your content" | Yes | 732 | Yes | 2 | cheat sheet | examples with real numbers, images |
| /creators, /creators/fitness, /creators/beauty | "creator directory", "fitness creators" | Yes | 313–332 | n/a | 1 | "2 creators", one is Demo | real creators |
| /blog | "blog" | Yes | 1,142 | n/a | footer | 17 articles, dates | categories (chips are noindex searches), author |
| /blog/<slug> ×17 | one keyword each | Yes | 1,829–2,058 article words | Yes | 1 (in-article aside "Create Your Account") | FAQ schema, covers | byline, citations, images in body |
| /terms, /privacy | legal | Yes | 1,869 / 1,361 | n/a | – | dated Sep 21 2026, Florida/AAA | address, company entity name, refund/payout specifics |
| /@handle (4 in sitemap) | creator name | Yes | 61–591 | – | none | – | nav/footer, "create your page" link |

Not found (404): /about, /contact, /compare (index), /signup, /login (auth is `/?auth=register|login`).

## Keyword gap (creator-side and fan-side)

| # | Query (approx. monthly volume where known from seo_keywords) | Intent | Page today | Verdict |
|---|---|---|---|---|
| 1 | onlyfans alternative(s) (6,600) | list/commercial | /compare/onlyfans + blog article | wrong page type, cannibalized |
| 2 | creator monetization platform (880) | commercial | /, /best-creator-monetization-platforms, 2 articles | covered; home H1 does not carry it |
| 3 | fanvue alternative (720) | commercial | /compare/fanvue + article | covered, thin |
| 4 | patreon alternative | commercial | /compare/patreon | thin |
| 5 | fansly alternative | commercial | /compare/fansly | thin |
| 6 | link in bio with payments / link in bio for creators (1,300) / best link in bio for creators (590) | commercial | /compare/linktree only; keyword queued | missing landing page |
| 7 | creator subscription platform / sell content subscriptions | commercial | /features (one H3) | missing |
| 8 | pay per view content platform / how to sell pay-per-view content (320) | commercial | /features (one H3); keyword queued | missing |
| 9 | creator paywall / content paywall for creators | commercial | none | missing |
| 10 | AI influencer generator / create an AI influencer / AI influencer platform | commercial, high intent | /features/character-generation (titled "AI character generation") | misaligned title/URL |
| 11 | how to make money with an AI influencer (590) / AI influencer monetization (320) / virtual influencer income (210) | informational | 3 articles | cannibalizing each other; consolidate into one pillar + 2 angles |
| 12 | how to monetize content as a creator (1,900) / monetize digital content (700) / monetize online content (590) | informational | /monetize-your-content + 3 articles | covered, overlapping |
| 13 | AI chatter / automated DM replies for creators (260) / how to sell content in DMs (320) | commercial-informational | /features/dm-agent + 2 articles | covered |
| 14 | online creator platform (1,000) | commercial | article only | needs product page (home should own it) |
| 15 | how to sell coaching / services from a creator page | commercial | none | missing |
| 16 | sell tickets to live sessions / paid livestream for creators | commercial | none | missing |
| 17 | creator payouts / how creators get paid online (590) / creator payout schedule (210) | informational | /features/payouts (thin) | thin |
| 18 | post to all social media at once / cross-post from one place (390) | commercial | /features (one H3); keyword queued | missing |
| 19 | custom domain for creator page | commercial (Studio) | none | missing |
| 20 | best platform for fitness creators / fitness creator monetization (and beauty, coaches, musicians, podcasters) | commercial | /creators/fitness (directory, 313 words) | thin; wrong page type |
| 21 | sell photo sets / content bundles online | commercial | /features (one H3) | missing |
| 22 | stan store alternative / beacons alternative / ko-fi alternative | commercial | /compare/* | thin |
| 23 | taxes for online creators (1,600) / chargebacks for digital content (170) | informational | queued | missing (good blog targets, need citations) |
| 24 | fan-side: how to support a creator directly / where to find creators to subscribe to | navigational | /creators (2 creators) | not viable until populated |
| 25 | fan-side: "[creator name] Creator Link Studio" | navigational | /@handle | works, but pages have no nav |

### Proposed pages (priority = intent × feasibility; all reuse the public_page layout and existing facts)

1. **/onlyfans-alternatives** — Title "Best OnlyFans Alternatives in 2026: Fees, Payouts and What You Can Sell" — H1 "The Best OnlyFans Alternatives in 2026" — fee/payout table for 8 platforms (data already in COMPETITORS); who each suits (incl. honest "stay on OnlyFans if…"); how to move without losing subscribers (link to existing article). Repeat for /fanvue-alternatives later.
2. **/features/memberships** — "Sell Content Subscriptions: Membership Tiers for Creators" — H1 "Membership Tiers Fans Actually Upgrade To" — tiers/trials/promo codes; card billing + what you keep (10%/3%); screenshot of tier setup.
3. **/features/pay-per-view** — "Pay-Per-View Content Platform: Put a Price on Any Post" — H1 "Pay-Per-View Posts, Unlocked in One Tap" — blurred-until-paid, wallet credits, bundles; pricing cheat sheet; screenshot of a locked post.
4. **/link-in-bio** — "Link in Bio With Payments: One Page That Sells" — H1 "A Link in Bio That Takes Payments" — tracked links + memberships + services on one handle; vs Linktree/Beacons/Stan table (reuse compare data); screenshot of a public page.
5. **/features/ai-influencer** (301 from /features/character-generation) — "AI Influencer Generator: Train One Character, Sell Unlimited Photos and Video" — H1 same — gallery of 6 generated images + 1 clip; training steps and credit cost per image/video; moderation/adult policy in one line.
6. **/for/{fitness,beauty,coaches,musicians,podcasters}** — "Creator Platform for Fitness Creators: Memberships, Programs and Sessions" — H1 "For Fitness Creators" — the 3 offers that fit the niche with example prices; which features matter; a real creator example when available.
7. **/features/publishing** — "Post to Nine Social Networks From One Studio" — H1 "Publish Everywhere at Once" — network list, scheduling, automations, AI captions; screenshot of the editor's Distribution section.
8. **/features/services-and-events** — "Sell Coaching, Custom Work and Live Sessions From Your Page" — bookings, tickets, built-in video calls; screenshot.
9. **/about** and **/contact** — see E-E-A-T.

## Blog engine quality (6 articles read in full: Sep 22 monetize-content, Sep 23 onlyfans-alternative, Sep 27 train-a-lora-character, Sep 30 pick-a-platform, Oct 3 30-day-plan, Oct 7 price-a-subscription-tier; all 17 measured)

- **Useful, not filler.** Concrete worked arithmetic ("50 members at $20 … Creator leaves you $8,901"), ordered procedures, specific do/don't lists (LoRA dataset: angles, lighting, trigger word, checkpoints). Voice is direct, second person, no hype, no emoji. This is above the median of AI blogs. Weaknesses: the same product-facts paragraph (card for memberships, wallet $1 = 10 credits, take rate) appears in nearly every article; every article ends with the identical aside (17/17 share the sentence "Start earning from your own page…").
- **Targeting:** 1 keyword per article, title ≤70 chars, meta ≤155, slug = keyword. Good.
- **Length:** 1,829–2,058 article words, every one (engine's own cap is 2,000; prompt asks 1,200–1,800). Uniform length is a detectable AI signature; let the range float 900–2,200.
- **Headings:** 10–17 H2/H3 per article, TOC with anchors, FAQ (3–5) with FAQ schema. Good.
- **Internal links:** 6–10 per article to product pages and other articles (rule: ≥3 article links + 1 feature link). Good, except the cluster bug above forces /features/character-generation into pricing articles.
- **External citations:** 0 in all 17 (forbidden by `validate()`). For money topics this caps trust.
- **Author/date:** date shown; author = Organization in schema; no visible byline, no author page.
- **Images:** 1 AI cover each (violet 3D illustration, S3) + 3 related-article thumbnails; 1 article has no cover; no in-body images or screenshots.
- **Duplication:** no copied text between articles (8-word shingle overlap < 1.5% for every pair). Topic overlap: 3× AI-influencer income, 2× "monetize content" + 30-day plan, 2× "choose a platform" (which also overlap the product page /best-creator-monetization-platforms).
- **Cannibalization with product pages:** "onlyfans alternative" and "fanvue alternative" (article vs /compare/*), "creator monetization platform" (two articles vs /best-… ; the engine itself skipped a third as "already covered").
- **/blog index:** 17 posts in one list, 24 per page so no pagination yet (code handles `?page=`); "categories" are `?q=` searches, noindex with canonical /blog; meta title/description fine; RSS feed valid (17 items, `lastBuildDate` absent). Search form on the page.
- **Cadence:** one article per day, 13:01 UTC, Sep 22 → Oct 8 with no gaps (17 days, 17 articles). Dev DB shows 40 published (25 are dev-only `demo-*` seeds, 404 on prod) and 14 queued; prod queue not readable from here, and prod slugs differ from dev for several keywords (e.g. prod `how-to-choose-an-onlyfans-alternative` vs dev `onlyfans-alternative-how-to-choose`).
- **Queue sanity (dev):** remaining = pay-per-view (320), membership tiers (260), sell content in DMs (320), payout schedule (210), cross-post (390), link in bio (1,300 + 590), taxes (1,600, hard), chargebacks (170), AI influencer content (480), "creator payouts stripe" (140 — the brand rule forbids naming Stripe, so this keyword will produce an article that cannot say its own keyword; drop it), plus 3 network-failed ones. Sensible, but it is 14 keywords (two weeks) and then empty; nothing niche-specific, nothing fan-side, nothing on bundles/services/events/custom domains.
- **Prompt (SeoDrafter.php:160-162):** sound guardrails (no invented stats, no Stripe, ≤3 brand mentions). Gaps: no instruction to vary length/structure, no citation allow-list, no "do not restate the plan prices in every article" (which is what made the pricing go stale in 15 places).

## E-E-A-T and trust (what a cautious creator looks for before connecting a bank)

| Signal | Status |
|---|---|
| About / team / founder | missing (404) |
| Contact page / address / phone | missing; email only inside legal pages |
| Company entity name, registration, jurisdiction | Terms: Florida law, AAA arbitration Orange County; no entity name or address |
| Author identity on articles | none (Organization author, no byline) |
| Social profiles / sameAs | none (`SOCIAL_PROFILES` empty) |
| Press, partners, reviews, ratings | none |
| Creator count / earnings paid out | none; directory shows 2 incl. "Demo" |
| Payout specifics (schedule, minimum, countries, holds, refunds) | not on /features/payouts; Terms §4 covers refunds |
| Legal pages | present, dated Sep 21 2026, readable, TOC, 16/15 sections; good |
| Security/MFA | exists in product (MFA in login modal) but never mentioned publicly |
| Adult content policy | Privacy §12 "Adults only"; nothing clearer for adult creators deciding whether they are allowed |

## Conversion path

- Landing → "Get Started" (header, hero, section CTA, footer) → register modal (same page, 1 click) → 5 fields + ToS line → "Account created — check your email to verify your account, then sign in" → email link → sign in → app (creator must then open Billing and pick Creator/Studio, then /setup). **Creator: 5–6 steps before seeing the product; fan: 4.**
- Does the landing explain what it is in 5 seconds? **No** (H1 is a slogan; explanation is the 3rd block, grey text). Screenshots at 1366×768 and 390×844 confirm the fold shows "CREATE. SHARE. EARN." + "Keep It All Connected." + paragraph + 2 buttons.
- Pricing visible without clicking? **No** on home (only the last FAQ). On /pricing: yes, excellent.
- Is "free" clear? Partly: "Join Free. Sell When You're Ready." and "Free for fans. Creators pick a plan after signing up." — but a creator arriving from "free OnlyFans alternative" learns only on /pricing that selling costs $49/mo, and nothing on the site argues why $49 beats 20%.
- Creator-vs-fan split? Only the one line in the modal. All CTAs are identical; "Start with Creator/Studio" loses the plan.
- Minimum form? No: last name and confirm-password are unnecessary; Google sign-in is present (good).

### Five changes with the highest expected signup lift

1. **Hero rewrite (home_body.php tab_hero):** keep "CREATE. SHARE. EARN." as a small eyebrow; H1 "Sell Memberships, Posts and Services From One Page."; subhead "The creator platform that keeps the fee low: 10% on Creator, 3% on Studio, payouts to your bank. Free for fans."; buttons "Create Your Page" (primary, → register with role=creator) and "See Pricing". Title stays "Creator Monetization Platform"; meta description → the subhead. Expected: largest single lift in both CTR from search and modal opens, because the page finally says what it is and what it costs.
2. **Pricing strip directly under the hero:** three tiles (Fan Free $0 · Creator $49 keep 90% · Studio $199 keep 97%) with one line "Most subscription platforms keep 20%" and a link to /pricing. Removes the biggest unknown before the first click.
3. **Proof row before the FAQ:** 4 real screenshots, a founder note with name/photo/Florida, 3 short creator quotes with handles, "Payouts to your bank · Export everything · support@…". Replace the "Latest posts" mosaic (demo accounts) until there are real creators.
4. **Intent-preserving signup:** modal fields → Email, Password, Google (name asked in /setup); `?plan=` and `?role=` carried from every CTA into the account record; verify-link signs the user in and lands creators on /setup with the chosen plan preselected (fans on the feed). Cuts the creator path from 6 steps to 3.
5. **Section order:** Hero → Pricing strip → "Everything you sell, on one page" (with screenshots) → Publish everywhere (screenshot) → Proof row → Free for fans → FAQ → final CTA. Move "Up and running in an afternoon" into the proof row as three screenshots instead of three icons.

## What is already good (do not "fix")
- Clean, keyword-aligned titles/H1/URLs on /features, /pricing, compare, best-of, monetize pages; canonical, robots, OG, FAQ/Article/Breadcrumb/ItemList/SoftwareApplication+Offer schema everywhere.
- /pricing is clear and honest: "Keep 90% / 97%", take rate, limits table, add-on price, credit costs, card fees disclosed.
- Compare pages cite 7 dated source links each and say when facts were checked.
- Blog: daily with no gaps, specific and readable, strong internal linking, FAQ schema, no copied text between articles, search-page noindex handled, IndexNow ping on publish.
- Terms/Privacy are real, dated, sectioned and readable.
- Google sign-in on the modal; MFA exists.
- llms.txt / llms-full.txt exist and use the current plan prices.

## Quick wins (≤1 day each)
1. Rewrite the two stale-pricing articles; add the price-drift check (P0) — 3 h. Impact: stops the credibility leak on the top keyword.
2. Fix `our_facts()` fee range and the Free-plan sentence in the drafter context; sweep "20% on the free plan" (P0) — 3 h. Impact: compare tables stop headlining "20%".
3. Hide the Demo account from directory/sitemap/home feed; drop "from 2 creators" (P0) — 1–2 h.
4. Home hero copy + pricing strip + meta description (changes 1–2) — 3 h. Impact: highest conversion lift per hour.
5. Profile footer "Create your page" link + nav (P1) — 2 h. Impact: growth loop and internal link equity from creator pages.
6. /about + /contact pages, fill SOCIAL_PROFILES, add footer links — 4 h.
7. Re-cluster the seo_keywords rows and add the `pricing-and-selling` cluster; stop penalising priority on network errors; drop "creator payouts stripe" — 1 h.
8. Rename /features/character-generation → /features/ai-influencer with keyword-led title, add 6 sample images — 3 h.
9. Add source links to the best-of table — 30 min.
10. Add the payout facts table (schedule, minimum, countries, holds) to /features/payouts — 1 h once the facts are confirmed.

## Bigger bets (≥2 days)
1. **Screenshots + proof across all product pages** (P1) — 1–2 days incl. capture. Expected: the single largest trust lift; prerequisite for any paid traffic.
2. **/onlyfans-alternatives and /fanvue-alternatives list pages** + repoint the articles — 1–2 days. Expected: the only realistic route to the 6,600/mo head term.
3. **Six missing feature/landing pages** (memberships, pay-per-view, link-in-bio, publishing, services-and-events, custom domains) — 3–5 days. Expected: covers the purchase-intent queries the site currently has no page for.
4. **Niche pages /for/<niche>** (fitness, beauty, coaches, musicians, podcasters) — 2–3 days. Expected: long-tail "platform for X creators" traffic that converts better than generic terms.
5. **Blog engine v2:** named author with bio, citation allow-list, variable length, intent-level dedupe at seed time, 40–60 new seeded keywords across bundles/services/events/niches/fan-side, LoRA cluster capped — 2–3 days. Expected: sustains the daily cadence past the 2 weeks of queue left and makes the articles citable.
6. **Signup flow:** role/plan intent through register, 2-field form, auto sign-in after verify, land on /setup — 2 days (touches auth; needs Daniel). Expected: halves creator drop-off between modal and first product view.
