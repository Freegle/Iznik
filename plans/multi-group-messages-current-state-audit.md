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
| 3202 | `groupid = getPrimaryGroupForMessage(db, msgid)` | Outcome/withdraw path fallback. | 🔵 verify each is genuinely owner-global |
| 3497 | `groupid = getPrimaryGroupForMessage(db, newMsgID)` | JoinAndPost fallback (owner just posted). | 🟡 |
| 1786, 1885, 1943 | `ctx.Groupid = authorizedGroups[0]` | "Primary acted-on group for logging" after resolving the authorized set. Per-group loop runs separately; this is just the log anchor. | 🟡 |
| 1754 | `SELECT … FROM messages_groups … ORDER BY messages_groups.arrival DESC LIMIT 1` | Picks newest group row for spatial/notify context. | 🔵 verify |
| 1812 | `SELECT spamtype FROM messages_groups WHERE msgid = ? AND groupid IN ? AND spamtype IS NOT NULL LIMIT 1` | Reads any one group's spamtype across the authorized set. | 🔵 verify intent |

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
| 392 | `… WHERE messages_groups.groupid IN (…) ORDER BY messages_groups.arrival ASC LIMIT 1` (approved-message challenge) | Picks one (msg, group) row from the volunteer's groups. Each challenge is inherently single-group-scoped (volunteer reviews within their groups). Likely fine, but if the same message is on two of the volunteer's groups it could be offered twice over time. | 🔵 verify |
| 446 | same pattern for photo-rotate challenge | same | 🔵 verify |
| 875–892 | `sendForReview(db, msgid, groupid, reason)` | Per-group already (Task 10). | ✅ |

### A5. Freebie-alerts fanout (`message/message.go`)

| Line | Code | Assessment | Status |
|------|------|-----------|--------|
| 1843–1849 | On approve of an Offer: `QueueTask(TaskFreebieAlertsAdd, {msgid})` — **no groupid passed** | The freebie-alerts worker resolves location/group itself. Need to confirm that resolution doesn't just take the first group's arrival/location (cf. V1 `FreebieAlerts.php:81` which used `groups[0]['arrival']`). | 🔵 verify worker side |
| 1908, 1959, 2020, 3208, 3855, 3929 | `TaskFreebieAlertsRemove` on delete/spam/outcome | Removal is keyed by msgid (global) — correct, item gone everywhere. | ✅ |

### A6. List / search dedup (`message/message_list.go`, `search.go`)

| Line | Code | Status |
|------|------|--------|
| 131,143,157,168,181,509 | `SELECT DISTINCT mg.msgid …` | ✅ deduped (Task 11) |
| 266–273 | `msg.Groups = groups` built from all `messages_groups` rows | ✅ multi-group array |
| 570 | `SELECT arrival FROM messages_groups WHERE msgid = ? … LIMIT 1` (pagination cursor) | 🔵 verify — last-arrival cursor picks one group's arrival; fine if list is ordered by contextual group arrival |

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
| 787 | `$primaryGroupId = $postedToGroups[0] ?? null;` (in `renderPostCard`/sponsors helper) | Picks first group for the per-post card's group context. **Not** the recipient-preferred group like the header. Verify whether the card should use the recipient's group too. | 🔵 verify |
| 933 | `$groupNames = collect($postedToGroups)…` | Renders ALL group names. ✅ |

### B2. Digest service (`app/Services/UnifiedDigestService.php`)

| Line | Code | Assessment | Status |
|------|------|-----------|--------|
| 972/979/987 | `postedToGroups[] = $post->groupid` / `'postedToGroups' => [$post->groupid]` | dedup aggregates groups into the array. ✅ (Task 18) |
| 795 | `$postGroupId = (int) ($deduped['postedToGroups'][0] ?? 0);` | First-group pick for some per-post context. | 🔵 verify (same question as UnifiedDigest:787) |
| 1013–1031 | resolves `groupid -> Group` from loaded `->groups`, merges union | ✅ |

### B3. Mailables

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `app/Mail/Message/DeadlineReached.php:72–76` | `recipientGroupForMessage()` → `?? $message->groups->first()` | ✅ recipient's group w/ first-group fallback (Task 29) |
| `app/Mail/Chat/ChatNotification.php:649–653` | `$refMessage->groups…->first()` recipient filter w/ fallback | ✅ (Task 29) |
| `app/Mail/Message/ChaseUp.php`, `ChaseUpPromised.php`, `AutoRepostWarning.php`, `ModStdMessageMail.php` | not yet inspected for group selection | 🔵 verify — chase-up / repost-warning mailables may pick a group for tracking or body group-name |
| `app/Console/Commands/Mail/TestMailCommand.php:913` | `$group = $message->groups->first();` | Test/dev tooling only. | 🟡 |

### B4. Other batch services

| File:Line | Code | Assessment | Status |
|-----------|------|-----------|--------|
| `app/Services/AutoApproveService.php` | per-group, checks `mg.heldby`, excludes spam on any group | ✅ (V1 audit §3) |
| `app/Services/PushNotificationService.php:1155,1249` | operates on `postedToGroups` arrays | 🔵 verify it fans out / dedups per group correctly |
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
| `modtools/components/ModMessageCrosspost.vue:75` | `… return groups[0].collection` | **Shows the first group's collection, not the contextual group's.** A crosspost held/pending on group A but approved on B may display the wrong status. | 🔵 verify |
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
| 3804 | `UPDATE messages_groups SET collection = ? WHERE msgid = ?` | ALL groups | 🔵 confirm which action; check V2 |
| 4553 | `DELETE FROM messages_groups WHERE msgid = ?` | deletes ALL groups | ✅ `handleDeleteMessage` per-group |
| 4591 | `UPDATE messages_groups SET collection = ? WHERE msgid = ?` | ALL groups | 🔵 confirm |
| 5190, 5196 | `UPDATE … arrival/collection … WHERE msgid = ?` (repost) | ALL groups | ✅ per-group repost (Task 23) |
| 5233 | `UPDATE … autoreposts = autoreposts + 1 WHERE msgid = ?` | ALL groups | ✅ |
| 5403–5408 | `DELETE FROM messages_groups WHERE msgid = ?` then INSERT one (move) | move wipes all groups | ✅ no move op in V2 |
| 270 | `UPDATE messages_groups SET msgtype = ? WHERE msgid = ?` | msgtype identical across groups → harmless | ⚪ |

### D2. `include/message/Message.php` — first-group reads

| Line | Code | Assessment | V2 coverage |
|------|------|-----------|-------------|
| 1750 | `$groupid = … $msg['groups'][0]['groupid'] : NULL` | first-group for some single-group context | 🔵 confirm |
| 5398 | `if ($me->isModOrOwner($groups[0]) && …)` | mod check against first group only | 🔵 confirm V2 `isModForMessage` checks any group |

### D3. `include/ai/ModBot.php`

| Line | Code | Status |
|------|------|--------|
| (rules load) | previously `$groups[0]['groupid']` only | ✅ Fixed — `getMergedRulesForGroups()` unions all groups (V1 audit §6). Note: this is the one V1 fix that *was* made because ModBot is still active and not yet in Go. |

### D4. `include/user/User.php`

| Line | Code | Status |
|------|------|--------|
| 1645 `getActiveCountss()` | `COUNT(*) … JOIN messages_groups … GROUP BY fromuser,type,outcome` — no DISTINCT, inflates per-user post counts on multi-group | ⚪ V1 retiring, noted in stats audit |
| 5585–5586 | `$thisone['groups'][0]['groupid']` / sets `groups[0]['namedisplay']` | first-group display only | 🔵 |
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

Everything else above is either already handled (✅), an intentional/legacy fallback (🟡),
or V1-retired (⚪). The items that look like they still need action:

1. **🔴 `session/session.go:1024` & `:1033`** — mod-queue badge held/unheld split reads
   global `m.heldby`. Switch to `mg.heldby`. (Stats audit flagged 1033; 1024 is its pair.)
2. **🔴 `group/groupWork.go:135`** — pending held/unheld split reads global `m.heldby`
   (joined `messages`), not `mg.heldby`. Per-group hold is invisible here and it breaks at
   Task 20. **This was under-flagged by the stats audit** (marked SAFE for the GROUP BY but
   the held expression uses the global column).
3. **🟡→remove `message/message.go:1799,2051,2084,2121`** — `messages.heldby` dual-writes,
   to be removed with Task 20.

## F. Items to verify before migrating (the 🔵 list)

- `message/message.go:1754, 1812, 3202, 570` — first/any-group reads in spatial-notify,
  spamtype probe, outcome fallback, pagination cursor.
- `microvolunteering.go:392, 446` — single-group challenge selection (double-offer risk).
- Freebie-alerts worker (queued from `message.go:1843`, cf. V1 `FreebieAlerts.php:81`) —
  does it take the first group's arrival/location?
- `UnifiedDigest.php:787` & `UnifiedDigestService.php:795` — per-post card uses
  `postedToGroups[0]` rather than the recipient-preferred group (header already fixed).
- `PushNotificationService.php:1155,1249` — confirm per-group fanout/dedup.
- `ModMessageCrosspost.vue:75` — displays `groups[0].collection`; may show wrong status.
- Batch mailables not yet inspected: `ChaseUp.php`, `ChaseUpPromised.php`,
  `AutoRepostWarning.php`, `ModStdMessageMail.php` — check group selection for tracking/body.
- V1 reads at `Message.php:1750, 3804, 4591, 5398` and `User.php:5585` — confirm V2 covers.

---

---

## G. Investigation results (deep-dive on the 🔵 items)

Each flagged item was opened and traced. Conclusions:

### G1. 🔴 **MAJOR — Spatial index is single-group per message** (browse / map / search visibility)

**This is the most significant finding and is not covered by any existing task.**

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
  via `groups->first()`. ✅ *(One minor open question — G7 below.)*
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

### G7. 🔵 Still to verify

- **Freebie-alerts worker.** Go queues `TaskFreebieAlertsAdd {msgid}` with no groupid
  (`message/message.go:1843`). The worker (and/or freebiealerts.app feed) resolves
  location/group itself; V1's `FreebieAlerts.php:81` used `groups[0]['arrival']`. Confirm
  the worker doesn't assume a single group's arrival/location for a cross-post.
- **Duplicate chase-up emails.** `ChaseUpService` now iterates per group; confirm the
  poster doesn't receive N chase-up emails for the *same item* on N groups (outcome is
  global). The `languishing`/per-user dedup logic may already cover this — verify.

---

## H. TODO — everything that should be done

Ordered by priority. Check off as completed.

### Critical (correctness — user-visible bugs)

- [ ] **H1. Make the spatial index per-group (G1).** Schema migration changing
  `messages_spatial` unique key `msgid` → `(msgid, groupid)`.
- [ ] **H2.** Update Go writer `addApprovedMessageToSpatialIndex`
  (`message/message.go:1730–1766`) to insert one row per approved group.
- [ ] **H3.** Update Laravel `MessageSpatialService::addApprovedMessage()` and
  `upsertRecentMessages()` to write/maintain one row per (msgid, groupid); fix the
  change-detection WHERE (line 66) and delete/remove logic so multi-group rows don't
  flip-flop.
- [ ] **H4.** Add `GROUP BY msgid` / `DISTINCT` dedup to spatial reads:
  `message/groups.go`, `message/bounds.go`, `message/search.go` — so a user in multiple of
  a post's groups sees it once. Add a multi-group test.
- [ ] **H5.** Verify `iznik-spatial-go` consumes per-group spatial rows correctly.

### Pre-Task-20 (must precede dropping `messages.heldby`)

- [ ] **H6.** `session/session.go:1024` & `:1033` — switch held/unheld badge counts from
  `m.heldby` to `mg.heldby`.
- [ ] **H7.** `group/groupWork.go:135` — switch the pending held-split expression from
  `m.heldby` (global) to `mg.heldby` (per-group). Add a test for a message held on one of
  two groups.
- [ ] **H8.** Remove the `messages.heldby` dual-writes in `message/message.go:1799, 2051,
  2084, 2121` as part of Task 20, after H6/H7 land and V1 is retired.

### Should-fix (consistency / minor visibility)

- [ ] **H9.** Digest per-post byline `UnifiedDigest.php:787` — use the recipient-preferred
  group instead of `postedToGroups[0]`.
- [ ] **H10.** Immediate-digest sponsors `UnifiedDigestService.php:795` — pick the
  recipient-preferred group instead of `postedToGroups[0]`.
- [ ] **H11.** Pagination cursor `message_list.go:570` — read the arrival of the same group
  the list ORDER BY used.

### Verify (decide whether action is needed)

- [ ] **H12.** Freebie-alerts worker / feed — confirm no single-group arrival/location
  assumption for cross-posts (cf. V1 `FreebieAlerts.php:81`).
- [ ] **H13.** Chase-up emails — confirm a cross-posted item doesn't trigger duplicate
  chase-ups to the poster across its groups.
- [ ] **H14.** `ModMessageCrosspost.vue:75` — decide whether the crosspost card should show
  all groups / the contextual group's collection rather than `groups[0]`.
- [ ] **H15.** `microvolunteering.go:392,446` — decide whether to dedup challenge offers by
  msgid so a cross-post isn't offered once per group.

### Already-handled — confirm coverage holds (no code expected)

- [ ] **H16.** Re-confirm the `getPrimaryGroupForMessage` callers
  (`message.go:1710, 2312, 2425, 2815, 3497`) are all genuinely owner-global / legacy
  fallbacks (Task 28 labelled them so).
- [ ] **H17.** Re-confirm V1 `Message.php` reads at `1750, 3804, 4591, 5398` and
  `User.php:5585` have V2 equivalents that handle multiple groups (V1 is audit-only).

### Documentation

- [ ] **H18.** Fold the spatial-index finding (G1) into
  `multi-group-messages-design.md` — it currently lists `messages_spatial` under
  "Tables already per-group (no changes needed)", which is **incorrect** (unique key is
  `msgid`, not `(msgid, groupid)`).
- [ ] **H19.** Correct `multi-group-stats-audit.md` — it marked `groupWork.go:135` SAFE but
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
