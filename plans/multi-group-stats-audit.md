# Multi-Group Stats Audit

> Audit of all stats/count queries that reference `messages` or `messages_groups` in Go, Laravel, and V1 PHP.
> Goal: identify any query that would double-count a message appearing in multiple groups.
> Completed: 2026-06-17

---

## Summary

**Almost all queries are safe.** The vast majority either:
- Use `COUNT(DISTINCT messages.id)` / `COUNT(DISTINCT msgid)` — can't inflate.
- Use `GROUP BY groupid` — each group counts its own rows, one per message.
- Query `messages` directly (not via `messages_groups` join) — one row per message.

**One actionable pre-Task-20 fix in Go:** `session.go` held-check still reads `m.heldby` (the global column that Task 20 will drop). Must switch to `mg.heldby` before Task 20 runs.

**One low-priority V1 issue:** `User.php::getActiveCountss()` inflates per-user post counts for multi-group messages. V1 PHP is being retired so this is noted only.

---

## Findings By File

### Go — `iznik-server-go/dashboard/dashboard.go`

| Line | Expression | Assessment |
|------|-----------|-----------|
| 119 | `COUNT(DISTINCT messages.id) … INNER JOIN messages_groups … WHERE groupid IN (?)` | ✅ SAFE — DISTINCT on messages.id |
| 196 | `COUNT(DISTINCT messages.id) … INNER JOIN messages_groups … WHERE groupid IN (?)` | ✅ SAFE — DISTINCT on messages.id |
| 272 | `COUNT(*) FROM messages WHERE id IN (SELECT msgid FROM messages_groups…)` | ✅ SAFE — subquery returns distinct msgids, outer table is messages |

---

### Go — `iznik-server-go/authority/stats.go`

| Line | Expression | Assessment |
|------|-----------|-----------|
| 75–86 | `COUNT(*) FROM messages INNER JOIN locations … GROUP BY PartialPostcode` | ✅ SAFE — `messages` table has one row per message; location is per-message |
| 106–118 | `COUNT(*) FROM messages INNER JOIN chat_messages … GROUP BY PartialPostcode` | ✅ SAFE — counts chat_messages (replies), not messages |
| 133–145 | `COUNT(*) FROM messages INNER JOIN messages_outcomes … GROUP BY PartialPostcode` | ✅ SAFE — counts outcomes per postcode, not per message-group row. One message can have multiple outcomes, but this is intentional. No messages_groups join, so multi-group has no effect. |

---

### Go — `iznik-server-go/group/groupWork.go`

All message-count queries here use `GROUP BY mg.groupid`, so each group's count includes only that group's rows — a multi-group message contributes exactly one row to each group's count.

| Line | Expression | Assessment |
|------|-----------|-----------|
| 135–140 | `COUNT(*) … GROUP BY mg.groupid, held` | ✅ SAFE — per-group counts; multi-group message counted once per group |
| 168–172 | `COUNT(*) … GROUP BY mg.groupid` | ✅ SAFE — same |
| 281–285 | `COUNT(DISTINCT me.msgid) … GROUP BY groupid` | ✅ SAFE — DISTINCT on msgid |
| 324–341 | `COUNT(DISTINCT mo.id) … GROUP BY groupid` | ✅ SAFE — DISTINCT on outcome ID; intentionally counts outcomes not messages |

---

### Go — `iznik-server-go/session/session.go`

These are the mod-queue badge counts (pending/spam/held) shown in the nav bar.

| Line | Expression | Assessment |
|------|-----------|-----------|
| 1020–1026 | `COUNT(*) FROM messages_groups mg … WHERE mg.groupid IN ? AND … m.heldby IS NULL` | ✅ SAFE — intentionally counts (message, group) pairs; a pending message on two of the mod's groups shows as 2 items (correct, each needs separate mod action) |
| 1029–1035 | `COUNT(*) FROM messages_groups mg … WHERE mg.groupid IN ? AND … m.heldby IS NOT NULL` | ⚠️ SAFE NOW but **must fix before Task 20** — uses `m.heldby` (global column, dropped in Task 20). Switch to `mg.heldby IS NOT NULL`. |
| 1041–1047 | `COUNT(*) FROM messages_groups mg … WHERE mg.groupid IN ? AND …` | ✅ SAFE — same logic as 1020 |
| 1062–1067 | `COUNT(*) FROM messages_groups mg … WHERE mg.groupid IN ? AND … mg.arrival >= …` | ✅ SAFE — spam count, per (message, group) pair |
| 1140–1143 | `COUNT(DISTINCT me.msgid) … GROUP BY groupid` | ✅ SAFE — DISTINCT on msgid |

**Pre-Task-20 fix required:** Lines 1029–1035 reference `m.heldby IS NOT NULL` to split held vs unheld pending items. The `messages.heldby` column is dropped in Task 20. The held check needs to become `mg.heldby IS NOT NULL` before that migration runs.

---

### Laravel — `iznik-batch/app/Services/StatsGenerationService.php`

All queries already use `distinct()` or `COUNT(DISTINCT …)`.

| Line | Expression | Assessment |
|------|-----------|-----------|
| 136–143 | `distinct()->count('messages_outcomes.msgid')` | ✅ SAFE |
| 147–154 | `distinct()->count('messages_groups.msgid')` | ✅ SAFE |
| 224–233 | `COUNT(*) … GROUP BY messages.sourceheader` | ✅ SAFE — GROUP BY on a messages-level field; the join to messages_groups is for filtering only; since the GROUP BY anchor is messages.sourceheader the result is per-message |
| 241–252 | `COUNT(*) … GROUP BY messages.type` | ✅ SAFE — same reasoning |
| 281–287 | `count()` on chat_messages table | ✅ SAFE |

---

### Laravel — `iznik-batch/app/Models/Group.php`

| Line | Expression | Assessment |
|------|-----------|-----------|
| 428 | `COUNT(DISTINCT messages_edits.msgid)` | ✅ SAFE |
| 445 | `COUNT(DISTINCT messages_groups.msgid)` | ✅ SAFE |

---

### Laravel — `iznik-batch/app/Services/GroupStatsService.php`

| Line | Expression | Assessment |
|------|-----------|-----------|
| 156–159 | `max('approvedat')` on messages_groups | ✅ SAFE — timestamp aggregation, not count |

---

### V1 PHP — `iznik-server/include/misc/Stats.php`

All global stats queries use `DISTINCT(messages_outcomes.msgid)` or similar. No inflation risk.

| Line | Expression | Assessment |
|------|-----------|-----------|
| 72, 84, 138, 152, 166 | `COUNT(DISTINCT(messages_outcomes.msgid))` or `COUNT(DISTINCT(messageid))` | ✅ SAFE |
| 183, 200 | `COUNT(*) … GROUP BY sourceheader / type` | ✅ SAFE — GROUP BY on messages-level fields; join to messages_groups for filtering only |
| 254 | `COUNT(*) FROM chat_messages INNER JOIN messages_groups …` | ✅ SAFE — counts chat_messages |
| 508, 529 | `COUNT(*) FROM messages_by …` | ✅ SAFE from multi-group perspective — counting people who took items, not messages |

---

### V1 PHP — `iznik-server/include/group/Group.php`

| Line | Expression | Assessment |
|------|-----------|-----------|
| 416 | `COUNT(*) … GROUP BY messages_groups.groupid, collection, held` | ✅ SAFE — GROUP BY groupid means each group's count is per that group's rows only |
| 486 | `COUNT(DISTINCT messages_edits.msgid) … GROUP BY groupid` | ✅ SAFE |
| 493–501 | `COUNT(DISTINCT messages_groups.msgid) … GROUP BY groupid` | ✅ SAFE |

---

### V1 PHP — `iznik-server/include/user/User.php`

| Line | Method | Expression | Assessment |
|------|--------|-----------|-----------|
| 1645 | `getActiveCountss()` | `COUNT(*) FROM messages INNER JOIN messages_groups … GROUP BY fromuser, type, outcome` | ⚠️ LOW PRIORITY — join to messages_groups without DISTINCT means a message on 2 groups is counted twice in the user's post count. **V1 PHP is being retired — no fix required.** |
| 6487 | `getModGroupsByActivity()` | `COUNT(*) FROM messages_groups … WHERE approvedby = ? … GROUP BY groupid` | ✅ SAFE — GROUP BY groupid, counts mod approvals per group |

---

### V1 PHP — `iznik-server/include/dashboard/Dashboard.php`

V1 is being retired; issues here do not require fixes.

| Line | Expression | Assessment |
|------|-----------|-----------|
| 163 | `COUNT(*) FROM messages_groups INNER JOIN messages_outcomes …` | ⚠️ V1 only — per messages_groups row, not distinct messages |
| 235 | `COUNT(*) FROM messages INNER JOIN messages_groups …` | ⚠️ V1 only — potential inflation |

---

## Action Items

### Must-fix before Task 20 (drop `messages.heldby`)

**`session.go:1029–1035`** — the held-pending badge count uses `m.heldby IS NOT NULL`. When Task 20 drops `messages.heldby`, this will break. Fix: change the held check to `mg.heldby IS NOT NULL`.

The fix in context:
```go
// Current (lines 1029-1035):
db.Raw("SELECT COUNT(*) FROM messages_groups mg "+
    "INNER JOIN messages m ON m.id = mg.msgid "+
    "INNER JOIN users u ON u.id = m.fromuser "+
    "WHERE mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 "+
    "AND m.deleted IS NULL AND u.deleted IS NULL AND m.heldby IS NOT NULL "+
    "AND mg.contentcheck_checked_at IS NOT NULL",
    activeGroupIDs, utils.COLLECTION_PENDING).Scan(&heldActive)

// Fix (use mg.heldby):
db.Raw("SELECT COUNT(*) FROM messages_groups mg "+
    "INNER JOIN messages m ON m.id = mg.msgid "+
    "INNER JOIN users u ON u.id = m.fromuser "+
    "WHERE mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 "+
    "AND m.deleted IS NULL AND u.deleted IS NULL AND mg.heldby IS NOT NULL "+
    "AND mg.contentcheck_checked_at IS NOT NULL",
    activeGroupIDs, utils.COLLECTION_PENDING).Scan(&heldActive)
```

### No action required

All other queries are either:
- Already safe (DISTINCT / GROUP BY groupid)
- In V1 PHP (being retired) with no V2 equivalent
