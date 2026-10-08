# Creator Link Studio — Pricing Model

Decided 2026-09-19. Monthly only (no annual), no free tier, no overage billing. Every tier
includes the full platform; tiers differ only on the rows below.

| | Creator $99/mo | Pro $249/mo | Studio $399/mo |
|---|---|---|---|
| Take rate on earnings | 10% | 5% | 3% |
| Team seats | 1 | 3 | 10 |
| AI influencers | 1 | 3 | 10 |
| AI credits per month | 50 | 150 | 300 |
| Scheduled automations | 5 | 20 | unlimited |
| Storage | 25 GB | 100 GB | 500 GB |

## AI credits

- One AI credit balance per account, separate from the fan-facing wallet ($1 = 10 wallet credits).
- Every billing date the plan adds its credits (50 / 150 / 300). Unused credits carry over.
- Top-ups: $1 per credit, packs of $10 / $25 / $50 / $100, into the same balance. Bought credits
  never expire.
- Prices: image 5 credits, enhance 1 credit, video 20 credits (Natural motion) or 30 credits (Cinematic, with sound). Charged when the run starts;
  refunded if the run fails or is cancelled. A retry pays again.
- Training, reference images and the training set cost nothing; the influencer count covers them.
- Brand images, captions and inbox AI are not metered.
- Upgrading mid-period adds the difference in monthly credits right away. Downgrading takes
  nothing away.

## Rules

- Reaching a count limit (influencers, automations, seats, storage) blocks adding more and offers
  an upgrade. Nothing already created is deactivated; existing items keep working.
- Running out of AI credits pauses generation (including scheduled automations, which resume on
  their own once credits arrive) and offers a top-up or upgrade. Nothing is billed automatically.
- An AI influencer = one trained model. Every non-deleted influencer counts (draft, training,
  ready or failed); retraining does not count as a new one.
- Automations = every rule, post or message, active or not.
- One creator profile per account on every tier.
- Connected social accounts and membership tiers are not limited.
- Plan changes are prorated by Stripe and keep the renewal date.
- Included on every tier: profile and link-in-bio, memberships, PPV, bundles, promo codes, free
  trials, events, services, DMs, broadcasts, audience CRM, analytics with export, cross-posting
  to all platforms, Fanvue integration, brand kit, AI brand images and captions, AI inbox replies
  and mass messages, Claude connector, moderation, MFA.

## Cost basis (measured 2026-09-18 on fal / OpenAI / S3)

Influencer training $2.43, image $0.035, video $0.30, storage $0.03 per GB-month, moderation and
captions under $0.02 per post. An AI credit sells for $1.00 and costs at most $0.10 to fulfil
(a video: 20 credits for $0.30 of compute).

## Where it is enforced

- `libs/Classes/PlanTiers.php`: the three tiers, the six rows (`ROWS`), what is included on every
  plan (`INCLUDED`), AI prices (`AI_PRICES`) and top-up packs (`AI_PACKS`).
- `libs/Classes/Plan.php`: `check_count()` for influencers / automations / seats,
  `period_bounds()` / `period_key()` for the billing period, `grant_monthly()`, `ai_price()`,
  `usage()` (billing page and the MCP `get_plan_usage` tool).
- `app/models/AiCreditsModel.php` + `ai_credit_transactions`: balance, ledger, idempotent
  purchase, guarded monthly grant.
- `libs/Classes/InfluencerJobService.php::create_job`: charges the credits; `fail_job`,
  `cancel_job` and the step error path refund them; `retry` charges again; `run_for_rule`
  checks the balance before spending anything on a scene prompt.
- Counts: `InfluencerActions::create` (web + MCP), `scheduler_saveAction` create branch and MCP
  `create_automation`, `team_invite` (seats), storage caps by `storage_gb`.
- Billing page `app/views/account/billing.php`: plan grid from `ROWS`, usage meters, AI credit
  balance and purchase, change plan (`change_subscription`, prorated, anchor unchanged).
- Stripe products must be named exactly Creator, Pro, Studio with one active monthly USD price
  each (9900 / 24900 / 39900). Tier is matched by product name.
