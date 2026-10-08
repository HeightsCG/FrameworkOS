# Events Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each event its own management page (`/events/<id>`) with attendees, sales, messaging, refunds, cancel, and a clear Virtual/In-person + who/price editor.

**Architecture:** Model-first. `EventsModel` gains stats/attendee/transition methods; a small `EventRefunds` class owns every money movement for events (refund one, cancel all) so the fan-cancel path and the creator actions share one tested implementation. `ApiEventsController` exposes creator-only endpoints; `EventsController` renders the list or the event page. The UI reuses the shared `.cs-ae-modal` section editor (site.css + `public/js/section-editor.js`), rendered inline for Settings.

**Tech Stack:** PHP 8.2 custom MVC, MySQL (PDO, native prepares — each named placeholder once per query), jQuery + Bootstrap 5 + SweetAlert2 + toastr, `ApiDataSvc.apiCall('post', …)`.

**Spec:** `docs/superpowers/specs/2026-09-25-events-management-design.md`

## Global Constraints

- Never `git commit` / push — Daniel commits and deploys. "Commit" steps below are replaced by "leave in working tree".
- Schema changes: apply by hand on dev (`mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos`) AND write `sql/2026-09-25_events_management.sql`.
- DB access only through model methods (`parent::select/insert/update/sql`) — never raw PDO in controllers.
- All API calls POST (BaseApiController rejects other methods); CSRF via `ApiDataSvc`.
- JS style: `var`, `el()` helper, toastr for feedback, SweetAlert2 confirms, never edit `public/js/api.data.js`.
- No helper text under inputs; labels + placeholder formats only. Button labels Title Case; headings/dialog text sentence case.
- Accent `#FF6A13`, buttons `#CD4C00` (existing `btn-primary`); no violet.
- Times stored UTC; display in the owner's `content_timezone`.
- Before any browser test that triggers notifications, tell Daniel which emails it will send; keep them (he wants them).
- Registration statuses: `registered` (shown "Going"), `canceled`, `refunded`, `removed`.
- `access_type` ∈ `free | paid | subscribers | tier` (free/paid = anyone); `price_credits` is the ticket price for any audience; `$1 = 10 credits`.

---

## File map

| File | Responsibility |
|---|---|
| `sql/2026-09-25_events_management.sql` (new) | `events.format`, `event_messages` table |
| `app/models/EventsModel.php` | + `format` in create/update, `stats()`, `attendees()`, `registration()`, `set_registration_status()`, `add_message()`, `messages()`, `going_user_ids()`, `has_paid_going()`; `claim_registration` prepaid only for `canceled` |
| `libs/Classes/EventRefunds.php` (new) | `refund_one()`, `remove_one()`, `cancel_all()` — every event money move |
| `app/controllers/api/ApiEventsController.php` | new endpoints; `event_cancel` (fan) delegates to `EventRefunds`; save validates format; register refuses canceled |
| `libs/Classes/ApiRoutes.php` | register new actions |
| `app/controllers/EventsController.php` | list vs. event page routing |
| `app/views/events/index.php` | list (new columns) + Create modal |
| `app/views/events/show.php` (new) | event page: header, stats, tabs, attendees, messages, settings |
| `app/views/events/_editor_sections.php` (new) | the editor sections, shared by Create modal and Settings tab |
| `public/js/events.js` | list + create modal + shared editor wiring (`EventEditor`) |
| `public/js/event-page.js` (new) | attendees actions, CSV, messages, settings save, cancel/delete |
| `public/css/events.css` | list + event page styles |
| `tests/events_management_test.php` (new) | model + EventRefunds checks on dev DB |

---

### Task 1: Schema + EventsModel reads/writes

**Files:** Create `sql/2026-09-25_events_management.sql`; Modify `app/models/EventsModel.php`; Create `tests/events_management_test.php`

**Interfaces — Produces:**
- `EventsModel::stats(int $event_id): array` → `['going'=>int,'gross'=>int credits,'net'=>int credits,'refunded_n'=>int,'refunded_credits'=>int]`
- `EventsModel::attendees(int $event_id): array` rows: `id,user_id,status,price_credits,net_credits,created_at,name,handle,email,avatar_url`
- `EventsModel::registration(int $event_id, int $reg_id): ?array`
- `EventsModel::set_registration_status(int $reg_id, string $from, string $to): bool` (conditional update = mutex)
- `EventsModel::going_user_ids(int $event_id): int[]`
- `EventsModel::has_paid_going(int $event_id): bool`
- `EventsModel::add_message(int $event_id, int $creator_id, string $body, int $recipients): int`
- `EventsModel::messages(int $event_id): array`
- `create`/`update_event` accept `format` (`virtual|in_person`)

- [ ] **Step 1: SQL file + apply on dev**

```sql
-- Events management (/events/<id>): virtual vs in-person, and the creator's messages to attendees.
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-25_events_management.sql
-- Already applied on dev (2026-09-25). Not yet on prod.
ALTER TABLE events ADD COLUMN format VARCHAR(16) NOT NULL DEFAULT 'virtual';
CREATE TABLE IF NOT EXISTS event_messages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id    INT UNSIGNED NOT NULL,
    creator_id  INT UNSIGNED NOT NULL,
    body        TEXT NOT NULL,
    recipients  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    KEY idx_event (event_id)
);
```

- [ ] **Step 2: Failing test** `tests/events_management_test.php` (same bootstrap as `tests/blocks_test.php`): create a throwaway event for creator 1 (paid 20 credits, cap 5, format in_person), insert registrations for fans 26 (paid, net 19) and 27 (free) via `claim_registration` + `set_paid`, then assert `stats()` = going 2, gross 20, net 19; `attendees()` has 2 rows with handles; `has_paid_going()` true; `set_registration_status(reg,'registered','removed')` true then again false; `going_user_ids()` = [26]; `add_message()` then `messages()` count 1; cleanup deletes the event, registrations and messages.

- [ ] **Step 3: Run** `APPLICATION_ENV=development php tests/events_management_test.php` → FAIL (undefined method `stats`).

- [ ] **Step 4: Implement** in `EventsModel` (native prepares: distinct placeholder names):

```php
public function stats($event_id){
    $r = parent::select("SELECT
            SUM(status = 'registered') AS going,
            COALESCE(SUM(CASE WHEN status = 'registered' THEN price_credits END), 0) AS gross,
            COALESCE(SUM(CASE WHEN status = 'registered' THEN net_credits END), 0) AS net,
            SUM(status = 'refunded') AS refunded_n,
            COALESCE(SUM(CASE WHEN status = 'refunded' THEN price_credits END), 0) AS refunded_credits
        FROM event_registrations WHERE event_id = :e", array('e' => (int) $event_id));
    $x = $r[0] ?? array();
    return array('going' => (int) ($x['going'] ?? 0), 'gross' => (int) ($x['gross'] ?? 0), 'net' => (int) ($x['net'] ?? 0),
                 'refunded_n' => (int) ($x['refunded_n'] ?? 0), 'refunded_credits' => (int) ($x['refunded_credits'] ?? 0));
}
public function attendees($event_id){
    return (array) parent::select("SELECT r.id, r.user_id, r.status, r.price_credits, r.net_credits, r.created_at,
            COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.u_name) AS name,
            u.u_name AS handle, u.user_email AS email, cp.avatar_url
        FROM event_registrations r JOIN user_accounts u ON u.user_id = r.user_id
        LEFT JOIN creator_profiles cp ON cp.user_id = r.user_id
        WHERE r.event_id = :e ORDER BY (r.status = 'registered') DESC, r.created_at DESC", array('e' => (int) $event_id));
}
public function registration($event_id, $reg_id){
    $r = parent::select("SELECT * FROM event_registrations WHERE id = :id AND event_id = :e", array('id' => (int) $reg_id, 'e' => (int) $event_id));
    return (is_array($r) && count($r) === 1) ? $r[0] : null;
}
public function set_registration_status($reg_id, $from, $to){
    return parent::update('event_registrations', array('status' => (string) $to), 'id = :id AND status = :from',
        array('id' => (int) $reg_id, 'from' => (string) $from)) > 0;
}
public function going_user_ids($event_id){
    $r = parent::select("SELECT user_id FROM event_registrations WHERE event_id = :e AND status = 'registered'", array('e' => (int) $event_id));
    return array_map(function ($x) { return (int) $x['user_id']; }, (array) $r);
}
public function has_paid_going($event_id){
    $r = parent::select("SELECT id FROM event_registrations WHERE event_id = :e AND status = 'registered' AND price_credits > 0 LIMIT 1", array('e' => (int) $event_id));
    return is_array($r) && count($r) === 1;
}
public function add_message($event_id, $creator_id, $body, $recipients){
    return (int) parent::insert('event_messages', array('event_id' => (int) $event_id, 'creator_id' => (int) $creator_id,
        'body' => (string) $body, 'recipients' => (int) $recipients, 'created_at' => date('Y-m-d H:i:s')));
}
public function messages($event_id){
    return (array) parent::select("SELECT * FROM event_messages WHERE event_id = :e ORDER BY id DESC", array('e' => (int) $event_id));
}
```

Also: add `'format' => (($f['format'] ?? 'virtual') === 'in_person') ? 'in_person' : 'virtual'` to `create()`, add `'format'` to the `update_event()` key list with the same normalisation; in `claim_registration()` the reactivation branch reads `status` and returns `prior_paid` only when the old row was `canceled` (a `refunded`/`removed` row reactivates with `prior_paid = 0`, and its `price_credits/net_credits` reset to 0). Update `attendee_count/is_registered` unchanged (they already use `registered`).

- [ ] **Step 5: Run test** → all `ok`.

- [ ] **Step 6: Leave in working tree** (no commit).

---

### Task 2: EventRefunds — refund one, remove one, cancel all

**Files:** Create `libs/Classes/EventRefunds.php`; Modify `tests/events_management_test.php`; Modify `app/controllers/api/ApiEventsController.php` (`event_cancelAction` delegates)

**Interfaces:**
- Consumes: Task 1 methods; `CreditsModel::apply_delta/get_balance`; `Notify::send`, `Notify::credits`, `Notify::name_of`.
- Produces:
  - `EventRefunds::refund_one(array $ev, int $reg_id, string $why = 'creator'): array` → `['ok'=>bool,'refunded'=>credits,'clawback'=>credits,'message'=>string]`. Claims `registered → refunded` first (mutex); credits fan `price_credits` (`refund`), claws back `min(net_credits, creator balance)` (`refund_reversal`), notifies the fan (and creator when `$why === 'fan'`).
  - `EventRefunds::remove_one(array $ev, int $reg_id): array` → `registered → removed`, no money, notifies the fan.
  - `EventRefunds::cancel_all(array $ev): array` → `['ok'=>true,'refunded_n'=>int,'notified'=>int]`; sets event `status = 'canceled'` first (so no new registrations), then refunds every paid going row via `refund_one($ev, $id, 'event_canceled')`, sets free going rows `registered → canceled`, notifies each attendee once ("<title> was canceled" + refund line when paid).

- [ ] **Step 1: Failing tests** appended: refund_one on the paid row → fan balance +20, creator −19, status `refunded`; second call → `ok=false`; remove_one on the free row → `removed`; a fresh event with 2 paid + 1 free → cancel_all refunds 2, event status `canceled`, no `registered` rows left; balances restored; cleanup.
- [ ] **Step 2: Run** → FAIL (class not found).
- [ ] **Step 3: Implement** `EventRefunds` (static methods, uses `new EventsModel()`, `new CreditsModel()`; event title decoded with `html_entity_decode`; when copy mentions the date use the reader's timezone via a private `when($ev, $uid)` identical to `ApiEventsController::event_when`, which then calls `EventRefunds::when` instead of duplicating).
- [ ] **Step 4:** `event_cancelAction` (fan): before start → find the fan's going row id and call `EventRefunds::refund_one($ev, $id, 'fan')` when paid, else `set_registration_status(id,'registered','canceled')`; after start → only `canceled`. Keeps its JSON (`refunded`).
- [ ] **Step 5: Run tests** → all ok. Leave in working tree.

---

### Task 3: API endpoints + save/register rules

**Files:** Modify `app/controllers/api/ApiEventsController.php`, `libs/Classes/ApiRoutes.php`

**Interfaces — Produces (all POST, `require_creator('manage')`, event must belong to the owner via `get_one($creator_id, $event_id)` else `jsonError('Event not found')`):**
- `event_attendees {event_id}` → `{stats, attendees:[{id,name,handle,avatar,registered,paid_dollars,status}]}`
- `event_attendees_csv {event_id}` → `text/csv` download (`Content-Disposition: attachment; filename="<slug>-attendees.csv"`), columns Name, Handle, Email, Registered, Paid, Status
- `event_refund_attendee {event_id, registration_id}` → `EventRefunds::refund_one(..., 'creator')`
- `event_remove_attendee {event_id, registration_id}` → `EventRefunds::remove_one`
- `event_message_send {event_id, body}` → body trimmed 1–2000 chars; recipients = `going_user_ids` minus `BlocksModel::related_ids(owner)`; each: `MessagesModel::get_or_create($owner, $uid)` + `send()` + `Notify::send($uid, 'events', $title, preview, '/inbox/thread/<conv>', 'fa-calendar-check', true)`; >50 recipients → dispatch job `event_message` (add `EventMessageJob` mirroring `BroadcastSendJob`, registered in `cron/queue_worker.php`); `add_message(...)`; returns `{recipients}`
- `event_messages {event_id}` → `{messages:[{sent_at, body, recipients}]}`
- `event_cancel_all {event_id}` → `EventRefunds::cancel_all`
- `event_delete` refuses when `has_paid_going()` ("Cancel the event to refund attendees first.")
- `event_save`: accepts `format`; virtual requires `external_url` http(s), in_person requires `location`; canceled events are read-only (`jsonError('This event was canceled.')`); returns `{id}`.
- `event_register`: refuses `status !== 'published'` (get_public already filters) — confirm canceled can't register.
- Fan confirmation notice: `event_where()` prints `Video link: <url>` for virtual, `Address: <location>` for in person.

- [ ] Steps: add each action; add names to `ApiRoutes::MAP` next to `'event_cancel'`; `php -l`; curl checks with a forged dev session (pattern in scratchpad `forge.php`) for: foreign event id → "Event not found"; attendees list shape; CSV headers; delete guard. Leave in working tree.

---

### Task 4: Event page + list

**Files:** Modify `app/controllers/EventsController.php`, `app/views/events/index.php`, `public/css/events.css`, `public/js/events.js`; Create `app/views/events/show.php`, `app/views/events/_editor_sections.php`, `public/js/event-page.js`

- **Routing:** `indexAction` → `$id = (int) (Main::get_url()[1] ?? 0)`; `$id > 0` → load owned event (404 via `Errors::page_not_found()` if missing), `stats`, `attendees`, `messages`, tiers, tz, owner handle → `$this->view->render('events/show')` (check `View::render` signature; else set the view name the way other controllers do). Else list.
- **List (`index.php`):** columns Event (title + "Virtual"/first line of address) · When · Price ("Free"/"$15.00", prefixed "Subscribers · " for subscribers/tier) · Sold (`going / cap` or `going`) · Status (Draft/Published/Canceled). Row → `location.href = '/events/' + id` (keyboard Enter/Space). Create modal = `.cs-ae-modal` containing `_editor_sections.php`; on success `location.href = '/events/' + o.id + '?tab=attendees'`.
- **Editor sections (`_editor_sections.php`):** Details (Title, Description) · Date & time (Starts, Ends) · Location (Format seg Virtual/In person → Video link | Address, Instructions) · Tickets (Who can attend seg Anyone/Subscribers → Plan select "Any plan"+paid plans; Price seg Free/Paid → Ticket price short field; Limit spots switch → Spots) · Publishing (Draft/Published seg). IDs `ev_*` as today plus `ev_format`, `ev_who`, `ev_pay`.
- **`EventEditor` (in `events.js`, exported on `window`):** `EventEditor(rootEl, {onSaved})` wires `SectionEditor`, segs, reveals, summaries, validation (title; start; end>start; virtual→link http(s); in_person→address; paid→price≥1; limit→spots≥1), `load(data)`, `save()` posting `event_save` with `access_type` derived (anyone→free/paid, subscribers→subscribers/tier), `price` when Paid, `format`.
- **Event page (`show.php`):** breadcrumb, title, status pill, when + where line, Copy Event Link, stat row, tabs (links `?tab=`). Attendees table with row menu (Refund/Remove, SweetAlert confirm, then `event_refund_attendee`/`event_remove_attendee`, reload), Export CSV (hidden form POST with csrf field to `/api/event_attendees_csv`), empty state. Messages: textarea + "Send to N Attendees" (disabled when N=0 or empty; confirm), history list. Settings: inline `.cs-ae-modal`-styled surface (class `cs-ae-modal cs-ae-inline` — add CSS so the surface is static, auto height, no fixed modal chrome) using `_editor_sections.php` + footer with Save Changes; danger zone: Cancel Event (confirm with count of paid attendees) / Delete Event (hidden when `has_paid_going`, replaced by the one-line reason). Canceled → Settings read-only (inputs disabled, no Save).
- **CSS:** stat row, tabs (active = accent underline), attendee table, status pills (Going green, Refunded/Removed/Canceled grey), messages list, danger zone; mobile ≤720px single column, table becomes stacked rows.
- [ ] Steps: build, `php -l`, `node --check`; leave in working tree.

---

### Task 5: Verify end to end

- [ ] `APPLICATION_ENV=development php tests/events_management_test.php` → all ok.
- [ ] Tell Daniel which emails the browser run sends (registration confirmations, a refund, a removal notice, one attendee message, cancellation notices) — then run headless Playwright with forged sessions (creator 1, fans 26/27): create event (virtual) → lands on page; fans register via API; attendees list + stats; refund one; remove one; send message; settings edit (switch to in person + address) saves; cancel event refunds remaining paid attendee; delete blocked while paid going, allowed after; mobile width screenshots; no console errors.
- [ ] Screenshot review against the Automation editor and the rest of the app (density, accent use, one primary button per view); fix anything off.
- [ ] Clean up throwaway events/registrations; report to Daniel with the SQL file to run on prod.
