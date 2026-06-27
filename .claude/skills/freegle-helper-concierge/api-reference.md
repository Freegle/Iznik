# Freegle Helper — API reference

Exact contracts the concierge uses. Base URL is the V2 Go API (`/api` via the app, or
`http://apiv2.localhost:8192/api` locally). All examples assume the offerer's auth (see Auth).

## Auth
Two credentials:
- **Offerer session JWT** (Link key → JWT) — used for the `/helper` control plane and
  `/message` reads/state. The Helper authenticates as the offerer.
- **Scoped send token** — `POST /message/<msgid>/helpertoken` (offerer-authed) →
  `{"ret":0,"token":"<jwt>"}`. 24h, `purpose=helper_delegate`, bound to that one `msgid`. Use as
  `Authorization: Bearer <token>` ONLY for `POST /chat/<chatid>/message`. The server enforces:
  the chat must already be about `<msgid>`, the message must carry `refmsgid=<msgid>`, type is
  Default/Interested only, rate-limited 20/chat/24h, and the row is written `fromhelper=1`.

## Control plane

### GET /helper/<msgid>
Returns the whole batch state:
```
{ "batch": { id, msgid, offereruserid, status, briefing, lastpolledat, lastrunat, pausedat } | null,
  "repliers": [ { id, batchid, userid, chatid, state, collection_ok, criteria_met, transport_ok,
                  distance_miles, is_connector, related_to, escalation_reason, parked_reason,
                  next_action, other_items_mentioned, cooldown_until, offerer_last_message_at,
                  last_processed_chatmsgid, knowledge,
                  item_states: [ { id, replierid, bulkitemid, state, qty_wanted, qty_allocated,
                                   score, score_breakdown } ] } ],
  "proposals": [ { id, batchid, type, replierid, bulkitemid, summary, proposed_text, payload,
                   rationale, status, resolved_text, resolvedat, resolvedby } ],
  "sent": [ { id, batchid, chatmsgid, chatid, replierid, kind, auto, proposalid } ] }
```
`batch: null` means the Helper hasn't been started for this offer → `EnsureBatch` first.

### POST /helper  (body: `{ "action": "...", "msgid": <id>, ...fields }`)
| action | required fields | effect |
|--------|-----------------|--------|
| `EnsureBatch` | `msgid` | create the batch row |
| `SetStatus` | `msgid`, `status` ("active"\|"paused"\|"stopped") and/or `briefing` | set batch status / briefing |
| `UpsertReplier` | `msgid`, (`userid` or `chatid`) + any record fields | upsert the knowledge record. Record fields: `state, collection_ok, criteria_met, transport_ok` (string yes/no/unknown/not_applicable), `distance_miles`, `is_connector`, `related_to`, `escalation_reason`, `parked_reason`, `next_action`, `other_items_mentioned`, `cooldown_until` (RFC3339), `offerer_last_message_at`, `last_processed_chatmsgid`, `knowledge` (free JSON string) |
| `SetItemState` | `replierid`, `bulkitemid`, `state` | per-item state + `qty_wanted`, `qty_allocated`, `score`, `score_breakdown` |
| `Proposal` | `msgid`, `type` ("allocation"\|"message"\|"rejection"\|"escalation"\|"reminder"\|"withdrawal_notice"), + `replierid`, `bulkitemid`, `summary`, `proposed_text`, `payload`, `rationale` | create a pending proposal for the offerer |
| `ResolveProposal` | `proposalid`, `decision` ("approve"\|"reject"\|"edit"), optional `text` | offerer resolves; on approve, downstream effects fire |
| `Send` | `msgid`, (`replierid` or `chatid`), `body`, `kind`, `auto` | send a chat message AS the offerer, record it in `helper_sent_messages`, set `fromhelper=1` |

Replier states (FSM): `NEW, GATHERING, QUALIFIED, ALLOCATED, CONFIRMED, COLLECTED,
PARKED_REPLIED, PARKED_QUIET, ESCALATED, TIMED_OUT, WITHDRAWN, REJECTED` (plan §"Conversation State Tracking").

## Reads for judgement
- `GET /message/<msgid>` → `bulkitems[]` (catalogue) + per-item `interest[]` (owner-visible:
  `userid, firstname, created_at, quantity, cancollect, state, lat, lng` blurred) +
  `availablenow`, `availableinitially`, `deadline`, `textbody` (briefing source).
- `GET /chat/rooms?msgid=<id>&since_msgid=<cursor>` → only chat rooms about this offer, since a
  cursor (the #618 shortcut — O(one post), not O(all chats)).
- `GET /chat/<chatid>/message` → messages in a room (filter by id > `last_processed_chatmsgid`).
- `GET /user/<id>` → reputation for scoring: ratings (thumbs up/down), `replytime`, `reneged`
  count, `collected` history, and `lat`/`lng` (for distance).

## Allocation + outcome
- `POST /message {action:"BulkInterestState", id:<msgid>, bulkitemid, userid, state:"Reserved"}`
  — records the promise (`messages_promises`) + sends `CHAT_MESSAGE_PROMISED`. Over-allocation
  is rejected (409). `state:"Collected"` decrements `availablenow` + feeds stats. `state:"Rejected"`
  notifies the candidate.
- `POST /message/bulk/state {items:[{bulkitemid,userid,state}]}` — many transitions in one call
  (transactional, same guards).
- Outcome (mark the whole post Taken when done): the standard outcomes API used by My Posts.

## Distance (no LLM geography)
Haversine between the replier's `lat/lng` (`GET /user/<id>`) and the item's `lat/lng`
(`GET /message/<id>`). Miles = `3958.8 * 2*asin(sqrt(sin²(Δφ/2) + cos φ1·cos φ2·sin²(Δλ/2)))`,
φ/λ in radians. Never infer distance from a place name in chat.
