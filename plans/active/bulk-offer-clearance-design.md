# Bulk Offer ("Clearance") — Structured Multi-Item Listings

**Status**: Design → Implementation
**Created**: 2026-06-02
**Branch**: `feature/bulk-offer-clearance`
**Relates to**: [`freegle-helper-concierge.md`](freegle-helper-concierge.md) (the AI concierge that sits on top of this data model)

## The Problem (from the Mind in Brighton case study)

A single offerer (e.g. Mind during an office clearance) has a large number of items to redistribute at once — ~120 items catalogued in a **spreadsheet** with **photos, measurements and condition**. Today Freegle only supports one item per post, so a clearance means ~120 separate posts, each composed and photographed individually, and replies scattered across all of them.

The case study describes the desired model precisely:

> "The full catalogue … was used to publish a coordinated batch of listings … presented to the local community as a **single, browsable offer**. Because many items were offered simultaneously, people were able to **express interest in more than one item in a single message**. Interest was captured against the **central catalogue**, so that for every item it was possible to see **who had asked for it, how many they wanted and when they were able to collect**."

The Freegle Helper concierge then manages the resulting conversations. **This work builds the foundation the concierge operates on**: the structured multi-item listing + per-item interest tracking. It does not build the concierge agent itself.

## Scope

In scope:
1. **Data model** — a message can carry a structured catalogue of items (name, quantity, condition, dimensions, description, photos), with per-item interest tracking (who, how many, when they can collect, state).
2. **Posting interface** — a "clearance" composer: add items as one-liners (name / qty / condition / dimensions / photos) **or** upload a spreadsheet (CSV). All items go into **one** post whose subject is the general offer (e.g. "Office Clearance").
3. **Browsing/response** — when viewing the post, a recipient ticks a **checkbox per item** and sets **how many** they want; their selection is recorded as interest against the catalogue and opens/produces a chat reply to the offerer.
4. **Email** — the catalogue renders sensibly in the digest/notification emails (item list with thumbnails, quantity, condition).
5. **Graceful degradation** — the message `textbody` always contains a human-readable summary of the catalogue, so any consumer that only knows `textbody` (search, V1 digest, plain-text email) still shows something useful.

Out of scope (later / concierge work): the Claude loop agent, allocation/scoring, promise automation, dashboard. Those consume this data via existing + new APIs.

## Data Model (separate tables, not JSON-in-body)

The user floated JSON-in-`textbody`. Rejected: the concierge and the UI both need to **query per-item interest and state** ("who wants item Y, how many, what state"). JSON blobs are unqueryable and fragile. We use normalised tables. The message stays a normal `Offer` (type unchanged); a bulk offer is simply one that has rows in `messages_bulk_items`.

Laravel migrations in `iznik-batch/database/migrations/` are the single source of truth.

### `messages_bulk_items` — the catalogue
| column | type | notes |
|---|---|---|
| `id` | bigIncrements | PK |
| `msgid` | unsignedBigInteger | FK → `messages.id` (cascade delete) |
| `position` | unsignedInteger default 0 | display order within the post |
| `name` | varchar(255) | item name (the one-liner) |
| `quantity` | unsignedInteger default 1 | how many offered (per item) |
| `condition` | enum(`New`,`LikeNew`,`Good`,`Used`,`Poor`,`Unknown`) default `Unknown` | from the case study's "condition assessed visually" |
| `dimensions` | varchar(255) nullable | free-text measurements ("120×80×74cm") |
| `description` | text nullable | optional extra detail per item |
| `created_at`/`updated_at` | timestamps | |

Indexes: `msgid`, (`msgid`,`position`).

### `messages_bulk_items_interest` — interest against the catalogue
| column | type | notes |
|---|---|---|
| `id` | bigIncrements | PK |
| `bulkitemid` | unsignedBigInteger | FK → `messages_bulk_items.id` (cascade) |
| `msgid` | unsignedBigInteger | FK → `messages.id` (cascade) — denormalised for easy per-post queries |
| `userid` | unsignedBigInteger | FK → `users.id` (cascade) |
| `quantity` | unsignedInteger default 1 | how many of this item they want |
| `cancollect` | varchar(255) nullable | when they can collect (free text) |
| `state` | enum(`Interested`,`Reserved`,`Collected`,`Withdrawn`,`Rejected`) default `Interested` | mirrors concierge item-states (Reserved≈promised/allocated, Collected≈rehomed) |
| `chatid` | unsignedBigInteger nullable | FK → `chat_rooms.id` — the conversation this interest belongs to |
| `created_at`/`updated_at` | timestamps | |

Unique key `(bulkitemid, userid)` — one interest row per user per item (re-expressing updates quantity/cancollect). Indexes: `msgid`, `userid`, `bulkitemid`.

This table answers the case-study requirement directly: for every item, who asked, how many, when they can collect, and current state.

### `messages_attachments.bulkitemid` — photos per item
Add a nullable `bulkitemid` column (FK → `messages_bulk_items.id`, ON DELETE SET NULL) to the existing `messages_attachments` table. A photo in a bulk listing belongs to exactly one catalogue item; `NULL` keeps existing post-level behaviour unchanged. (The pre-existing-but-unused `messages_attachments_items` table links to the global `items` catalogue, not per-message rows, so it is unsuitable.)

## API (V2 Go — `iznik-server-go`, where message reads & writes already live)

**Read** — `GET /message/{id}` gains a `bulkitems` array. Each entry:
```
{ id, position, name, quantity, condition, dimensions, description,
  attachments: [ {id, path, paththumb, ...} ],   // grouped by bulkitemid
  interestcount,            // distinct users interested
  interestedquantity,       // sum of requested quantities
  yourinterest: { quantity, cancollect, state } | null,  // for the calling user
  interest: [ {userid, quantity, cancollect, state} ]    // owner/mod only
}
```
A loader goroutine in `GetMessagesByIds` fetches the catalogue + grouped attachments + interest summary, mirroring the existing attachment/outcome fan-out. `bulk: true` and `bulkcount` are derived (presence-based) for list/summary use.

**Write (create/edit)** — `PUT /message` and `PATCH /message` accept an optional `bulkitems` array (each: position, name, quantity, condition, dimensions, description, attachmentids[]). The handler upserts `messages_bulk_items`, links attachments via `bulkitemid`, and rebuilds the `textbody` summary. `availableinitially` on the message is set to the total quantity across items.

**Express interest** — new `POST /message` action `BulkInterest`:
```
{ action: "BulkInterest", id: <msgid>, items: [ {bulkitemid, quantity, cancollect} ] }
```
Upserts interest rows for the calling user, ensures a `User2User` chat room with the offerer exists, and posts **one consolidated** `Interested` chat message referencing the post (`refmsgid`) summarising the selected items — so it flows through the existing reply/chat system and the concierge sees it. Clearing a tick (quantity 0) sets that row to `Withdrawn`.

**State (owner/concierge)** — `POST /message` action `BulkInterestState` `{ id, bulkitemid, userid, state }` transitions an interest row (Interested→Reserved→Collected, or →Rejected/Withdrawn) with permission checks (offerer or mod only).

All write paths keep `textbody` in sync with a readable summary (graceful degradation).

## Frontend (`iznik-nuxt3`)

- **Composer** — a "Clearance / many items" entry into the give flow. A `BulkItemEditor` component renders a list of item rows; each row is a one-liner (name, qty, condition select, dimensions) with a compact `PhotoUploader` (per item). A "Upload spreadsheet" control parses a CSV (papaparse — already used in modtools) with columns `name,quantity,condition,dimensions,description` into rows. Submits via the compose store → `PUT`/`POST` with `bulkitems`.
- **Display** — `MessageExpanded` renders the catalogue when `message.bulkitems` is present: each item shows photo, name, "N available", condition, dimensions, a **checkbox** and a **quantity** stepper (capped at available). A single "Register interest" action sends `BulkInterest` for all ticked items, then opens the chat. Reuses `useReplyStateMachine` for auth/send.
- **List/summary** — `MessageSummary` shows a bulk indicator ("Office Clearance · 12 items") and a small thumbnail strip.
- Follows existing conventions: `bootstrap-vue-next`, FontAwesome `<v-icon>`, `NumberIncrementDecrement`, SCSS `/* */` comments.

## Email (`iznik-batch` UnifiedDigest — the live immediate path)

`preparePosts()` adds `items` (the catalogue) to each post. Templates render it:
- **MJML** (`unified.blade.php`): below the description, an `<mj-table>` row-per-item (40×40 thumbnail + name + "×qty" + condition), following the existing jobs-table pattern. The OFFER pill shows the item count.
- **AMP** and **text**: equivalent item list. Text: `  - {name} ×{qty} ({condition})`.
The V1 daily-digest path renders `textbody`, which carries the readable summary, so it degrades gracefully without V1 changes.

## Testing

- **Migrations**: covered by the test-DB rebuild (`migrate:fresh`); add a Laravel test asserting tables/columns + cascade behaviour.
- **Go**: handler tests — create with `bulkitems`, `GET` returns catalogue + grouped attachments + interest summary + `yourinterest`; `BulkInterest` upsert + consolidated chat message + idempotent re-express; `BulkInterestState` permissions; quantity cap. Run via status API `/api/tests/go`.
- **Laravel**: digest render test asserts the item table appears for a bulk offer and the text summary lists items. Run via `/api/tests/laravel`.
- **Frontend**: Vitest unit tests for `BulkItemEditor` (add/remove/CSV parse) and the display checkbox→payload mapping. Playwright E2E (via Chrome MCP and `/api/tests/playwright`) for compose-a-clearance and express-interest flows.

## Key decisions / rationale
- **Separate tables, not JSON** — the concierge needs queryable per-item interest + state.
- **Still a normal `Offer`** — no new message type; presence of `messages_bulk_items` rows flags it. Minimises blast radius (no `messages` migration, no V1 parity break).
- **Interest flows through chat** — reuses the existing reply/promise/notification machinery the concierge already understands; the new tables add the per-item structure on top.
- **`textbody` summary always maintained** — every non-V2 consumer keeps working.
