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

## The distance cap belongs to the recipient

Size a cap from the **recipient's** local density, not the post's origin. Sizing it at the
origin permanently blocks rural members from posts in the nearest town they already drive to.

## See also

- `.claude/rules/tests-and-ci.md` - a reach row inserted in a test needs its bounding geometry.
- `docs/moderators/rippling-out.md` and `docs/members/rippling-out.md` - what we tell people.
