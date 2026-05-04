# Post Review Service — Design Spec

**Date:** 2026-05-04  
**Status:** Draft — pending user approval

## Overview

Enable groups to opt in to automated post review, as a prerequisite for moving toward post-publication moderation. For members on the Default tier, posts enter a new silent `AutoReview` collection state immediately on submission, are processed by a background review pipeline, and emerge as either `Approved` or `Pending` within a few seconds. Moderators never see the intermediate state and are not notified until a post reaches `Pending`.

This spec also covers two companion improvements: consolidation of the overlapping `worrywords` and `spam_keywords` systems into a unified concern taxonomy, and wiring rspamd into the mail pipeline as a proper milter.

Moderator-facing language throughout: **"automated review"** or **"review helper"**. Never "AI".

---

## Non-Goals (prototype scope)

- Auto-reject (remains a human action always)
- Safety disclaimer auto-injection (post-prototype feature — see Safety Triggers section)
- Automated assignment of Trusted status
- Replacing the existing spam/spammer infrastructure (Spam.php, spam_users, etc.)
- Changes to how Moderated or Trusted tier users are processed

---

## 1. Membership Tiers

The four tiers map onto existing `POSTING_*` constants — no new DB columns needed:

| Tier | Existing constant | Behaviour |
|---|---|---|
| Trusted | `POSTING_UNMODERATED` | Straight to Approved — no review |
| Default (AI) | `POSTING_DEFAULT` | AutoReview → pipeline → Approved or Pending |
| Moderated | `POSTING_MODERATED` | Straight to Pending — no pipeline call |
| Can't Post | `POSTING_PROHIBITED` | Rejected at submission |

For Default tier on groups **without** `ai_review: true`: existing behaviour is preserved (currently Pending for new members per group logic).

---

## 2. New Collection State: `AutoReview`

Add `AutoReview` to the `messages_groups.collection` enum via a Laravel migration:

```sql
ALTER TABLE messages_groups MODIFY COLUMN collection
  ENUM('Incoming','Pending','Approved','Spam',
       'QueuedYahooUser','Rejected','QueuedUser','AutoReview');
```

Rules:
- Posts in `AutoReview` are **not** shown in the mod pending queue
- Posts in `AutoReview` are **not** visible to other members
- **No mod notifications** are triggered for `AutoReview` posts
- Posts transition to `Approved` or `Pending` within ~5s under normal conditions
- A sweeper job (Laravel scheduled command, runs every minute) moves any post that has been in `AutoReview` for > 60 seconds to `Pending` (service failure fallback)

---

## 3. Architecture

```
User submits post
      │
      ▼
Go backend (message.go) — fast, synchronous
      │
      ├─ Check tier + group opt-in
      │
      ├─ Trusted          → set Approved, done
      ├─ Moderated        → set Pending, notify mods, done
      ├─ Can't Post       → reject, done
      └─ Default + ai_review:true
              │
              ├─ Set collection = AutoReview   ← returns to user immediately
              └─ Fire-and-forget goroutine ──→ POST /review (no await)
                                                      │
                                              post-review service
                                              (Node.js, ai-flower)
                                                      │
                                              FSM pipeline
                                              (PII → Keyword → LLM)
                                                      │
                                              PATCH /api/internal/message/{id}/collection
                                              → Approved | Pending
```

The Go backend fires the HTTP request and immediately returns to the user without waiting for the verdict. The post-review service calls back via an internal API endpoint to update the collection.

If the service is unavailable, the fire-and-forget call fails silently. The 60-second sweeper then catches the post and moves it to Pending.

---

## 4. post-review Service

New Node.js container at `post-review/`, following the `monitor-fsm/` pattern.

- **Runtime:** Node.js, ai-flower, Gemini Flash Lite adapter
- **Storage:** SQLiteStorage (ai-flower built-in) — one FSM instance per post, full transition audit trail
- **HTTP endpoints:**
  - `POST /review` — accepts post content and group context, starts FSM instance (responds 202 immediately)
  - `GET /health` — liveness check
- **Network:** Internal Docker network only, not routed through Traefik
- **Credentials:** `GEMINI_API_KEY` environment variable; internal callback URL via `INTERNAL_API_BASE`

Request payload from Go:

```json
{
  "messageId": 12345,
  "subject": "Sofa",
  "body": "Brown corner sofa, good condition. Collection from...",
  "groupId": 42,
  "groupRules": { "weapons": true, "alcohol": false, "food": true },
  "groupCentreLat": 51.5,
  "groupCentreLng": -0.12,
  "groupAreaRadiusMiles": 20
}
```

---

## 5. ai-flower FSM

### States

```
START (start)
  → unconditional → PII_CHECK

PII_CHECK (tool — host-driven)
  → pii_found   → PENDING_END
  → pii_clean   → KEYWORD_CHECK

KEYWORD_CHECK (tool — host-driven)
  → keyword_flagged → PENDING_END
  → keyword_clean   → LLM_REVIEW

LLM_REVIEW (agent — Gemini Flash Lite)
  → approve     → APPROVED_END
  → pending     → PENDING_END
  → error       → PENDING_END  (fallback)

APPROVED_END (end)
PENDING_END   (end — carries reason tags)
```

### FSM Context

Passed through all states and accumulated:

```typescript
{
  messageId: number,
  subject: string,
  body: string,
  groupId: number,
  groupRules: Record<string, boolean>,
  groupCentreLat: number,
  groupCentreLng: number,
  groupAreaRadiusMiles: number,
  reasons: Array<{ tag: string, detail: string }>,  // accumulated
}
```

### End-state callback

On reaching `APPROVED_END` or `PENDING_END`, an `onInstanceCompleted` hook fires a `PATCH` to the internal API with `{ collection, reasons }`. Reason tags are stored in the `messages_groups` row for moderator display.

---

## 6. Stage 1: PII Detection (local — no API call)

Regex patterns (UK-focused), run in-process. Post content never leaves the machine at this stage.

| Type | Pattern |
|---|---|
| UK mobile | `07\d{9}` and spaced variants |
| International phone | `\+44[\s\d]{10,}`, `00 44 ...` |
| Email address | Standard RFC pattern |
| UK postcode | `[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}` |
| Street address | House number + road/street/lane/avenue/close/drive/crescent/way |
| Occupancy phrases | "only day I'm home", "I'll be in on [day]", "must collect [day]", "I'm away from [date]", "home all day [day]", "away [date] to [date]" |

On any match: add reason tag `pii:<type>` (e.g. `pii:phone`, `pii:postcode`, `pii:occupancy`), transition to `PENDING_END`. Post never reaches the LLM.

Moderator-visible label: "Possible personal information detected — please ask the member to remove it."

---

## 7. Stage 2: Unified Keyword Check

### The current problem

`worrywords` and `spam_keywords` are overlapping systems with no principled boundary. Both exist to route posts for review, but with different matching mechanics, different schemas, different per-group support, and separate management UIs.

### Unified concern taxonomy

Replace both tables with a single `concern_keywords` table. Existing entries are migrated in. The modtools management UI is updated to manage this single table.

**Schema:**

```sql
CREATE TABLE concern_keywords (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  keyword     VARCHAR(255) NOT NULL,
  substance   VARCHAR(255) NULL,          -- human-readable description (from old worrywords)
  category    ENUM(
                'substance_regulated',    -- UK legally controlled (old: Regulated)
                'substance_reportable',   -- must be reported (old: Reportable)
                'substance_medicine',     -- prescription/OTC medicines (old: Medicine)
                'scam',                   -- commercial spam, phishing, wire transfers (old: spam_keywords Spam)
                'review',                 -- general flag for human attention (old: Review in both)
                'allowed'                 -- whitelist: suppress other matches (old: Allowed)
              ) NOT NULL,
  match_mode  ENUM('fuzzy','literal','regex') NOT NULL DEFAULT 'literal',
  exclude     TEXT NULL,                  -- regex exclusion pattern (from old spam_keywords)
  scope       ENUM('global','group') NOT NULL DEFAULT 'global',
  group_id    INT NULL,                   -- set when scope = 'group'
  action      ENUM('block','flag') NOT NULL DEFAULT 'flag',
  -- 'block': route to PENDING before LLM (high confidence)
  -- 'flag': pass tag to LLM as context hint, LLM makes final call
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY  uq_keyword_scope (keyword, scope, group_id)
);
```

**Migration mapping:**

| Old source | Old type/action | New category | New match_mode | New action |
|---|---|---|---|---|
| worrywords | Regulated | substance_regulated | fuzzy | block |
| worrywords | Reportable | substance_reportable | fuzzy | block |
| worrywords | Medicine | substance_medicine | fuzzy | flag |
| worrywords | Review | review | fuzzy | flag |
| worrywords | Allowed | allowed | literal | — |
| spam_keywords | Spam + Literal | scam | literal | block |
| spam_keywords | Spam + Regex | scam | regex | block |
| spam_keywords | Review + Literal | review | literal | flag |
| spam_keywords | Review + Regex | review | regex | flag |
| groups.settings.spammers.worrywords | (per-group) | review | literal | flag |

**Per-group words** (currently stored in `groups.settings.spammers.worrywords` as a comma-separated string) are migrated to rows in `concern_keywords` with `scope=group` and the appropriate `group_id`. The group settings field is retained during transition to avoid breaking the existing settings UI, but reads from `concern_keywords` at runtime.

### Matching logic in the post-review service

1. Load all `global` entries + `group` entries for this group's `group_id`
2. Strip `allowed` entries from the text before other checks
3. For `fuzzy` entries: Levenshtein distance match (same logic as existing WorryWords.php)
4. For `literal` entries: word-boundary regex `\bkeyword\b` (case-insensitive)
5. For `regex` entries: raw regex match with optional `exclude` pattern
6. `block` action matches → add reason tag `keyword:<category>` → transition to `PENDING_END` immediately
7. `flag` action matches → add reason tag `keyword:<category>` → continue to LLM_REVIEW, passing the tags in context

### Modtools management UI

The two existing subtabs ("Worry Words" and "Spam Keywords") in Support → Spam are merged into a single "Keyword List" subtab. The new UI adds:
- Category selector (mapped to the new enum)
- Match mode selector (fuzzy / literal / regex)
- Action selector (block / flag)
- Scope indicator (global vs per-group entries shown together, filterable)

Per-group keywords continue to be manageable from the group settings Spammers section (as a simple list), but now write to `concern_keywords` rather than the group settings JSON string.

### Vector embeddings

The `feature/vector-search` branch has embedding infrastructure. Short post subjects (3–10 words) produce unreliable embeddings — the semantic space is too compressed to reliably distinguish "free sofa" from "sofa for sale." The `block`-action keyword check handles high-confidence cases without embeddings. Vector similarity search is worth revisiting once post-review verdict data provides calibration ground truth.

---

## 8. Stage 3: LLM Review (Gemini Flash Lite)

### What the LLM checks

A single structured prompt call. Only called when Stages 1 and 2 produce no `block` matches.

1. **System rules** (always active): no loans or borrowing, no events, no volunteering requests, no commercial services
2. **Group rules**: only rules set `true` in `groups.rules` (28-rule taxonomy, same as existing ModBot)
3. **Post quality**: Is the description adequate? Dangerously vague Wanteds (e.g. "Furniture any", bare category words with no detail)
4. **Safety-trigger items**: car seats, helmets, knives/swords, upholstered furniture, cot mattresses, banned invasive plants (see Safety Triggers section)
5. **Location extraction**: any place names or collection-point addresses mentioned in the post body

Any `flag`-action keyword matches from Stage 2 are passed to the LLM as context hints, not as definitive verdicts.

### What the LLM does not judge

- Whether a location is out-of-area (it lacks geographic context — handled post-LLM)
- PII (already handled in Stage 1)
- High-confidence spam patterns (handled in Stage 2 as `block`)

### Prompt design

```
System: You are a post reviewer for Freegle, a UK community platform where people
give away items for free. Review the following post against the rules below and
return a structured JSON assessment. Be fair: consider intent and context, not
just literal rule matching. Do not be over-cautious.

Rules active for this group: [list from groupRules + system rules]
Context flags from automated checks: [flag-action keyword matches, if any]

Post subject: {subject}
Post body: {body}

Return JSON:
{
  "verdict": "APPROVE" | "PENDING",
  "confidence": 0.0–1.0,
  "reasons": [{ "tag": "rule:weapons", "detail": "brief explanation" }],
  "location_mentions": ["Manchester", "Didsbury M20"],
  "safety_triggers": ["car_seat", "knife"]
}
```

### Routing rule

`APPROVE` only when `confidence ≥ 0.85` AND `reasons` is empty.  
Any populated `reasons`, or `confidence < 0.85`, routes to `PENDING`.  
Malformed JSON or LLM error → `PENDING` (fallback).

---

## 9. Out-of-Area Check (post-LLM, in service)

After the LLM returns `location_mentions`:

1. Geocode each mention via Nominatim (or the existing Freegle geocoder endpoint)
2. Compare each geocoded point to the group centre coordinates (passed in request context)
3. If any mention is more than `groupAreaRadiusMiles` miles from the group centre → add reason tag `location:out_of_area`
4. That reason overrides an `APPROVE` verdict to `PENDING`

If geocoding fails for any mention: skip the out-of-area check for that mention and continue. Never route to `PENDING` solely due to a geocoding failure.

---

## 10. Safety Triggers

Safety-trigger item detection uses the same keyword taxonomy defined in the client-side `PostItem.vue` component (7 categories as of writing: upholstered furniture, cot mattresses, helmets, car seats, knives/swords, invasive plants, "free"/"giving away" phrasing). This component is the **source of truth**.

A canonical JSON config file at `post-review/src/safety-triggers.json` holds the category definitions and keyword arrays. `PostItem.vue` references its own hardcoded array but includes a comment pointing to this file. For the prototype these are kept in sync manually; a proper shared package is post-prototype work.

**Prototype behaviour:** a safety trigger adds reason `safety:<type>` and routes to `PENDING` so a moderator can add the standard disclaimer text.

**Post-prototype:** auto-attach the standard disclaimer text to the post and approve it (no human needed for known-safe categories like pallets or car seats where the concern is informational, not prohibitive).

---

## 11. Rspamd Milter Integration

Rspamd is already running as `freegle-rspamd` with its web UI at `rspamd.localhost`, but is not wired into the mail pipeline. SpamAssassin is currently called programmatically from PHP (`SPAMD_HOST=spamassassin-app`).

Rspamd is a superset of SpamAssassin: it can incorporate SpamAssassin scores alongside its own ML-based scoring, DMARC/DKIM checks, fuzzy hash matching, and greylisting. Wiring it in as a milter means spam is filtered at the SMTP layer before it reaches PHP, reducing load on the PHP processing pipeline.

### Current state

```
Internet SMTP → [MTA] → PHP MailRouter → SpamAssassin (programmatic)
                                       → iznik processing
Rspamd running but connected to nothing in the mail path
```

### Target state

```
Internet SMTP → [MTA] → rspamd milter → PHP MailRouter → iznik processing
                             ↑
                     SpamAssassin via SPAMD_HOST
                     (rspamd calls SpamAssassin as a plugin)
```

### Implementation

1. Configure rspamd `worker-proxy.inc` (milter worker) in `conf/rspamd/local.d/` to listen on port 11332
2. Configure the MTA to use rspamd as a milter on port 11332
3. Configure rspamd to call SpamAssassin via the existing `spamassassin-app` container (rspamd has a built-in SpamAssassin plugin: `spamassassin { server = "spamassassin-app:783"; }`)
4. Set rspamd action thresholds: `add_header` at score 5, `reject` at score 15 (conservative for Freegle's mix)
5. Remove the direct `SPAMD_HOST` call from the PHP apiv1 environment (rspamd handles it now)
6. Update docker-compose to expose rspamd milter port internally

### Scope note

The exact MTA in production needs to be confirmed before implementing. In dev, mailpit already accepts rspamd headers but does not support milter protocol — dev testing uses rspamd's HTTP API (`/checkv2`) called from MailRouter.php in place of the direct SpamAssassin call.

---

## 12. Opt-In Group Setting

New boolean in `groups.settings` JSON (default `false`):

```json
{ "ai_review": true }
```

Groups that haven't opted in are unaffected. For the prototype, Freegle staff toggle it manually. A modtools UI control can be added post-prototype if the rollout broadens.

---

## 13. Moderator-Facing Language

- Never use "AI" in any moderator-visible text or UI label
- Use "automated review" or "review helper"
- The `AutoReview` state is labelled "Under Review" if it ever needs to be surfaced (it should not normally appear in the mod queue)
- Reason tags shown to mods are human-readable:
  - `pii:phone` → "Possible phone number detected — ask member to remove personal contact details"
  - `rule:weapons` → "Post may mention weapons (automated review)"
  - `keyword:scam` → "Flagged by automated review"
  - `location:out_of_area` → "Post mentions a location that may be outside this group's area"
  - `safety:car_seat` → "Car seat — standard safety disclaimer may be needed"
- The per-post audit log (full FSM transition record, LLM confidence, reasons) is accessible via a modtools detail view but not surfaced by default

---

## 14. Error Handling

| Scenario | Behaviour |
|---|---|
| post-review service unreachable | Post stays `AutoReview`; sweeper moves to `Pending` after 60s |
| LLM API error or timeout | Immediately transition to `PENDING_END` in FSM |
| LLM returns malformed JSON | Immediately transition to `PENDING_END` |
| Geocoding fails for a location mention | Skip out-of-area check for that mention; continue |
| Callback to internal API fails | FSM logs error; sweeper catches via 60s timeout |
| Group rules JSON invalid | Treat as no group rules active; continue with system rules only |

---

## 15. Audit Log

SQLite managed by ai-flower's SQLiteStorage, one FSM instance per post. Also a summary row written to a new `messages_review_log` table for dashboard/reporting:

```sql
CREATE TABLE messages_review_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id      INT NOT NULL,
  group_id        INT NOT NULL,
  stage_stopped   ENUM('pii','keyword','llm','error') NOT NULL,
  llm_verdict     ENUM('APPROVE','PENDING') NULL,
  llm_confidence  DECIMAL(4,3) NULL,
  reasons_json    TEXT NULL,
  final_verdict   ENUM('Approved','Pending') NOT NULL,
  duration_ms     INT NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_message (message_id),
  INDEX idx_group_date (group_id, created_at)
);
```

Used for: threshold calibration, moderator trust-building, false-positive analysis.

---

## 16. Prototype Rollout

1. Deploy `post-review/` container alongside existing stack
2. Set `ai_review: true` on 2–3 volunteer groups
3. Monitor `messages_review_log` for false positives (approved posts that mods later reject) and false negatives (pending posts that would have been fine)
4. Adjust LLM confidence threshold (currently 0.85) and keyword `block`/`flag` assignments based on data
5. Roll out to further groups on request

---

## Open Questions

- **Production MTA identity**: Needed before implementing rspamd milter wiring. What MTA handles inbound mail in production (Postfix? Exim?)?
- **Internal callback auth**: The `PATCH /api/internal/message/{id}/collection` endpoint needs to be authenticated (shared secret or internal-network-only restriction).
- **together.io fallback**: Gemini Flash Lite is the default. If cost or availability becomes an issue, the ai-flower LLM adapter is swappable. A together.io adapter needs writing (straightforward — OpenAI-compatible API).
