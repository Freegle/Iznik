# Multi-Group Messages — Current-State Audit (Single-Group Assumptions)

> **Purpose:** A fresh, exhaustive sweep of the codebase *as it stands today* (branch
> `messages-multiple-groups`) for every place that treats a message (post) as if it
> belongs to exactly one group, or only ever uses the first group.
>
> **Deliberately over-liberal.** This list includes confirmed bugs, intentional fallbacks,
> V1-retired code, and "probably fine but worth a look" cases. False positives are
> expected — the goal is completeness so nothing is missed when we migrate.
>
> **Method:** ripgrep sweeps across all four code repos for `groups[0]`, `groups->first()`,
> `MessageGroups[0]`, `getPrimaryGroupForMessage`, `messages.heldby/spamtype/spamreason`,
> `LIMIT 1` group selection, `postedToGroups[0]`, and `[0]['groupid']`, plus manual
> inspection of the surrounding logic.
>
> **Cross-reference:** Existing effort is tracked in
> `multi-group-messages-implementation.md` (Tasks 1–29), `multi-group-stats-audit.md`,
> and `multi-group-v1-audit-results.md`. Each row below notes whether it is already
> addressed by that effort, so the reviewer can see what is genuinely outstanding.
>
> **Status legend:**
> - ✅ **Handled** — already migrated to multi-group by prior task work.
> - 🟡 **Intentional fallback** — uses first/primary group on purpose (owner-global path,
>   legacy fallback when no contextual groupid supplied). Verify the fallback is still
>   acceptable; otherwise no change.
> - 🔴 **Outstanding** — still assumes single group; needs migration.
> - 🔵 **Verify** — unclear; needs a closer look to decide.
> - ⚪ **V1 / retired** — V1 PHP (`iznik-server`), audit-only, no fix planned (V2 must cover).

---

## A. Go API — `iznik-server-go`

### A1. Core moderation context & primary-group fallback (`message/message.go`)

| Line | Code | Assessment | Status |
|------|------|-----------|--------|
| 1551–1554 | `getPrimaryGroupForMessage()` — `SELECT groupid FROM messages_groups WHERE msgid = ? LIMIT 1` | The canonical "pick one group" helper. Doc-comment already re-labelled as legacy fallback (Task 28). | 🟡 |
| 1710 | `ctx.Groupid = getPrimaryGroupForMessage(db, msgid)` (mod-context bootstrap) | Bootstrap default before request groupid is applied. | 🟡 |
| 2312 | `if pg := getPrimaryGroupForMessage(db, req.ID); pg > 0` | Draft-conversion fallback (owner-global path, Task 24). | 🟡 |
| 2425 | `groupid = getPrimaryGroupForMessage(db, req.ID)` | Submit/edit fallback when no contextual groupid. | 🟡 |
| 2815 | `groupid = getPrimaryGroupForMessage(db, req.ID)` | Convert-to-draft fallback (Task 24). | 🟡 |
| 3202 | `groupid = getPrimaryGroupForMessage(db, msgid)` | Mod-delete audit log: uses `?groupid=` when supplied, else owner-global fallback. | 🟡 acceptable (G6) |
| 3497 | `groupid = getPrimaryGroupForMessage(db, newMsgID)` | JoinAndPost fallback (owner just posted). | 🟡 |
| 1786, 1885, 1943 | `ctx.Groupid = authorizedGroups[0]` | "Primary acted-on group for logging" after resolving the authorized set. Per-group loop runs separately; this is just the log anchor. | 🟡 |
| 1754 | `SELECT … FROM messages_groups … ORDER BY messages_groups.arrival DESC LIMIT 1` | This is `addApprovedMessageToSpatialIndex` — picks ONE group → single-group spatial/browse/search index. | 🔴 G1 → H2 |
| 1812 | `SELECT spamtype FROM messages_groups WHERE msgid = ? AND groupid IN ? AND spamtype IS NOT NULL LIMIT 1` | Existence check across authorized groups to mark ham (global). Correct. | ✅ (G5) |

### A2. Backwards-compat dual-writes to global `messages` columns (`message/message.go`)

These keep the soon-to-be-dropped `messages.heldby` in sync. They are **scheduled for
removal in Task 20** but still present today.

| Line | Code | Status |
|------|------|--------|
| 1799 | comment: "clear messages.heldby for backwards compat" + the `UPDATE messages SET heldby = NULL` it guards | 🟡 (remove in Task 20) |
| 2051 | `UPDATE messages SET heldby = ?` dual-write on hold | 🟡 |
| 2084 | `UPDATE messages SET heldby = ?` dual-write (back-to-pending) | 🟡 |
| 2121 | clear `messages.heldby` when no group still holds | 🟡 |

### A3. Mod-queue / dashboard counts reading the GLOBAL `messages.heldby`

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `session/session.go:1024` | `… AND m.heldby IS NULL …` (unheld pending badge) | Reads global column; will break at Task 20 AND ignores per-group holds. Stats audit flagged 1029–1035; **1024 is the matching NULL branch.** | 🔴 (pre-Task-20 fix) |
| `session/session.go:1033` | `… AND m.heldby IS NOT NULL …` (held pending badge) | The one the stats audit explicitly flagged. Switch to `mg.heldby`. | 🔴 (pre-Task-20 fix) |
| `group/groupWork.go:135` | `(m.heldby IS NOT NULL) as held … GROUP BY mg.groupid, held` | **`m` here is the joined `messages` table** — reads the GLOBAL held flag, not `mg.heldby`. A message held on group A but not B shows held in BOTH groups' pending split, and breaks at Task 20. The stats audit marked groupWork "SAFE (GROUP BY groupid)" but did **not** catch that the held split uses the global column. | 🔴 (likely under-flagged by stats audit) |
| `group/groupWork.go:204` | `(m.heldby IS NOT NULL) as held … FROM memberships m` | Here `m` = `memberships` — this is *membership* hold, not message hold. **False positive.** | ⚪ n/a |

### A4. Microvolunteering challenge selection (`microvolunteering/microvolunteering.go`)

| Line | Code | Assessment | Status |
|------|------|-----------|--------|
| 392 | `… WHERE messages_groups.groupid IN (…) ORDER BY messages_groups.arrival ASC LIMIT 1` (approved-message challenge) | Picks one (msg, group) row. `microactions` dedup is by `msgid`, so a reviewed item is excluded on all groups — no double-offer. | ✅ no change (G7c) |
| 446 | same pattern for photo-rotate challenge | same | ✅ no change (G7c) |
| 875–892 | `sendForReview(db, msgid, groupid, reason)` | Per-group already (Task 10). | ✅ |

### A5. Freebie-alerts fanout (`message/message.go`)

| Line | Code | Assessment | Status |
|------|------|-----------|--------|
| 1843–1849 | On approve of an Offer: `QueueTask(TaskFreebieAlertsAdd, {msgid})` — **no groupid passed** | Worker posts one location-based record per msgid; not group-partitioned. Only `created_at` uses an arbitrary group's arrival (cosmetic). | ✅ no change (G7b) |
| 1908, 1959, 2020, 3208, 3855, 3929 | `TaskFreebieAlertsRemove` on delete/spam/outcome | Removal is keyed by msgid (global) — correct, item gone everywhere. | ✅ |

### A6. List / search dedup (`message/message_list.go`, `search.go`)

| Line | Code | Status |
|------|------|--------|
| 131,143,157,168,181,509 | `SELECT DISTINCT mg.msgid …` | ✅ deduped (Task 11) |
| 266–273 | `msg.Groups = groups` built from all `messages_groups` rows | ✅ multi-group array |
| 570 | `SELECT arrival FROM messages_groups WHERE msgid = ? … LIMIT 1` (pagination cursor) | 🔴 (low) G4 → H11 — reads an arbitrary group's arrival; list orders by the contextual group's, so a page boundary can skip/repeat |

### A7. Repost scheduling (`message/message.go:702–748`)

Rewritten to evaluate per-group (`map[groupid]RepostSettings`, eligible only when valid on
every group). ✅ (Task 23).

### A8. Other Go `.Groupid` singular uses (`message/message.go`)

Lines 288–289, 726–738, 821–850, 1069–1107 iterate `MessageGroups` (loops, not `[0]`) —
these are correct multi-group handling. Listed here only to record they were inspected. ✅

---

## B. Laravel batch — `iznik-batch`

### B1. Digest (`app/Mail/Digest/UnifiedDigest.php`)

| Line | Code | Assessment | Status |
|------|------|-----------|--------|
| 71 (constructor) | now `preferredGroupForPost($this->posts->first())` for `$trackingGroupId` | ✅ recipient's group (Task 27) |
| 257 (`rebuildFromDescriptor`) | inline preferred-group for sponsors | ✅ (Task 27) |
| 304, 313 | `return $groups[0];` inside `preferredGroupForPost()` | Final fallback when recipient shares no group with the post. | 🟡 |
| 787 | `$primaryGroupId = $postedToGroups[0] ?? null;` (per-post card byline + `/explore` link) | First-group pick; can name a group the recipient isn't in. Should use the recipient-preferred group (matches Task-27 header fix). | 🔴 (low) G3 → H9 |
| 933 | `$groupNames = collect($postedToGroups)…` | Renders ALL group names. ✅ |

### B2. Digest service (`app/Services/UnifiedDigestService.php`)

| Line | Code | Assessment | Status |
|------|------|-----------|--------|
| 972/979/987 | `postedToGroups[] = $post->groupid` / `'postedToGroups' => [$post->groupid]` | dedup aggregates groups into the array. ✅ (Task 18) |
| 795 | `$postGroupId = (int) ($deduped['postedToGroups'][0] ?? 0);` | Immediate-mode sponsors fetched for the first group; cross-post may show the wrong group's sponsors. | 🔴 (low) G3 → H10 |
| 1013–1031 | resolves `groupid -> Group` from loaded `->groups`, merges union | ✅ |

### B3. Mailables

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `app/Mail/Message/DeadlineReached.php:72–76` | `recipientGroupForMessage()` → `?? $message->groups->first()` | ✅ recipient's group w/ first-group fallback (Task 29) |
| `app/Mail/Chat/ChatNotification.php:649–653` | `$refMessage->groups…->first()` recipient filter w/ fallback | ✅ (Task 29) |
| `app/Mail/Message/ChaseUp.php`, `ChaseUpPromised.php`, `AutoRepostWarning.php`, `ModStdMessageMail.php` | `groupId` received from caller; services iterate per group | ✅ per-group (G5). **But** the chase-up *email* double-sends for cross-posts → 🔴 G7a → H12 |
| `app/Console/Commands/Mail/TestMailCommand.php:913` | `$group = $message->groups->first();` | Test/dev tooling only. | 🟡 |

### B4. Other batch services

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `app/Services/AutoApproveService.php` | per-group, checks `mg.heldby`, excludes spam on any group | ✅ (V1 audit §3) |
| `app/Services/PushNotificationService.php:1155,1249` | operates on the already-deduped post list (one entry per msgid); refs are doc comments | ✅ no change (G5) |
| `app/Services/StatsGenerationService.php`, `GroupStatsService.php`, `Models/Group.php` | DISTINCT / GROUP BY groupid | ✅ (stats audit) |
| `app/Console/Commands/Dedup/TnDedupCommand.php` | merges duplicate tnpostid posts into one multi-group message | ✅ (Task 12) |

---

## C. Nuxt3 frontend — `iznik-nuxt3`

### C1. ModTools components — contextual-group computeds with `groups[0]` fallback

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `modtools/components/ModMessage.vue:805` | `return message.value.groups[0].groupid` | Fallback when no `groupid` prop. Prop plumbed in Task 14. | 🟡 |
| `modtools/components/ModMessage.vue:818` | `…find(g => …gid) || message.value.groups[0]` | Contextual lookup with `[0]` last resort. | 🟡 |
| `modtools/components/ModMessageButton.vue:188` | `return message.value.groups[0].groupid` | Prop fallback (Task 14). | 🟡 |
| `modtools/components/ModStdMessageModal.vue:359` | `return message.value.groups[0].groupid` | Prop fallback (Task 14). | 🟡 |
| `modtools/components/ModMessageDuplicate.vue:59` | `ret = message.value.groups[0].groupid` | Prop fallback (Task 14). | 🟡 |
| `modtools/components/ModMessageCrosspost.vue:47` | `if (groups?.length) return groups[0].groupid` | Prop fallback (Task 14). | 🟡 |
| `modtools/components/ModMessageCrosspost.vue:75` | `… return groups[0].collection` | Display-only awareness card for a *separate* related message; legacy separate-crosspost model is being phased out by TN dedup. | 🟡 no change (G7d) |
| `modtools/components/ModLogGroup.vue:98` | `return log.value.message.groups[0]` | Documented last-resort after `log.group`/`log.groupid` (Task 26). | 🟡 |

### C2. ModTools composables

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `modtools/composables/useModMessages.js:66` | `return msg.groups[0].arrival` | Sort fallback when no context groupid (Task 16 added contextual path before this). | 🟡 |
| `modtools/composables/useModMembers.js:44–45` | `b.groups[0].arrival … a.groups[0].arrival` | **Member** sort, not message — member belongs to the group being moderated. False positive for messages. | ⚪ n/a |

### C3. Non-mod components

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `components/OutcomeModal.vue:316` | `return (shared[0] || groups[0]).groupid` | Outcome is global; groupid only for donation-ask context. Picks shared-with-user group first. | 🟡 (Task 17) |
| `components/MessageReportModal.vue:140` | `… : message.value.groups[0].groupid` | Best-shared-group w/ `[0]` fallback (Task 21). | 🟡 |
| `components/MicroVolunteeringCheckMessage.vue:247` | `Number.parseInt((shared[0] || groups[0]).groupid)` | Shared-group-first for the volunteer's review context. | 🟡 |
| `stores/message.js:718–727` | `getByGroup` matches ANY group | ✅ (Task 13) |
| `components/MessageHistory.vue` | per-row `grouparrivalago(group.arrival)` | ✅ (Task 26) |
| `pages/message/[id].vue` | `groups.every(g => g.collection === 'Rejected')` | ✅ (Task 26) |

### C4. Confirmed non-message false positives (record only)

`ModVolunteerOpportunity.vue:24/30/59`, `ModCommunityEvent.vue:23/30/52`,
`VolunteerOpportunityModal.vue:737`, `CommunityEventModal.vue:664` — volunteer
opportunities & community events, which legitimately attach to a single group. ⚪ n/a.

---

## D. V1 PHP — `iznik-server` (audit-only; V2 must cover)

V1 is being retired. These are documented so V2 coverage can be confirmed; **no fixes planned.**

### D1. `include/message/Message.php` — group writes missing `groupid` in WHERE

| Line | Code | Single-group bug | V2 coverage |
|------|------|------------------|-------------|
| 3045 | `UPDATE messages_groups SET collection = ? WHERE msgid = ?` (reject, subject branch) | rejects on ALL groups | ✅ `handleReject` per-group |
| 3051 | `UPDATE … collection = 'Rejected' … WHERE msgid = ?` / `SET deleted = 1 WHERE msgid = ?` | rejects/deletes ALL groups | ✅ |
| 3804 | `UPDATE messages_groups SET collection = ? WHERE msgid = ?` | ALL groups | ⚪ V1 retired; V2 mod actions are per-group (V1 audit) |
| 4553 | `DELETE FROM messages_groups WHERE msgid = ?` | deletes ALL groups | ✅ `handleDeleteMessage` per-group |
| 4591 | `UPDATE messages_groups SET collection = ? WHERE msgid = ?` | ALL groups | ⚪ V1 retired; V2 mod actions are per-group (V1 audit) |
| 5190, 5196 | `UPDATE … arrival/collection … WHERE msgid = ?` (repost) | ALL groups | ✅ per-group repost (Task 23) |
| 5233 | `UPDATE … autoreposts = autoreposts + 1 WHERE msgid = ?` | ALL groups | ✅ |
| 5403–5408 | `DELETE FROM messages_groups WHERE msgid = ?` then INSERT one (move) | move wipes all groups | ✅ no move op in V2 |
| 270 | `UPDATE messages_groups SET msgtype = ? WHERE msgid = ?` | msgtype identical across groups → harmless | ⚪ |

### D2. `include/message/Message.php` — first-group reads

| Line | Code | Assessment | V2 coverage |
|------|------|-----------|-------------|
| 1750 | `$groupid = … $msg['groups'][0]['groupid'] : NULL` | first-group for some single-group context | ⚪ V1 retired; no action |
| 5398 | `if ($me->isModOrOwner($groups[0]) && …)` | mod check against first group only | ⚪ V1 retired; V2 `isModForMessage` checks **any** group |

### D3. `include/ai/ModBot.php`

| Line | Code | Status |
|------|------|--------|
| (rules load) | previously `$groups[0]['groupid']` only | ✅ Fixed — `getMergedRulesForGroups()` unions all groups (V1 audit §6). Note: this is the one V1 fix that *was* made because ModBot is still active and not yet in Go. |

### D4. `include/user/User.php`

| Line | Code | Status |
|------|------|--------|
| 1645 `getActiveCountss()` | `COUNT(*) … JOIN messages_groups … GROUP BY fromuser,type,outcome` — no DISTINCT, inflates per-user post counts on multi-group | ⚪ V1 retiring, noted in stats audit |
| 5585–5586 | `$thisone['groups'][0]['groupid']` / sets `groups[0]['namedisplay']` | first-group display only | ⚪ V1 retired; no action |
| 3527 | `$groupids = count(...)==0 ? [0] : $groupids` | sentinel for "no groups", not a message group-pick | ⚪ false positive |

### D5. V1 integrations (single-group by nature, but listed)

`integrations/FreebieAlerts.php:81` (`groups[0]['arrival']`),
`integrations/RepairCafeWales.php:59`, `integrations/ReachVolunteering.php:111–112` —
**FreebieAlerts.php:81 is the relevant one**: it uses the first group's arrival as the
item's `created_at`. The V2 freebie-alerts worker (queued from `message.go:1843`) should be
checked for the same assumption (see A5). The others are partner integrations operating on
a single supplied group — ⚪ n/a.

### D6. V1 Dashboard / Stats (stats audit, V1 retiring)

`include/dashboard/Dashboard.php:163,235` — per-`messages_groups`-row counts that can
inflate; ⚪ V1 only. `include/misc/Stats.php` all DISTINCT — ✅.

---

## E. Summary of genuinely outstanding items (the short list)

After the deep-dive (§G), everything not listed below is either already handled (✅), an
intentional/legacy fallback (🟡), or V1-retired (⚪). The genuinely-outstanding work, by
priority (full tasks in §H):

**Critical — user-visible correctness**
1. **✅ DONE (validated, not committed) — Spatial index is now per-group (G1).**
   `messages_spatial` was `UNIQUE(msgid)`, so a cross-posted message was indexed under only
   one group and invisible in browse/map/search on the others (and the reconciler
   flip-flopped it between groups). Fixed across schema + both writers + reconciler +
   read-side dedup + the spatial-go index. → H1–H5.
2. **✅ DONE (validated, not committed) — Chase-up emails double-sent for cross-posts (G7a).**
   `ChaseUpService` now stamps `lastchaseup` on all the item's groups when one chase-up is
   sent, so a cross-posted item is chased up once per interval, not once per group. → H12.

**Pre-Task-20 (must precede dropping `messages.heldby`)**
3. **✅ DONE (validated, not committed) — `session/session.go:1024` & `:1033`** now read
   `mg.heldby` for the held/unheld badge split. → H6.
4. **✅ DONE (validated, not committed) — `group/groupWork.go:135`** held-split now reads
   `mg.heldby`, not the global `messages.heldby`. **Was under-flagged by the stats audit.** → H7.
5. **🟡→remove `message/message.go:1799,2051,2084,2121`** — `messages.heldby` dual-writes,
   to be removed with Task 20. → H8.

**Should-fix — consistency (low stakes)**
6. **✅ DONE (validated, not committed) — Digest per-post byline / immediate sponsors** now
   use the recipient-preferred group, matching the Task-27 header fix. → H9, H10.
7. **🔴 Pagination cursor `message_list.go:570` (G4)** — reads an arbitrary group's arrival
   instead of the contextual group's; page boundaries can skip/repeat. → H11.

## F. Items that were flagged for verification (now all resolved)

Every item originally flagged 🔵 has been investigated; see **§G** for the full trace and
**§H** for the resulting tasks. Outcome at a glance:

| Flagged item | Outcome | Where |
|--------------|---------|-------|
| `message.go:1754` spatial-index writer | 🔴 Change — spatial index is single-group | G1 → H1–H5 |
| `message.go:1812` spamtype probe | ✅ Correct (existence check) | G5 |
| `message.go:3202` mod-delete audit log | 🟡 Acceptable fallback | G6 |
| `message_list.go:570` pagination cursor | 🔴 Change (low) | G4 → H11 |
| `microvolunteering.go:392,446` challenge selection | ✅ No change (`microactions` dedup by msgid) | G7c |
| Freebie-alerts worker | ✅ No change (location-based, one post/msgid) | G7b |
| `UnifiedDigest.php:787` / `UnifiedDigestService.php:795` | 🔴 Change (low) | G3 → H9, H10 |
| `PushNotificationService.php:1155,1249` | ✅ No change (operates on deduped list) | G5 |
| `ModMessageCrosspost.vue:75` | 🟡 No change (display-only, legacy) | G7d |
| Chase-up / auto-repost mailables | 🔴 Chase-up email double-sends; repost is fine | G7a → H12 |
| V1 `Message.php` / `User.php` reads | ⚪ No action (V1 retired; V2 covers) | §H |

---

## G. Investigation results (deep-dive on the 🔵 items)

Each flagged item was opened and traced. Conclusions:

### G1. ✅ **FIXED (validated, not committed) — Spatial index was single-group per message** (browse / map / search visibility)

**This was the most significant finding and was not covered by any prior task. Implemented in H1–H5.**

- `messages_spatial` has a **`UNIQUE(msgid)`** key
  (`iznik-batch/database/migrations/2025_12_10_094529_create_messages_spatial_table.php:21`)
  — exactly **one row per message**, carrying **one `groupid`**.
- The public browse/map and search all filter on that single groupid:
  - `iznik-server-go/message/groups.go:28` — `AND messages_spatial.groupid = <gid>`
  - `iznik-server-go/message/bounds.go:55,60` — `messages_spatial.groupid`, joins `groups`
  - `iznik-server-go/message/search.go:82,139,180` — `AND messages_spatial.groupid IN (...)`
- Both writers pick **one** group and upsert a single row:
  - Go: `addApprovedMessageToSpatialIndex` (`message/message.go:1730–1766`) —
    `ORDER BY messages_groups.arrival DESC LIMIT 1`, inserts one row.
  - Laravel reconciler: `MessageSpatialService::addApprovedMessage()`
    (`app/Services/MessageSpatialService.php:240–281`) —
    `orderByDesc('messages_groups.arrival')->first()`, one row.
  - Laravel bulk reconciler `upsertRecentMessages()` (same file, 41–100) joins
    `messages_groups` (N candidate rows for a multi-group message) but the
    `ON DUPLICATE KEY UPDATE` collapses to one row. **Worse:** its change-detection WHERE
    includes `messages_spatial.groupid != messages_groups.groupid`
    (line 66), so for a multi-group message the stored groupid will *always* mismatch the
    other group's candidate row — the message **flip-flops** between groups on every
    reconciler run (churn + nondeterministic visibility).

**Impact:** A message cross-posted to groups A and B is indexed under only one of them.
A user browsing/searching only the other group never sees it, even though it's live there.

**Fix shape (schema + Go + Laravel + read-side dedup):**
1. Change `messages_spatial` unique key from `msgid` → composite `(msgid, groupid)`.
2. Update both writers to insert **one row per group** the message is approved on.
3. Update the bulk reconciler's change-detection and delete logic to be per-(msgid,groupid).
4. Add `GROUP BY msgid` / `DISTINCT` to `groups.go`, `bounds.go`, `search.go` reads so a
   user in multiple of a post's groups sees it **once** (mirrors the Task 11 list dedup).
5. Confirm the spatial server (`iznik-spatial-go`) consumes the per-group rows correctly.

### G2. 🔴 Mod-queue / pending-work held splits read global `messages.heldby`

Confirmed (already in §A3 / §E):
- `session/session.go:1024` (unheld) & `:1033` (held) → switch to `mg.heldby`.
- `group/groupWork.go:135` → `m` is the joined `messages` table; the held split uses the
  **global** column, so per-group holds are invisible and it breaks at Task 20. Switch the
  `held` expression to `mg.heldby IS NOT NULL`. (`groupWork.go:204` is `memberships` — n/a.)

### G3. 🔴 (low) Digest per-post byline & immediate-email sponsors use `postedToGroups[0]`

The digest **header** group was fixed to the recipient's group (Task 27), but two per-post
sites still take the first group:
- `app/Mail/Digest/UnifiedDigest.php:787` — the "Posted on <group>" byline + `/explore`
  link uses `$postedToGroups[0]`. On a cross-post this can name a group the recipient isn't
  in. Should use the recipient-preferred group (reuse `preferredGroupForPost()` logic).
- `app/Services/UnifiedDigestService.php:795` — immediate-mode picks
  `postedToGroups[0]` to fetch sponsors. For a cross-post the sponsors shown may be the
  wrong group's. Lower stakes (sponsors), but inconsistent with the header fix.

### G4. 🔴 (low) Pagination cursor reads an arbitrary group's arrival

`message/message_list.go:570` — the "next page" cursor does
`SELECT arrival FROM messages_groups WHERE msgid = ? … LIMIT 1`. The list is ordered by the
**contextual** group's arrival, but the cursor may read a *different* group's arrival for a
multi-group message → a page boundary could skip or repeat a post. Edge case; fix by
selecting the arrival for the same group the ORDER BY used.

### G5. ✅ Resolved as already-correct (downgraded from 🔵)

- **Chase-up / auto-repost mailables** (`ChaseUpService`, `AutoRepostService`): both
  iterate `messages_groups` per group and pass `groupId: $group->id`; comments explicitly
  document the multi-group fix. The mailables (`ChaseUp.php`, `ChaseUpPromised.php`,
  `AutoRepostWarning.php`, `ModStdMessageMail.php`) receive `groupId` from the caller, not
  via `groups->first()`. ✅ **Caveat:** the chase-up *email* (not the repost) double-sends
  for cross-posts — see G7a / H12.
- **`PushNotificationService::notifyDailyNewPosts` / `buildDailyNewPostsPayload`**: operate
  on the already-deduped post list (one entry per msgid with a `postedToGroups` array);
  `postedToGroups` references are doc comments only. No double-send. ✅
- **`message/message.go:1812`** (spamtype probe): `… groupid IN ? AND spamtype IS NOT NULL
  LIMIT 1` is an *existence* check across the authorized groups to decide ham-marking
  (global). Correct. ✅

### G6. 🟡 Acceptable fallbacks / minor display (downgraded from 🔵)

- `message/message.go:3202` — mod-delete audit log: uses `?groupid=` when supplied, else
  primary-group fallback for owner-global delete. Acceptable.
- `microvolunteering.go:392,446` — challenge selection picks one (msg, group) from the
  volunteer's own groups via `LIMIT 1`. No correctness bug; only a slim chance the same
  item is offered as a challenge once per group over time. Low priority.
- `ModMessageCrosspost.vue:47,75` — the standalone crosspost card shows `groups[0]`'s name
  and collection. Display-only; for a multi-group related message it shows just the first
  group. Low priority.

### G7. Verify items — investigated and resolved

All four 🔵 items have been traced to a firm decision.

**G7a. 🔴 Duplicate chase-up emails — CHANGE NEEDED.**
`ChaseUpService::process()` (`app/Services/ChaseUpService.php:398–421`) iterates **every**
Freegle group and calls `getCandidates($group->id, …)`, which returns the message's
`messages_groups` row **for that group** (filtered `WHERE messages_groups.groupid = ?`,
line 554). For a message cross-posted to groups A and B it is therefore a candidate in both
iterations. `canChaseup()` (lines 584–609) is *explicitly per-group* — it reads the
per-group `lastchaseup` and `autoreposts` and the code comment states "we check only the
current group … each group is evaluated independently." So once both groups have hit max
reposts and have a reply, the poster receives **two** "What happened to: X?" emails for the
**same physical item**. The per-group behaviour is correct for *reposting* (each group has
its own schedule) but wrong for the *chase-up email*, which asks the poster to record a
**global** outcome. `ChaseUpPromised` has the same path.
**Fix:** gate the chase-up email on the item, not the group — send at most one chase-up per
`(fromuser, msgid)` within the chaseup interval. Recommended: before sending, check the
**most recent `lastchaseup` across all of the message's `messages_groups` rows** (not just
the current group); when one is sent, stamp `lastchaseup` on **all** of the message's rows
(or record an item-level marker). A per-run "already chased msgids" set alone is
insufficient because eligibility on the second group can arrive on a *later* cron run.

**G7b. ✅ Freebie-alerts worker — NO CHANGE NEEDED.**
The Go API queues `freebie_alerts_add {msgid}`; the processor is
`ProcessBackgroundTasksCommand::handlelnsAdd()`
(`app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php:1240–1341`). It posts **one**
record to freebiealerts.app keyed by `id => $msgId`, with `latitude`/`longitude` from the
`messages` row (a single, global location — correct for a physical item). freebiealerts.app
is a **location-based** external feed and does **not** partition by Freegle group, so there
is no multi-group visibility problem. The only group-dependent field is
`created_at => $group->arrival`, where `$group` is the first Approved `messages_groups` row
(`->first()` with no ordering, line 1278–1281) — a *cosmetic* approval-timestamp
inaccuracy. Optional tidy-up: order by earliest arrival for determinism; not required.

**G7c. ✅ Microvolunteering challenge offers — NO CHANGE NEEDED.**
The challenge queries (`microvolunteering.go:382, 433`) `LEFT JOIN microactions ON
microactions.msgid = messages_groups.msgid AND microactions.userid = ?` and require
`microactions.id IS NULL`. Because `microactions` is keyed by **`msgid`** (not
`(msgid, groupid)`), once a volunteer reviews a message on *any* group's challenge it is
excluded from *all* future challenges for that volunteer. The `LIMIT 1` picks a single
(msg, group) row to scope the review to one group (correct for per-group `sendForReview`),
and there is no double-offer of the same item.

**G7d. 🟡 ModMessageCrosspost card — NO CHANGE NEEDED (optional polish).**
`ModMessageCrosspost.vue` renders a one-line awareness note about a **separate, related**
message (a detected duplicate/crosspost — `messages_related`), not the message being
moderated. Under the new model TN duplicates collapse into one multi-group message
(Task 12 dedup), so separate-crosspost rows are a shrinking legacy set. The card shows
`groups[0]`'s name (line 47) and collection (line 75); for a related message that is itself
multi-group it would show only the first group. Display-only, secondary, and on the
declining path — **leave as-is.** Optional polish: show all group names / contextual
collection.

---

## H. TODO — everything that should be done

Ordered by priority. Check off as completed.

### Critical (correctness — user-visible bugs) — ✅ DONE (validated; not yet committed)

> Go suite 3193✓ and Laravel suite 4238✓ (incl. 4 new tests); migration verified on a
> fresh test DB. **spatial-go (H5) not locally runnable** — additive `GROUP BY ms.msgid`
> consistent with `search.go`; CI runs the spatial-go suite separately.

- [x] **H1. Make the spatial index per-group (G1).** New migration
  [2026_06_17_000001_make_messages_spatial_per_group.php](../iznik-batch/database/migrations/2026_06_17_000001_make_messages_spatial_per_group.php)
  swaps `UNIQUE(msgid)` → `UNIQUE(msgid, groupid)`, dropping/re-adding the msgid FK and
  detecting the old single-column unique by shape (name-agnostic; aliases the
  `information_schema` column to dodge a server-dependent column-case bug).
- [x] **H2.** `addApprovedMessageToSpatialIndex`
  ([message/message.go](../iznik-server-go/message/message.go)) now loops all approved
  groups and inserts one row each (removed `ORDER BY arrival DESC LIMIT 1`).
- [x] **H3.** `MessageSpatialService` ([app/Services/MessageSpatialService.php](../iznik-batch/app/Services/MessageSpatialService.php)):
  `upsertRecentMessages()` and `addApprovedMessage()` write one row per (msgid, groupid);
  change-detection joins on **both** msgid and groupid (removed the `groupid != groupid`
  flip-flop clause); `removeOldMessages()`/`removeNonApprovedMessages()` are per-group
  (incl. orphaned / soft-deleted group rows); new `spatialAdminRemoveIfGone()` only evicts
  a msgid from the external server once **no** group rows remain.
- [x] **H4.** `ROW_NUMBER() OVER (PARTITION BY id …)` dedup wrapper added to
  [groups.go](../iznik-server-go/message/groups.go) and [bounds.go](../iznik-server-go/message/bounds.go);
  [search.go](../iznik-server-go/message/search.go) uses `COUNT(DISTINCT messages_index.wordid)`
  so per-group rows don't inflate relevance. Tests: `TestMyGroupsDedupsMultiGroup`,
  `TestBoundsDedupsMultiGroup`, plus two `MessageSpatialServiceTest` reconciler tests.
- [x] **H5.** [dataset_messages.go](../iznik-spatial-go/dataset_messages.go) load + delta
  queries `GROUP BY ms.msgid` (with `MAX()` for promised/successful) so the per-message
  point index stays one item per msgid (avoids R-tree orphan churn from duplicate extids).

### Pre-Task-20 (must precede dropping `messages.heldby`)

- [x] **H6.** ✅ DONE (validated, not committed) — `session/session.go:1024` & `:1033` now
  read `mg.heldby` (per-group) for the unheld/held pending badge split. The `messages m`
  join stays (needed for `m.deleted`/`m.fromuser`). Test: `TestWorkCountPendingHeldPerGroup`
  (`session_test.go`).
- [x] **H7.** ✅ DONE (validated, not committed) — `group/groupWork.go:135` held-split now
  reads `mg.heldby` (not the global `messages.heldby`). Updated `TestGetGroupWork_HeldPending`
  to seed `messages_groups.heldby`; added `TestGetGroupWork_HeldPerGroup` (message held on
  one of two groups shows held only there). Go suite 3195✓.
- [ ] **H8.** Remove the `messages.heldby` dual-writes in `message/message.go:1799, 2051,
  2084, 2121` as part of Task 20, after H6/H7 land and V1 is retired. *(Deferred — depends
  on V1 retirement; not actionable now.)*

### Should-fix (consistency / correctness)

- [x] **H9.** ✅ DONE (validated, not committed) — `UnifiedDigest::prepareCard()` byline +
  `/explore` link now use `selectPreferredGroup(postedToGroups, recipient's groups)` instead
  of `postedToGroups[0]`, so a cross-post's byline names the recipient's group (matching the
  header/subject). Test: `test_byline_uses_recipients_group_for_cross_post`.
- [x] **H10.** ✅ DONE (validated, not committed) — `UnifiedDigestService` immediate-mode
  sponsors now scope to the recipient-preferred group via the same (now `public`)
  `UnifiedDigest::selectPreferredGroup()`. Laravel 4240✓.
- [ ] **H11.** Pagination cursor `message_list.go:570` — read the arrival of the same group
  the list ORDER BY used (the contextual group), not an arbitrary `LIMIT 1` group row.
- [x] **H12. Deduplicate chase-up emails per item (G7a).** ✅ DONE (validated, not
  committed) — `ChaseUpService::processGroup()` now stamps `lastchaseup` on **all** of the
  message's `messages_groups` rows when a chase-up is sent (was per-group), restoring V1's
  whole-item `WHERE msgid = ?` behaviour. Because `process()` scans groups sequentially and
  `getCandidates()` re-reads `lastchaseup`, the other groups then see the item as recently
  chased and skip it — so a cross-posted item is chased up once per interval, not once per
  group. Reposting stays per-group (keys off arrival/autoreposts). Test:
  `test_crosspost_chased_up_once_not_per_group` (`ChaseUpServiceTest.php`); Laravel 4239✓.
  (`ChaseUpPromised` shares this path, so it's covered too.)

### Investigated — NO change needed (resolved 🔵 items; recorded for the reviewer)

- **Freebie-alerts worker (G7b).** One location-based post per msgid; not group-partitioned.
  Only `created_at` uses an arbitrary group's arrival — cosmetic. *Optional* determinism
  tidy-up (`ProcessBackgroundTasksCommand.php:1278`: order by earliest arrival); not
  required.
- **Microvolunteering challenge offers (G7c).** `microactions` dedup is by `msgid`, so a
  reviewed item is excluded on all groups. No double-offer.
- **`ModMessageCrosspost.vue` (G7d).** Display-only awareness card for a separate related
  message; legacy separate-crosspost model is being phased out by TN dedup. Leave as-is.
- **`getPrimaryGroupForMessage` callers** (`message.go:1710, 2312, 2425, 2815, 3497`) —
  all owner-global / legacy fallbacks; Task 28 labelled them. No change.
- **V1 `Message.php` reads** (`1750, 3804, 4591, 5398`) and **`User.php:5585`** — V1 is
  retired (audit-only). V2 destructive writes are confirmed per-group (V1 audit results);
  V2 mod-permission checks use `isModForMessage` which checks **any** group. No action.

### Documentation

- [ ] **H13.** Fold the spatial-index finding (G1) into
  `multi-group-messages-design.md` — it currently lists `messages_spatial` under
  "Tables already per-group (no changes needed)", which is **incorrect** (unique key is
  `msgid`, not `(msgid, groupid)`).
- [ ] **H14.** Correct `multi-group-stats-audit.md` — it marked `groupWork.go:135` SAFE but
  the held split reads the global column (H7).

---

## Search commands used (for reproducibility)

```bash
# Go
rg -n "MessageGroups\[0\]|\.Groups\[0\]|Groups\[0\]" iznik-server-go --type go
rg -n "getPrimaryGroupForMessage|PrimaryGroup" iznik-server-go --type go
rg -n "m\.heldby|messages\.heldby|messages\.spamtype|messages\.spamreason" iznik-server-go --type go
rg -n "messages_groups.*LIMIT 1|ORDER BY .*arrival.*LIMIT 1" iznik-server-go --type go

# Nuxt
rg -n "groups\[0\]" iznik-nuxt3 -g '!node_modules'

# Laravel
rg -n "groups->first|groups\(\)->first|postedToGroups\[0\]|\['groups'\]\[0\]" iznik-batch/app

# V1 PHP
rg -n "groups\[0\]|groups->first|UPDATE messages_groups|DELETE FROM messages_groups" iznik-server/include
```
