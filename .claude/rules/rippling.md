---
paths:
  - "iznik-batch/app/Services/Ripple/**"
  - "iznik-batch/app/Console/Commands/Ripple/**"
  - "iznik-server-go/rippling/**"
---

# Traps when working on rippling

How rippling is meant to work is in
[`docs/developers/reference/rippling-algorithm.md`](../../docs/developers/reference/rippling-algorithm.md).
This page is only the things that have caught people out.

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

## There are no per-community rows any more

A post is one `messages` row with one moderation state, and the reach polygon is the only
spread mechanism. If you find yourself joining a per-community table, it was removed in
`2026_09_20_000001_remove_group_model.php`; the answer you want is on `messages` or on the
member.

## See also

- `.claude/rules/tests-and-ci.md` - a reach row inserted in a test needs its bounding geometry.
- `docs/members/rippling-out.md` - what we tell people.
