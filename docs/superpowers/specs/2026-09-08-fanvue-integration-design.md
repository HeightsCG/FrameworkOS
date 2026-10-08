# Fanvue integration — design (2026-09-08)

## Goal
Creators connect their Fanvue account once, then any post published or scheduled in
Creator Link Studio (manually, by an automation, or via the MCP connector) can also be
published to Fanvue. Fanvue is a peer paid-content platform, so the FULL post is
mirrored (caption, real media, audience, PPV price, publish time) — unlike social
cross-posting, which sends a teaser.

## Fanvue side (owner does this once)
* App in Fanvue Builder. App id `de5f6f6d-3e21-48ad-9aea-83270f1ed9be`,
  OAuth client id `b61f7ee9-f0ea-48ef-86db-e5a88762869e`. Client secret in app.ini only.
* Redirect URIs registered: `https://www.creatorlinkstudio.com/account/fanvue_callback`
  and the dev one `http://framework.contentos.cvk/account/fanvue_callback`.
* Scopes selected in Builder = scopes requested here:
  `openid offline_access offline read:self read:post write:post read:media write:media`.

## Config (app.ini, environment section)
```
fanvue_client_id     = 'b61f7ee9-...'
fanvue_client_secret = '...'
fanvue_token_key     = '<64 hex>'   ; optional; encrypts stored tokens (falls back to a key derived from db_pass)
```

## Data
Table `user_fanvue_accounts` (one row per creator):
id, user_id (unique), fanvue_user_uuid, handle, display_name, access_token (encrypted),
refresh_token (encrypted), expires_at (UTC), scope, status ('connected'|'disconnected'),
connected_at, disconnected_at, last_error, created_at, updated_at.

`posts.fanvue_post_uuid` varchar(36) NULL — set when mirrored.

## Components
* `FanvueService` (libs/Classes): OAuth (authorize URL with PKCE, code exchange with
  client_secret_basic, refresh with rotation), authenticated request helper (adds
  `X-Fanvue-API-Version: 2025-06-26`, auto-refreshes on expiry), `whoami`,
  `upload_media(bytes, filename, type)` (multipart session → signed part URLs → complete →
  poll until ready, ≤60s), `create_post(text, mediaUuids, audience, price, publishAt)`.
* `FanvueAccountsModel`: get_for_user, upsert_tokens, set_disconnected, encrypt/decrypt.
* `FanvueShareService::share(user, post, scheduled_iso)`: builds the Fanvue post from a CLS
  post — caption → text; media (original_key from S3, only `ready` + non-rejected) →
  upload; audience free → followers-and-subscribers, subscribers/ppv → subscribers;
  ppv price credits → cents (10 credits = $1; min 300 cents else audience-only);
  scheduled_iso → publishAt. Records fanvue_post_uuid. Never throws.
* Routing: 'fanvue' is a pseudo account id in the existing cross-post picker
  (`share_accounts`, scheduler `social_accounts`). `SocialShareService::share` splits it
  out and calls FanvueShareService; the rest go to Post for Me as today.

## UI
* Settings → Integrations: Fanvue card (icon, Connected/Not connected badge, handle,
  Connect / Disconnect). Connect → `api/fanvue_connect` returns authorize URL; callback
  `/account/fanvue_callback` exchanges the code, fetches /users/me, stores the row, redirects
  to `?section=connected&connected=1`.
* Studio composer + Scheduler rule form: Fanvue appears as an entry in the account list
  (id 'fanvue', platform 'fanvue') — no new controls.
* MCP: `create_post`, `publish_post`, `schedule_post` accept `share_accounts` (array of
  ids incl. 'fanvue'); automations already carry `social_accounts`.

## Gating / safety
Plan::can_social_post. Manager+ can connect/disconnect. Tokens encrypted at rest.
Sharing is best-effort: a Fanvue failure never fails the CLS publish; the error is
logged, stored in last_error, and surfaced in the automation run message.

## Testing
Dev app + a real Fanvue creator login: connect, publish image post, schedule post,
automation run with 'fanvue' selected, disconnect, forced token expiry → refresh.
