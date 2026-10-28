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

- [x] **The flag's name is `implicit`.** Decided 2026-10-28. The notes also said "rename
  `rippled` to mod messaging allowed"; that was rejected. Most of the code that reads this flag
  is not about messaging (removal on retraction, the leave guard, stories, attribution), and a
  contact-specific name invites someone to flip it for a contact reason. Add a new column rather than renaming
  `rippled` in place. Go, batch and the frontend deploy separately, so a rename would break
  whichever deploys second.
- [x] **Enum values.** Decided 2026-10-28: the `added_origin` values in the table below, which maps
  each place a membership is inserted to a value.
- [x] **A TN implicit membership is treated exactly like a ripple one.** Decided 2026-10-22.
  Whatever a ripple membership gets, a TN implicit membership gets too, and the code decides it
  from `implicit`, never from `added_origin`. The one exception is automatic removal (below):
  - **Mail settings are the second exception.** Changed 2026-10-28: a TN implicit membership
    starts with mail off (0 / 0 / 0). A ripple poster is told by the intro mail; a TN poster
    is told nothing, so copying their settings would start the events and volunteering
    roundups from a group they never chose (digests already skip TN users). Ripple joins keep
    their rule, inline in `ExpandService::addPosterMembershipToRippledGroups`: the poster's own
    setting, preferring an organic membership (`ORDER BY rippled ASC, added DESC`), immediate (-1) as daily (24),
    events and volunteering as they are, 0 / 0 / 0 for a poster with no membership anywhere.
  - **ModTools member lists:** shown, with contact restricted (see the contact decision below).
  - **Member counts:** counted. `updateMemberCounts`
    ([GroupMaintenanceService.php:29](../iznik-batch/app/Services/GroupMaintenanceService.php#L29))
    counts every row today, ripple members included.
- [x] **Moderators cannot contact any implicit member, ripple or TN.** Decided 2026-10-28.
  Contact keys on `implicit = 1` alone, so it stays inside "decided by `implicit`". This
  reverses `be0a642faf` (2026-10-26) for ripple members, which made them contactable from the
  member list like any other member (Discourse 10102, and 10283: moderators had no way to reach
  one side of a held chat). Phase 5 puts the restriction back, keyed on `implicit`, for chat,
  mail, member standard messages and the ModTools buttons. Replies about the post itself already
  stay with its home community (`PosterMessagingGroups`). Check the held-chat case from 10283
  when doing it, since that was why the restriction was lifted.
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

### `added_origin` values

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
| Moderators can't start a chat, send standard messages or write about the post; member actions are silent; ModTools buttons hidden | yes (decided 2026-10-28; not true today since `be0a642faf`, Phase 5 restores it) | yes |
| Removed automatically when the post is withdrawn or retracted | yes, once no live post of theirs remains on the group | **no, never**: kept even after every post is gone (Phase 0) |
| Removed on a ban | yes | yes |
| Can leave without the moderator-role guard | yes | yes (TN accounts are never moderators anyway) |
| Blank posting status not shown as Moderated | yes | yes |
| Email settings copied from the poster's other memberships | yes | no: mail off, as nothing tells a TN poster they joined |
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

Done 2026-10-28 against `880d327b7e`. Line numbers in this section are from that commit; the
links in Phases 3 and 5 date from 2026-10-21 and several have moved, so use this section to find
the code. Tick each row off in Phase 3 or Phase 5 as it is converted.

- [x] List every reader of `memberships.rippled`, `memberships_history.rippled`, the `Rippled`
  log text and `messages_groups.mod_messaging_allowed`.
  - The original grep (`\.rippled\b|'rippled'|rippled\s*=|\bRippled\b`) misses double-quoted
    `"rippled"`. It also matches the in-memory `rippled` field of rippling analytics samples
    ([analytics.go:193-730](../iznik-server-go/rippling/analytics.go#L193)), which is not the
    column. The lists below come from `git grep -n -w -i rippled`, read by hand.
  - `memberships_history.rippled` and the `Rippled` log text are each read in only two places.
- [x] Find every insert into `memberships` and every insert into `memberships_history`.
- [x] Find how TN removes a membership.
- [x] Find where group member counts and member lists are computed, for Phase 6.
- [x] Count rows for each `Group/Joined` log text, to size Phase 4a. Done as Q1 to Q3
  (2026-10-22 to 2026-10-26), in the `added_origin` table and in 4a. Phase 4b is sized at about
  100 rows.

### What changes the plan

1. **Ripple members are contactable again, since `be0a642faf` (2026-10-26).** That commit
   removed the ripple checks from the moderator chat, mail and standard-message paths, and from
   ModTools. Only replies about the rippled post itself stay with its home community
   (`PosterMessagingGroups`, [message.go:2931](../iznik-server-go/message/message.go#L2931)).
   Every remaining contact guard is TN-only and reads posts (`UserIsUnaddressedOnly`). So "a
   TN implicit membership is treated exactly like a ripple one", and "contact restricted, as
   ripple members are now", no longer hold for contact. Decided 2026-10-28 (Phase 0): no implicit
   member can be contacted, so Phase 5 restores the restriction for ripple members too.
2. **ModTools never reads `member.rippled`.** [ModMember.vue:433](../iznik-nuxt3/modtools/components/ModMember.vue#L433)
   and [ModMemberButtons.vue:230](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L230)
   read `member.mod_messaging_allowed`. Go works that out per person from their posts
   ([membership.go:838-843](../iznik-server-go/membership/membership.go#L838)). The Phase 5
   frontend item should become a Go change: work out `mod_messaging_allowed` from the
   membership row being listed, which is already per group. Keep the JSON name. The frontend
   then needs no change for contact, only for showing the origin.
3. **Neither account merge looks at the flag when both accounts belong to the group.**
   [user.go:3307-3341](../iznik-server-go/user/user.go#L3307) (`MergeUsersTx`) and
   [User.php:1265-1310](../iznik-batch/app/Models/User.php#L1265) (`User::merge`, used by the TN
   sync) keep the survivor's row and merge role, `added`, `configid`, `settings` and `heldby`.
   An implicit survivor merged with an ordinary loser stays implicit. Phase 3 should take the
   lower `implicit` of the two rows. For `added_origin`, keep the survivor's value, or take the
   older row's, to match how `added` is merged.
4. **Six of the eleven insert sites write no `memberships_history` row**, and three write no
   join log (table below). Phase 2 puts `added_origin` on history, but those sites will leave
   no history row to carry it. Phase 3 has to decide, for each site, whether to add a row. A
   history row also triggers the welcome and spam checks, so adding one changes behaviour.
5. **The mail settings rule tries the home group first.** `addPosterMembershipToRippledGroups`
   first copies the poster's membership of the post's earliest group
   ([ExpandService.php:2311](../iznik-batch/app/Services/Ripple/ExpandService.php#L2311)).
   Only when there is none does it use `ORDER BY rippled ASC, added DESC` (line 2328). TN
   ingestion does not copy settings (mail off, Phase 0), so the rule stays inline in
   ExpandService.
6. **A mail unsubscribe removes the membership without a `Group/Left` log.** See TN removal
   below.

### Readers of `memberships.rippled`

| Where | What it decides | Becomes | Phase |
|---|---|---|---|
| [membership.go:467](../iznik-server-go/membership/membership.go#L467), selected at :596, :623, :915 | `Rippled` on `GetMembershipsMember`: member list, search, spam and related lists | add `implicit` and `added_origin` | 2 |
| [membership.go:565](../iznik-server-go/membership/membership.go#L565) | Banned list built from `users_banned`, with `0 AS rippled` | also `0 AS implicit, 'unknown' AS added_origin` | 2 |
| [membership.go:1613](../iznik-server-go/membership/membership.go#L1613) `selfLeaveKeepsRole` | Leave guard | `implicit` | 5 |
| [user.go:160](../iznik-server-go/user/user.go#L160), selected at :447 | `Rippled` on the member's own membership list | add both | 2 |
| [user.go:1594](../iznik-server-go/user/user.go#L1594) | Blank posting status not shown as Moderated | `implicit` | 5 |
| [message.go:2931](../iznik-server-go/message/message.go#L2931) `PosterMessagingGroups` | Which groups may write to the poster about a post | `implicit` | 5 |
| [message.go:7178](../iznik-server-go/message/message.go#L7178) `withdrawRippledCopiesAndBlock` | Deletes the ripple membership on withdraw | `implicit = 1 AND added_origin = 'rippled'` | 5 |
| [chatmessage.go:492](../iznik-server-go/chat/chatmessage.go#L492), [:540](../iznik-server-go/chat/chatmessage.go#L540) | Reply attribution: `wasHome`, `wasRippleJoin` | decide per query | 5 |
| [attribution.go:37](../iznik-server-go/rippling/attribution.go#L37) `EstablishedOriginMemberExists`, [analytics.go:973](../iznik-server-go/rippling/analytics.go#L973), [metrics.go:184](../iznik-server-go/rippling/metrics.go#L184), :189 | Attribution statistics | decide per query | 5 |
| [rippling/membership.go:32](../iznik-server-go/rippling/membership.go#L32) `ClearRippledMembership` | The clear; callers [membership.go:1480](../iznik-server-go/membership/membership.go#L1480) (partner join), :1534 (join), [user.go:2543](../iznik-server-go/user/user.go#L2543) (postcode change) | write `implicit = 0` too | 3 |
| [rippling/membership.go:75](../iznik-server-go/rippling/membership.go#L75) `ClearRippledMembershipsAtLocation` | Moved into the area | select on `implicit`. Not decided whether moving in clears a TN implicit membership: Phase 0 names only TN consent as clearing one | 5 |
| [story.go:149](../iznik-server-go/story/story.go#L149), [:198](../iznik-server-go/story/story.go#L198) | Story group and review queue | `implicit` | 5 |
| [BirthdayService.php:54](../iznik-batch/app/Services/BirthdayService.php#L54) | Birthday appeals | `implicit` | 5 |
| [ReengageContentService.php:241](../iznik-batch/app/Services/ReengageContentService.php#L241) | Re-engagement sign-off | `implicit` | 5 |
| [ExpandService.php:1056](../iznik-batch/app/Services/Ripple/ExpandService.php#L1056) `retractRippledCopyInGroup` | Deletes the ripple membership on retraction | `implicit = 1 AND added_origin = 'rippled'` | 5 |
| [ExpandService.php:1082](../iznik-batch/app/Services/Ripple/ExpandService.php#L1082), :1124, :1134 `pullBannedRippleMemberships` (`ripple:remove-banned`) | Removes ripple joins into groups the member is banned from | `added_origin = 'rippled'`. A moderator ban already deletes the row ([membership.go:310](../iznik-server-go/membership/membership.go#L310)), and TN ingestion should refuse a banned group | 5 |
| [ExpandService.php:2328](../iznik-batch/app/Services/Ripple/ExpandService.php#L2328) | Mail settings fallback | `ORDER BY implicit ASC` (point 5 above) | 5 |
| [ExpandService.php:2404](../iznik-batch/app/Services/Ripple/ExpandService.php#L2404) | Ripple join insert | write both columns | 3 |
| [ExpandService.php:2493](../iznik-batch/app/Services/Ripple/ExpandService.php#L2493) | Intro mail welcome list | `added_origin = 'rippled'` | 5 |
| [RippleEngagementService.php:102](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L102) | The clear | write `implicit = 0` too | 3 |
| [RippleEngagementService.php:147](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L147), :218, :259, :265 | Posting, reply and write-back passes | `implicit`, skipping TN on replies and write-backs | 5 |
| [ReplyAttributionBackfillService.php:75](../iznik-batch/app/Services/Ripple/ReplyAttributionBackfillService.php#L75), :81 | Attribution backfill | decide per query | 5 |
| [RippleMembershipBackfillService.php:40-147](../iznik-batch/app/Services/Ripple/RippleMembershipBackfillService.php#L40) | `ripple:backfill-memberships` | retire (4a) | 4a |

The Laravel `Membership` and `MembershipHistory` models have no `rippled` cast or constant, so
there is nothing to remove from them. Phase 2 adds the new ones.

### Readers of `memberships_history.rippled` and the `Rippled` log text

| Where | What | Becomes | Phase |
|---|---|---|---|
| [MembershipsProcessingService.php:66](../iznik-batch/app/Services/MembershipsProcessingService.php#L66) | Welcome email suppression (history) | `added_origin IN ('rippled', 'tn_unaddressed')` | 5 |
| [user.go:1536](../iznik-server-go/user/user.go#L1536) | Spread check (history) | exclude both origins | 5 |
| [MembershipsProcessingService.php:158](../iznik-batch/app/Services/MembershipsProcessingService.php#L158) | Spread check (log text) | history `added_origin` | 5 |
| [ExpandService.php:1991](../iznik-batch/app/Services/Ripple/ExpandService.php#L1991), [:2573](../iznik-batch/app/Services/Ripple/ExpandService.php#L2573) | Rejoin suppression (log text) | stays on logs | - |
| [ModLog.vue:36](../iznik-nuxt3/modtools/components/ModLog.vue#L36), [useSystemLogFormatter.js:267](../iznik-nuxt3/modtools/composables/useSystemLogFormatter.js#L267) | Display (log text) | keep; add the TN join text | 3 |
| Writers: [ExpandService.php:2421](../iznik-batch/app/Services/Ripple/ExpandService.php#L2421) (history), [:2436](../iznik-batch/app/Services/Ripple/ExpandService.php#L2436) (log) | | keep writing | - |

### Readers of `mod_messaging_allowed` and the unaddressed checks

| Where | What | Phase |
|---|---|---|
| [modmessaging.go:33](../iznik-server-go/modmessaging/modmessaging.go#L33) `PostIsUnaddressed`; callers [chatmessage.go:800](../iznik-server-go/chat/chatmessage.go#L800), [message.go:3751](../iznik-server-go/message/message.go#L3751), [:4499](../iznik-server-go/message/message.go#L4499), [:6326](../iznik-server-go/message/message.go#L6326), [microvolunteering.go:684](../iznik-server-go/microvolunteering/microvolunteering.go#L684), [:1149](../iznik-server-go/microvolunteering/microvolunteering.go#L1149) | Post has no owner | 5 |
| [modmessaging.go:53](../iznik-server-go/modmessaging/modmessaging.go#L53) `UserIsUnaddressedOnly`, :64 `UsersUnaddressedOnly`; callers [chatroom.go:408](../iznik-server-go/chat/chatroom.go#L408) (chat), [membership.go:211](../iznik-server-go/membership/membership.go#L211) (mail, standard messages), [membership.go:838](../iznik-server-go/membership/membership.go#L838) (member list flag) | Contact, per person; the mixed-poster rule | 5 (becomes per group) |
| [ProcessBackgroundTasksCommand.php:601](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L601) `postIsUnaddressed` (caller :730), [:621](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L621) `userIsUnaddressedOnly` (caller :889) | Batch copies | 5 |
| [messageGroup.go:91](../iznik-server-go/message/messageGroup.go#L91) `modMessagingAllowed`, used at [message.go:787](../iznik-server-go/message/message.go#L787); selected at message.go:612; carried in [message_list.go:37](../iznik-server-go/message/message_list.go#L37), :58 | Message card flag | 5 |
| [RippleEngagementService.php:152](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L152) | Posting pass ignores unaddressed posts | 5 |
| [GroupPostIngestionService.php:569](../iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php#L569) (writer), [PostSyncer.php:259](../iznik-batch/app/Services/TrashNothing/Sync/PostSyncer.php#L259), `MessageGroup` model cast | Writer | 7 |
| Frontend: [MessageReportModal.vue:198](../iznik-nuxt3/components/MessageReportModal.vue#L198), [ModMessage.vue:1329](../iznik-nuxt3/modtools/components/ModMessage.vue#L1329) (post flag); [ModMember.vue:433](../iznik-nuxt3/modtools/components/ModMember.vue#L433), [ModMemberButtons.vue:230](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L230) (member flag) | Reads the JSON name; no change if the name is kept | - |

Tests that pin today's behaviour: the mixed-poster rule is asserted by
`TestUserIsUnaddressedOnlyIsFalseForMixedAndForOrdinaryPosters`
([modmessaging_test.go:64](../iznik-server-go/test/modmessaging_test.go#L64)), and
`TestUserStaysUnaddressedOnlyAfterTheirPostIsRemoved` (:103) bears on the "never removed
automatically" decision. Other test files that set or assert these columns:
Go `membership_test.go`, `modmessaging_test.go`, `rippled_in_mod_contact_test.go`,
`rippled_in_ordinary_member_test.go`, `chatmessage_reach_test.go`, `rippling_metrics_test.go`,
`story_test.go`, `user_test.go`, `rippling/{analytics_extra,attribution,metrics}_test.go`;
Laravel `ExpandServiceTest`, `ResumedReachTest`, `RippleMembershipBackfillServiceTest`,
`ReplyAttributionBackfillServiceTest`, `ClearEngagedMembershipsCommandTest`,
`MembershipsProcessingServiceTest`, `BirthdayServiceTest`, `ReengageEmailsCommandTest`,
`ReengageVolunteerResolutionTest`, `DonationAskOptOutTest`, `ProcessBackgroundTasksCommandTest`,
`GroupPostIngestionServiceTest`, `PostSyncerTest`, `OrmHarness/Wave1ChatRoomExpandTest`,
`OrmHarness/Wave2RippleTargetsTest`; Vitest `ModMember`, `ModMemberButtons`, `ModMessage`,
`MessageReportModal`, `ModLog`, `useSystemLogFormatter`.

### Insert sites

Eleven places create a membership. Each one is a Phase 3 checklist row, and every insert must go
through the per-codebase helper that reports `unknown`.

| Site | Origin | History row | Join log (text) |
|---|---|---|---|
| [membership.go:1554](../iznik-server-go/membership/membership.go#L1554) `addMemberToGroup` (join endpoint) | `manual` / `replied` / `moderator` (byuser ≠ user) | yes, :1571 | yes, :1589 (`Manual`, `Auto` or none) |
| [membership.go:1490](../iznik-server-go/membership/membership.go#L1490) `putMembershipsPartner` | `partner` | yes, :1507 | yes, :1514 (`via partner`) |
| [user.go:1783](../iznik-server-go/user/user.go#L1783) `AddMembership`; callers [chatmessage.go:1098](../iznik-server-go/chat/chatmessage.go#L1098), [:1195](../iznik-server-go/chat/chatmessage.go#L1195) | `replied` | yes, :1805 | yes (reason passed in) |
| [user.go:2320](../iznik-server-go/user/user.go#L2320) `PutUser` with `groupid` (`PutUser` at :2190, `UserPutRequest` at :2150) | `signup`, from the new `origin` parameter | yes, :2343 | yes, :2328 (no text) |
| [message_submit.go:131](../iznik-server-go/message/message_submit.go#L131) | `posted` | **no** | yes (no text) |
| [message.go:4078](../iznik-server-go/message/message.go#L4078) `JoinAndPostAs` | `posted` | **no** | yes (no text) |
| [group.go:1020](../iznik-server-go/group/group.go#L1020) group creator | `moderator` | **no** | **no** |
| [ExpandService.php:2396](../iznik-batch/app/Services/Ripple/ExpandService.php#L2396) | `rippled` | yes, :2416 | yes, :2436 (`Rippled`) |
| [IncomingMailService.php:1275](../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php#L1275) email subscribe | `subscribed` | **no** | yes (`Subscribed`) |
| [AddMembershipCommand.php:53](../iznik-batch/app/Console/Commands/User/AddMembershipCommand.php#L53) | `moderator` | **no** | **no** |
| [EmailReplaySyncer.php:392](../iznik-batch/app/Services/TrashNothing/Sync/EmailReplaySyncer.php#L392) | `partner`: a test-harness stub that stands in for TN's partner add, never run against production | **no** | **no** |

Phase 3 adds a twelfth, TN post ingestion. The two Go struct inserts go through
`db.Create(&membership)` and `db.Create(&history)` in `AddMembership`, so the `Membership` and
`MembershipHistory` structs in [user.go](../iznik-server-go/user/user.go#L150) need the new
fields as well as the map inserts. Account merges move rows rather than creating them (point 3
above).

### TN removal

- **TN's API removal** is `DELETE /memberships?partner=` →
  [`deleteMembershipsPartner`](../iznik-server-go/membership/membership.go#L1735). It deletes the
  Approved row and logs `Group/Left` (`via partner`). It runs `selfLeaveKeepsRole` first, which
  never blocks a TN account because they are never moderators.
- **Mail `{group}-unsubscribe`** ([IncomingMailService.php:1315](../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php#L1315))
  deletes the row through Eloquent and **writes no `Group/Left` log**. TN members can use it
  too. A ripple member who leaves this way is therefore not seen as having opted out by rejoin
  suppression, which looks for a `Group/Left` after the `Rippled` join. Outside this plan's
  scope, but worth a fix of its own.
- The partner `forget` path ([session.go:673](../iznik-server-go/session/session.go#L673)) is for
  LoveJunk accounts only (`ljuserid`). It logs `Group/Left` for each group.
- **Other removals**, beyond those Phase 0 names (leaving, TN, moderator remove or ban,
  merge): spam cleanup bans and deletes
  ([SpamCleanupService.php:57](../iznik-batch/app/Services/SpamCleanupService.php#L57)); account
  forget, limbo and purge ([UserManagementService.php:445](../iznik-batch/app/Services/UserManagementService.php#L445),
  :581, [user.go:3071](../iznik-server-go/user/user.go#L3071), [session.go:704](../iznik-server-go/session/session.go#L704));
  `RemoveMembershipCommand` and `MoveMembersCommand`. All of them act on an account or are run
  by hand, not when a post is withdrawn, so they don't conflict with "never removed
  automatically".

### Member counts, member lists and mailings (Phase 6)

- **`membercount`**: `GroupMaintenanceService::updateMemberCounts`
  ([GroupMaintenanceService.php:16](../iznik-batch/app/Services/GroupMaintenanceService.php#L16)) counts every Approved row.
  `queryActiveMembers` ([iznik-routing-go/group_actives.go:83](../iznik-routing-go/group_actives.go#L83)) counts Approved
  members active in 90 days. Neither excludes ripple members.
- **Member lists**: `GetMemberships` ([membership.go:499](../iznik-server-go/membership/membership.go#L499)), and
  `enrichMembers` (:830) for the contact flag.
- **Mailings**: digests (`UnifiedDigestService`, selecting on `emailfrequency`), ChitChat
  (`NewsfeedDigestService.php:86`), and events and volunteering roundups
  (`Concerns/BuildsUserRoundups.php:87`, on `eventsallowed` / `volunteeringallowed` and
  `emailfrequency != 0`). None of them reads `rippled`, so a TN implicit member's mail depends
  only on its own settings. They start at 0 / 0 / 0 (Phase 0), so it gets no roundups from the
  group; digests skip TN users anyway. ChitChat does not read membership mail settings at all
  (`NewsfeedDigestService::digestForUser` gates on the user), so this does not change it.

---

## Phase 2 - Schema (additive only)

Laravel migration plus an idempotent `_migration.sql` for production, following
[2026_06_23_000010_add_rippled_to_memberships_migration.sql](../iznik-batch/database/migrations/2026_06_23_000010_add_rippled_to_memberships_migration.sql):
an `information_schema` check first, and columns added so that MySQL 8 can do it as an `INSTANT`
change.

Split into two commits, at your request (2026-10-28): `implicit` first, reviewed on its own, then
`added_origin`. Each column is added `AFTER` an existing one with `ALGORITHM=INSTANT`, so it
refuses rather than rebuilds if INSTANT is ever unavailable.

### 2a. `implicit` (first commit)

[2026_10_28_000001_add_implicit_to_memberships.php](../iznik-batch/database/migrations/2026_10_28_000001_add_implicit_to_memberships.php)
and its [`_migration.sql`](../iznik-batch/database/migrations/2026_10_28_000001_add_implicit_to_memberships_migration.sql).

- [x] `memberships.implicit TINYINT(1) NOT NULL DEFAULT 0`, after `rippled`.
- [x] Copy existing values, in chunks of 10,000 ids, as `SET implicit = rippled WHERE implicit <> rippled`.
  - **The copy drifts until Phase 3 ships**, because ripple joins and clears write `rippled`
    alone until then. So it syncs in both directions and can be run again: **re-run the
    `_migration.sql` once Phase 3's dual writes are live in both Go and the batch.** Go and the
    batch deploy separately, and a run before both are out lets drift back in. The ALTER skips
    itself, and the file ends by printing how many rows are out of line, which should be 0.
  - **That re-run must come before the first TN implicit membership exists, and never run
    after it.** The copy sets `implicit = rippled`, and a `tn_unaddressed` row has `rippled = 0,
    implicit = 1`, so the copy would clear it. It can't be filtered on `added_origin`, because
    2a's SQL runs before that column exists.
- [x] **No index on `implicit`.** Decided from Q7 (production, 2026-10-27): 180,982 of
  5,158,594 memberships (3.5%) have `rippled = 1`, held by 24,530 users across 481 groups.
  - A flag that is 1 on 3.5% of rows is a poor index on its own. Every live read is already
    narrowed by `(userid, groupid)`, `userid` or `groupid`.
  - `rippled` has never had an index, and the same reads have run on it in production since
    2026-06-23. `implicit` covers the same rows plus the TN implicit memberships (about 100
    more to start with), so it needs none either.
  - The only reads of the whole table on the flag are one-off: the Phase 2 copy and the Phase 4
    backfills. They run in chunks by `id`.
- [x] Laravel `Membership`: `implicit` cast to boolean. Go: `Implicit` on `GetMembershipsMember`
  and `user.MembershipTable`, selected by all three member-list queries, the Banned list (`0`) and
  `user.GetMemberships`. The API reports `implicit` from now on: **run the SQL before deploying
  this Go API**, or the member lists fail on the missing column.
- [x] Tests: `MembershipImplicitColumnTest` (Laravel: column, default, copy in both directions,
  re-run); `membership_implicit_test.go` (Go: member list incl. Banned, `user.GetMemberships`).

### 2b. `added_origin` (second commit)

[2026_10_28_000002_add_added_origin_to_memberships.php](../iznik-batch/database/migrations/2026_10_28_000002_add_added_origin_to_memberships.php)
and its [`_migration.sql`](../iznik-batch/database/migrations/2026_10_28_000002_add_added_origin_to_memberships_migration.sql),
run after 2a's SQL (the column goes after `implicit`).

- [x] `memberships.added_origin ENUM(...) NOT NULL DEFAULT 'unknown'`, after `implicit`. The ENUM
  values are in the `added_origin` table's order.
- [x] `memberships_history.added_origin ENUM(...) NOT NULL DEFAULT 'unknown'`, after `rippled`.
  History records creation, so it gets the origin and no `implicit`. Keep
  `memberships_history.rippled` until Phase 7.
- [x] `added_origin` stays `unknown` until Phase 4a. There is no copy step.
- [x] Laravel: `Membership::ADDED_ORIGIN_*` and `ADDED_ORIGINS`. `MembershipAddedOriginColumnTest`
  checks that `ADDED_ORIGINS` matches the ENUM on both tables. Go: `AddedOrigin` on the same structs
  and selects as `implicit` (`'unknown'` on the Banned list). **Run the SQL before deploying this Go
  API**, or the member lists fail on the missing column.
- [x] `user.MembershipTable` is what `AddMembership` inserts, so its `AddedOrigin` has
  `gorm:"default:unknown"`: an unset field would otherwise send `''`, which the ENUM refuses in
  strict mode. The Go test database is not strict, so a test reading the stored value back is
  what catches it (`TestAddMembershipLeavesAddedOriginOnTheColumnDefault`, shown red without the
  tag).
- [x] `MembershipHistory` (Go) is not changed: nothing reads it, and Phase 3 adds the field when
  it writes it.

---

## Phase 3 - Write both columns, and record the origin on every insert

From here on `rippled` and `implicit` are written together, so either can be read.

**3a, the `implicit` writes, done 2026-10-28** (uncommitted): every write of `memberships.rippled`
now writes `implicit` too, and TN ingestion writes it for unaddressed posts. `added_origin`,
history rows and join logs for the new TN rows are 3b.

**3b, the `added_origin` writes, done 2026-10-28** (uncommitted): every insert into `memberships`
and `memberships_history` sets `added_origin` through `addedorigin.ForInsert` (Go, new package
`iznik-server-go/addedorigin`) or `Membership::addedOriginForInsert` (batch). Both merges take the
older row's origin and the lower `implicit`. Not done in 3b: history rows and join logs at the
sites that write none today, and the TN addressed insert (below).

- [x] ExpandService ripple join: `implicit = 1`, `added_origin = 'rippled'`; history row
  `added_origin = 'rippled'`.
- [x] `ripple:backfill-memberships` sets `implicit = 1` with `rippled = 1`.
- [x] Every clear path sets `implicit = 0` alongside `rippled = 0`:
  `rippling.ClearRippledMembership` ([rippling/membership.go:30](../iznik-server-go/rippling/membership.go#L30))
  and `RippleEngagementService::clear`. Both now match `rippled = 1 OR implicit = 1`, so the
  partner join and the join endpoint clear a TN implicit row. The batch passes and the
  move-in clear still pick candidates on `rippled = 1`, so they never reach one.
- [x] Mail settings: TN ingestion starts with mail off (changed 2026-10-28). The ripple join
  keeps its inline rule in ExpandService; its `ORDER BY rippled ASC` fallback moves to
  `implicit` with the other readers in Phase 5.
- [x] Account merge takes the lower `implicit` of the two rows (Phase 1 point 3), and `rippled`
  the same way. `added_origin` follows the older row, whose `added` is kept; a tie keeps the
  survivor's. Go `MergeUsersTx` does it in its own UPDATE...JOIN before the `added` one, since
  MySQL does not promise the order of a multi-table UPDATE's assignments. Batch `User::merge`
  does it on the model. No "became ordinary" log is written when a merge lowers `implicit`.
- [x] Join endpoint ([membership.go:1553](../iznik-server-go/membership/membership.go#L1553)):
  accepts `origin` and sets `added_origin` on the membership and history row (`joinOrigin`).
  **Changed from the plan:** the client may send `manual`, `posted`, `replied` or `signup`, not
  `moderator`. A moderator adding someone else is always `moderator`, decided by the server
  from `userid ≠ byuser` whatever the request says, so a self-join cannot claim it. Old clients
  fall back to the `manual` flag (`true` → `manual`, `false` → `replied`, neither → `unknown`,
  reported). The frontend `joinGroup(userid, groupid, origin)` now takes the origin and still
  sends `manual` for an old API: `GroupHeader` and `explore/join` send `manual`, the reply
  flow `replied`. The ModTools Add Member flow sends none and is a moderator add. The
  `Group/Joined` log text is unchanged (`Manual`, `Auto` or none), since ModLog reads it.
- [x] **`PutUser` takes an `origin` parameter** ([user.go:2182](../iznik-server-go/user/user.go#L2182)).
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
    column. Add cases for a missing `origin` and a refused one. Done; the refusal comes before
    the account is created.
- [x] Every other insert site in the Phase 0 table sets its value: partner join `partner`
  (row and history), `user.AddMembership` takes an `origin` argument (both chat callers
  `replied`; row and history), `SubmitMessage` and `JoinAndPostAs` `posted`, the group creator
  `moderator`, email subscribe `subscribed`, `AddMembershipCommand` `moderator`, the
  email-replay stub `partner`. `SubmitMessage`'s raw `INSERT IGNORE` became a GORM chain to match
  `JoinAndPostAs`. **Still open:** the five sites with no history row (the two post joins, the
  group creator, email subscribe, `AddMembershipCommand`) still write none. Adding one would
  start the welcome mail and spam check for them, which is a behaviour change to decide on its
  own. Moves (`MoveMembersCommand`, merges, restore and demerge of a dump) carry the row's
  existing value.
- [x] **Any live code that sets `added_origin = 'unknown'` reports a Sentry error.** After this
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
  - Done as `addedorigin.ForInsert(site, userid, groupid, origin)` and
    `Membership::addedOriginForInsert($origin, $site, $userid, $groupid)`. Anything that is not a
    value, or is `unknown`, is written as `unknown` and reported at error level; the join goes
    ahead. Go tests swap the reporter with `addedorigin.SetReporter`; batch tests bind a
    recorder as `sentry`.
- [x] **TN post ingestion via the API makes the poster a member of the post's origin group.**
  The origin group is the one ingestion works out from the post's own location
  (`Location::groupsNear()`), the group the post's `rippled_in = 0` row goes on. Today ingestion
  deliberately creates no membership
  ([GroupPostIngestionService.php:265](../iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php#L265)).
  From now on it must, whether or not the poster consented, so every TN post has a membership
  behind it. In the same transaction as the post:
  - **3c status, done 2026-10-28** (uncommitted). `recordMembership` (renamed from
    `recordImplicitMembership`) still runs after routing and outside the post's transaction, as
    3a decided; the new membership, its history row and its join log are written by
    `insertMembership` in one transaction of their own. Unaddressed: `tn_unaddressed`,
    `implicit = 1`, mail 0 / 0 / 0, log `TrashNothing unaddressed` with `byuser` NULL.
    Addressed with no membership: `partner`, `implicit = 0`, column mail defaults, log
    `via partner` by the member, as TN's partner add. Both set `ourPostingStatus = 'DEFAULT'` and
    queue the member for reach mail like every other join. The guards below are in place:
    welcome mail and the batch spread check read `rippled = 1 OR added_origin =
    'tn_unaddressed'` and the log text; the Go spread check (`activedistance`) excludes
    `tn_unaddressed`; birthday, re-engagement sign-off and both story queries add
    `implicit = 0` beside `rippled = 0`. Phase 5 drops the `rippled` half of each. ModLog shows
    both log texts (`via partner` used to read as "joined automatically when posting/replying").
    The missing-field report is `PostSyncer::reportMissingConsentField`, once per `sync()`.
    **With this, TN ingestion no longer needs to wait for the Phase 5 readers** (production
    sequence row 5). It must still go live after the last run of 2a's sync (row 3), which would
    clear every TN implicit row. Moderator contact for these members is still decided by
    `messages_groups` until Phase 5.
  - **3a status** (`recordImplicitMembership`, run after routing and not in a transaction:
    it logs a failure rather than throwing, so a failed write never leaves the post
    half-routed). Done: the unaddressed insert (`implicit = 1`, shared mail settings, no row for
    a banned poster) and the consent clear (through `RippleEngagementService::clear`, reason
    `consented through TrashNothing`, which also clears a ripple membership). The insert sets
    `ourPostingStatus = 'DEFAULT'`, not blank: `ContentCheckService::isUserModerated` reads a
    blank status as moderated, so a blank row would hold every later post by that poster on the
    group. 3b: the unaddressed insert sets `added_origin = 'tn_unaddressed'`. Still not done:
    the history row and the join log, and the addressed insert (`partner`). The history row must
    wait for the welcome-mail guard, because a history row with `rippled = 0` sends the
    per-group welcome.
  - **No membership, and the group isn't in the poster's `freegle_group_ids`:** insert a
    membership with `added_origin = 'tn_unaddressed'` and `implicit = 1`, mail settings off
    (0 / 0 / 0, Phase 0), plus a `memberships_history` row (same origin) and a `Group/Joined` log
    (text such as `TN cross-post`) so the backfill and ModLog can read it.
  - **No membership, and the group is in `freegle_group_ids`:** insert with
    `added_origin = 'partner'` and `implicit = 0`, plus the history row and log.
  - **A membership already exists:** leave `added_origin` alone, since it records how that row
    was created. Never make an ordinary membership implicit. Clear an implicit one
    (`implicit = 0`, with the "became ordinary" log) when this post comes with consent for the
    group.
  - **This item is not a dual write.** A TN implicit membership has `rippled = 0`, so every
    reader still on `rippled` treats it as an ordinary membership until Phase 5 moves that reader.
    That holds whether or not the `implicit` column exists yet. (If it ships before Phase 2
    reaches production, still create the membership with `added_origin` set; Phase 4b then sets
    `implicit = 1` from `mod_messaging_allowed`.) These must be guarded before the change goes
    live, or the change must wait for Phase 5:
    - the per-group welcome email ([MembershipsProcessingService.php:66](../iznik-batch/app/Services/MembershipsProcessingService.php#L66)),
      which would mail TN users;
    - the spread check;
    - birthday appeals and the story queue;
    - the mail settings, which must be off (0 / 0 / 0), never copied (Phase 0).

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
- [x] Keep writing `messages_groups.mod_messaging_allowed` as now until Phase 7. It is Phase 4b's
  source, and the post-level checks read it until Phase 5. Unchanged.

---

## Phase 4 - Backfills (commands Edward runs)

Write these as artisan commands like the existing `ripple:*` ones: dry run by default, chunked,
idempotent, safe to re-run, with counts printed at the end.

### 4a. `added_origin` from logs - `memberships:backfill-origin`

**Done 2026-10-28** (uncommitted): `memberships:backfill-origin`
([BackfillOriginCommand.php](../iznik-batch/app/Console/Commands/Membership/BackfillOriginCommand.php),
[MembershipOriginBackfillService.php](../iznik-batch/app/Services/MembershipOriginBackfillService.php)).
Dry run unless `--send`; `--user`, `--chunk`. Three passes:
- **Latest joins:** one keyset read of every Group/Joined log through `FORCE INDEX (type)`
  (Q1's path, minutes), the latest id per pair kept in a temporary table on the write
  connection. Plain reads, so no locks on `logs`; an `INSERT ... SELECT` would take shared locks
  on every row it reads. Production has no `(user, groupid)` index on `logs`, and Q8 showed a
  per-membership lookup through `logs.user` costs hours.
- **Memberships:** chunks by id of rows still `unknown`, joined to that table and to the log by
  primary key. `rippled = 1` → `rippled`, else the Phase 0 mapping. The post test is Q3's,
  one `EXISTS` per ambiguous row (about 650,000). The UPDATE also requires `unknown`, so it never
  overwrites a value the live code set.
- **History:** chunks by id; each chunk reads the join logs in its time window (split where
  rows are more than 10 minutes apart, so a row with an outlying date doesn't widen the read),
  and each row takes the nearest join for its pair within 60 seconds. `rippled = 1` → `rippled`.

Decisions to check:
- Any text whose actor is another real account is `moderator`, including the one `Reinstated by
  Geeks` row. That row isn't handled separately.
- Merges are **reported, not handled**: a membership whose latest join was logged more than a day
  after its `added` is listed (count plus 10 sample ids). Matching the join log to `added` instead
  would follow Phase 3's merge rule (older row's origin), but it depends on `added` and the log
  timestamps agreeing, which nothing has checked on production.
- The report also lists: `rippled = 1` without a `Rippled` latest join (Q8 expects about 102),
  a `Rippled` latest join with no rippled history row (Q4's June window), and a latest history
  row with `rippled = 1` whose latest join isn't `Rippled`. None of these changes what is written.
- `ripple:backfill-memberships` was rewritten, not retired: it now flags only when the `Rippled`
  log is the latest Group/Joined row for the pair (any later join, including "became ordinary",
  skips it), and sets `added_origin = 'rippled'` along with the flag.

- [x] For each membership with `added_origin = 'unknown'`, take the **most recent** `Group/Joined`
  log for `(userid, groupid)`, ignoring `Ripple-created membership became ordinary:` rows, and
  map its text to a value as in the Phase 0 table. The latest join is the current row's
  creation, because a membership row is deleted when the member leaves. Do **not** use "any
  `Rippled` log exists": that misreads a member who was rippled in, left, and later joined by hand.
- [x] The `logs` table is the main source. `Group/Joined` rows are never purged, and they cover
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
- [x] Known false reads to handle or report: account merges (logs move to the surviving account,
  but if both accounts were members of the group the survivor keeps its own row), and
  memberships with no join log at all (→ `unknown`).
- [x] **What Q2 showed** (production, 2026-10-22, 5,147,813 memberships):
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
- [x] **What Q3 showed** (production, 2026-10-26). The test is whether a post of theirs on the
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
- [x] Fill `memberships_history.added_origin` too, where the row can be matched.
- [x] Rewrite or retire `ripple:backfill-memberships`
  ([RippleMembershipBackfillService.php](../iznik-batch/app/Services/Ripple/RippleMembershipBackfillService.php)).
  It uses "any `Rippled` log", which is the misread above.

### 4b. TN memberships from posts - `memberships:backfill-tn`

- [ ] Scope (Phase 0): only the post rows (`rippled_in = 0`) with `mod_messaging_allowed = 0`,
  about 100, live or not. Rows with `mod_messaging_allowed = 1` are not touched. For each
  distinct `(fromuser, groupid)` in that set:
  - **The poster has another post on that group with `mod_messaging_allowed = 1`:** skip. They
    consented to the group once, so the membership is not implicit (Phase 0).
  - **No membership:** create one with `implicit = 1`, `added_origin = 'tn_unaddressed'`, mail
    settings off (0 / 0 / 0), a `memberships_history` row (same origin) and a
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

- [ ] Member counts and member lists treat a TN implicit member exactly as they treat a ripple
  member today (Phase 0). Mail does not: the membership starts with mail off. Any place that
  excludes or limits ripple members must do it by `implicit`, so TN members are covered without
  a second rule. The "statistics that count local activity" rule in
  [rippling.md](../.claude/rules/rippling.md) is one such place: change it from "memberships
  rippling created" to `implicit = 1`.
- [x] TN members who never chose the group get no mail from it: the membership starts at
  0 / 0 / 0 (changed 2026-10-28; it used to copy the ripple rule). Say so in the PR description.
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
- **Production sequence.** Each step is live before the next starts.

  | # | Step | Why here |
  |---|---|---|
  | 1 | Phase 2 SQL (2a, then 2b), then the Phase 2 Go API | The member lists select both columns |
  | 2 | Phase 3 dual writes and origin on every insert, Go and batch, **without** TN ingestion | TN ingestion has no old-column equivalent (Phase 3) |
  | 3 | Re-run 2a's sync for the last time, then 4a | The sync clears TN implicit rows, so it runs before any exist. 4a only touches `unknown` and is safe to re-run |
  | 4 | Phase 5 readers: Go, then batch, then frontend | The frontend ships itself on green master; it falls back to `rippled` |
  | 5 | TN ingestion (Phase 3), then 4b if Q12 clears it | Needs the Phase 5 batch readers (welcome mail, spread check, birthday, stories), or guards on them |
  | 6 | Phase 7 code that no longer reads or writes `rippled`, then the drop-column SQL | A Go struct that still selects a dropped column fails every query |
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
