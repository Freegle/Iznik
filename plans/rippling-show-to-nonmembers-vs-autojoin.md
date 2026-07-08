# Rippling Out: show posts to non-members instead of auto-joining

Design analysis. Counterfactual: what if rippling stopped auto-joining people to
other groups, and instead simply showed the rippled post to non-members on the
browse and email pages and let them join voluntarily?

Source thread: [Rippling Out (Discourse 9808)](https://discourse.ilovefreegle.org/t/rippling-out/9808/1).
This is grounded in a full read of the rippling code surface (iznik-batch reach
engine, iznik-server-go apiv2, iznik-server apiv1, iznik-nuxt3 frontend) and all
292 posts of the thread.

---

> ## NOT PLANNED FOR IMPLEMENTATION
>
> This document is an exploratory "what if" analysis only. It is **not** a plan,
> not a decision, and not scheduled for any work. Nothing here is being built. It
> exists to think through the trade-offs of one alternative design for rippling
> out, so the option is understood if it is ever revisited. The current rippling
> design (with auto-join) is what is live behind the kill switch.

---

## TL;DR

The change is smaller than it first looks, because the audience side already works
the way the alternative would. The single most important finding:

> **Auto-join is load-bearing for the POSTER, not for the audience.** Non-members
> already see rippled posts through the spatial (isochrone) browse feed and the
> reach-mail path, neither of which touches memberships. The only thing the
> membership rows actually buy is that the *poster* receives the receiving
> group's digest and mygroups feed. Audience visibility is already pure geometry;
> only poster awareness is membership.

Removing auto-join removes a side effect (real memberships the poster never asked
for) that has known consequences: it inflates three member-count stats, exposes the
poster's data to moderators of groups they never chose to join, and brings distant
content into the poster's own feed. It is also the loudest single complaint in the
thread. The cross-group insertion that does the core work
(`messages_groups.rippled_in=1`) is untouched either way.

The trade-off has three sides. Some things get simpler or more accurate; three
capabilities are lost (all replaceable with new mechanisms, not by reinstating
auto-join); and one issue the thread already flagged appears: every rippled-in post
in a mod's queue would then visibly come from a non-member. The sections below lay
out each side without taking a position on which design to choose.

---

## The two designs, precisely

### What rippling does today

When a post ripples into a receiving group, `ExpandService` (one serial loop per
tick) does **two** independent things:

1. **Cross-group insertion.** Inserts a `messages_groups` row with `rippled_in=1`
   for the receiving group. The post is now in that group's feed and pending
   queue. (`ExpandService.php` rippleIntoNewGroups, lines ~819-840.)
2. **Poster auto-join.** `addPosterMembershipToRippledGroups`
   (`ExpandService.php:865-977`) inserts a `memberships` row (`rippled=1`,
   `role=Member`) for the **poster** in every receiving group, copies the
   poster's home-group `emailfrequency` (downgrading immediate to daily), writes
   a `memberships_history` row, and writes a `logs` row
   (`type=Group, subtype=Joined, text='Rippled'`) as the durable provenance
   marker. It also sends `RippleIntroMail`.

There is a **second, separate auto-join** on the audience side: when a non-member
taps Send on a reply, the frontend silently joins them to the group
(`useReplyStateMachine.handleJoinGroup`, `js:854-856`).

Two supporting mechanisms hang off the poster auto-join:

- **Rejoin guard** (`ExpandService.php:792-815`): if the poster's most recent
  `Group/Joined` log for a group is a ripple-join they then left, do not
  re-ripple into it. Reads `logs.text='Rippled'`.
- **Pull-on-leave** (`pullRippledPostsFromLeftGroups`,
  `ExpandService.php:1042-1122`): when the poster leaves a group their post
  rippled into, retract the copy. Also keyed on the `logs.text='Rippled'` signal.

### The alternative

- Delete `addPosterMembershipToRippledGroups`. No `memberships.rippled=1` rows are
  ever written. The poster is never enrolled anywhere by rippling.
- Keep `messages_groups.rippled_in=1` cross-group insertion exactly as is. The post
  is still visible in receiving groups.
- Non-members keep seeing rippled posts via the location/isochrone feed and
  `FilterReachBlocked`, which are already purely spatial and already the default
  path for most users.
- Replace the silent reply-time auto-join with an explicit "Join [group] to reply"
  affordance.
- Replace `RippleIntroMail` and the poster's incidental receiving-group digest with
  a targeted "your post is now visible to people in N nearby communities"
  notification, fired from the `messages_groups` insert rather than from a
  membership insert.

Reach enforcement (held replies, `ReachQueryService` `ST_Contains`, the chat write
gate) is unchanged, because it was never membership-based.

---

## The affected surface

Everywhere the rippling-out code touches, grouped by how much it depends on the
auto-join membership versus pure location/visibility.

**Already spatial - unaffected by removing auto-join:**

- Browse nearby/isochrone feed and `FilterReachBlocked` (`isochrone/message.go:257-293`).
- In-app reply-eligibility and chat write gate (`ReachQueryService.php:24-43`,
  `message.go:832-886`, `chatmessage.go:413-415`) - all `ST_Contains`.
- Email/TN incoming reply hold and release (`rippling_held_replies`,
  `RippleReplyService`, `ReleaseRepliesCommand`) - spatial and status-based.
- The whole reach engine: routing server, Dijkstra, `users_approxlocs` KNN,
  hazard schedule, audience cap, reply-saturation stop, extent governor,
  self-tuning (`RippleTuneService` reads `messages_groups` and `rippling_reach`,
  never memberships).
- All nine reach-experiment KPIs (`rippling/metrics.go`) classify by
  `messages_groups.rippled_in=1`, not `memberships.rippled`.
- Every `rippled_in=0` guard already added to My Posts, ModTools Edit queue,
  IP-abuse, subject-repeat, stats generation, auto-repost, user-data-export.
  These guard the `messages_groups` fan-out and stay necessary.

**Membership-dependent - the actual blast radius:**

- Poster's daily/immediate digest from receiving groups
  (`UnifiedDigestService.php:1204-1268`, `getPostsForUser` reads
  `$user->memberships()` with no `rippled` filter).
- Poster's mygroups browse feed and unread badge
  (`isochrone/message.go:211-213, 303-307`).
- `RippleIntroMail` and the welcome-suppression branch
  (`MembershipsProcessingService.php:63-99`).
- Rejoin guard and pull-on-leave (both keyed on `logs.text='Rippled'`).
- Membership-removal tail of retraction (`retractRippledCopyInGroup`,
  `ExpandService.php:387-432`).
- `checkSeenOnManyGroups` spam threshold relaxed from 16 to 35 specifically to
  tolerate ripple auto-joins (`MembershipsProcessingService.php:126-159`).
- Three member-count stats with no `rippled=0` guard: `APPROVED_MEMBER_COUNT`,
  `ACTIVE_USERS`, `OUR_POSTING_BREAKDOWN` (`StatsGenerationService.php`).
- `PushNotificationService::filterPushRecipients` (`php:999-1016`) uses
  zero-memberships as a proxy for "ex-member, do not push".
- Session/user/search endpoints that return or scope by membership
  (`session.go:937`, `user.go:402`, `message.go:1356`).
- ModTools: the auto-join log entry and the poster appearing in a receiving
  group's member list.

---

## Change / break / stays the same / impossible, by area

| Area | Changes | Breaks | Stays the same | Becomes impossible |
|---|---|---|---|---|
| Browse nearby/isochrone (default path) | None | Nothing | Spatial query, reach gate, blurring, pin-closest-two | Nothing |
| Browse mygroups (poster) | Poster's rippled-into groups drop out of their mygroups feed | Poster's own feed/badge no longer shows those groups (they still see their post via nearby) | Non-poster users unchanged | Showing the poster their own rippled copies in mygroups without a new query branch |
| Digest to audience non-members | No unsolicited digest to non-members in reach (already the case today unless auto-joined) | Nothing technical; a deliberate consent stance | Organic members of receiving groups still get digest/reach-mail | Email to non-members in reach without a new spatial user lookup + consent model |
| Digest to poster | Receiving-group digest replaced by a one-time reach notification | Poster loses any awareness of spread unless the notification is built | Poster's origin-group and voluntary-group digest unchanged | Per-group email frequency for receiving groups (no membership row to hold it) |
| Reply in-app | Remove silent reply-time join; add explicit "Join to reply" + a third "in reach, not a member" state | More reply friction; `?reply=1` deep-link must show Join before the composer | Server-side reach gate; existing members reply as today | Zero-friction reply for non-members |
| Reply by email/TN | None | Nothing | Whole held-reply cycle is spatial | Nothing |
| Reach enforcement | None | Nothing | Every gate is `ST_Contains` | Nothing |
| Moderation queue | None needed (`isUserModerated` returns true for a missing row, same as a `rippled=1` row with null status) | Nothing | 1-hour veto, AutoApprove `rippled_in=1` bypass, Pending/Spam counts, Edit-queue filter | Nothing |
| Spam/abuse | Drop `logs.text='Rippled'` exclusion; revert threshold 35 -> 16 (audited) | If threshold left at 35, spam detection is permanently weaker | IP-abuse, subject-repeat, incoming-mail checks (all `rippled_in=0`) | Nothing; the stack gets simpler |
| Retraction/lifecycle | `retractRippledCopyInGroup` loses its membership tail; `pullRippledPostsFromLeftGroups` becomes dead code | No leave-based per-group retraction; rejoin guard goes inert | Origin-removal retraction, cap-scope geometry retraction, held-reply lifecycle | Poster opt-out from one receiving group via "leave group" |
| Stats / experiment KPIs | Three member-count stats auto-correct passively | Nothing; inflated stats become accurate | All KPIs key on `rippled_in`; reply-attribution stays correct | Counting "distinct non-members reached per group" from a membership query |
| Push notifications | Must replace the zero-memberships guard with a token-presence or explicit opt-out check | Without the fix, non-member repliers get no mobile push (silent) | Email chat notifications (use `chat_roster`) | Safe push to non-member repliers without fixing the V1-era guard |
| Session/auth/search/profile | Responses naturally correct with no `rippled=1` rows; search scopes to genuine groups | Poster's `groupids=0` search no longer spans receiving groups | Mod-role queries (role-filtered, never saw rippled rows) | Enumerating a poster's reach groups via a membership query |
| Mod visibility/audit | Poster no longer in receiving group's member list; no auto-join log for new traffic | "Which users were auto-joined here" audit lost for future events | Rippled-in banner, delete-as-spam suppression (arrival-timestamp based) | Single-query audit of system-created memberships |
| Reach engine/governor | Remove the membership add, rejoin guard, pull-on-leave, retraction tail | Rejoin guard inert on day 1 (re-ripple after opt-out not blocked) | Routing, hazard schedule, cross-group insertion, self-tuning | Re-entry suppression using the `logs.text='Rippled'` signal |

---

## What stays the same (headline)

- The browse nearby feed - the default path for most users - is already spatial.
  Non-members see rippled posts with zero code change.
- The entire reach engine and governor: routing, hazard schedule, audience cap,
  reply-saturation stop, extent control, self-tuning. None of it ever read
  memberships.
- Cross-group insertion (`messages_groups.rippled_in=1`) - the core of rippling -
  is unchanged.
- The held-reply / reach-enforcement system end to end.
- All nine reach-experiment KPIs and the reply-attribution capture.
- Moderation: the 1-hour veto window, AutoApprove fast-track for rippled-in posts,
  Pending/Spam queues, the Edit-queue filter, and the mod rippled-in banner.
- Email chat notifications between two parties (they use `chat_roster`, not
  memberships).
- Organic members of receiving groups keep receiving digest and reach-mail through
  their own memberships, fully unaffected.

## What gets simpler or more accurate

- **Stats accuracy auto-corrects.** Three inflated member-count stats become
  accurate on the first batch run after deployment, with no code change.
- **GDPR footprint shrinks.** Posting one item no longer enrols you in every
  reached group, and no longer exposes your data to those groups' moderators.
- **Retraction simplifies** to a single soft-delete plus a log write.
- **Spam stack cleans up** and the threshold can return to full strength.
- **Moderator member lists reflect only people who chose to join** - rippled
  posters are no longer added to the receiving group's member list.
- **The poster's own feed stays local** - no distant content dragged in.
- **Reach KPIs get cleaner**: `was_home_member=0` unambiguously means "not a
  voluntary member", with no 300-second grace-window hack to mask a silent
  auto-join.

## What we would be unable to do (or only do much worse)

1. **Poster self-service opt-out from one receiving group.** Today the poster can
   leave the group and pull-on-leave retracts the copy. With no membership there is
   nothing to leave. Needs a new "remove my post from this community" affordance
   backed by a `rippling_optout` table.
2. **Re-entry suppression after opt-out.** The rejoin guard reads
   `logs.text='Rippled'`, which is only ever written by the auto-join. It goes
   permanently inert. If "never re-ripple after the poster opts out" is a
   requirement, it needs a replacement signal designed atomically with the removal.
3. **Zero-friction in-app reply for non-members.** An explicit join step is now
   required.
4. **Poster receiving the full digest of receiving-group activity.** Replaced by a
   targeted reach notification, which is what the poster actually wants anyway.
5. **Per-group email frequency for receiving groups** (no membership row to hold
   the preference).
6. **Push to non-member repliers** until `filterPushRecipients` is fixed.
7. **Single-query audit of system-created memberships** for future events (use
   `messages_groups.rippled_in=1` instead).

---

## What this means for moderators

This is the sharpest practical difference, and it is worth stating plainly.

**Today (current rippling), a rippled-in post is a genuine member's post.** The
auto-join creates a real `memberships` row (`role=Member`), identical to any other
member. From a moderator's point of view there is **no difference at all** between
a rippled-in post and any other post on their group, and the fact that the poster
did not expect the join is irrelevant to the mod. They can do everything they
normally do.

**Under the alternative, the rippled-in poster is a non-member of the receiving
group.** That introduces a real, specific difference for receiving-group mods.

Still possible (these act on the post, so they are unchanged):

- Approve or reject the copy in their pending queue, with the 1-hour auto-approve
  window applying exactly as today.
- Hold, delete, or delete-as-spam their group's copy.
- Add a mod comment/note on the post.
- Reply to or message the poster (chat needs no membership).
- Report the post or poster to Freegle and the spam systems.

No longer possible (these need a `memberships` row on their group, which a
non-member does not have):

- Put the poster on Review/Moderated so their future posts are held
  (`ourPostingStatus` lives on the membership row).
- Ban or remove the poster from the group.
- See the poster in the member list or member search, or open their membership
  record and history.
- Add a member note about them.

Same under both designs: editing a rippled-in post works, but the edit propagates
to every group's copy (one shared message row), so a receiving-group mod still
cannot make a local-only edit.

So the mod-facing cost of the alternative is concrete: a receiving-group mod keeps
the post-level controls but loses the member-level ones, above all the ability to
put a problem poster on Review for their group. This is also the reason Edward gave
in the thread (#280, #285) for not removing auto-join: mods expect posts to come
from members.

---

## The Discourse 9808 objections

The 292-post thread splits cleanly by root cause. This is the central political
fact:

> **Jax (#279):** "Turn auto-join off for offerers and wanteds and I think you will
> have solved a lot of the London issues for members." **Edward (#280, #285):**
> agrees it would be better from a pure member perspective, but declined it because
> "historically mods have assumed that posts always come from members" and warned
> "all hell (more hell) would break loose".

So the alternative under analysis is exactly the proposal made in the thread, and
the reason it was not taken is a mod-expectations problem, not a technical one.

| Objection (theme) | Root cause | Under the alternative | Why |
|---|---|---|---|
| Poster auto-joined to 8-32 groups without consent (loudest complaint) | Auto-join | **Solved** | No `rippled=1` rows are ever written |
| Poster's own browse feed fills with distant content | Auto-join | **Solved** | mygroups join only returns genuine memberships |
| Ripple too far too fast, especially London | Reach itself | **Unchanged** | Same reach polygon, same speed, same groups |
| 1-hour mod veto window feels like lost control | Reach itself | **Unchanged** | AutoApprove keys on `rippled_in=1`, not membership |
| Posts from lax neighbouring groups breaking local rules; shared edits propagate | Reach itself | **Unchanged** | Cross-group insertion and single-row edit model are identical |
| Crow-flies distance misleads about real travel | Reach itself | **Unchanged** | Display-layer concern, independent of memberships |
| Stale / heavily-replied posts still rippling | Reach itself | **Unchanged** | Reply-saturation logic is independent of auto-join |
| Rural / transport-poor members bypassed by isochrone | Reach itself | **Unchanged** | Routing and deprivation modifier, not membership |
| Volunteer identity / group purpose undermined | Both | **Mixed** | Location-scoped delivery still erodes the group unit, but auto-joined posters vanish from member lists, partly restoring "membership = chosen community" |
| TN duplicate posts appearing several times | Reach itself | **Unchanged** | Dedup works on `rippled_in`, not memberships |
| Cannot accelerate urgent/perishable items | Reach itself | **Unchanged** | Schedule and posting-flow concern |

Net: the alternative **solves the two loudest member complaints outright**
(non-consensual auto-join, and the polluted personal feed), **partly eases**
volunteer-morale concerns, and **changes nothing** about the larger set of
reach/speed/moderation objections, which need separate work on the reach
algorithm, the veto window, distance display, and the schedule.

## New concerns the alternative creates (none appear in the thread)

1. **Every rippled-in post now visibly comes from a non-member.** This is the one
   Edward flagged as his reason for not doing it (#280, #285). Mods opening a
   post's author will find no membership in their group. Qualitatively new versus
   the rare TN-from-non-member case today. This is a change-management and
   mod-communication consideration more than a code one, and it is the main
   obstacle the thread identified.
2. **Poster gets no notification of spread** unless the replacement notification
   ships in the same change. The thread shows posters valued knowing (the problem
   in #111 was ordering and content of the email, not its existence).
3. **Reply friction on the email deep-link path.** A non-member following a
   `?reply=1` link types a reply, hits Send, and only then is told to join. If the
   join triggers an OAuth redirect and the in-progress text is not serialised, they
   lose what they typed.
4. **Silent push gap** for non-member repliers with no other memberships
   (`filterPushRecipients` drops them). Invisible in tests because test users
   always have a membership.
5. **Spam detection weakens** if the `checkSeenOnManyGroups` threshold is left at
   35 after the `rippled=1` inflation is gone. The code comment says 35 is "only
   safe once rippling is live".
6. **Per-group poster retraction disappears** with no replacement (same gap as
   "unable to do" #1).

---

## Prerequisites before this could ship

1. **Fix `PushNotificationService::filterPushRecipients`** so non-member repliers
   are not silently dropped from mobile push. Check `users_push_notifications` for
   an active token, or add an explicit opt-out flag, rather than using
   zero-memberships as the proxy.
2. **Revert the `checkSeenOnManyGroups` threshold** from 35 to 16 (or an audited
   value), after checking how many real users have voluntarily joined 17-35 groups,
   to avoid a false-positive spike.
3. **Build the replacement reach notification** atomically with removing
   `RippleIntroMail`, so the poster is never left with zero awareness of spread.
4. **Decide and build the "Join to reply" flow**, including serialising in-progress
   reply text across the OAuth redirect from the `?reply=1` deep-link.
5. **Clean up any historic `rippled=1` membership rows** left from test toggles of
   `RIPPLE_ENABLED` (a one-off `DELETE FROM memberships WHERE rippled=1`).
6. **Plan mod communication** for "posts in your queue can come from non-members",
   since this is the one concern the thread explicitly predicted would cause
   trouble.

Independent of the design choice, add `WHERE mg.rippled_in=0` to
`RippleTuneService.groupPostVolumes()` (`php:211-225`); it currently inflates
per-group volume and could trip the hotspot detector for the wrong reason.

---

## Summary of the trade-off

This document is an analysis, not a recommendation. The choice between the two
designs is a product and change-management decision rather than a purely technical
one. The three sides of the trade-off:

- **What gets simpler or more accurate:** three inflated member-count stats
  self-correct, the GDPR footprint shrinks, and retraction, spam, and welcome logic
  simplify.
- **What is lost:** per-group poster opt-out and the re-entry guard (both keyed on
  `logs.text='Rippled'`), the poster's awareness of spread via the receiving-group
  digest, and zero-friction non-member reply. Each could be replaced without
  reinstating auto-join: a "your post reached N communities" notification fired from
  the `messages_groups` insert; a `rippling_optout` table keyed on
  `(userid, groupid)` plus a "remove my post from this community" control; and an
  explicit "Join to reply" affordance.
- **What does not change:** the reach algorithm, speed, and extent; the 1-hour mod
  veto; cross-group content crossing into other groups' queues; and the larger set
  of thread objections about reach itself.

The main non-technical consideration the thread surfaces is the moderator
expectation that posts come from members (#280, #285); under the alternative, every
rippled-in post in a receiving group's queue would visibly come from a non-member.
