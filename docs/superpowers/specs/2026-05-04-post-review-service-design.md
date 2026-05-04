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
- Posts in `AutoReview` are **not** visible to other members — **except** the post's own author, who sees their post immediately as if it were live
- **No mod notifications** are triggered for `AutoReview` posts
- Posts transition to `Approved` or `Pending` within ~5s under normal conditions
- A sweeper job (Laravel scheduled command, runs every minute) moves any post that has been in `AutoReview` for > 60 seconds to `Pending` (service failure fallback)

### Author visibility

The Go API and Laravel message-fetch endpoints must include `AutoReview` posts when the requesting user is the post's author (`messages.fromuser = current user`). From the author's perspective the post is live — they can see it, share it, and receive replies. The collection state is an internal routing concept, not a user-facing status.

---

## 3. Architecture

```
User submits post
      │
      ▼
Go backend (message.go) — fast, synchronous
      │
      ├─ Trusted          → set Approved, done
      ├─ Moderated        → set Pending, notify mods, done
      ├─ Can't Post       → reject, done
      └─ Default + ai_review:true
              │
              ├─ Set collection = AutoReview
              ├─ Push job to beanstalkd (tube: post_review)
              └─ Return to user immediately ← submission complete

              ↓  (seconds later, async)

Laravel batch worker (freegle-batch)
      │  picks up post_review job
      │
      └─ POST /review → post-review service (awaits response)
                              │
                        Node.js, ai-flower FSM
                        PII → Keyword → LLM
                              │
                        returns { verdict, reasons }
                              │
              Laravel batch updates messages_groups.collection
              → Approved (silent) | Pending (triggers mod notification)
```

The Go backend sets `AutoReview` and enqueues a beanstalkd job **before returning to the user**, so no post can be stranded without a queued review job. The Laravel batch worker — which already has authenticated DB access — picks up the job, calls the post-review service synchronously, and writes the final collection state. No callback endpoint is needed.

### Queue durability

The operation order in Go is:

1. Write `messages_groups.collection = AutoReview` (DB commit)
2. Put job to beanstalkd (synchronous — beanstalkd acknowledges before `put` returns)
3. Return HTTP response to user

If step 2 fails (beanstalkd unavailable), the post sits in `AutoReview` and the 60-second sweeper moves it to `Pending`. This is the acceptable safety net — the post is never lost and eventually reaches a stable state.

Beanstalkd is in-memory and does not persist jobs across restarts. A beanstalkd crash between steps 2 and 3 would lose the job; the sweeper again handles this. For production, beanstalkd persistence (`-b` flag with a binlog file) should be enabled to reduce the window where the sweeper is the sole safety net.

If the batch worker cannot reach the post-review service it retries the job (beanstalkd `release` with delay). After a configurable number of retries it buries the job and the sweeper catches the post.

---

## 4. post-review Service

New Node.js container at `post-review/`, following the `monitor-fsm/` pattern.

- **Runtime:** Node.js, ai-flower, Gemini Flash Lite adapter
- **Storage:** SQLiteStorage (ai-flower built-in) — one FSM instance per post, full transition audit trail
- **HTTP endpoints:**
  - `POST /review` — accepts post content and group context, runs FSM, returns verdict synchronously (called by Laravel batch)
  - `GET /health` — liveness check
- **Network:** Internal Docker network only, not routed through Traefik
- **Credentials:** `GEMINI_API_KEY` environment variable
- **LLM:** Gemini Flash Lite. together.io is out of scope for the prototype; the ai-flower adapter interface makes it a later drop-in.

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

**Per-group opt-out:** Some groups permit members to include contact details in posts (e.g. phone number for collection arrangements). Groups set `contactdetails: true` in `groups.rules` via a new ModSettings toggle: "Do you allow members to include personal contact details (phone numbers, addresses) in posts?" When this is true, PII matches are logged but do not trigger `PENDING_END` — the post continues to Stage 2. The default (absent or false) is to block. The `contactdetails` rule is also added to ModBot's `getRuleDescriptions()` so it participates in the existing Gemini-based rule check for groups not yet on the new pipeline.

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
3. **Post quality**: Is the description adequate? Dangerously vague Wanteds (e.g. "Furniture any", bare category words with no detail). Note: vague post detection is best-effort — reliability is lower than other checks and should be validated against production data before treating as high-confidence.
4. **Scam behaviour signals**: Does the post attempt to extract money (mention of payment, bank transfer, deposit), move the conversation off-platform (references to WhatsApp, Telegram, texting a number), or show other patterns inconsistent with free giving? This is behaviour-based, not item-value-based — there is no attempt to assess whether an item is expensive.
5. **Safety-trigger items**: car seats, helmets, knives/swords, upholstered furniture, cot mattresses, banned invasive plants (see Safety Triggers section)
6. **Location extraction**: any place names or collection-point addresses mentioned in the post body

Any `flag`-action keyword matches from Stage 2 are passed to the LLM as context hints, not as definitive verdicts.

### What the LLM does not judge

- Whether a location is out-of-area (it lacks geographic context — handled post-LLM)
- PII (already handled in Stage 1, unless the group has opted to allow contact details)
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

### Current state

```
Internet SMTP → Postfix (conf/postfix/) → freegle-mail-handler (bash pipe)
                                        → HTTP POST → batch-prod Laravel
                                                    → IncomingMailService.php
                                                    → SpamAssassin (programmatic, port 783)

Rspamd container: running, web UI at rspamd.localhost — connected to nothing in the mail path
```

**Confusing naming:** there are two classes both named `SpamCheckService` in different namespaces:
- `App\Services\Mail\Incoming\SpamCheckService` — the one actually used for incoming mail (SpamAssassin only, called from `IncomingMailService.php` lines 2158 and 2703)
- `App\Services\SpamCheck\SpamCheckService` — has `checkRspamd()` and `checkAll()`, but its only caller is `SpamCheckListener` which fires on outgoing mail and is gated by `SPAM_CHECK_ENABLED` (not set anywhere in docker-compose, so dormant)

`RSPAMD_HOST` / `RSPAMD_PORT` are not set in docker-compose; the container happens to be named `rspamd` so the config defaults work, but this is incidental.

### Target state

```
Internet SMTP → Postfix → rspamd milter (port 11332) → freegle-mail-handler
                                │                     → HTTP POST → batch-prod
                         adds X-Rspamd-* headers
                         rejects definite spam at SMTP level (5xx)
                         milter_default_action = accept (rspamd down → mail flows normally)
```

Spam caught at milter level never enters the Postfix queue and never reaches Laravel. Borderline mail passes with rspamd score headers that Laravel can optionally read.

### Implementation steps

1. **Postfix `main.cf`** — add milter config to `conf/postfix/main.cf`:
   ```
   smtpd_milters = inet:rspamd:11332
   non_smtpd_milters = inet:rspamd:11332
   milter_default_action = accept
   milter_protocol = 6
   ```

2. **Rspamd milter worker** — add `conf/rspamd/local.d/worker-proxy.inc`:
   ```
   bind_socket = "rspamd:11332";
   ```

3. **Rspamd → SpamAssassin** — add `conf/rspamd/local.d/spamassassin.conf`:
   ```
   server = "spamassassin-app:783";
   ```
   This lets rspamd incorporate SpamAssassin scores into its own decision, so we get both systems' coverage without calling SpamAssassin separately from Laravel.

4. **Rspamd action thresholds** — add `conf/rspamd/local.d/actions.conf`:
   ```
   actions {
     add_header = 5;
     rewrite_subject = 8;
     reject = 15;
   }
   ```
   Conservative starting point for Freegle's mix; tunable after observing scores in production.

5. **docker-compose** — add `RSPAMD_HOST=rspamd` and `RSPAMD_PORT=11334` to the `batch-prod` environment; expose port 11332 internally for Postfix. Set `SPAM_CHECK_ENABLED=false` explicitly to suppress the dormant outgoing listener.

6. **Laravel** — the `App\Services\Mail\Incoming\SpamCheckService` SpamAssassin call can be retained alongside the milter initially (belt and braces). Once rspamd milter scores prove reliable, the redundant direct SpamAssassin call can be removed.

7. **Resolve class naming confusion** — rename `App\Services\SpamCheck\SpamCheckService` to `App\Services\SpamCheck\RspamdService` to eliminate the ambiguity with the incoming-mail class.

### Dev testing

Mailpit does not support the milter protocol. In dev, rspamd's HTTP check API (`POST http://rspamd:11334/checkv2`) is used directly from a test helper rather than via the milter path. The milter configuration is exercised in CI against the Postfix container.

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
| post-review service unreachable | Batch worker retries job; sweeper moves post to `Pending` after 60s |
| LLM API error or timeout | FSM transitions to `PENDING_END`; batch marks collection `Pending` |
| LLM returns malformed JSON | FSM transitions to `PENDING_END` |
| Geocoding fails for a location mention | Skip out-of-area check for that mention; continue |
| Beanstalkd job lost (restart) | Sweeper catches `AutoReview` posts > 60s old → moves to `Pending` |
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
5. Roll out to all groups — the per-group `ai_review` flag is a **staging mechanism**, not a permanent feature. The aim is a short, decisive prototype period (weeks, not months), not an open-ended opt-in/out rollout. A prolonged multi-group opt-in creates maintenance overhead and leaves the codebase in a half-finished state indefinitely.

### Transition: existing members

When `ai_review` is enabled on a group, existing members need tier assignment:

- Members currently on `POSTING_MODERATED` who have a **mod note** remain on `POSTING_MODERATED` (manual moderation preserved for flagged accounts)
- Members on `POSTING_MODERATED` with no mod note, and all members on `POSTING_DEFAULT`, enter the new pipeline normally
- Members on `POSTING_UNMODERATED` (Trusted) are unaffected

Note: some groups apply mod notes universally as a record-keeping habit rather than as a flag. This is an edge case to be handled operationally rather than by special-casing in code.

---

## Open Questions

- **Beanstalkd persistence in production**: Enable `-b /var/lib/beanstalkd/binlog` in the production beanstalkd config to survive restarts. Confirm this is done before enabling `ai_review` on any group.
- **together.io**: Out of scope for prototype. The ai-flower adapter interface makes it a later drop-in if Gemini cost or availability becomes a concern.
- **Rspamd score tuning**: The initial thresholds (add_header=5, reject=15) are conservative guesses. After a few weeks of production traffic, review the score distribution in rspamd's web UI (`rspamd.localhost`) and adjust.
