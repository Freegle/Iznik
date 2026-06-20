# Freegle Helper — Implementation Plan

**Status**: In progress
**Created**: 2026-06-20
**Branch**: feature/bulk-offer-management (worktree /home/edward/FreegleDocker-bulk-mgmt)
**Design**: plans/active/freegle-helper-concierge.md (FSM, scoring, stages, tone — authoritative)

## Goal

Build the Freegle Helper AI concierge on top of the clearance feature. Implements
the FULL FSM from the design doc. State + scores + proposed decisions live in new
`helper_*` DB tables, served by the Go API. The AI loop runs on Edward's Claude
Code subscription (monitor-fsm-style poller + headless `claude`, parameterised for
ANY offerer account). The AI **auto-handles simple cases** (gathering questions,
factual answers, acknowledgements, nudges, timeouts — auto-sent) and **proposes
complex decisions** (allocations/promises, escalations, criteria-ambiguous,
overrides) for the human to confirm / edit / send. The /clearance management page
is reworked to show prioritisation/scoring/FSM state, a per-item summary grouped by
FSM state, a pause button, the proposal queue (confirm/edit/send), and outreach
(contacted-not-replied) collapsed until they reply. Helper-sent chat messages carry
an "AI" badge and are never reprocessed.

## Decisions (locked)
- Storage: DB tables + Go API.
- HITL: AI auto-sends simple; humans confirm/edit/send complex per FSM.
- Scope: full FSM.
- Execution: monitor-fsm-style scripts (bash poller, no LLM cost; headless claude on
  change; `ANTHROPIC_API_KEY` unset → subscription). Runs as ANY account (Link→JWT).
- Attribution: helper_sent_messages records chatmsgids (no core chat schema change).
- No LLM geography: haversine in the script from API lat/lng only.
- A "batch" = ONE clearance message (one msgid, many bulkitems). The clearance
  feature consolidated the old many-posts model into a single message.

## Schema (Phase 1 — Laravel migrations, source of truth)

### helper_batches  — one Helper run per clearance message
id PK · msgid FK messages CASCADE UNIQUE · offereruserid FK users CASCADE ·
status ENUM('active','paused','stopped') DEFAULT 'active' ·
briefing JSON NULL (criteria/constraints/prefs extracted by Claude) ·
lastpolledat TS NULL · lastrunat TS NULL · pausedat TS NULL ·
created_at · updated_at · INDEX(offereruserid)

### helper_repliers  — knowledge record per person per batch
id PK · batchid FK helper_batches CASCADE · userid FK users CASCADE ·
chatid FK chat_rooms SET NULL NULL ·
state ENUM(NEW,GATHERING,QUALIFIED,ALLOCATED,CONFIRMED,COLLECTED,PARKED_REPLIED,
PARKED_QUIET,ESCALATED,TIMED_OUT,WITHDRAWN,REJECTED) DEFAULT 'NEW' ·
collection_ok ENUM('yes','no','unknown') DEFAULT 'unknown' ·
criteria_met ENUM('yes','no','unknown','na') DEFAULT 'unknown' ·
transport_ok ENUM('yes','no','unknown') DEFAULT 'unknown' ·
distance_miles DECIMAL(7,2) NULL (haversine, never LLM) ·
is_connector TINYINT DEFAULT 0 · related_to BIGINT NULL ·
escalation_reason VARCHAR(512) NULL · parked_reason VARCHAR(512) NULL ·
next_action VARCHAR(512) NULL · other_items_mentioned TINYINT DEFAULT 0 ·
cooldown_until TS NULL · offerer_last_message_at TS NULL ·
last_processed_chatmsgid BIGINT NULL (dedupe inbound) ·
knowledge JSON NULL · created_at · updated_at ·
UNIQUE(batchid,userid) · INDEX(state)

### helper_item_states  — per replier per item: state + score
id PK · replierid FK helper_repliers CASCADE · bulkitemid FK messages_bulk_items CASCADE ·
state ENUM(<same FSM set>) DEFAULT 'NEW' ·
qty_wanted INT UNSIGNED DEFAULT 1 · qty_allocated INT UNSIGNED DEFAULT 0 ·
score DECIMAL(6,2) NULL · score_breakdown JSON NULL ·
created_at · updated_at · UNIQUE(replierid,bulkitemid) · INDEX(bulkitemid,state)

### helper_proposals  — complex decisions queued for human confirm/edit/send
id PK · batchid FK helper_batches CASCADE ·
type ENUM('allocation','message','rejection','escalation','reminder','withdrawal_notice') ·
replierid FK helper_repliers CASCADE NULL · bulkitemid FK messages_bulk_items SET NULL NULL ·
summary VARCHAR(512) NULL · proposed_text TEXT NULL (editable draft) ·
payload JSON NULL ({qty,state,userid,...}) · rationale VARCHAR(1024) NULL ·
status ENUM('pending','sent','dismissed','superseded') DEFAULT 'pending' ·
resolved_text TEXT NULL · resolvedat TS NULL · resolvedby FK users NULL ·
created_at · updated_at · INDEX(batchid,status)

### helper_sent_messages  — AI-sent chat messages (badge + dedupe outbound)
id PK · batchid FK helper_batches CASCADE ·
chatmsgid FK chat_messages CASCADE UNIQUE · chatid FK chat_rooms CASCADE ·
replierid FK helper_repliers SET NULL NULL ·
kind ENUM('gathering','answer','ack','nudge','allocation','rejection','reminder','withdrawal_notice','other') DEFAULT 'other' ·
auto TINYINT DEFAULT 1 · proposalid FK helper_proposals SET NULL NULL ·
created_at · INDEX(chatid)

Also: `*_migration.sql` idempotent SQL per table for production.

## API contract (Phase 2 — Go, iznik-server-go)

All authorised as offerer/mod of the message (reuse `isModForMessage` + fromuser check).

- `GET /helper/{msgid}` → `{ batch, repliers:[{...,item_states:[...]}], proposals:[...], sent:[...] }`
  (404/empty if no batch; only offerer/mod).
- `POST /helper` dispatch on `action`:
  - `HelperEnsureBatch` {msgid} → creates batch row if absent (idempotent), returns batchid.
  - `HelperState` {msgid, status} → active|paused|stopped (pause button + driver gate).
  - `HelperUpsertReplier` {msgid, userid, fields...} → upsert knowledge record (driver).
  - `HelperSetItemState` {replierid, bulkitemid, state, qty_wanted, score, score_breakdown} (driver).
  - `HelperProposal` {msgid, type, replierid?, bulkitemid?, summary, proposed_text, payload, rationale} → queue (driver).
  - `HelperResolveProposal` {proposalid, decision: 'send'|'dismiss', text?} → human action.
    On 'send': perform side effect by type — message→POST chat msg (+record sent);
    allocation→BulkInterestState Reserved + Promise + record; rejection→chat msg.
  - `HelperRecordSent` {msgid, chatid, chatmsgid, replierid?, kind, auto} (driver, after auto-send).

Driver authenticates as the offerer (existing JWT). Page calls as the logged-in offerer.

## FSM driver (Phase 3 — helper/ dir, monitor-fsm style)
- `helper/poll.sh` — bash+curl, polls chat list for the batch's offerer, compares
  unseencount/latest msg id vs sentinel file; emits "changed" only on delta. No LLM.
- `helper/run-loop.sh` — loop: poll; on change OR timeout-tick, `unset ANTHROPIC_API_KEY`
  and invoke `claude -p` with prompt + injected state (GET /helper) + new chat messages
  + precomputed distances (haversine in bash/node).
- `helper/prompt.md` — the FSM operating prompt: stages, scoring table, tone rules,
  transition table, no-LLM-geography, auto-vs-propose policy. Claude acts via the Go
  Helper API + chat send API (tools = curl wrappers documented in the prompt).
- `helper/config.example.env` — account link key, apiv2 base, msgid.
- `helper/haversine.mjs` — distance from lat/lng (never LLM).
- Auto-send: gathering/answer/ack/nudge/reminder/withdrawal_notice. Propose:
  allocation, rejection-by-allocation, escalation, criteria-ambiguous.

## Frontend (Phase 4 — iznik-nuxt3)
- `api/HelperAPI.js` (or extend MessageAPI) + store methods in stores/message.js:
  fetchHelper(msgid), helperSetStatus, helperResolveProposal.
- Rework:
  - `ClearanceManager.vue` — load helper state; **HelperStatusBar** (pause/resume +
    last-run time + active/paused); **proposal queue** at top; per-item summary.
  - `HelperItemSummary.vue` (new) — counts grouped by FSM state per item, at the top
    of each item.
  - `HelperProposalCard.vue` (new) — summary + rationale + editable text + Send/Dismiss.
  - `ClearanceManageItem.vue` — outreach group (GATHERING/NEW/PARKED_QUIET) collapsed
    until they reply; show score + FSM badge.
  - `ClearanceCandidate.vue` — score + score breakdown tooltip, FSM state badge, AI
    badge when last/any message was helper-sent.
  - `composables/useClearance.js` — extend with FSM-state groups, labels, score format.
- vitest specs for every new/changed component + composable.

## Validation (Phase 5)
- Go: `iznik-server-go/test/helper_test.go` green.
- Laravel: migration test green.
- vitest: new specs green (status API :12490, docker-cp pattern).
- Headless Chrome screenshots of reworked page (seed helper data) → PR.
- PR targets feature/bulk-offer-clearance (NOT master).

## Status table
| # | Task | Status | Notes |
|---|------|--------|-------|
| 0 | Plan + schema/API/driver design | ✅ | this file |
| 1 | helper_* migrations + Laravel test | ⬜ | |
| 2 | Go Helper API + helper_test.go | ⬜ | |
| 3 | FSM driver scripts + prompt | ⬜ | |
| 4 | Frontend rework + vitest | ⬜ | |
| 5 | Validate + screenshots + PR | ⬜ | |
