---
paths:
  - "iznik-batch/app/Services/Ripple/**"
  - "iznik-batch/app/Console/Commands/Ripple/**"
  - "iznik-server-go/rippling/**"
  - "iznik-batch/app/Services/ContentCheckService.php"
  - "iznik-batch/app/Services/AutoApproveService.php"
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

## Back to pending pulls every copy, and automation must not put them back

A moderator's Back to pending on any one group pulls the post back to Pending on **every**
group it is on, the home copy included (`handleBackToPending`, `SendForReviewAllGroups`). Only
the acting moderator's copy is held. Every copy pulled back is marked
`messages_groups.needs_moderator`, and only a moderator's Approve clears it. The content check
and auto-approve both skip a flagged copy.

Before that flag existed, the content check re-approved every unheld copy a minute after the
next edit, with no log entry, so a moderator's decision was quietly undone everywhere but on
their own group (122011064, 121985402). Any new automatic approval path must honour the flag.

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

## See also

- `.claude/rules/tests-and-ci.md` - a reach row inserted in a test needs its bounding geometry.
- `docs/moderators/rippling-out.md` and `docs/members/rippling-out.md` - what we tell people.
