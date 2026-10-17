---
paths:
  - "iznik-batch/app/Services/Ripple/**"
  - "iznik-batch/app/Console/Commands/Ripple/**"
  - "iznik-server-go/rippling/**"
  - "iznik-batch/app/Services/ContentCheckService.php"
  - "iznik-batch/app/Services/AutoApproveService.php"
  - "iznik-server-go/microvolunteering/microvolunteering.go"
  - "iznik-server-go/message/message.go"
---

# Traps when working on rippling

How rippling is meant to work is in
[`docs/developers/reference/rippling-algorithm.md`](../../docs/developers/reference/rippling-algorithm.md).
This page is only the things that have caught people out.

## One post, many rows: `SELECT DISTINCT` does not save you

A rippled post is **one `messages` row** with **one `messages_groups` row per receiving
group**. Any query that joins `messages_groups` returns one row per group unless it dedupes.

`SELECT DISTINCT` reads as "this query is deduped" and makes an audit skip it. It is not.
DISTINCT drops only rows identical in **every** selected column, so as soon as a per-group
column such as the arrival time is in the select list, nothing is dropped:

```sql
SELECT DISTINCT m.id, m.subject, mg.arrival   -- mg.arrival differs per group, so DISTINCT does nothing
INNER JOIN messages_groups mg ON mg.msgid = m.id
```

This is how a member's posting history showed the same offer 23 times: it had rippled to 23
groups. An audit that trusted DISTINCT then declared one list "the only remaining example" and
was wrong, because the replies modal had the same shape behind a DISTINCT.

Dedupe with `GROUP BY m.id`, or filter to origin rows with `AND mg.rippled_in = 0`, which is the
established pattern across the Go code. `COUNT(*)` over such a join is wrong for the same
reason; use `COUNT(DISTINCT messages.id)`.

## Deleting is per group, editing is global

Per-group moderation state lives in the per-group row, so deleting or rejecting a rippled post
removes **only that group's** row. The shared `messages.deleted` stamp is set as a cascade only
once no undeleted group rows remain anywhere.

Editing is the opposite: an edit mutates the single shared `messages` row, so it appears on
every group at once. That is a genuinely different mechanism rather than an inconsistency, and
it is worth saying plainly to moderators who notice the asymmetry.

## Back to pending pulls every unreviewed copy, and automation must not put them back

A moderator's Back to pending on any one group pulls the post back to Pending on every group
it is on, the home copy included (`handleBackToPending`, `pullBackToPending`,
`SendForReviewGroupsExcept`), **except** a copy on another community whose own moderator
reviewed it by hand. That copy stays Approved with its `approvedby`/`approvedat`, gets no log,
and is not withdrawn by a home send-back. The acting moderator's own copies always go back.
Only the acting moderator's copy is held. Every copy pulled back is marked
`messages_groups.needs_moderator`, and only a moderator's Approve clears it. The content check
and both auto-approve crons skip a flagged copy.

The Check queue's Reject (`MarkChecked` with `reject`) is the same pull-back on the post's own
community, through `pullBackToPending`, but it is a second look and not the home community's
decision, so it passes `homeDecision = false`. That is the difference that matters. A home Back
to pending (`homeDecision = true`) withdraws every unreviewed rippled copy (soft-deleted, a
Message/Deleted log written against the poster for each, the poster's ripple-join membership
removed) and bars the post from rippling for good (`rippling_blocked`). A Check reject does none
of it: the unreviewed copies on other communities go back to their own Pending queues, held by
nobody, in the neutral `backToPendingReason` wording, and a copy a neighbour's moderator reviewed
by hand stays live. Only the acting community's own copy and its Hold log say "Check"; a
community outside the trial has no Check queue and is never told about one.

The split is load-bearing for the automated review. Its `recent_action` fact holds a member's
next posts for 90 days after any Message/Deleted, Rejected or Replied log written by someone
other than the member, so a withdraw log written for a reject the moderator then reverses would
penalise the poster for a post the moderator approved. Only the moderator's own later Reject
should do that.

Either way the reach goes `held`, not `stopped` (`stopped` is what the engine's orphan
retraction acts on, and it exempts only `held`). A Check reject bars nothing, so a moderator's
later Approve of the home copy resumes the reach (next section).

What counts as "in the Check queue" is `utils.CheckQueueJoins` plus `utils.CheckQueueConditions`
and nothing else. The list, the menu badge, Mark all as checked, marking particular posts and the
Check reject are all built from them. They were once four hand-written copies, and they drifted:
the bucket stamped posts of deleted members that the list hid, marking by id stamped a copy a
moderator had approved, and a post fell out of the list and the badge when its poster left the
community, which for a banned poster is the post a moderator most wants to see. A new query about
the Check queue uses those two pieces, and a change to what "in Check" means is made there, once.

"Reviewed by hand" is `messages_groups.approvedby IS NOT NULL OR checkedby IS NOT NULL`
(`moderatorApprovedElsewhere`). `approvedby` only holds because `handleApprove` is the one path
that sets it: the content check and auto-approve write NULL and the ripple insert leaves it out.
`checkedby` is a moderator marking a self-published post checked in the Check queue; two
microvolunteers' approvals stamp `checkedat` alone, and that is not a review. A new automatic
approval path that stamps either would make its copies survive every later Back to pending. A
report quorum (`SendForReviewAllGroups`) is not a moderator's review and still pulls every copy.

`checkedby` is also stamped when a moderator **approves** the same post on another community they
moderate (`stampOwnSelfPublishedCopies`): the copy of it that published itself on one of their
other trial communities has been looked at. Only an Approve. The earned-reach gate lifts as soon
as any copy of the post is approved or checked (`ExpandService::modLooked`), so stamping on a
Reject or Delete would release a post the moderator has just turned down, and take it out of the
Check queue where somebody still has to decide about it.

Before that flag existed, the content check re-approved every unheld copy a minute after the
next edit, with no log entry, so a moderator's decision was quietly undone everywhere but on
their own group (122011064, 121985402). Any new automatic approval path must honour the flag.

## A pull-back freezes the reach, and approving the home copy resumes it

A pull-back sets `rippling_reach.status` to `held` with nothing scheduled: `FreezeReachIfOriginPending`
once the post's home copy is no longer live (a moderator's Back to pending, a members' report quorum,
a moderator's report), and `FreezeReach` outright for a home community's Back to pending. A
moderator's **Approve of a home copy** (`handleApprove`, `rippled_in = 0`, any community the post was
posted to) then calls `ResumeReachIfHeld`: `held` becomes `expanding`, due now. The engine's next run
catches the reach up to the tick the clock says the post is at, counted from its arrival, so the
time it spent in review is not given back and a post held for hours takes several steps at once.
Nobody already told is told again (`rippling_reach_notified`), and `updated_at` is left for the
engine to bump, because it is the reach mailer's signal.

What does not resume it, silently:

- **A home community's Back to pending.** It writes `rippling_blocked` as well: the post must never
  ripple again, and `ResumeReachIfHeld` refuses a blocked post. The same row is what stops a repost,
  which deletes the reach row, starting a new reach.
- **Approving a rippled-in copy alone.** That says nothing about the post's own community.
- **A reach that is not `held`.** `expanding` keeps its schedule; `done` and `stopped` are left
  alone. A frozen reach has lost which of those it was, and the engine returns a finished one to
  `done` the next time it looks.
- **A post with no live home copy.** The check is in the same UPDATE as the write, because the
  engine retracts the rippled copies of an `expanding` reach whose origin is not live and deletes
  its reach row (`retractCopiesOrphanedByOriginRemoval`), which is what the freeze exists to prevent.

A new way of approving a home copy, or of undoing a pull-back, has to call `ResumeReachIfHeld`
itself. Nothing else will: the post stays `held` for the rest of its life, with no error and no log
line, and members stop being told about it.

## Nothing ripples unless its home copy is approved

`messages_spatial` keeps a post for up to five minutes after it is moved back to Pending, and
the freeze (`FreezeReachIfOriginPending`) does nothing to a post whose reach has not been
created yet. On 121999685 a Back to pending 12 seconds after approval was followed by approved
copies on 13 neighbouring groups, deleted again a minute later. So `initialiseNew` and
`rippleIntoNewGroups` check the home copy itself, and `advanceDue` never writes a status over a
freeze made while it was running.

A copy that has been removed, by a moderator or by retraction, leaves a row behind, and that
row stops the post rippling into the group again even when it is re-approved. That is
deliberate.

## A missing reach row means retention, not failure

The reach table is **transient**. Rows are deleted when a post is removed, when a community opts
out, and when a post is orphaned, and older rows are pruned. "Most posts have no reach row" is
therefore a statement about how long rows are kept, and any census that counts a missing row as
a reach failure will be wrong.

## The blocked-reply metric counts impressions, not people

The `reply_blocked` event is emitted on the **read** path, once per out-of-reach post in each
fetched batch, with no deduplication by person or post, and it is re-counted on every refetch.
It measures "the reply button was withheld on a post view".

It runs around two orders of magnitude above real reply volume, so it is meaningless as a
"people affected" figure and must never be quoted as one. The number that does mean that is the
refusal returned by the send endpoint, which is tiny, because the read path hides the button
before anyone gets there.

## Two silent routes to "no limit"

Both of these stored the sentinel meaning no distance limit, for members who had asked for the
opposite:

- A lookup returned **before** its routing call when no curated town fell inside the candidate
  box, so the narrow end of the distance control saved "no limit" instead of a small radius.
- A one-shot widening migration was re-applied on **every** monthly run, walking members up to
  the sentinel a step at a time. A migration that runs on a schedule has to be able to tell
  whether it has already run.

## Rippled-in copies are not the receiving community's own posts

They arrive already approved, which has repeatedly meant they skip things that apply to a
community's own posts: the receiving community's rules were not checked, and its moderators
could contact the poster when only one action had been suppressed. When you add anything that
acts on a post in a community, decide explicitly what it does to a copy that rippled in.

For the same reason, statistics that count local activity must exclude memberships rippling
created itself, or auto-joins get counted as pre-existing local members.

## Writing to the poster and turning the post away are different questions

`NotifyPosterFlag(home, gid) == 0` is read twice in the reject and delete handlers: once for
whether the poster is told, and once for whether this is a receiving community turning the
post away, which clips its reach so it cannot ripple back. A rippled-in community may now
write to a poster who is an ordinary member there (`PosterMessagingGroups`), but its copy is
still not a home copy. Feed only the notify flags from `PosterMessagingGroups`; leave the
turned-away check on `HomeGroups`, or a rejection there lets the post ripple straight back.

The delete handler removes the rows both are read from, so read them before the delete.

## A cleared ripple flag must stay cleared

`memberships.rippled` is cleared once the member joins, moves in, or deals with the community,
and each clear writes `Ripple-created membership became ordinary: <reason>` to the log. Any
job that sets the flag from the original `Rippled` join log, as `ripple:backfill-memberships`
does, has to skip memberships with a later clear, or it silently makes those members
uncontactable again.

## A held reply released to somebody who never got the post

Replies held while a post spreads can be released long after, and most of them go to people the
post never reached. Releasing them all at once on a post already marked taken dumps them on the
offerer. Anything that releases in bulk needs to check the post is still open and the recipient
is still relevant.

## The reach partition is not reproducible

The partition builder collects edges into a map and then iterates it, and Go randomises map
iteration order, so leaf assignment differs run to run. Every rebuild therefore invalidates
stored reach labels. Do not assume two builds of the same input agree.

Related: stored reach blobs omit one field that the in-memory labels carry, so a stored reach
behaves slightly differently near the origin from a freshly computed one.

## Budgets are in minutes, and the clock was recalibrated

Reaches sized before the travel-time recalibration kept their **minute** budget but are now
evaluated against the slower calibrated clock, so an old reach covers less ground than it did.
When comparing reaches across that date, you are not comparing like with like.

## The distance cap belongs to the recipient

Size a cap from the **recipient's** local density, not the post's origin. Sizing it at the
origin permanently blocks rural members from posts in the nearest town they already drive to.

## The batch and the Go API read the post-moderation settings by one rule

docker-compose hands both the same raw strings (`${VAR:-}`, so unset arrives as the empty string).
The batch reads the master switch, the wait, the community lists and the automod timeout through
`App\Support\EnvSetting`; the Go API reads the same variables through `utils.EnvSwitch` (on only for
`true` or `1`, any case, trimmed), `utils.EnvPositiveInt` (a whole number of at most nine digits,
else the default) and `utils.EnvGroupIDs` (plain ASCII digits only, zero is no community). The two
test files hold the same cases (`iznik-batch/tests/Unit/Support/EnvSettingTest.php` and
`iznik-server-go/utils/envsetting_test.go`); a case added to one belongs in the other.

A value one side calls on and the other calls off is silent. Either posts publish themselves with
no countdown, Check queue or badge to say so, or a countdown shows for a post nothing will publish.
So do not read one of these variables with `os.Getenv(...) == "true"`, `(bool) env()` or `(int) env()`.

`RIPPLE_EARNED_REACH_ENABLED` follows the same rule: `config/freegle.php` reads it through
`EnvSetting::flag` and `microvolunteering.go` through `utils.EnvSwitch`. A `(bool) env()` read of
any of these is a bug, not a second rule: PHP's own cast reads "off" and "no" as on.

## The countdown mirrors the cron, condition by condition

The countdown on a Pending card (`message/autoapproveat.go`: `computeAutoapproveat` and
`automodApproved`) is a promise about when `AutoApproveCleanService` will publish the copy, so it
carries the cron's own conditions, in step with `currentApprove` and the candidate query: the chart's
decision recorded in **approve** mode (a shadow decision never counts down), **strictly** later than
the content check and the last edit (equal to the second is not later), the poster's posting status
still NULL, no hold on the post or the copy, the poster not deleted, not a quality sample, not pulled
back, no spam reason, and the wait counted from the later of arrival and the decision, with
`autoapprove_hold_until` as a floor. A condition added to the cron needs the same here, and the other
way round, or a moderator is promised a publish that never comes, or gets none shown for one that does.
That includes the lockdown: both crons skip a post with an open `lockdown_holds` row and do nothing at
all while `posts` is held, so there is no countdown for either (`lockdown.ItemHeld`, `lockdown.Held`).

Opening the Pending queue holds every Pending copy it can show on the trial communities in the request
for at least ten more minutes (`autoapprove_hold_until`, extend-only), not just the page fetched, so
nothing publishes under a moderator who has the queue open.

## See also

- `.claude/rules/tests-and-ci.md` - a reach row inserted in a test needs its bounding geometry.
- `docs/moderators/rippling-out.md` and `docs/members/rippling-out.md` - what we tell people.
