# Events management — design

Date: 2026-09-25 · Status: approved in chat, pending spec review

## Problem

`/events` lists events and opens an edit form, but a creator cannot run an event: they can't see who
registered, what sold, message attendees, refund anyone, or cancel. The data already exists
(`events`, `event_registrations` with `status`, `price_credits`, `net_credits`). The form also
confuses two separate questions (who can attend vs. whether they pay) and has no notion of a
virtual vs. in-person event.

## Scope

In: an event page per event with Attendees / Messages / Settings; sales numbers; refund or remove one
attendee; message all attendees; cancel the whole event with automatic refunds; Virtual / In person
format; independent "who can attend" and "price".

Out (later): check-in at the door, waitlists, recurring events, fan self-cancel UI.

## 1. Events list — `/events`

- Same table; a row opens `/events/<id>` (row is `role=button`, Enter/Space, focus ring).
- Columns: **Event** (title; "Virtual" or the address's first line underneath) · **When** · **Price**
  ("Free" / "$15.00"; "Subscribers" prefix when members-only) · **Sold** (`18 / 25`, or `18` without a
  cap) · **Status** (Draft / Published / Canceled).
- **Create Event** opens the section editor in a modal (Details, Date & time, Location, Tickets,
  Publishing). On save it navigates to the new event's page.

## 2. Event page — `/events/<id>`

Routed by `EventsController::indexAction` reading `Main::get_url()[1]` (numeric id) → owner-scoped
`EventsModel::get_one($creator_id, $id)`; unknown id → 404. Same permission as the list
(`can_act_as_creator` + `team_allows('manage')`).

**Header**
- "Events /" breadcrumb link, title, status pill, "Sat, Aug 15 · 6:00 PM EDT", and "Virtual" or the address.
- Stat row: **Sold** (going / cap), **Gross** (sum of `price_credits` of going registrations, $),
  **Your earnings** (sum of `net_credits` of going), **Refunded** (count + $ of refunded).
- Copy Event Link (the public `/@handle` URL with the event anchor).

**Tabs** (`?tab=attendees|messages|settings`, attendees default; plain links, no JS routing).

### Attendees
- Table: avatar/name + @handle · Registered (date) · Paid ($ or "Free") · Status (Going / Canceled /
  Refunded / Removed) · row menu.
- Row menu (Going rows only): **Refund** (paid rows) — returns their credits, claws back
  `net_credits` capped at the creator's balance, status → `refunded`, notifies the fan;
  **Remove** — status → `removed`, frees the seat, no money moves, notifies the fan. Both confirm first.
- **Export CSV**: name, handle, email, registered at (creator tz), paid, status.
- Empty: "No one has registered yet." + Copy Event Link.

### Messages
- Composer: one textarea + **Send to N attendees** (N = going). Disabled at 0 attendees or empty text.
- Delivery: each going attendee gets it via `MessagesModel::send` from the creator (appears in their
  Inbox thread) + `Notify::send` (email if offline). Blocked pairs are skipped. >50 recipients are
  queued on the existing job queue (same pattern as broadcasts).
- History below: sent at · text · recipients count.

### Settings
The section editor rendered inline on the page (not a modal); Save stays on the page.
- **Details**: Title, Description.
- **Date & time**: Starts, Ends (creator timezone stated once).
- **Location**: Format toggle **Virtual / In person**. Virtual → **Video link** (required, http(s)).
  In person → **Address** (required). **Instructions** for either.
- **Tickets**: **Who can attend** Anyone / Subscribers; Subscribers → **Plan** (Any plan / one plan).
  **Price** Free / Paid → **Ticket price (USD)** ≥ $1. **Limit spots** switch → **Spots**.
- **Publishing**: Draft / Published.
- Danger zone at the bottom of Settings:
  - **Cancel Event** (published or draft with registrations): confirm → refund every paid going
    registration, notify every going attendee, status `canceled`. Irreversible. Canceled events stay
    listed, can't be registered for, and their Settings are read-only.
  - **Delete Event**: only when there are no going paid registrations; otherwise the button is
    replaced by "Cancel the event to refund attendees first."

## 3. Data

`sql/2026-09-25_events_management.sql` (applied on dev):
- `events.format VARCHAR(16) NOT NULL DEFAULT 'virtual'` (`virtual` | `in_person`).
- `event_messages` (`id`, `event_id`, `creator_id`, `body TEXT`, `recipients INT`, `created_at`),
  index on `event_id`.
- `event_registrations.status` values: `registered` (Going), `canceled`, `refunded`, `removed`. The
  row keeps `price_credits`/`net_credits` after a refund so history shows what was paid; a later
  re-registration on a refunded/removed row is charged again (`claim_registration` treats only a
  `canceled`-with-payment row as prepaid).
- Access/pricing keep existing columns: `access_type` ∈ free | paid | subscribers | tier encodes *who*
  (free/paid = anyone); `price_credits` is the ticket price for any audience (0 = free). Save and
  register already follow this (in-progress change finished here).

## 4. Server

`ApiEventsController`, all `require_creator('manage')` + ownership of the event:
- `event_attendees` (list + stats), `event_attendees_csv` (download; POST form so CSRF holds),
  `event_refund_attendee`, `event_remove_attendee`, `event_message_send`, `event_messages`,
  `event_cancel_all`.
- Refund logic shared with the fan cancel path (`EventsModel` + a small `EventRefunds` helper):
  claim the row with a conditional status update (mutex), credit the fan, claw back `net_credits`
  capped at balance.
- `event_save` gains `format`; validates video link (virtual) or address (in person).
- Fan confirmation email/notice shows the video link or the address by `format`.
- Register refuses canceled events.

## 5. Testing

- CLI model checks on dev: stats math, refund mutex (second refund no-ops), remove, cancel-all refunds
  every paid attendee exactly once, delete guard.
- Headless browser: list → page, tabs, refund/remove from the row menu, message send (with the
  emails it sends announced to Daniel first), Settings save, cancel event, mobile width, no JS errors.
