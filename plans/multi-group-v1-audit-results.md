# Multi-Group V1 PHP Audit Results

> Cross-reference of V1 PHP audit gaps (from `multi-group-messages-design.md`) against V2 Go / Laravel batch code.
> Completed: 2026-06-17

---

## Summary

All actionable V1 gaps have V2 coverage. One item (ModBot auto-approve rules) is not yet in Go — it lives in V1 PHP only and is not yet migrated; the multi-group risk there is low (see below).

---

## Findings Per V1 Gap

### 1. `reject()` updates ALL groups

**V1 issue:** V1 `Message::reject()` ran `UPDATE messages_groups SET collection = 'Rejected' WHERE msgid = ?` — updating every group row, not just the one the mod was acting on.

**V2 status: ✅ Fixed (Task 6 / `handleReject`)**

`handleReject` ([message.go:1856](../iznik-server-go/message/message.go#L1856)) calls `resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)` to determine the target group set, then:

```sql
UPDATE messages_groups SET collection = 'Rejected', rejectedat = NOW()
WHERE msgid = ? AND groupid IN ? AND collection = 'Pending'
```

When a `groupid` is supplied in the request, only that group is targeted. Without a `groupid`, `resolveAuthorizedGroups` returns only the groups the mod is authorised on — never all groups globally.

---

### 2. `sendForReview()` updates ALL groups

**V1 issue:** V1 `sendForReview()` set `collection = 'Pending'` and wrote `spamreason` on every `messages_groups` row for the message.

**V2 status: ✅ Fixed (Task 10 / `sendForReview`)**

`sendForReview` ([microvolunteering.go:895](../iznik-server-go/microvolunteering/microvolunteering.go#L895)) now takes an explicit `groupid` and writes only to that row:

```sql
UPDATE messages_groups SET spamreason = ?, collection = 'Pending' WHERE msgid = ? AND groupid = ?
```

All microvolunteering challenge handlers that call `sendForReview` pass the message's group ID.

---

### 3. `autoapprove()` processes all groups (or was it per-group?)

**V1 issue:** V1 `Message::autoapprove()` iterated the result set — one row per `(msgid, groupid)` — and approved each row individually. This was already per-group, but the risk was that the candidate query read `messages.heldby` (global), not `messages_groups.heldby`, so a hold on one group didn't block auto-approval on another.

**V2 status: ✅ Correct and improved**

`AutoApproveService` ([AutoApproveService.php:41](../iznik-batch/app/Services/AutoApproveService.php#L41)) is explicitly multi-group-safe:
- Candidate query filters `messages_groups.heldby IS NULL` (per-group hold check, not the global `messages.heldby`).
- Also filters out messages in Spam collection on **any** group (`whereNotExists` subquery).
- Groups candidates by `msgid`, checks logs once per message, then calls `approveOnGroup()` per `(msgid, groupid)` pair.
- `approveOnGroup` writes `UPDATE messages_groups SET collection = 'Approved' … WHERE msgid = ? AND groupid = ?`.

The V1 race condition (global `heldby` check) is gone.

---

### 4. `move()` deletes all groups, inserts one

**V1 issue:** V1 `Message::move($newGroupId)` deleted ALL `messages_groups` rows for a message, then inserted one new row for `$newGroupId`. Calling move on a multi-group message silently removed all other group memberships.

**V2 status: ✅ Fixed (Task 6 / `handleDeleteMessage`)**

The Go API has no `move` operation — the equivalent is a per-group delete followed by a separate approval on the target group. `handleDeleteMessage` ([message.go](../iznik-server-go/message/message.go)):
- Deletes only `WHERE msgid = ? AND groupid = ?` (the specified group's row).
- Soft-deletes the message itself only when no groups remain.

There is no path in the Go API that removes all groups for a move.

---

### 5. `spam()` is a global delete

**V1 issue:** V1 `Message::spam()` soft-deleted the entire message and all its `messages_groups` rows regardless of which group the mod was acting on.

**V2 status: ✅ Fixed (Task 7 / `handleSpam`)**

`handleSpam` ([message.go:1990](../iznik-server-go/message/message.go#L1990)) soft-deletes only the target group's row (`deleted = 1` on that row). The message itself is soft-deleted only if no non-deleted groups remain:

```sql
SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND heldby IS NULL AND deleted = 0
```

**Deviation note:** The implementation uses `deleted = 1` on the `messages_groups` row rather than `collection = 'Spam'` + writing `spamtype`/`spamreason` as the original plan specified. The `spamtype`/`spamreason` columns on `messages_groups` are currently unwritten by `handleSpam`. This is a known deviation recorded in the plan status table; reconcile before Task 20 (column drop).

---

### 6. ModBot uses first group for rules

**V1 issue:** `ModBot::reviewPost()` ([ModBot.php:79](../iznik-server/include/ai/ModBot.php#L79)) read group rules from `$groups[0]['groupid']` only. A message cross-posted to two groups where group B had stricter rules than group A would never have group B's rules checked if group A was `$groups[0]`.

**V2 status: ✅ Fixed**

`ModBot.php` is V1 PHP and still active. Fixed by extracting a `getMergedRulesForGroups(array $groups): array` method that iterates all groups the post belongs to and takes the union of rules (any rule enabled on any group is included). The `reviewPost()` method now calls this instead of loading only `$groups[0]`'s rules.

**Test added:** `ModBotTest::testMergedRulesIncludeAllGroups` — creates group A with `weapons=TRUE` only and group B with `alcohol=TRUE` only, calls `getMergedRulesForGroups`, and asserts both rules appear in the merged result.

---

## Files Verified

| File | What was checked |
|------|-----------------|
| `iznik-server-go/message/message.go` | `handleReject`, `handleSpam`, `handleDeleteMessage` |
| `iznik-server-go/microvolunteering/microvolunteering.go` | `sendForReview` |
| `iznik-batch/app/Services/AutoApproveService.php` | Full implementation |
| `iznik-server-go/housekeeper/housekeeper.go` | Command registry (no Go auto-approve impl) |
