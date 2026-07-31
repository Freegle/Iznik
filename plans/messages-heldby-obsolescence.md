# Making `messages.heldby` obsolete

**Status:** plan, not started
**Why now:** Discourse 9970/2 (Sheila) is the *fifth* attempt at "scope the hold to the right group".
Every previous attempt fixed one layer, left the legacy column in place, and was closed as partial.

## The problem in one line

`messages.heldby` is a message-level mirror of a fact that is inherently **per-group**
(`messages_groups.heldby`). Any reader of the mirror leaks one group's hold onto every other group
the post rippled to.

## Why four previous attempts failed

| Attempt | Branch / PR | What it changed | Why it was still partial |
|---|---|---|---|
| 1 | `dc051052e` | frontend scoping | column still dual-written and read elsewhere |
| 2 | `c03eabea6` ("re-attempt") | frontend scoping | same |
| 3 | `1979ca73b` / `3b7f44aa5`, PR **#1072** | per-group row in ModTools | closed by edwh 2026-07-14, no reason recorded |
| 4 | server `effectiveHeldby()` (on master) | resolves hold to *viewer's* groups | fixes cross-team leak, **not** the multi-group mod |
| 5 | PR **#1199** (FSM, open) | frontend `contextGroup?.heldby` | leaves the column and the Laravel readers |

Attempt 4 is the subtle one and explains the recurrence. `effectiveHeldby()`
(`iznik-server-go/message/heldby.go`) returns a hold on **any group the viewer moderates**. For a mod
of several nearby groups (Sheila, Borehamwood/Hatfield/Potters Bar/Welwyn/Watford) a post rippled to
two of her groups and held on one makes `message.heldby` truthy while she is administering the
*other*, unheld copy. The API says "held", so the UI hides every button. The fix is not another
frontend patch: the field itself is unanswerable without a group context.

## Current state of the migration (audited 2026-07-30)

`messages_groups.heldby` added by `iznik-batch/database/migrations/2026_04_14_000001_*`; data copied
by `..._000002_*`. It is **already the enforced source of truth** — PR #1131 made the central action
dispatcher check it (see the hold-enforcement audit). `messages.heldby` is a pure legacy mirror.

**Already per-group (no work):**
- `iznik-server-go/group/groupWork.go:137` — ModTools badge counts, reads `mg.heldby`
- `iznik-batch/app/Services/AutoApproveService.php:81`
- `iznik-batch/app/Services/ContentCheckService.php:395`
- `iznik-batch/app/Monitoring/ScheduledOutcomeRegistry.php:101`
- `iznik-batch/app/Models/Group.php:273`

**Still reads the cross-group mirror (all wrong, all user-visible):**
- `iznik-batch/app/Services/ModNotifService.php:215` — pending count for **one** `groupid`, excluded by
  a hold on any group. Prime suspect for the pending-count complaints (9951, 9481/635).
- `iznik-batch/app/Services/PushNotificationService.php:467` — same shape, `mg.groupid IN (...)` but
  `m.heldby IS NULL`. Suppresses mod push for groups that never held anything.
- `iznik-batch/app/Services/ChaseUpService.php:279` — chase-ups silently skipped across groups.
- Frontend: `ModMessage.vue`, `ModMessageButton.vue`, `ModMessageButtons.vue` (what #1199 targets),
  plus `iznik-nuxt3/api/heldConflict.js` and the `useHeldNotice` composable.

**Writers to retire** (`iznik-server-go/message/message.go`): sets at `2584`, `2617`; clears at
`2199`, `2374`, `2673`.

**Out of scope — different tables that legitimately own a `heldby`:** `memberships`, `admins`,
`volunteering`, `communityevents`, `spam_users`, `chat_messages_held`.

## The decision that makes this final

`Message.heldby` (`message.go:232`, JSON `heldby`) **cannot be answered correctly without a group
context**, so stop trying. Two options:

- **A (preferred): remove `heldby` from the message payload entirely.** Consumers read
  `groups[].heldby` for the group they are administering. Already populated
  (`message.go:492`, `message_list.go:251`). Kills the whole class of bug — there is no
  ambiguous field left to misread.
- **B: keep `heldby` but make it context-dependent**, resolved against `contextGroupid`. Smaller
  frontend diff, but preserves a footgun for the next consumer that forgets the context.

Recommend **A**. B is how we got attempts 1-5.

## Phases

Each phase ships and stays green on its own; the column drops only at the end.

**Phase 1 — fix the Laravel readers (highest user-visible value, no contract change).**
Point `ModNotifService:215`, `PushNotificationService:467`, `ChaseUpService:279` at `mg.heldby` for
the group already in each query. Independently valuable: mod counts, push and chase-ups stop being
suppressed cross-group. Tests: per-service case with a post on groups A+B held only on B, asserting
A's count/notification/chase-up is unaffected.

**Phase 2 — frontend reads per-group only.**
Supersede #1199: `ModMessage.vue`, `ModMessageButton.vue`, `ModMessageButtons.vue`, `heldConflict.js`,
`useHeldNotice` all resolve from `groups[]` against the context group. #1199's per-group computeds and
its 9970/2 regression test are a good starting point — recover them rather than rewrite.

**Phase 3 — drop `heldby` from the API payload** (option A). Remove the field from `Message`, delete
`effectiveHeldby()` and its test. Grep the app/ModTools for stragglers before merging.

**Phase 4 — stop dual-writing.** Remove the five `UPDATE messages SET heldby` statements. Hold and
release then touch `messages_groups` only.

**Phase 5 — drop the column.** Laravel migration dropping `messages.heldby` (index/FK first).
Refresh test fixtures in the same PR: a column drop against stale fixtures fails with 1146 — see the
drop-table/stale-fixture finding.

## Verification

- Reproduce first: post on two groups moderated by the same mod, hold on one, confirm on the other
  that buttons show, the badge counts, push fires and chase-ups run.
- Cross-team case stays fixed: a mod of an unrelated group must still see no hold.
- Re-check Derek 9481/635 ("2 posts held by another Mod, only 1 blue in Pending") after Phase 1 —
  the badge query in `groupWork.go` is already per-group, so if it still misreports, the cause is the
  Pending/Pendingother split, not this migration. Track separately rather than assuming Phase 1 fixes it.
- `grep -rn "messages\.heldby\|m\.heldby" iznik-server-go iznik-batch iznik-nuxt3` returns nothing
  outside migrations after Phase 5.

## Note for the monitor FSM

The FSM re-derived this fix from a fresh report with no knowledge of PR #1072 or the earlier branches,
and opened #1199 as a fifth attempt. Before opening a fix PR it should search closed PRs and branch
history for the same feature area and surface prior attempts (especially human-closed ones) rather
than starting from scratch.
