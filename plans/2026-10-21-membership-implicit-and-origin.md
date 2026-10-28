# Membership `implicit` flag and `added_origin` enum

Replace `memberships.rippled` with two columns, and move TrashNothing "didn't opt in" from posts to
memberships:

- **`implicit`** (bool, `NOT NULL DEFAULT 0`): 1 = the member has not knowingly joined this
  community. Changes over time: it clears to 0 when they join, move into the area, or engage.
- **`added_origin`** (enum): how the membership row was created. Never changes after insert.

Today `rippled` does both jobs: it says how the row was created, and it is cleared when the
membership becomes ordinary. TN posters who didn't opt in have no membership at all; the
restriction on them is worked out from `messages_groups.mod_messaging_allowed` on their posts.

The steps are in the order they need to happen. Each phase can ship as its own PR.

---

## Phase 0 - Decisions to settle before writing code

- [ ] **The flag's name.** The notes say both `implicit` and "rename `rippled` to mod messaging
  allowed". Use `implicit`: most of the code that reads this flag is not about messaging
  (removal on retraction, the leave guard, stories, attribution), and a contact-specific
  name invites someone to flip it for a contact reason. Add a new column rather than renaming
  `rippled` in place. Go, batch and the frontend deploy separately, so a rename would break
  whichever deploys second.
- [ ] **Enum values.** The proposal below maps each place a membership is inserted to a value.
- [x] **A TN implicit membership is treated exactly like a ripple one.** Decided 2026-10-22.
  Whatever a ripple membership gets, a TN implicit membership gets too, and the code decides it
  from `implicit`, never from `added_origin`. The one exception is automatic removal (below):
  - **Mail settings** come from the same rule as a ripple join
    ([ExpandService.php:2340-2351](../iznik-batch/app/Services/Ripple/ExpandService.php#L2340)).
    The poster's own setting is copied, preferring a membership they chose
    (`ORDER BY implicit ASC, added DESC`). Immediate (-1) becomes daily (24). Events and
    volunteering are copied as they are. A poster with no membership anywhere gets 0 / 0 / 0.
    Move that rule out of ExpandService into one shared helper that both the ripple join and TN
    ingestion call, so the two cannot drift apart.
  - **ModTools member lists:** shown, with contact restricted, as ripple members are now.
  - **Member counts:** counted. `updateMemberCounts`
    ([GroupMaintenanceService.php:29](../iznik-batch/app/Services/GroupMaintenanceService.php#L29))
    counts every row today, ripple members included.
- [x] **Contact is decided per group, not per person.** Decided 2026-10-22. Today
  `UsersUnaddressedOnly` lets moderators of every group contact a TN poster if any one of
  their posts was addressed (the "mixed poster" rule). That rule was a bug: whether a group's
  moderators may contact someone depends on that person's membership of that group alone. A
  TN poster who consented to group A and was cross-posted to group B can be contacted by A's
  moderators and not by B's. The mixed-poster rule goes, and nothing replaces it (Phase 5).
- [x] **A post's status follows the poster's current relationship with the group, not their
  consent when they posted.** Decided 2026-10-22. A TN post's "nobody owns this" status
  (reports skip moderators, moderators can't edit it) is read from the poster's membership.
  When the poster later consents, joins or engages, their old posts on that group become
  moderator-owned.
  - **Consenting once, through TN, makes the membership non-implicit** (`implicit = 0`, with the
    "became ordinary" log). That happens either when a TN post arrives with the group in
    `freegle_group_ids` (Phase 3 ingestion), or when TN adds them to the group: the partner join
    already calls `ClearRippledMembership` on an existing row
    ([membership.go:1479](../iznik-server-go/membership/membership.go#L1479)), and Phase 3 makes
    that clear `implicit` too.
  - **Consent only goes one way.** A later TN post without the group in `freegle_group_ids`
    never makes the membership implicit again. Only leaving and being cross-posted back creates
    a new implicit row.
- [x] **A TN implicit membership is never removed automatically.** Decided 2026-10-22.
  - **While any of the poster's posts on the group is live**, nothing automatic removes the
    membership. If it went, the post would read as addressed, and moderators would gain reports
    and editing on it.
  - **Unlike a ripple membership, it is not deleted when their last post on the group is
    withdrawn, retracted or expires.** Ripple memberships are deleted then
    ([ExpandService.php:1052](../iznik-batch/app/Services/Ripple/ExpandService.php#L1052),
    [message.go:7162](../iznik-server-go/message/message.go#L7162)); TN implicit memberships
    stay.
  - **What can remove one:** the member leaving or TN removing them, a moderator removing or
    banning them, and an account merge. Nothing else.
  - **This is the one exception to "decided by `implicit`, not `added_origin`"** (above).
    Automatic removal is a rippling behaviour, so it keys on `added_origin = 'rippled'`. A
    removal that keyed on `implicit = 1` would start deleting TN memberships.
- [x] **Historical TN backfill scope** (Phase 4b). Decided 2026-10-22: backfill only the
  `messages_groups` rows with `mod_messaging_allowed = 0`, about 100 in all, whether or not the
  post is still live. Rows with `mod_messaging_allowed = 1` get nothing: no membership is
  created for them, and existing memberships keep the column defaults (`implicit = 0`, plus
  whatever `added_origin` Phase 4a gives them).

### Proposed `added_origin` values

Counts are from Q1 of
[2026-10-22-membership-origin-prod-queries.sql](2026-10-22-membership-origin-prod-queries.sql),
run on 2026-10-22. They count every `Group/Joined` log row ever written, not current memberships;
Q2 gives those. "Actor" is how `byuser` relates to the member: `none` = NULL (the system),
`self` = the member, `other` = someone else, normally a moderator.

| Value | Set by | Log text (actor), count | Backfill (Phase 4a) |
|---|---|---|---|
| `manual` | Clicked Join: [membership.go:1553](../iznik-server-go/membership/membership.go#L1553) with `manual=true` | `Manual` (self), 181,279, since 2023-07 | exact |
| `posted` | Joined in order to post: [message_submit.go:131](../iznik-server-go/message/message_submit.go#L131), `JoinAndPostAs` ([message.go:4078](../iznik-server-go/message/message.go#L4078)) | NULL (self), 863,183, mixed in with `signup` and joins that gave no reason | from Q3: a post of theirs on the group within 30 min of the join → `posted`, else `unknown` |
| `replied` | Joined in order to reply: [chatmessage.go:1097](../iznik-server-go/chat/chatmessage.go#L1097) through `AddMembership` ([user.go:1775](../iznik-server-go/user/user.go#L1775)); LoveJunk replies at [chatmessage.go:1194](../iznik-server-go/chat/chatmessage.go#L1194); the frontend's join-to-reply through the join endpoint with `manual=false` | `Joined to reply to a post` (self), 7,379, since 2026-07; `LoveJunk user joining to reply` (self), 2,917, since 2024-04; `Auto` (self), 63,065, since 2023-07 | first two exact; `Auto` from Q3, as for `posted`, else `replied` |
| `subscribed` | Email subscribe: [IncomingMailService.php:1275](../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php#L1275) | `Subscribed` (self), 72, since 2026-09 | exact |
| `rippled` | [ExpandService.php:2396](../iznik-batch/app/Services/Ripple/ExpandService.php#L2396) | `Rippled` (none), 559,315, since 2026-06-23 | exact |
| `moderator` | A moderator or support adds a member: the join endpoint with `byuser` ≠ `user`; the group creator ([group.go:1020](../iznik-server-go/group/group.go#L1020)); `AddMembershipCommand` | NULL (other), 769,854, of which only about 18,400 are a real account (Q10); `Manual`/`Auto` (other), 13; the one `Reinstated by Geeks: ...` row | any text with `byuser` a real account other than the member → `moderator` (support included); `byuser = 0` → `unknown` (see that row); the Geeks row by hand |
| `partner` | TN membership-add flow, where the member opted in: [membership.go:1489](../iznik-server-go/membership/membership.go#L1489); also TN post ingestion when the post's origin group is in the poster's `freegle_group_ids` (new, Phase 3) | `via partner` (self), 102,119, since 2026-04-28 | exact |
| `tn_unaddressed` | TN post ingestion when the post's origin group isn't in the poster's `freegle_group_ids` (new, Phase 3) | none yet; Phase 3 adds one | Phase 4b, not 4a |
| `signup` | Account created with a group: [user.go:2312](../iznik-server-go/user/user.go#L2312). No caller in this repo sends a group today. From Phase 3, `PutUser` takes an `origin` parameter that sets the column | NULL (self), indistinguishable from `posted` | not recoverable: falls into `unknown`. Correct from Phase 3 on |
| `unknown` | Fallback and column default. **Live code that sets `unknown` reports a Sentry error** (Phase 3); only the backfill may set it without one | NULL (none), 1,739,605, from 2016-02 to 2026-05-25: system joins by the retired V1 code, with no reason recorded. Also NULL with `byuser = 0`, 751,442, from 2016-02 to 2018-08: a system placeholder, not an account (Q10) | everything not mapped above. Treat `byuser = 0` as no actor, the same as NULL |

`Ripple-created membership became ordinary: <reason>` (self), 51,139, since 2026-09-04, is a
change of state and not a creation: the backfill ignores it when picking a membership's latest
join, and it sets no value.

**What Q1 changed:**
- **The two reply joins I hadn't found** (`Joined to reply to a post`, `LoveJunk user joining to
  reply`) both go through `user.AddMembership`, so its only callers are now classified.
  LoveJunk replies map to `replied`. The user record shows which partner they came from; add a
  separate value only if something needs to tell partners apart by membership.
- **Before 2023-07-26 no join recorded a reason**, so older rows are NULL text throughout. About
  half of all join logs can only be `unknown`, or `moderator` by actor. That matters little: they
  are all ordinary memberships (`implicit = 0`), and the enum's real job, telling `rippled` and
  `tn_unaddressed` apart from everything else, is exact from 2026-06-23 on.
- **NULL text is still being written today** by the post joins, website signup and
  moderator adds. Phase 3 must give each of those a reason, or `added_origin` stays
  ambiguous for new rows too.
- Still to classify: the TN email-replay stub at
  [EmailReplaySyncer.php:392](../iznik-batch/app/Services/TrashNothing/Sync/EmailReplaySyncer.php#L392).
  An account merge moves an existing row, so it keeps that row's value and has no value of its
  own.

From Phase 3 on, the join endpoint takes an explicit origin, so `Auto` and NULL text stop being
written and new rows are exact.

### Rippled vs TN implicit: where they differ

Both have `implicit = 1`. `added_origin` decides the cases where they differ:

| Behaviour | `rippled` | `tn_unaddressed` |
|---|---|---|
| Moderators can't start a chat, send standard messages or write about the post; member actions are silent; ModTools buttons hidden | yes | yes |
| Removed automatically when the post is withdrawn or retracted | yes, once no live post of theirs remains on the group | **no, never**: kept even after every post is gone (Phase 0) |
| Removed on a ban | yes | yes |
| Can leave without the moderator-role guard | yes | yes (TN accounts are never moderators anyway) |
| Blank posting status not shown as Moderated | yes | yes |
| Email settings copied from the poster's other memberships (one shared helper, keyed on `implicit`) | yes | yes |
| Shown in ModTools member lists (contact restricted) and counted in `membercount` | yes | yes |
| Excluded from birthday appeals, re-engagement sign-off, story review queue | yes | yes |
| Welcome email suppressed | yes (RippleIntroMail instead) | yes, and no intro mail either |
| Excluded from the abuse "joined many groups" spread check | yes | yes: TN places posts by location, not by choice |
| Rejoin suppression after leaving | yes | no: rippling doesn't create these |
| Reply attribution: "home member" | no | no, same as rippled |
| Reports skip moderators and two votes remove the post; moderators can't edit it | no: moderators of the post's own group can act | yes |
| Cleared by the member posting on the group | yes | only by consenting through TN: a later post with the group in `freegle_group_ids`, or TN adding them to the group. Never made implicit again afterwards |
| Cleared by the member replying or writing back | yes | **no** (Phase 5) |

---

## Phase 1 - Inventory (no code changes)

- [ ] List every reader of `memberships.rippled`, `memberships_history.rippled`, the `Rippled`
  log text and `messages_groups.mod_messaging_allowed`, using the greps below. Tick each one off
  in Phase 3 or Phase 5 as it is converted.
  - `git grep -n -E "\.rippled\b|'rippled'|rippled\s*=|\bRippled\b" -- '*.go' '*.php' '*.vue' '*.js'`
  - `git grep -n -E "mod_messaging_allowed|ModMessagingAllowed|PostIsUnaddressed|UnaddressedOnly"`
  - `git grep -n "'Rippled'"` (log text readers: ExpandService rejoin guard, MembershipsProcessingService, ModLog.vue, useSystemLogFormatter.js)
- [ ] Find every insert into `memberships` (the table above) and every insert into
  `memberships_history`.
- [ ] Find how TN removes a membership (unsubscribe or removal through the partner API) and
  confirm it goes through a path that deletes the row and logs `Group/Left`.
- [ ] Find where group member counts and member lists are computed, for Phase 6.
- [ ] On production (Edward), count rows for each `Group/Joined` log text, to size Phase 4a.
  Phase 4b is already sized: about 100 rows with `mod_messaging_allowed = 0`.

---

## Phase 2 - Schema (additive only)

Laravel migration plus an idempotent `_migration.sql` for production, following
[2026_06_23_000010_add_rippled_to_memberships_migration.sql](../iznik-batch/database/migrations/2026_06_23_000010_add_rippled_to_memberships_migration.sql):
an `information_schema` check first, and columns added so that MySQL 8 can do it as an `INSTANT`
change.

- [ ] `memberships.implicit TINYINT(1) NOT NULL DEFAULT 0`
- [ ] `memberships.added_origin ENUM(...) NOT NULL DEFAULT 'unknown'`
- [ ] `memberships_history.added_origin ENUM(...) NOT NULL DEFAULT 'unknown'`. History records
  creation, so it gets the origin and no `implicit`. Keep `memberships_history.rippled` until
  Phase 7.
- [ ] Copy existing values: `UPDATE memberships SET implicit = rippled WHERE rippled = 1`, in
  chunks. `added_origin` stays `unknown` until Phase 4a.
- [x] **No index on `implicit`.** Decided from Q7 (production, 2026-10-27): 180,982 of
  5,158,594 memberships (3.5%) have `rippled = 1`, held by 24,530 users across 481 groups.
  - A flag that is 1 on 3.5% of rows is a poor index on its own. Every live read is already
    narrowed by `(userid, groupid)`, `userid` or `groupid`.
  - `rippled` has never had an index, and the same reads have run on it in production since
    2026-06-23. `implicit` covers the same rows plus the TN implicit memberships (about 100
    more to start with), so it needs none either.
  - The only reads of the whole table on the flag are one-off: the Phase 2 copy and the Phase 4
    backfills. They run in chunks by `id`.
- [ ] Update the Laravel `Membership` model (casts, constants for the enum values) and the Go
  structs ([membership.go:488](../iznik-server-go/membership/membership.go#L488),
  [user.go:158](../iznik-server-go/user/user.go#L158)).

---

## Phase 3 - Write both columns, and record the origin on every insert

From here on `rippled` and `implicit` are written together, so either can be read.

- [ ] ExpandService ripple join: `implicit = 1`, `added_origin = 'rippled'`; history row
  `added_origin = 'rippled'`.
- [ ] Every clear path sets `implicit = 0` alongside `rippled = 0`:
  `rippling.ClearRippledMembership` ([rippling/membership.go:30](../iznik-server-go/rippling/membership.go#L30))
  and `RippleEngagementService::clear`.
- [ ] Join endpoint ([membership.go:1553](../iznik-server-go/membership/membership.go#L1553)):
  accept an explicit origin (`manual` / `posted` / `replied` / `moderator`) and set
  `added_origin`. The frontend sends it from `stores/auth.js joinGroup` and
  `useReplyStateMachine`. Old clients send nothing; fall back to today's `manual` flag
  (`true` → `manual`, `false` → `replied`).
- [ ] **`PutUser` takes an `origin` parameter** ([user.go:2182](../iznik-server-go/user/user.go#L2182)).
  Add `Origin string \`json:"origin"\`` to `UserPutRequest`
  ([user.go:2142](../iznik-server-go/user/user.go#L2142)). When the request also has a `groupid`,
  the membership it creates ([user.go:2312](../iznik-server-go/user/user.go#L2312)) sets
  `added_origin` from it, along with the matching `memberships_history` row.
  - Use the same name and the same check as the join endpoint's origin parameter below. An
    unrecognised value is refused with a 400 that lists the accepted values. Values that only
    the system may set (`rippled`, `tn_unaddressed`, `partner`, `unknown`) are refused too.
  - A `groupid` with no `origin` still creates the membership with `unknown`, which reports to
    Sentry (see the `unknown` rule below), so a caller that forgets is visible.
  - Tests: extend the three tests that send `groupid` (`TestPutUserWithGroup`,
    `TestPutUserWithGroupWritesMembershipsHistory`,
    `TestReachQueue_RegisteringWithAGroupQueuesTheNewMember`) to send an `origin` and check the
    column. Add cases for a missing `origin` and a refused one.
- [ ] Every other insert site in the Phase 0 table sets its value.
- [ ] **Any live code that sets `added_origin = 'unknown'` reports a Sentry error.** After this
  phase, every way of creating a membership should know its origin, so `unknown` on a new row
  means a bug: a new insert path, or a caller that didn't say why. Report it, and still create
  the membership; a join must never fail because its origin is missing.
  - **Where to report:** one helper per codebase that every insert goes through, so the check
    can't be skipped. In Go, `sentry.CaptureMessage`, as in
    [labelverdicts.go:79](../iznik-server-go/rippling/labelverdicts.go#L79). In the batch,
    `app('sentry')->captureMessage(..., \Sentry\Severity::error())`, as in
    [LockdownTickCommand.php:150](../iznik-batch/app/Console/Commands/Lockdown/LockdownTickCommand.php#L150).
    Include the insert site, `userid` and `groupid`, so the event says which path forgot.
  - **An insert that leaves the column out gets the default `unknown` with no report**, because
    the helper never sees it. So every insert must go through the helper; Phase 1's list of
    insert sites is the checklist.
  - **The backfill (Phase 4a) is exempt.** It sets `unknown` on purpose for about 1.9 million
    old rows, and reporting each one would flood Sentry. It prints its `unknown` count in its
    summary instead.
  - Tests: an insert with no origin creates the membership and reports once.
- [ ] **TN post ingestion via the API makes the poster a member of the post's origin group.**
  The origin group is the one ingestion works out from the post's own location
  (`Location::groupsNear()`), the group the post's `rippled_in = 0` row goes on. Today ingestion
  deliberately creates no membership
  ([GroupPostIngestionService.php:265](../iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php#L265)).
  From now on it must, whether or not the poster consented, so every TN post has a membership
  behind it. In the same transaction as the post:
  - **No membership, and the group isn't in the poster's `freegle_group_ids`:** insert a
    membership with `added_origin = 'tn_unaddressed'` and `implicit = 1`, mail settings from
    the shared helper (Phase 0, same as a ripple join), plus a `memberships_history` row (same origin) and a `Group/Joined` log
    (text such as `TN cross-post`) so the backfill and ModLog can read it.
  - **No membership, and the group is in `freegle_group_ids`:** insert with
    `added_origin = 'partner'` and `implicit = 0`, plus the history row and log.
  - **A membership already exists:** leave `added_origin` alone, since it records how that row
    was created. Never make an ordinary membership implicit. Clear an implicit one
    (`implicit = 0`, with the "became ordinary" log) when this post comes with consent for the
    group.
  - **If this ships before the `implicit` column exists** (before Phase 2 reaches production),
    still create the membership with `added_origin` set. Phase 4b then sets `implicit = 1` from
    `mod_messaging_allowed`, so it becomes implicit later. In that gap, every reader still on
    `rippled` treats it as an ordinary membership. These must be guarded before the change goes
    live, or the change must wait for Phase 5:
    - the per-group welcome email ([MembershipsProcessingService.php:66](../iznik-batch/app/Services/MembershipsProcessingService.php#L66)),
      which would mail TN users;
    - the spread check;
    - birthday appeals and the story queue;
    - the mail settings, which must come from the shared helper and never from a default.

    Member lists and counts need no guard: they include TN implicit members on purpose (Phase 0).

    Moderator contact stays blocked in the meantime, because `PostIsUnaddressed` and
    `UserIsUnaddressedOnly` still read `messages_groups`.
  - **A post with no `freegle_group_ids` field at all is a configuration fault, not a
    poster's choice.** Q5 suggests a developer key without the field enabled flagged every TN
    post as unaddressed for 27 hours. With this phase that would make every TN poster implicit
    on their group. Tell an absent field apart from an empty list: keep reading it as "no
    consent", but report it to Sentry once per sync run, so a key change is caught in minutes,
    not weeks.
  - Tests: an unaddressed post, an addressed post, an existing ordinary membership, an existing
    implicit membership with a consenting post, and a dry run (`GroupPostIngestionService`'s
    `dryRun` writes nothing).
- [ ] Keep writing `messages_groups.mod_messaging_allowed` as now until Phase 7. It is Phase 4b's
  source, and the post-level checks read it until Phase 5.

---

## Phase 4 - Backfills (commands Edward runs)

Write these as artisan commands like the existing `ripple:*` ones: dry run by default, chunked,
idempotent, safe to re-run, with counts printed at the end.

### 4a. `added_origin` from logs - `memberships:backfill-origin`

- [ ] For each membership with `added_origin = 'unknown'`, take the **most recent** `Group/Joined`
  log for `(userid, groupid)`, ignoring `Ripple-created membership became ordinary:` rows, and
  map its text to a value as in the Phase 0 table. The latest join is the current row's
  creation, because a membership row is deleted when the member leaves. Do **not** use "any
  `Rippled` log exists": that misreads a member who was rippled in, left, and later joined by hand.
- [ ] The `logs` table is the main source. `Group/Joined` rows are never purged, and they cover
  ripple joins from before `memberships_history.rippled` existed. Cross-check against
  `memberships_history.rippled = 1` and report the disagreements.
  - **Q4 (production, 2026-10-26):** 294 `Rippled` join logs have no matching history row with
    `rippled = 1`. All 294 fall within 17 seconds, 2026-06-23 16:15:20 to 16:15:37: the first
    ripple run after `d24d91564a` went live, which joined posters from posts that had rippled
    before it, before `d96b7558f4` added the history flag. Outside that run the two sources
    agree, so the history table is a reliable fallback for every later ripple join, and the
    logs are needed only for those 294.
  - **The other direction:** 4 history rows have `rippled = 1` and no `Rippled` join log. That
    doesn't affect the backfill, because a membership flagged `rippled = 1` is mapped to
    `rippled` by the flag, whatever the logs say.
  - **Q11 (production, 2026-10-27):** all 4 were written on 2026-06-26 between 06:40 and 08:16,
    and none has any `Group/Joined` log. That is the lock-wait storm described in `7be2ef890c`
    (deployed 08:41 the same day): concurrent `ripple:expand` runs held locks on `logs`, and
    `INSERT INTO logs` failed with 1205 after the membership and history rows had been written.
    Two of the four users were never merged, so a merge doesn't explain them. Three memberships
    no longer exist. The fourth (56596456) still has `rippled = 1` and maps to `rippled` by the
    flag. Nothing to handle by hand.
  - **Lesson for Phase 3:** the membership, history and log writes aren't atomic. Write
    `added_origin` on the membership row itself, so the backfill is the last time the origin has
    to be read back from the logs.
- [ ] Known false reads to handle or report: account merges (logs move to the surviving account,
  but if both accounts were members of the group the survivor keeps its own row), and
  memberships with no join log at all (→ `unknown`).
- [ ] **What Q2 showed** (production, 2026-10-22, 5,147,813 memberships):
  - **36% have no join log at all** (1,869,620) and get `unknown`. Logs only go back to 2016-02.
  - **Latest join `Rippled`: 230,296.** 179,477 still have `rippled = 1`. The other 50,819 have
    been cleared, which matches the 51,139 "became ordinary" logs, less memberships deleted
    since. All of them get `added_origin = 'rippled'`; `implicit` copies the current flag.
  - **Every other kind of latest join has `rippled = 0`**, with two exceptions that Q8 and Q9
    are checking:
    - 105 memberships with `rippled = 1` and no join log (101 by Q8, 2026-10-27). Most belong
      to accounts created *after* the membership was added, with no `Merged` log: the
      signature of the old email-verify merge (`session/merge.go`, until October 2026). That
      merge moved memberships to the surviving account but left logs and `memberships_history`
      on the deleted one ([modtools.md](../.claude/rules/modtools.md)). Q13 confirms it by
      finding each ripple join log under another account. The flag rule handles them. The same
      merge also strands non-ripple memberships without logs; those fall into `unknown`.
    - 1 membership with `rippled = 1` whose latest join was the member's own (NULL text).
      **Q9 (2026-10-27): not the `ripple:backfill-memberships` misread.** The member (2274888)
      joined and left group 21324 twice in 2018, and that 2018 join is the latest log on
      their account. The current membership was added on 2026-08-22 at 12:20:02, the same
      second as their log-less ripple membership of group 21541 in Q8. It is the same
      email-verify merge pattern as above, so the flag is correct. Q13 checks it with the
      101.
  - **Rule:** `rippled = 1` now means `added_origin = 'rippled'` whatever the logs say, because
    only the ripple paths ever set the flag. Q8 and Q9 found no exception.
  - **NULL text made by someone other than the member** (629,704 current memberships). Decided
    from Q10 (production, 2026-10-27):
    - **`byuser = 0`: 751,442 of the 769,854 such join logs (97.6%),** from 2016-02 to
      2018-08. User 0 has no user row; it's the legacy code's placeholder for "the system", so
      these become `unknown`, the same as a NULL `byuser`.
    - **The other ~18,400 were made by real accounts:** moderators, support, and some accounts
      whose current `systemrole` is User. `systemrole` is the role now, not when the join was
      made, so those were most likely moderators at the time. They become `moderator`, which
      covers support adding a member too.
    - Most of the 629,704 current memberships will therefore be `unknown`. Q2 can't split them
      by `byuser`, but the logs suggest the large majority.
- [ ] **What Q3 showed** (production, 2026-10-26). The test is whether a post of theirs on the
  group arrived within 30 minutes of the join:

  | Latest join | Memberships | Post near the join | Backfill gives |
  |---|---|---|---|
  | `Auto` (self), since 2023-07-26 | 47,177 | 370 (0.8%) | `replied`; the 370 `posted` |
  | NULL (self), since 2023-07-26 | 69,137 | 51,090 (74%) | `posted` for the 51,090; `unknown` for the other 18,047 |
  | NULL (self), before 2023-07-26 | 529,615 | 118,719 (22%) | `posted` for the 118,719; `unknown` for the other 410,896 |

  - **`Auto` is almost entirely joins to reply.** The current frontend sends `manual=false` only
    from the reply flow ([useReplyStateMachine.js:1140](../iznik-nuxt3/composables/useReplyStateMachine.js#L1140)),
    and it sends `manual=true` from Join buttons ([GroupHeader.vue:500](../iznik-nuxt3/components/GroupHeader.vue#L500),
    [explore/join](../iznik-nuxt3/pages/explore/join/[[id]].vue)).
  - **Since 2023, NULL text from the member is almost all joins to post.** Every self-join from
    the frontend now sends `manual`, so NULL text from the member themselves comes from the post
    joins ([message_submit.go:131](../iznik-server-go/message/message_submit.go#L131), `JoinAndPostAs`).
    The 26% with no post found are probably posts deleted or purged since. They still get
    `unknown`, because the backfill only claims what it can show.
  - **Before 2023, no join recorded a reason**, so only the 22% matched to a post can be
    classified.
  - One rule covers all three rows: a post within the window means `posted`. Otherwise `Auto`
    becomes `replied`, and NULL text becomes `unknown`.
- [ ] Fill `memberships_history.added_origin` too, where the row can be matched.
- [ ] Rewrite or retire `ripple:backfill-memberships`
  ([RippleMembershipBackfillService.php](../iznik-batch/app/Services/Ripple/RippleMembershipBackfillService.php)).
  It uses "any `Rippled` log", which is the misread above.

### 4b. TN memberships from posts - `memberships:backfill-tn`

- [ ] Scope (Phase 0): only the post rows (`rippled_in = 0`) with `mod_messaging_allowed = 0`,
  about 100, live or not. Rows with `mod_messaging_allowed = 1` are not touched. For each
  distinct `(fromuser, groupid)` in that set:
  - **The poster has another post on that group with `mod_messaging_allowed = 1`:** skip. They
    consented to the group once, so the membership is not implicit (Phase 0).
  - **No membership:** create one with `implicit = 1`, `added_origin = 'tn_unaddressed'`, mail
    settings from the shared helper, a `memberships_history` row (same origin) and a
    `Group/Joined` log, exactly as Phase 3 ingestion does. Set `added` to the post's arrival
    time, not now.
  - **An existing membership whose `added_origin` is `tn_unaddressed`** (created by Phase 3
    ingestion before the `implicit` column existed): set `implicit = 1`.
  - **Any other existing membership:** leave it. Never make an ordinary membership implicit.
- [ ] At about 100 rows this can run in one pass, but keep it a dry run by default with a
  per-row listing, so Edward can read every change before running it with `--send`.
- [ ] **Hold: Q5 suggests these rows may not be real non-consent** (production, 2026-10-26).
  - **106 rows, 78 (poster, group) pairs, all arriving in 27 hours:** 2026-09-25 16:55 to
    2026-09-26 19:38. There are none before or after.
  - **The window starts 45 minutes after `291e589e0d`,** which moved the posts sync to a new
    TrashNothing developer key. TN has to turn on `freegle_group_ids` for each key, and
    ingestion reads a missing field as "no consent". So every post in the window may have been
    flagged regardless of what the poster chose.
  - **What "addressed" means for older TN posts:** most `mod_messaging_allowed = 1` TN posts
    came in by email, before API ingestion. The email path sets `tnpostid`
    ([IncomingMailService.php:3066](../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php#L3066))
    and leaves the column at its default of 1. That is not an explicit consent, but the email
    path refuses a TN post to a group the poster isn't a member of
    ([IncomingMailService.php:2674](../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php#L2674)),
    so it still means the poster had joined that group. The skip rule below, and Q6, rely on
    that.
  - **Q6:** 62 of the 78 posters are "mixed": they have an addressed TN post somewhere. The
    second half of Q6 (pairs that would lose moderator contact) hasn't been reported yet.
  - **The rows look like real members:** 50 of the 78 pairs have another post on the same group
    that *was* addressed. 58 already have an ordinary membership of that group, most of them
    years old (2015 to 2025).
  - **What the backfill would do:** create implicit memberships for 15 pairs (22 rows), skip 50
    pairs that consented, and leave 13 existing memberships alone. Among those 13: one rippled
    membership (44492943 / 21593) and one Rejected post.
  - **Before running 4b:** run Q12 (unaddressed vs addressed TN posts by day and by hour), and
    ask Andrew when `freegle_group_ids` was enabled on the new key. If the window is the key,
    the 15 pairs are not evidence of anything: don't run 4b, and fix those 106 rows to
    `mod_messaging_allowed = 1`. Today they are treated as ownerless: moderators can't edit
    them or reply about them, and two member reports remove them.
- [ ] Before any write, count the effect of deciding contact per group: how many TN posters
  are currently contactable everywhere through the mixed-poster rule, and how many
  `(poster, group)` pairs will become uncontactable once it is gone. This is to report the
  effect, not to reopen the decision.

---

## Phase 5 - Move readers to the new columns

Go API first, then the batch jobs, then the frontend (see Deploy order).

### `implicit` readers (replacing `rippled`)

- [ ] Moderator chat: [chatroom.go:446](../iznik-server-go/chat/chatroom.go#L446) via
  `IsRippleOnlyMembership` → an `implicit` check. Also replace `UserIsUnaddressedOnly` at
  [chatroom.go:409](../iznik-server-go/chat/chatroom.go#L409).
- [ ] Member actions and standard messages: [membership.go:178](../iznik-server-go/membership/membership.go#L178),
  [membership.go:222](../iznik-server-go/membership/membership.go#L222),
  `notifyposter` in [ProcessBackgroundTasksCommand.php:892](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L892),
  `userIsUnaddressedOnly` at [ProcessBackgroundTasksCommand.php:616](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L616).
- [ ] Writing to the poster about a post: `PosterMessagingGroups` ([message.go:2920](../iznik-server-go/message/message.go#L2920)).
- [ ] Member list contact flag: [membership.go:837-842](../iznik-server-go/membership/membership.go#L837) (now from the membership row).
- [ ] Remove the mixed-poster rule everywhere it is enforced or described: the "A mixed poster
  ... is deliberately NOT restricted" comments in [modmessaging.go](../iznik-server-go/modmessaging/modmessaging.go)
  and [ProcessBackgroundTasksCommand.php:613](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L613),
  the "Someone who has ALSO posted normally is a real member" comment at
  [chatroom.go:407](../iznik-server-go/chat/chatroom.go#L407), and any Go, Laravel or Vitest test
  that asserts a mixed poster can be contacted. Changing those tests is the decision being
  carried out; say so in the PR, and give the reason (Phase 0: per group, not per person).
- [ ] Automatic removal: [ExpandService.php:1052](../iznik-batch/app/Services/Ripple/ExpandService.php#L1052),
  [ExpandService.php:1082-1134](../iznik-batch/app/Services/Ripple/ExpandService.php#L1082),
  [message.go:7162](../iznik-server-go/message/message.go#L7162). These become `implicit = 1 AND added_origin = 'rippled'`.
  They must **not** become `implicit = 1` alone, which would delete TN implicit memberships
  (Phase 0: those are never removed automatically). Add a test for each removal path: a TN
  implicit membership survives its last post being withdrawn.
- [ ] Leave guard: `selfLeaveKeepsRole` ([membership.go:1612](../iznik-server-go/membership/membership.go#L1612)).
- [ ] Moderated status display: [user.go:1586](../iznik-server-go/user/user.go#L1586).
- [ ] Email settings source: [ExpandService.php:2328](../iznik-batch/app/Services/Ripple/ExpandService.php#L2328) (`ORDER BY implicit ASC`).
- [ ] Birthday appeals [BirthdayService.php:54](../iznik-batch/app/Services/BirthdayService.php#L54), re-engagement
  [ReengageContentService.php:241](../iznik-batch/app/Services/ReengageContentService.php#L241), stories
  [story.go:149](../iznik-server-go/story/story.go#L149), [story.go:198](../iznik-server-go/story/story.go#L198).
- [ ] Reply attribution: [chatmessage.go:492](../iznik-server-go/chat/chatmessage.go#L492),
  [chatmessage.go:540](../iznik-server-go/chat/chatmessage.go#L540),
  `EstablishedOriginMemberExists` ([attribution.go:37](../iznik-server-go/rippling/attribution.go#L37)),
  [metrics.go:184](../iznik-server-go/rippling/metrics.go#L184), [analytics.go:973](../iznik-server-go/rippling/analytics.go#L973),
  [ReplyAttributionBackfillService.php:75](../iznik-batch/app/Services/Ripple/ReplyAttributionBackfillService.php#L75).
  Decide per query whether `wasRippleJoin` should mean `implicit = 1` or
  `added_origin = 'rippled'`. Today they are the same; once TN memberships exist they differ.
- [ ] RippleEngagementService: the posting pass reads `implicit`, and ignores TN posts
  (`tnpostid IS NOT NULL`) instead of using `mod_messaging_allowed` ([line 152](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L152)).

### `added_origin` readers (replacing creation-time `rippled` and the log text)

- [ ] Welcome email suppression: [MembershipsProcessingService.php:66](../iznik-batch/app/Services/MembershipsProcessingService.php#L66) →
  `added_origin IN ('rippled', 'tn_unaddressed')` on the history row.
- [ ] Spread check exclusions: [MembershipsProcessingService.php:154-158](../iznik-batch/app/Services/MembershipsProcessingService.php#L154) (log text) and
  [user.go:1528](../iznik-server-go/user/user.go#L1528) (history) → exclude both origins.
- [ ] Intro mail welcome list: [ExpandService.php:2493](../iznik-batch/app/Services/Ripple/ExpandService.php#L2493).
- [ ] Rejoin suppression ([ExpandService.php:1991](../iznik-batch/app/Services/Ripple/ExpandService.php#L1991),
  [ExpandService.php:2573](../iznik-batch/app/Services/Ripple/ExpandService.php#L2573)): this reads past `Group/Joined` logs
  after a leave, when the membership row is already gone, so it has to stay on logs. Leave it, and
  keep writing the `Rippled` log text.
- [ ] Attribution backfill: [ReplyAttributionBackfillService.php:75-81](../iznik-batch/app/Services/Ripple/ReplyAttributionBackfillService.php#L75).

### Post-level TN checks (replacing `mod_messaging_allowed`)

`PostIsUnaddressed(msgid)` becomes: the post's own row (`rippled_in = 0`) joined to the poster's
membership on that group has `implicit = 1 AND added_origin = 'tn_unaddressed'`.

- [ ] [modmessaging.go](../iznik-server-go/modmessaging/modmessaging.go) and the batch copy at
  [ProcessBackgroundTasksCommand.php:596](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L596).
- [ ] Callers stay the same: [chatmessage.go:800](../iznik-server-go/chat/chatmessage.go#L800) (report becomes a vote),
  [message.go:3751](../iznik-server-go/message/message.go#L3751) (moderator reply),
  [message.go:4499](../iznik-server-go/message/message.go#L4499) (editing),
  [message.go:6326](../iznik-server-go/message/message.go#L6326) (report endpoint),
  [microvolunteering.go:684](../iznik-server-go/microvolunteering/microvolunteering.go#L684),
  [microvolunteering.go:1149](../iznik-server-go/microvolunteering/microvolunteering.go#L1149).
- [ ] Message card flag: `modMessagingAllowed` ([messageGroup.go:92](../iznik-server-go/message/messageGroup.go#L92)) and
  [message.go:787](../iznik-server-go/message/message.go#L787) (keep the JSON field name `mod_messaging_allowed`
  so [ModMessage.vue:1329](../iznik-nuxt3/modtools/components/ModMessage.vue#L1329) and
  [MessageReportModal.vue:198](../iznik-nuxt3/components/MessageReportModal.vue#L198) keep working).

### TN-specific changes

- [ ] **A TN member who leaves a group can no longer be contacted by its moderators.** Today a
  moderator can open a chat to anyone, member or not
  ([chatroom.go:402](../iznik-server-go/chat/chatroom.go#L402) checks only that the moderator moderates the group).
  Add: a moderator-started chat to a TN account needs an Approved, non-implicit membership.
  Also decide whether an **existing** chat room stays open after the member leaves. Today that
  room is returned before any check runs.
- [ ] **A signature on every moderator message to a TN member.** TN shows the message as coming
  from the Freegle user, and our "{Group} Volunteers" sender name
  ([ChatNotification.php:165-188](../iznik-batch/app/Mail/Chat/ChatNotification.php#L165)) is
  lost. Append a sign-off to the body for TN recipients (`User::isTN()`, plus a `tnuserid`
  check) on: User2Mod chat notifications, standard messages
  ([ProcessBackgroundTasksCommand.php](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php)),
  and moderator replies about a post. No existing signature mechanism was found, so this is new.
- [ ] **Replying or writing back doesn't make a TN member's membership ordinary.** Skip TN users in
  `clearForReplies` and `clearForWriteBacks`
  ([RippleEngagementService.php:209](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L209),
  [:241](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L241)). Those clears exist so
  moderators can deal with the replier. For TN users, moderators report the user through TN's
  report API instead:
  - [ ] **Ping Andrew (TN) a few days before this ships** so the report API is ready on their side.
  - [ ] TN API client method, a Go endpoint or background task, and a ModTools "Report to
    TrashNothing" action, shown where Chat is hidden for TN implicit members.

### Frontend

- [ ] ModTools reads `member.implicit` and `member.added_origin`, falling back to
  `member.rippled` while an old API is live: [ModMember.vue:302](../iznik-nuxt3/modtools/components/ModMember.vue#L302),
  [ModMemberButtons.vue:127](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L127),
  [:149](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L149),
  [:282](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L282).
- [ ] Show the origin in the member view ("Added because their post rippled in", "Added by
  TrashNothing; did not choose this community").

---

## Phase 6 - Side effects of TN memberships

- [ ] Member counts, member lists, digests, ChitChat and events/volunteering mail treat a TN
  implicit member exactly as they treat a ripple member today (Phase 0). Any place that
  excludes or limits ripple members must do it by `implicit`, so TN members are covered without
  a second rule. The "statistics that count local activity" rule in
  [rippling.md](../.claude/rules/rippling.md) is one such place: change it from "memberships
  rippling created" to `implicit = 1`.
- [ ] TN members who never chose the group will now get digests from it whenever the copied
  `emailfrequency` is not 0. That is what ripple members get today. Say so in the PR
  description, so it is a known effect and not a surprise.
- [ ] Banning a TN implicit member deletes the row, so the post then reads as addressed; check
  that the ban also takes the post down.
- [ ] TN re-subscribes its members by mail (see [modtools.md](../.claude/rules/modtools.md)). Check that
  doesn't turn an implicit membership into an ordinary one.

---

## Phase 7 - Cleanup (after the backfills have run on production and every reader is moved)

- [ ] Stop writing `memberships.rippled`; drop it from the Go structs and the API JSON (once the
  frontend no longer falls back to it); drop the column.
- [ ] `memberships_history.rippled`: drop once its readers use `added_origin`.
- [ ] `messages_groups.mod_messaging_allowed`: confirm with a grep that nothing reads it. Its
  only remaining value is as a record of what the poster consented to when they posted. Drop it,
  or keep it renamed `unaddressed` if you want that record or a fallback for a missing
  membership. Drop the write in TN ingestion with it.
- [ ] Remove `UsersUnaddressedOnly` / `UserIsUnaddressedOnly` and their batch copies.

---

## Throughout

- **Deploy order.** The frontend ships itself when master goes green; the Go API is rebuilt by
  hand later. So: the Go API adds the new JSON fields before the frontend reads them, the frontend
  falls back to the old field, and the PR description says "deploy Go first" (see
  [frontend-traps.md](../.claude/rules/frontend-traps.md)). Production SQL runs before the code
  that writes the new columns.
- **Tests.** For every reader changed, a test with a ripple implicit membership, a TN implicit
  membership, and the same membership after it has been cleared. Run the Go, Laravel, Vitest and
  Playwright suites through the status container. Update the orb if CI test configuration changes.
- **Docs, in the same PR as each change:** [rippling-algorithm.md](../docs/developers/reference/rippling-algorithm.md),
  [trashnothing.md](../docs/developers/reference/trashnothing.md), `docs/moderators/rippling-out.md`,
  `docs/members/rippling-out.md`, and the moderator docs on contacting members.
  `scripts/check-docs-freshness.mjs` enforces this.
- **Settled decision.** Add to [conventions.md](../.claude/rules/conventions.md): "Whether a
  community's moderators may contact a member is decided by that member's membership of that
  community, never by their posts or memberships elsewhere." This stops the mixed-poster rule
  from being reasoned back in. Rewrite the mixed-poster paragraph in
  [trashnothing.md:310](../docs/developers/reference/trashnothing.md#L310), which describes the old rule.
- **Rules.** Rewrite "A cleared ripple flag must stay cleared" in
  [rippling.md](../.claude/rules/rippling.md) for `implicit` / `added_origin`, and add a trap: a
  missing TN implicit membership makes the post read as addressed, which gives moderators powers
  they shouldn't have.
