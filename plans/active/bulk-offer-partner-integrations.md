# Bulk Offer — Partner Integration Impact & Fan-Out Scheme

**Status**: Design / decision-needed
**Created**: 2026-06-18
**Relates to**: [`../../pr-walkthrough/prs/pr-618/bulk-offer-clearance-design.md`](../../pr-walkthrough/prs/pr-618/bulk-offer-clearance-design.md) (PR #618), [`freegle-helper-concierge.md`](freegle-helper-concierge.md)

## Why this exists

A bulk ("clearance") offer is **one `Offer` message carrying many items** (`messages_bulk_items` rows). All three outbound partner integrations assume **one post = one item**. This doc records how a bulk post reaches each partner today, and a concrete scheme for "fanning out" a bulk post into per-item virtual posts at the integration boundary so partners need no (or minimal) changes.

## How a bulk post reaches each partner with NO changes

### Freebie Alerts — push, we control
- **Add**: `POST {FREEBIE_ALERTS_API_URL}/freegle/post/create`, header `Key:`. Payload `{ id, title, description, latitude, longitude, images, created_at }`. `images` = comma-separated attachment URLs.
- **Remove**: `POST /freegle/post/{msgid}/delete`.
- Queued via `background_tasks` (`TaskFreebieAlertsAdd` / `freebie_alerts_remove`) from three approve paths (Go `handleApprove`, Laravel `ContentCheckService::processUnprocessed`, `AutoApproveCleanService::approve`); processed by `queue:background-tasks` → `handleFreebieAlertsAdd/Remove`. Config: `FREEBIE_ALERTS_API_URL`, `FREEBIE_ALERTS_KEY`.
- **Bulk today**: one freebie, `title`=subject ("Office Clearance"), `description`=textbody summary, `images`=**all items' photos concatenated**. Removed only when the whole post gets an outcome. No `type`/`item`/`url` field in payload (FA presumably builds clickthrough from `id`).

### Love Junk — push, we control; LJ calls back on reply
- **Create**: `POST {LOVE_JUNK_API}/freegle/drafts?secret=`. **Edit**: `PUT /freegle/drafts/{draftId}`. **Complete**: `POST /freegle/offers/{ljOfferId}/complete`. **Delete**: `DELETE /freegle/drafts/{draftId}`.
- Payload `{ freegleId, title, description, source, userData{userId,firstName,lastName}, locationData{postcode,latitude,longitude,area}, images:[{url}] }`. `title` = `messages_items[0].name` (**first item only**, `LIMIT 1`). Coords blurred.
- Cron `integrations:sync-lovejunk` (1-min), groups with `onlovejunk=1`, `arrival >= 24h`. Tracking table `lovejunk(msgid UNIQUE, timestamp, success, status JSON {draftId}, deleted, deletestatus)`.
- **Callback**: LJ user reply → `POST /apiv2/chat/lovejunk` `{ refmsgid, partnerkey, message, ljuserid, ..., offerid }` → `chat.CreateChatMessageLoveJunk`; `GetLoveJunkUser` validates `partnerkey` against `partners_keys` (name LIKE lovejunk), links `users.ljuserid`, stores `chat_rooms.ljofferid`.
- **Bulk today**: one draft, `title` = **first item only — all other items invisible**. Reply callback references the whole post, not an item. Commercial: monthly LJ revenue split with TN (`lovejunk:send-tn-invoice`) is computed by post counts.

### Trash Nothing — pull; TN also writes back
- TN polls `GET /api/changes?since=&partner=<key>` → ids; then `GET /api/message/{id}?partner=<key>`. Full `Message` payload: `subject`, `type`, `textbody`, `item:{id,name}` (**single**, nil for TN-origin), `attachments[]` (flat), `availablenow/availableinitially`, `tnpostid`, `location`, `groups`, etc. Partner key bypasses anon-stripping/blur.
- TN writes back: `PATCH /api/message` or `/api/message/tn/{tnpostid}` (edit, by id or tnpostid), `DELETE /api/message/{id}`, action `Promise`/`Taken`/`Received`/`PartnerConsent`. Inbound posts arrive by email with `X-Trash-Nothing-*` headers; photos scraped from `trashnothing.com/pics/...`.
- **Bulk today**: one message, single `item`, flat `attachments`, `availableinitially`=total qty. No sub-item structure; write-backs can't target an item.
- **V2 already adds** `bulkitems[]` to `GET /message/{id}` (PR #618) — and TN consumes this exact Go API.

## Decision: fan-out (we synthesise per-item posts) vs native (partner consumes `bulkitems`)

| Partner | Model | Fan-out difficulty | Recommendation |
|---|---|---|---|
| Freebie Alerts | push (we own) | **Low** — payload already per-item-shaped | Offer fan-out (they do nothing) |
| Love Junk | push (we own) + callback | **Medium** — needs id scheme, tracking-table key, callback resolution | Offer fan-out; agree count/accounting first |
| Trash Nothing | pull + write-back | **High** — virtualise reads *and* writes | Ask them to consume `bulkitems` natively |

## Synthetic-id fan-out scheme (for the push partners)

### Shared id namespace
Per-item virtual posts need stable, collision-free ids that map back to `(msgid, bulkitemid)`. Options:
- **(a) Reserved high range**: `virtualid = OFFSET + bulkitemid` where `OFFSET` is above any real `messages.id` (e.g. `1_000_000_000_000`). `bulkitemid` is already a unique PK, so this is 1:1 and reversible (`bulkitemid = virtualid - OFFSET`). Simplest; recommended.
- **(b) Composite**: `virtualid = msgid * 1e6 + position` — fragile (position can change), avoid.

A small resolver: `virtualid ⇄ bulkitemid ⇄ (msgid)` lives in one Go helper used by every fan-out + resolution path.

### Clickthrough resolution (required for both push partners)
Neither FA nor LJ stores a URL we give them for the *listing view* — FA builds from `id`, LJ deep-links from `freegleId`. So the message page (`/message/{id}`) and the V2 `GET /message/{id}` must accept a synthetic id and resolve it to the parent bulk post, ideally scrolling/highlighting the specific item. Without this, fanned-out links 404. **This is the gating item for fan-out — confirm with FA whether they deep-link by `id` before building.**

### Per-partner write paths

**Freebie Alerts**
- On approve of a bulk post: iterate `messages_bulk_items`; one `create` per item — `id=virtualid`, `title`=item name, `description`=item description (fallback post summary), `images`=that item's attachments (grouped by `bulkitemid`), lat/lng/created_at from the post.
- On per-item outcome (`BulkInterestState` → Collected, or quantity exhausted): `POST /freegle/post/{virtualid}/delete`. On whole-post delete: delete all virtualids.
- Queue change: `TaskFreebieAlertsAdd`/`remove` carry `{msgid, bulkitemid}`; `handleFreebieAlertsAdd/Remove` branch on presence of `bulkitemid`.

**Love Junk**
- Iterate items; one `POST /freegle/drafts` per item with `freegleId=virtualid`, `title`=item name, `images`=item attachments.
- Tracking: extend `lovejunk` to `UNIQUE(msgid, bulkitemid)` (nullable `bulkitemid` for normal posts) so each item has its own `draftId`/edit/delete lifecycle.
- **Callback**: `POST /apiv2/chat/lovejunk` arrives with `refmsgid=virtualid`. `CreateChatMessageLoveJunk` must resolve `virtualid → (msgid, bulkitemid)`, create/reuse the User2User chat against the real `msgid`, and record a `messages_bulk_items_interest` row for that `bulkitemid` so the concierge sees which item. `ljofferid` stored as today.
- **Commercial**: fanning out multiplies counts in `lovejunk:send-tn-invoice`. Agree with LJ/TN whether a clearance counts as 1 or N before enabling.

### Trash Nothing (native path — preferred)
- Expose existing `bulkitems[]` on `GET /message/{id}` (already in PR #618). A post is "bulk" iff `len(bulkitems) > 0`.
- Ask TN to: render items individually; map per-item promise/outcome to action `BulkInterestState` `{id, bulkitemid, userid, state}`.
- Fan-out alternative for TN is **not recommended**: would require injecting synthetic ids into `/api/changes`, synthesising `GET /api/message/{virtualid}`, and demuxing every write-back (`PATCH`/`DELETE`/`Promise`/`Taken` by id *and* `tnpostid`) to `(msgid, bulkitemid)` — a full read+write virtualization layer.

## Open decisions for Edward
1. FA: do they deep-link by `id`? (gates whether fan-out links work)
2. LJ: count a clearance as 1 post or N for the revenue split?
3. TN: willing to consume `bulkitems` natively, or do they want fan-out?
4. Confirm the reserved-id OFFSET is safely above max `messages.id` and won't collide with any other id consumer.

## Status of the feature
On branch `feature/bulk-offer-clearance` / PR #618 — **not merged**. This integration work is not yet built; the partner emails (sent separately) are advance notice while it's on a branch.
