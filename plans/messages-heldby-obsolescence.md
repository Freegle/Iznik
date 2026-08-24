# Making `messages.heldby` obsolete

**Status (audited 2026-08-03):** Phases 1, 2 and 4 are done on master. Phase 3 was executed in full,
then deliberately half-reverted the same day as a backwards-compat shim for bundled ModTools apps;
it is not "not started", it is a settled, load-bearing decision (see below). Phase 5 (drop the
column) is still open, blocked on two preconditions.

**Why now:** Discourse 9970/2 (Sheila) is the fifth attempt at "scope the hold to the right group".
Every attempt before it fixed one layer, left the legacy column in place, and was closed as partial.
PR #1199 (commit `a6b52512f`) is the first attempt that actually stuck, landing Phases 1, 2 and (for
half a day) 3 and 4 in one PR. This document was written before that PR merged; the phase list and
status line below are what the plan originally said, corrected against what is now on master.

## The problem in one line

`messages.heldby` is a message-level mirror of a fact that is inherently **per-group**
(`messages_groups.heldby`). Any reader of the mirror leaks one group's hold onto every other group
the post rippled to.

## Why the previous attempts were still partial

| Attempt | Branch / PR | What it changed | Why it was still partial |
|---|---|---|---|
| 1 | `dc051052e` | frontend scoping | column still dual-written and read elsewhere |
| 2 | `c03eabea6` ("re-attempt") | frontend scoping | same |
| 3 | `1979ca73b` / `3b7f44aa5`, PR **#1072** | per-group row in ModTools | auto-closed by the monitor's adversarial review 2026-07-14: duplicated a helper its own docblock called the only place for that logic, inconsistent fail-open vs fail-safe across the three Vue consumers, and display-only (never touched hold/release or the API) |
| 4 | server `effectiveHeldby()` (on master) | resolves hold to *viewer's* groups | fixes cross-team leak, **not** the multi-group mod |
| 5 | PR **#1199** (merged 2026-07-30, commit `a6b52512f`) | Phase 1 Laravel readers, frontend per-group reads, field removal (Phase 3 option A), dual-write removal (Phase 4) | landed cleanly and executed most of this plan in one go, but same-day follow-up (row below) partially reversed it |
| ("row 6", not a new attempt) | commit `6a0290646` (same day, 22:06) | reinstated message-level `heldby` and `effectiveHeldby()` as a deliberate, documented backwards-compat shim | not a failed attempt, a deliberate reversal: bundled ModTools app installs render held state from the removed field and had no path to the per-group data, so removing it broke Hold/Release visibility in every installed app (Discourse 9481/636). Pinned by `TestMessagePayloadKeepsMessageLevelHeldbyForBundledApps`. This is what blocks Phase 3 option A now. |
| 6 | PR **#1238** (`fix/moderation-hold-visibility-9481-642`, this PR) | `effectiveHeldby()` changed from OR-across-viewer's-groups to AND-across-viewer's-groups (Discourse 9481/642); fixed the Phase 1 straggler (`MicrovolunteeringNotifyService.php`); added the missing `ChaseUpService` regression test | completes the one Phase 1 site the plan missed and fixes a second-order bug in the row-above compat shim; Phase 5 remains open, blocked by PR #1230 |

Attempt 4 is the subtle one and explains the recurrence. The original `effectiveHeldby()` returned a
hold on **any group the viewer moderates**. For a mod of several nearby groups (Sheila, Borehamwood /
Hatfield / Potters Bar / Welwyn / Watford) a post rippled to two of her groups and held on one made
`message.heldby` truthy while she was administering the *other*, unheld copy. The API said "held", so
the UI hid every button. Attempt 6 (this PR) hit the same class of bug again from the other direction:
a mod covering two groups, one held and one not, could see the compat field report "held" for the
wrong one of her own groups (Discourse 9481/642, "Derek"). Both bugs share a root cause: a
message-wide yes/no field cannot express a per-group condition. That is why Phase 3 option A (delete
the field) was the right call architecturally and why it keeps getting reverted for compat reasons
instead.

## Current state of the migration (audited 2026-08-03, re-audited against origin/master)

`messages_groups.heldby` added by `iznik-batch/database/migrations/2026_04_14_000001_*`; data copied
by `..._000002_*`. It has been **the enforced source of truth since PR #1131** — the central action
dispatcher (`iznik-server-go/message/message.go`, `dispatchPostMessageAction`, ~line 4610) checks
`messages_groups.heldby` directly via `heldByAnotherMod` (~line 4575), scoped to the authorized
groups an action would touch. This check never reads `Message.Heldby` or calls `effectiveHeldby()`
and does not consult the request body, so it has been correct and decoupled from the payload field
throughout every attempt above, including the one currently live.

**Already per-group (no work, confirmed unchanged):**
- `iznik-server-go/group/groupWork.go:145` (badge counts; line drifted from the plan's 137, further
  narrowed by #1199 to count only content-checked-or-held rows)
- `iznik-batch/app/Services/AutoApproveService.php:81`
- `iznik-batch/app/Services/ContentCheckService.php:420` (line drifted from 395; also reworked by
  #1199 to content-check held posts instead of skipping them, via `recordCheckOnly()`)
- `iznik-batch/app/Monitoring/ScheduledOutcomeRegistry.php:101`
- `iznik-batch/app/Models/Group.php:291` (line drifted from 273)

**Fixed by Phase 1 (PR #1199 / commit `a6b52512f`):**
- `iznik-batch/app/Services/ModNotifService.php:219` (`whereNull('messages_groups.heldby')`)
- `iznik-batch/app/Services/PushNotificationService.php:497` (`mg.heldby IS NULL`)
- `iznik-batch/app/Services/ChaseUpService.php:282` (`whereNull('messages_groups.heldby')`) — fixed
  correctly, but shipped **without** a regression test; `ChaseUpServiceTest.php`,
  `ChaseUpCommandTest.php` and `NotificationChaseUpServiceTest.php` had zero occurrences of
  "heldby". This PR adds one, using the existing `createChaseCandidate()` helper: candidate on group
  A, a second `messages_groups` row on group B with `heldby` set, assert A's chase-up still fires.

**Fixed by this PR (the straggler the plan missed):**
- `iznik-batch/app/Services/MicrovolunteeringNotifyService.php:80` still filtered on
  `messages.heldby IS NULL`. Because nothing has written `messages.heldby` since Phase 4 landed, that
  predicate was unconditionally true for every message and had stopped filtering anything at all:
  microvolunteering "please review" notifications could be sent for messages that were genuinely held
  per-group. The query already selects `messages_groups.groupid` (line 70), so the fix is the same
  shape as the three Phase 1 sites: filter on `messages_groups.heldby` instead. Existing fixtures
  (`MicrovolunteeringNotifyServiceTest.php:80`, `AI/MicrovolunteeringNotifyCommandTest.php:63`) always
  set `heldby => null` on the `messages` row and never simulated a `messages_groups` hold, so this had
  no test coverage; this PR adds a held/cross-group case matching
  `ModNotifServiceTest::test_pending_work_counts_message_held_on_a_different_group`.

**Frontend (Phase 2), corrected from the original plan:**
`ModMessage.vue`, `ModMessageButton.vue`, `ModMessageButtons.vue` all read `groups[].heldby` scoped to
a per-component context-group computed (`contextGroup?.heldby` / `.groups?.find(...)`), landed by
#1199. This is a simpler 2-state design than any of the three closed PRs (#1065, #1068, #1072)
attempted, and none of their shared-composable helpers (`messageGroupHeldById`,
`findMessageGroupRow`, `normaliseHeldById`) exist on master; #1199 used inline per-component lookups
instead. `git grep -n "heldby" iznik-nuxt3/` returns zero live-code hits for message-level
`message.heldby` anywhere in the app.

**Correction to the plan's original text:** `iznik-nuxt3/api/heldConflict.js` and
`composables/useHeldNotice.js` were listed as Phase 2 scope. They were never mirror readers and there
was never anything to migrate here. They consume a different, already-per-group `{heldby,
heldbyname}` pair that the Go API puts only in the 409 conflict body of `dispatchPostMessageAction`
(`message.go:4595-4611`, via `heldByAnotherMod`), unrelated to `Message.Heldby`
(`message.go:243`) or `effectiveHeldby()`. Phase 2 is complete without touching either file.

**Writers, confirmed retired (Phase 4):** `iznik-server-go/message/message.go` now writes
`messages_groups.heldby` only. `git grep -n "UPDATE messages SET" iznik-server-go iznik-batch | grep
heldby` returns nothing. Six statements, not the plan's five (`handleReject` clears it on two separate
branches, reject-with-subject and plain-delete, which the plan counted as one):
- `handleHold` (line 2586) and `handleBackToPending` (line 2616) set it
- `handleApprove` (line 2208), `handleReject`'s two branches (lines 2349, 2354) and `handleRelease`
  (line 2661) clear it

**Out of scope, different tables that legitimately own a `heldby`:** `memberships`, `admins`,
`volunteering`, `communityevents`, `spam_users`, `chat_messages_held`.

## The decision that makes this final (updated)

`Message.heldby` (`message.go:243`, JSON `heldby`) cannot be answered correctly without a group
context. The plan originally offered two options:

- **A: remove `heldby` from the message payload entirely.** Attempted in full by `a6b52512f`. Reverted
  the same day by `6a0290646` because bundled ModTools app installs (the app bundles its web build
  into the binary; the production APK ships weekly at best) render held state as
  `v-if="message.heldby"` / `v-if="!message.heldby"` and had no path to the per-group data.
  Removing the field broke Hold/Release visibility in every installed app (Discourse 9481/636).
  **Option A is blocked**, not abandoned: it is the correct end state, but it requires the installed
  app floor to move past pre-per-group builds first, and there is no such floor today.
- **B: keep `heldby` but make it context-dependent.** This is now the operative choice, implemented
  as a compat shim rather than a permanent design: `effectiveHeldby()` resolves the field from
  `groups[]` against the viewer's own moderated groups, deprecated in its own doc comment, kept only
  "for bundled app clients". This PR (Discourse 9481/642) is that shim's second iteration: semantics
  changed from OR-across-the-viewer's-own-groups (report held if *any* of the viewer's own groups is
  held) to AND-across-the-viewer's-own-groups (report held only if *all* of them agree; disagreement
  returns nil, the same as an unheld post). This trades one failure mode for another when a mod's own
  groups disagree: previously an unheld group could show a spurious hold, now a genuinely held group
  can show as unheld until the viewer is actually looking at it. That trade was made deliberately,
  not by oversight, and is scoped to the rare case (a moderator's own groups disagreeing on the same
  post), not the common single-group-mod path, which is unaffected either way.

**Exit criterion:** when the installed-app floor rises past pre-per-group ModTools builds (every
installed client reads `groups[].heldby` directly), delete `Message.Heldby`, `effectiveHeldby()`
(`heldby.go`, `heldby_test.go`), and `TestMessagePayloadKeepsMessageLevelHeldbyForBundledApps`
together, in one PR. Not piecemeal: deleting the test without the field (or vice versa) just
reproduces attempt 5/6's mistake of an unpinned removal.

## Phases

**Phase 1 — fix the Laravel readers. DONE.**
`ModNotifService.php:219`, `PushNotificationService.php:497`, `ChaseUpService.php:282` all read
`messages_groups.heldby` for the group already in each query (landed by `a6b52512f`).
`MicrovolunteeringNotifyService.php:80`, the straggler the plan missed, and the missing
`ChaseUpService` regression test, are fixed by this PR.

**Phase 2 — frontend reads per-group only. DONE.**
`ModMessage.vue`, `ModMessageButton.vue`, `ModMessageButtons.vue` all resolve holds from `groups[]`
against the context group (landed by `a6b52512f`/#1199). `heldConflict.js` and `useHeldNotice` were
never in scope; see the correction above.

**Phase 3 — drop `heldby` from the API payload (option A). BLOCKED, not started.**
Executed by `a6b52512f`, reverted by `6a0290646` the same day as a bundled-app compat shim (Discourse
9481/636), which this PR's `effectiveHeldby()` change (Discourse 9481/642) refines rather than
removes. See "The decision that makes this final" above for the exit criterion. Do not attempt option
A again without first resolving the installed-app floor problem; a repeat of `a6b52512f`'s plain
removal will reproduce 9481/636.

**Phase 4 — stop dual-writing. DONE.**
No writers of `messages.heldby` remain anywhere in `iznik-server-go` or `iznik-batch`, confirmed by
grep. The five `UPDATE messages SET heldby` statements the plan named are gone; hold and release touch
`messages_groups` only (six statements total, see above).

**Phase 5 — drop the column. Still open. Two preconditions:**
- (a) `iznik-server-go/message/message.go:481`'s raw single-message SELECT (`SELECT messages.id, ...,
  heldby, ... FROM messages LEFT JOIN users LEFT JOIN messages_likes`) still selects the dead
  `messages.heldby` column. It's functionally inert today (the scanned value is unconditionally
  overwritten at line 651, before `effectiveHeldby()` recomputes it at line 661), but dropping the
  column before this SELECT is edited breaks `GET /api/message/:id` with an unknown-column SQL error.
  **Not done in this PR:** open PR #1230 (`feat/orm-migration-v2`) rewrites this exact query, and
  every other `heldby`-touching raw SQL in `message.go` and `groupWork.go`, into GORM builder chains
  as part of a ~1592-site migration. Editing the same lines here would create a guaranteed conflict
  for a one-line housekeeping change against a large in-flight migration; do it after #1230 lands,
  against whatever the query looks like post-migration.
- (b) The stale-fixture trap: dropping a column against stale test fixtures fails with MySQL error
  1146. Any Phase 5 PR must refresh fixtures in the same PR, see the drop-table/stale-fixture finding.

## Verification

- Reproduce first: post on two groups moderated by the same mod, hold on one, confirm on the other
  that buttons show, the badge counts, push fires and chase-ups run. All fixed as of Phase 1.
- Cross-team case stays fixed: a mod of an unrelated group must still see no hold.
- Multi-group-own-groups-disagree case (Discourse 9481/642, this PR): a mod covering two of her own
  groups, held on one and not the other, must not see the unheld one misreport as held via the compat
  field. Web frontend is unaffected either way since it never reads the compat field.
- Re-check Derek 9481/635 ("2 posts held by another Mod, only 1 blue in Pending") separately from
  9481/642: 635 is the ModTools badge count (`groupWork.go`, already per-group, unaffected by this
  PR); 642 is the compat-field ambiguity this PR fixes. Different bugs, don't conflate.
- `grep -rn "messages\.heldby\|m\.heldby" iznik-server-go iznik-batch iznik-nuxt3` currently returns:
  the migration column definition, `message.go:481`'s dead SELECT, and `heldby.go`/`heldby_test.go`'s
  compat shim and its test. That is the expected, complete list until Phase 5. It should return
  nothing outside migrations after Phase 5.

## Note for the monitor FSM

The FSM re-derived this fix from a fresh report with no knowledge of PR #1072 or the earlier branches,
and opened #1199 as a fifth attempt. Before opening a fix PR it should search closed PRs and branch
history for the same feature area and surface prior attempts (especially human-closed ones) rather
than starting from scratch.

PR #1238 (this PR) is fresh evidence of the same lesson: it is the sixth attempt in this feature area,
opened on a field (message-level `heldby`) the plan already recommended removing, and it landed
correctly only because it stayed narrowly scoped to the compat shim rather than re-litigating Phase 3.
