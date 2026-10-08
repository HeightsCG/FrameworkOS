# Growth instrumentation and production readiness — 2026-10-08

## Summary
GA4 (G-KQBG52F6SZ) and Microsoft Clarity load on production, Search Console is verified (DNS TXT) and Bing has BingSiteAuth.xml; the full event map (sign_up_start → sign_up → login → become_creator → creator_plan_change → purchase / join_membership) exists in `libs/Layout/google_analytics.php` and is wired to real API successes. The site is fast (TTFB 0.21–0.46 s on all six key pages), HTTP/2 + gzip, valid Amazon-issued TLS to 2027-01-22, no stack-trace leakage, sensitive paths blocked. The two things that will quietly kill signups are email deliverability (creatorlinkstudio.com has NO SPF, NO DMARC, NO MX and no DKIM record I could find, while the app hard-gates login on a verification email) and a seven-step path from "Get Started" to a paid creator with no in-product nudge, no trial, and no server-side funnel numbers to see where people fall off. Production ops are thin: no health endpoint, no documented backups, no error tracker, no uptime monitor, security headers mostly absent, and an unsigned POST to the Stripe webhook returns 500.

## Findings

### 1. Analytics and attribution

**[P1] No server-side funnel report** — `AdminModel::overview()` (app/models/AdminModel.php:21-66) has users, creators, active subs, MRR, platform take, moderation counts. Nothing per day, no verified %, no became-creator %, no paid-plan %. The data is already in `user_accounts` (`created_at`, `email_verified`, `role_id`, `subscription_status`, `acq_*`). Fix: add `AdminModel::funnel($days)` with one query — `COUNT(*)`, `SUM(email_verified)`, `SUM(role_id = creator)`, `SUM(subscription_status in paid)` grouped by `DATE(created_at)`, plus a `GROUP BY acq_source, acq_medium` breakdown — and a "Growth" tab on /admin. Effort: 3–4 h.

**[P1] Acquisition source only visible one user at a time** — `acq_*` columns are written at register (`record_first_touch`, ApiAuthController.php:57) and shown only on app/views/admin/user.php:88-93. No aggregate. Same fix as above (signups by source/medium/campaign/landing page, last 7/30/90 days). Effort: included above.

**[P1] GA4 conversions not marked / no Google Ads or Meta pixel** — home HTML has only the gtag + Clarity; no `AW-` conversion tag, no `fbq`. The events fire, but unless `sign_up`, `become_creator`, `creator_plan_change` and `purchase` are marked as key events in the GA4 property, no conversion reporting or ads optimisation exists. Fix (no code): GA4 Admin → Events → mark the four as key events; link Google Ads when ads start. If Meta/TikTok ads are planned, add the pixel to google_analytics.php behind a config key. Effort: 30 min (GA UI); 1 h per pixel.

**[P2] Verification completion is not tracked** — `verify_email` success (libs/Layout/verify_email.php:28-33) sends no GA event, so GA sees sign_up but never "email_verified". Fix: `CLSTrack('email_verified')` in `show_result(ok)`. Effort: 10 min.

**[P2] `creator_plan_change` has no `value`** — google_analytics.php:34 sends only `plan`. Add `value: price, currency: 'USD'` from PlanTiers so GA revenue reports work. Effort: 30 min.

**[P2] `begin_checkout` for memberships is never followed by a `purchase`** — `subscribe_plan` fires `begin_checkout`, but Stripe Checkout returns to a success URL with no event. Fix: on the subscription success return, fire `join_membership {tier:'paid', value}`. Effort: 1 h.

**Good:** GA tag and config present on prod (verified in fetched HTML), Clarity id present, `sign_up_start` fires when the register panel opens (public/js/landing.js:30), Google sign-in path tracked via `?signed_in=google&new=1`, first-touch cookie `cls_ft` (90 days) copied to `acq_*` at register, admins emailed on every signup (SignupAlertJob).

### 2. Signup funnel friction

The path today: Get Started → 5 fields + strong password rules → "check your email" → click link → page says verified, then redirects to `/` **logged out** after 2.5 s → open Sign In modal again → land on Home feed as a fan → find Settings → "Become a Creator" tab → accept agreement → `/account/billing?tab=plan` → add card + pick $49 → setup checklist. Seven screens before any creator value.

**[P0] Verification does not sign the user in** — `verify_emailAction` (ApiAuthController.php:200-230) only flips `email_verified`; the page then redirects to `/`. Every creator has to re-enter credentials. Fix: on success call `LoginGate::finish($user)` / `UserSession::start` (no MFA set yet on a new account) and redirect to `/account/settings?section=creator&welcome=1` (or straight to the plan page). Effort: 2 h.

**[P0] Signup never asks "creator or fan"** — register form fields (prod HTML): first name, last name, email, password, confirm. No intent. After login a would-be creator lands on the discovery feed with no prompt; the only "Become a Creator" buttons are on /dashboard (app/views/dashboard/index.php:38), Settings and Billing. Fix: add an "I want to: Create and sell / Follow creators" toggle to the register form, store it (`user_accounts.signup_intent`), and route creators to become_creator + plan on first login; show a persistent banner on Home for creator-intent users who have not finished. Effort: 1 day.

**[P1] Free creator has nothing to do** — `Plan::can_use_creator_features` (libs/Classes/Plan.php:55-62) requires a paid plan; Free creators get no public profile, no Studio, no setup checklist (SetupService gates on `can_use_creator_features`). So "Become a Creator" is immediately a $49 decision with a card. No trial (`PlanTiers::BILLING` has no trial field; docs/pricing-model.md: "Monthly only, no annual, no free tier"). Fix options: (a) 14-day Creator trial with card (one `trial_days` in BILLING + `BillingService::change_plan` first charge deferred), or (b) let Free creators build their profile/tiers/first post (publish stays gated) so the checklist runs before payment. (b) keeps the fee model intact. Effort: 1–2 days either way.

**[P1] Password policy is the strictest thing on the page** — 8+ chars, upper, lower, digit AND symbol (ApiAuthController.php:514-531). Typical abandonment driver; Google sign-in exists but the password route is primary. Fix: 8+ chars + not-common-password (or 10+ chars, no composition rules). Effort: 1 h.

**[P1] Verification email is sent synchronously and its failure is ignored** — `send_verification_email` is called inline (ApiAuthController.php:64) with a 15 s Postmark timeout (Notifications.php:111) and the return value is discarded; the user always sees "check your email". If Postmark rejects (e.g. unverified sender domain, see §3) the account is created, cannot log in, and nobody knows except error_log. Fix: check the return; on failure delete/flag the row and show "we couldn't send the email, try again or use Google"; and surface failed sends on /admin. Effort: 2 h.

**[P1] Register has no rate limit or bot check** — login/forgot/resend/promo all use `LoginAttemptsModel`; `registerAction` does not, and there is no captcha/honeypot (grep across app/libs: none). Bots will fill `user_accounts`, every one emails all admins (SignupAlertJob) and burns Postmark quota. Fix: `count_recent($ip, 'register', 60) >= 5` guard + a hidden honeypot field. Effort: 1 h.

**[P2] Promo codes exist only on the billing page** — `billing_change_plan` takes `promo_code` (ApiBillingController.php:100, billing_accounts.promo_*), but /pricing and the register flow never carry one (no `?promo=` → cookie → prefill). Fix: accept `?promo=WELCOME20` on /pricing and `/?auth=register`, store in `cls_ft`, prefill on the plan page. Effort: 2 h.

**[P2] Terms consent is passive** — "By creating an account you agree…" (libs/Layout/auth_modal.php:110). Fine for most jurisdictions; the Creator Agreement has an explicit checkbox later. No change needed unless legal asks.

**Good:** 24 h verification token with resend (`resend_verificationAction`, rate-limited), Google sign-in, login rate limit per IP (5/15 min) and per account (10/15 min), 7-step setup checklist with auto-detection (SetupService), become_creator → plan page redirect with "Free needs no card".

### 3. Email deliverability (DNS checked with dig from here)

**[P0] creatorlinkstudio.com has NO SPF and NO DMARC** — `dig TXT creatorlinkstudio.com` returns only `google-site-verification=…`; `dig TXT _dmarc.creatorlinkstudio.com` is empty. Mail is sent From `…@creatorlinkstudio.com` (app.ini `mail_from`) through Postmark. Gmail/Yahoo/Microsoft now require SPF or DKIM alignment and a DMARC record; without them the verification email (a hard login gate) lands in spam or is rejected. Fix (DNS, Route 53): `TXT @ "v=spf1 include:spf.mtasv.net ~all"`, `TXT _dmarc "v=DMARC1; p=none; rua=mailto:dmarc@creatorlinkstudio.com"` (move to `p=quarantine` after two clean weeks). Effort: 20 min.

**[P0] No DKIM record found** — I swept Postmark's `YYYYMMDDpm._domainkey` selectors for 2025-10 → 2026-10 plus pm/pm1/pm2/google/default/k1/s1/selector1/2: nothing. Either the Postmark DKIM TXT was never published (Postmark then signs with its own domain → not aligned) or it uses a selector I did not guess. Fix: Postmark → Sender Signatures → Domains → copy the DKIM TXT (name like `20260901pm._domainkey`) into Route 53 and click Verify; confirm "DKIM verified" + "Return-Path verified". Effort: 15 min.

**[P1] No MX record** — the From address cannot receive replies or bounce-backs; several filters score "no MX on sender domain" as spam. Fix: MX → Google Workspace / Postmark Inbound / forwarding (e.g. ImprovMX), and make `support@` a real mailbox. Effort: 30 min.

**Good:** Return-Path CNAME `pm-bounces.creatorlinkstudio.com → pm.mtasv.net` exists; sends go through the Postmark `outbound` stream with a 15 s timeout and errors logged (`[postmark] send failed`); header-injection stripping present.

### 4. Production system checks (from outside + code)

**[P1] Stripe webhook returns 500 to an unsigned POST** — `POST https://www.creatorlinkstudio.com/webhook/stripe` with `{}` → 500. Code path (WebhookController.php:88-104): 500 only when `stripe_webhook_secret` is missing for the environment, else 400 "invalid signature". Daniel has confirmed live webhooks deliver, so this is probably the env-key lookup returning empty for this request path, or an exception before `constructEvent`. Verify once in Stripe Dashboard → Webhooks → recent deliveries (should be 2xx). If they are 500, renewals/dunning (`BillingService::settle`) silently stop. Effort: 30 min to confirm; 1 h if the lookup is wrong.

**[P1] No health endpoint, no uptime monitor, no status page** — /health, /healthz, /status, /up, /api/health all 404; no monitor referenced anywhere in docs/code. Fix: `GET /health` → `{"ok":true,"db":true,"queue_lag_s":N}` (checks DB and `jobs` oldest pending), then point UptimeRobot/BetterStack at it and at `/`. Effort: 2 h.

**[P1] No error tracker and no documented log location** — 192 `error_log(` calls, no Sentry/Bugsnag/Rollbar, no `set_exception_handler`, no `display_errors`/`log_errors` in app/Bootstrap.php or .htaccess (relies on php.ini — nothing leaked in the 404, `/@%00` → 400, bad-param probes). Fix: Sentry PHP SDK (`sentry/sentry`) + `set_exception_handler`/`set_error_handler` in Bootstrap, DSN in app.ini, 1 h; or at minimum a daily `grep -c` of the Apache error log mailed to admins.

**[P1] Backups: none documented** — no mysqldump/snapshot/backup mention in docs/, cron/, or sql/ (only MFA "backup codes"). If prod MySQL is RDS, confirm automated snapshots + retention; if it is on the EC2 box, add a nightly `mysqldump | gzip | aws s3 cp` to content-os-bucket with lifecycle 30 d. Effort: 1 h + a documented restore test.

**[P1] Security headers missing** — response has only `x-frame-options: SAMEORIGIN, SAMEORIGIN` (appended twice: `.htaccess` + `public/.htaccess` both `Header always append`). No `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP. Fix (NOT via .htaccess edits on live without Daniel — a previous .htaccess change caused a 500): in the vhost/ALB, `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"`, `X-Content-Type-Options nosniff`, `Referrer-Policy strict-origin-when-cross-origin`; change one of the two `append` lines to `set`. Effort: 1 h, deploy by Daniel.

**[P2] Redirect chain uses an explicit `:443`** — `http://creatorlinkstudio.com/` → 301 `https://creatorlinkstudio.com:443/` → 301 `https://www.creatorlinkstudio.com:443/`. Browsers cope; some crawlers/UTM links treat `host:443` as a different URL and it is a double hop. Fix: redirect apex+http straight to `https://www.creatorlinkstudio.com/` (ALB rule or `RewriteRule … https://www.%{HTTP_HOST}/$1`). Effort: 30 min.

**[P2] /api returns an HTML 404 page for an unknown action and `text/html` for JSON** — `POST /api/no_such_action` → 404 text/html page; every JSON reply is `text/html; charset=UTF-8` by design (BaseApiController.php:6 comment). Works with the in-house `ApiDataSvc`, but any external/MCP client will choke. Fix: in Bootstrap's ApiRoutes dispatch, return `{"success":false,"message":"Unknown action"}` with 404 for unmatched actions; consider `Content-Type: application/json` on `/api/*`. Effort: 1 h.

**[P2] Cron/queue runners for prod are undocumented** — 11 scripts in cron/ (billing, queue_worker, scheduler, moderate, social_metrics, seo_draft, domains, site_images, …); only `cron/domains.php` has a crontab line written down (docs/custom-domains.md:30-32). Signup alerts, new-post fan-out and billing retries depend on `queue_worker.php` and `billing.php` running. Fix: one `docs/ops.md` with the prod crontab, expected cadence and "how to tell it is running" (last-run timestamp in a `cron_runs` table shown on /admin). Effort: 2 h.

**[P2] `login_attempts` never pruned** — `LoginAttemptsModel` has record/count only. Add `DELETE … WHERE created_at < NOW() - INTERVAL 7 DAY` to a nightly cron. Effort: 20 min.

**[P2] Session lifetime is php.ini default** — cookie flags are right (`secure; HttpOnly; SameSite=Lax`, domain `.creatorlinkstudio.com`, `session_regenerate_id` on login) but no `gc_maxlifetime`/`cookie_lifetime` and no "remember me", so idle sessions can expire after 24 min (observed `csrf_expired` responses point the same way). Fix: `ini_set('session.gc_maxlifetime', 86400*14)` + cookie lifetime for a remember-me checkbox. Effort: 2 h.

**Good:** TLS: Amazon RSA 2048, valid 2026-07-09 → 2027-01-22 (ACM auto-renews), TLSv1.3, chain verifies; HTTP/2; gzip (55.7 KB → 12.6 KB); static assets `cache-control: max-age=31536000`; TTFB 0.21–0.46 s across /, /pricing, /features, /blog, /creators, /compare/onlyfans (18 samples, all 200); `Options -Indexes` (/js/ and /css/ → 403); .env, app.ini, .git/HEAD, phpinfo → 404; `/@%00` → 400 with no trace; trusted-proxy aware `get_ip_address`; CSRF on every API POST (verified: `csrf_expired` JSON).

### 5. Monetization readiness

**[P1] Membership sales are blocked until the creator finishes Stripe Connect Express** — `subscribe_planAction` (ApiPostsController.php:476-480) refuses unless `payouts_enabled` on the creator's Express account, i.e. full KYC (identity, bank) before a single fan can subscribe. Credits-based sales (PPV, bundles, messages, services, events) work without Connect (earnings accrue as credits; Connect needed only to cash out). This asymmetry is not explained anywhere: the setup step says "Connect Stripe to receive your earnings" and the public site says only "Connect your bank once… cash out" (FeaturePages.php:140-141). Fix: either (a) let memberships sell before payouts are enabled (hold funds on the platform like credits, release after `payouts_enabled`), or (b) say it plainly on /pricing FAQ and in the setup step: "Memberships go live after you finish payout setup (about 5 minutes, ID + bank)". Effort: (b) 1 h; (a) 1–2 days.

**[P1] No trial, no annual plan** — `PlanTiers::BILLING` is monthly only; /pricing says "free trial" only as a creator-to-fan feature. Every paid creator starts at $49 with a card the same day they decide. Recommend a 14-day trial on Creator (card up front, GA `begin_checkout`) or annual at 2 months free. Effort: 1–2 days (BillingService first-charge date + quote line).

**[P2] Pricing consistency: OK** — /pricing (prod) shows Free $0 / Creator $49 / Studio $199 / +$15 per extra influencer, matching `PlanTiers::TIERS` and `ADDONS`; `pricing_rows()` is built from PlanTiers (not a live Stripe call), so a Stripe outage cannot blank the page. Plan purchase for a new creator: `billing_card_setup` (SetupIntent) → `billing_change_plan` → `BillingService::change_plan` charges immediately or returns `requires_action` for 3DS (`billing_pending`/`billing_confirm`). Credit purchase (`buy_credits` → `confirm_credit_purchase`) needs only a signed-in account. Neither requires Connect. No blocker found.

**[P2] Fan-side first payment has an extra hop** — fans need a credits wallet ($1 = 10 cr) before any PPV/service/event purchase; memberships go through Stripe Checkout. Fine, but the Home feed for a brand-new fan should show the wallet CTA on the first locked post (check in UI pass).

## What is already good
- GA4 + Clarity live; event map covers the whole funnel; first-touch attribution stored per user; Search Console (DNS) and Bing verified.
- Fast, HTTP/2, gzip, valid cert, no error leakage, CSRF + rate limits on login/forgot/resend/promo, trusted-proxy IP handling.
- Return-Path CNAME for Postmark exists; verification resend + 24 h token; Google sign-in.
- Prices on /pricing match code; plan purchase and credit purchase have no hidden prerequisite.

## Quick wins (≤ 1 day)
1. SPF + DMARC + DKIM + MX records (1 h total) — biggest single lever on signup completion.
2. Auto sign-in after email verification and send to the creator step (2 h).
3. Mark the four GA4 key events; add `email_verified` event and `value` on plan change (1 h).
4. Register rate limit + honeypot; check `send_verification_email` return (3 h).
5. `/health` + external uptime monitor (2 h).
6. Confirm Stripe webhook deliveries are 2xx in the dashboard (30 min).
7. Security headers + fix double X-Frame-Options via vhost (1 h, Daniel deploys).
8. Relax password composition rules (1 h).

## Bigger bets (≥ 2 days)
1. Creator-intent signup + guided first session (intent toggle → become_creator → plan → checklist), expected to lift fan→creator conversion several-fold because today the only prompt is buried in Settings. 1–2 days.
2. Creator trial (14 days, card up front) or "build before you pay" Free creator mode. 1–2 days.
3. Admin Growth tab: signups/verified/creators/paid per day, by acquisition source, by landing page; plus cron last-run and failed-email panels. 1 day.
4. Sentry + nightly DB backup to S3 + docs/ops.md with the prod crontab and a restore drill. 1 day.
5. Let memberships sell before Connect payouts are enabled (hold-and-release), or at least explain the requirement on /pricing and in the checklist. 1–2 days / 1 h.
