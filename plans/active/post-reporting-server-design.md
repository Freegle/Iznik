# Post Reporting System — Server-Side Design

## 1. New Database Schema

### New table: `messages_reports`

Follows the pattern of `newsfeed_reports`.

```
messages_reports
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  msgid           BIGINT UNSIGNED NOT NULL (FK -> messages.id)
  userid          BIGINT UNSIGNED NOT NULL (FK -> users.id) -- who reported
  reason          ENUM('Spam','Scam','NotSuitable','Duplicate','AlreadyTaken','Other')
  freetext        TEXT NULLABLE -- for 'Other' or additional detail
  timestamp       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  UNIQUE KEY (msgid, userid) -- one report per user per message
  INDEX (msgid)
  INDEX (userid)
  INDEX (timestamp)
```

### New columns on `messages_groups` table:

```
reportcount     INT UNSIGNED DEFAULT 0  -- denormalized count of distinct reporters
modreviewedat   TIMESTAMP NULLABLE      -- when a mod last approved after reports
modreviewedby   BIGINT UNSIGNED NULLABLE -- which mod reviewed after reports
```

`reportcount` is denormalized for performance — avoids JOIN to `messages_reports` in hot `ListMessagesMT` path. Maintained atomically via report submission handler.

`modreviewedat`/`modreviewedby` is the key to loop prevention. When a mod approves a reported message, these are set. Subsequent reports check these to decide on auto-escalation.

No changes to `messages` table. Existing `heldby` field reused for holding. `reportcount` is per-group (cross-posted message could be reported on one group but not another).

New log subtype: `'Reported'` in the `logs` table enum + `LOG_SUBTYPE_REPORTED` constant.

## 2. API Endpoints

### New action on existing `POST /message` endpoint:

Add `"Report"` action to the switch in `PostMessage()`. New fields on `PostMessageRequest`:
```go
Reason   *string `json:"reason"`    // report reason enum value
Freetext *string `json:"freetext"`  // optional free text
```

### New `"DismissReports"` action:

Allows mods to clear reports without further action (marks as reviewed).

### New read endpoint (mods only):

`GET /message/:id/reports` — returns report list. Per "no enrichment" rule, returns reporter user IDs only; frontend resolves display names.

## 3. Report Submission Flow

`handleReport` follows the pattern of `handleHold`, `handleBackToPending`:

1. Authenticate via `user.WhoAmI(c)` — must be logged in
2. Validate reason is valid enum value
3. Check message exists, is in Approved collection, is not user's own post
4. Insert into `messages_reports` with `ON DUPLICATE KEY UPDATE` (idempotent)
5. Count distinct reporters: `SELECT COUNT(DISTINCT userid) FROM messages_reports WHERE msgid = ?`
6. Update `messages_groups.reportcount` atomically
7. Execute auto-escalation logic (section 4)
8. Log with type `LOG_TYPE_MESSAGE`, subtype `LOG_SUBTYPE_REPORTED`
9. Queue `push_notify_group_mods`
10. Return success

Authorization: Any logged-in group member can report. Non-members cannot. Reporter must not be the poster.

## 4. Auto-Escalation Logic

**Threshold: 2 distinct reporters triggers auto-escalation.**

```go
const ReportEscalationThreshold = 2
```

When `reportcount >= ReportEscalationThreshold`:

1. Check loop prevention (section 5)
2. If not blocked:
   - Move Approved → Pending: `UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND collection = 'Approved'`
   - Clear `approvedby` and `approvedat`
   - Do NOT set `heldby` — leave unheld so any mod can pick it up
   - Log as `LOG_SUBTYPE_HOLD` with text "Auto-escalated: reported by N users"
   - Queue push notification to group mods
3. If blocked by loop prevention:
   - Do NOT move to pending
   - Log report but note "mod review overrides auto-escalation"
   - Still queue push notification

## 5. Loop Prevention Mechanism

Rules:

1. **First escalation (no prior mod review):** `modreviewedat IS NULL` and `reportcount >= 2`. Auto-escalate normally.

2. **After mod review, low report volume:** `modreviewedat IS NOT NULL` and report count since last review < 5. Do NOT auto-escalate. The mod has decided it's fine.

3. **After mod review, high report volume:** `modreviewedat IS NOT NULL` and report count since last review >= 5. Override mod's decision. Reset `modreviewedat = NULL`.

4. **Time decay:** If `modreviewedat` older than 7 days, treat as expired. Revert to standard threshold of 2.

```
if reportcount >= ReportEscalationThreshold:
    if modreviewedat IS NULL OR modreviewedat < NOW() - 7 DAYS:
        moveApprovedToPending()
    elif reportcount >= ModOverrideThreshold (5):
        moveApprovedToPending()
        resetModReviewTimestamp()
    else:
        logReportOnly()
```

Modification to `handleApprove`: When approving a message with `reportcount > 0`, set `modreviewedat = NOW()`, `modreviewedby = myid`. Do NOT reset `reportcount`.

## 6. Edit Interaction Rules

**Rule 1: Poster edits a reported message — report count resets.**
- Reset `reportcount = 0`
- Reset `modreviewedat = NULL`
- Delete rows from `messages_reports`
- If message was moved to Pending by escalation, it stays Pending
- Log "Reports reset due to poster edit"

**Rule 2: Mod edits a reported message — counts as reviewed.**
- Set `modreviewedat = NOW()`, `modreviewedby = myid`
- Do NOT reset `reportcount`

**Rule 3: Editing a post in Pending due to reports — does NOT auto-re-approve.**
Posts in Pending stay in Pending. A mod must explicitly approve.

## 7. Mod Workflow

Messages moved to Pending by reports appear in normal Pending queue. `reportcount` field distinguishes them — frontend shows "Reported 3 times" badge.

Reports (reporter IDs + reasons + freetext) fetched in new goroutine within `GetMessagesByIds` when `isMod == true` and `reportcount > 0`.

```go
type MessageReport struct {
    Userid    uint64     `json:"userid"`
    Reason    string     `json:"reason"`
    Freetext  *string    `json:"freetext,omitempty"`
    Timestamp *time.Time `json:"timestamp"`
}
```

Mod actions:
- **Approve**: Existing handler. Sets `modreviewedat`. Message goes back to Approved.
- **Reject**: Existing handler. Normal rejection email.
- **Delete**: Existing handler.
- **DismissReports** (new): Clears `reportcount`, deletes `messages_reports` rows, sets `modreviewedat`. Message stays in current collection.

## 8. Background Job Processing

New task type: `email_post_reported`

Queued when auto-escalated. Contains `msg_id`, `group_id`, `report_count`, `reasons`. Sends digest-style notification to group mods (augments push notification).

No new cron job needed — escalation logic runs synchronously in report handler.

## 9. Analytics / Feedback Data Model

`messages_reports` table IS the analytics data. Key queries:

1. **Reports by reason** (30-day window, grouped by reason)
2. **Frequently reported posters** (90-day, users with 3+ reported posts)
3. **Report accuracy** (fraction of reported posts that were rejected vs approved after review)
4. **Time to resolution** (avg minutes from first report to mod review)

Integration with microvolunteering: Messages with reports can be prioritized in `getApprovedMessageChallenge` via `LEFT JOIN messages_reports` + `ORDER BY`.

## 10. Migration Plan

**Phase 1: Database** — 3 Laravel migrations (messages_reports table, messages_groups columns, logs subtype).

**Phase 2: Go API** — Add `handleReport`/`handleDismissReports`, modify `handleApprove` and `PatchMessage`, add report fields to structs.

**Phase 3: Batch** — Add `email_post_reported` handler.

**Phase 4: Tests** — TestReportMessage, TestAutoEscalation, TestLoopPrevention, TestModOverride, TestEditReset, TestDismiss, TestOwnMessage (403), TestNotMember (403).

**Phase 5: Frontend** — Separate task.

No breaking changes. New columns have defaults. New action is additive. Existing flow untouched until reports exist.
