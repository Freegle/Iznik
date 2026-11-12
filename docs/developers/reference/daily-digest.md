---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/UnifiedDigestService.php
  - iznik-batch/app/Models/UserDigest.php
  - iznik-batch/database/migrations/2026_09_10_000001_add_carryover_to_users_digests.php
  - iznik-batch/database/migrations/2026_11_12_000001_add_held_back_to_users_digests.php
  - iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php
---

# The daily digest: which posts a member is sent

The daily digest is one roll-up email a day. `UnifiedDigestService::sendDigestToUser()` builds it
and `loadPostsForUser()` (wrapped by `getPostsForUser()`) chooses the posts. The daily-posts push
shares `getPostsForUser()`. Members on immediate mail are served by a different path, the
cursor digest and the reach mailer (see `rippling-algorithm.md`); this page is the daily path only.

## The cursor and three ways a post gets in

Each member has a `users_digests` row (`mode = 'daily'`). Its `lastmsgdate` is the cursor: the
newest post the last run examined. A run offers a member three groups of posts:

| Arm | Source | Query shape |
|---|---|---|
| Window | posts on the member's groups that arrived after the cursor, oldest first, at most `DIGEST_LOAD_CAP` | a range on the group index |
| Carryover | `users_digests.carryover`: posts a previous digest left out at the post cap | primary-key lookup |
| Held back | `users_digests.held_back`: posts the reach gate kept out of an earlier digest | primary-key lookup |

They are three queries on purpose. Putting the carryover inside the window query as an OR cost
60 to 74 seconds a member on production, because the planner could no longer use the group
index. Do not merge the arms.

## The reach gate

A post that has a `rippling_reach` row is sent to a member only when its reach covers them. A
frozen reach (`status = 'held'`, the post is under moderation) is never sent. Both come back from
the queries as one column, `reach_held_back`, not as a WHERE clause, and
`loadPostsForUser()` drops the flagged rows from the posts it returns.

The reach grows on a schedule of 1, 3, 6, 12, 24, 48, 72, 120 and 168 hours after the post
arrives, so at the moment the digest runs a post can reach a nearby member and not yet reach one
further out.

## Why the gate flag is a column

The cursor moves to the newest post examined. If the gate were a WHERE clause, a post it kept out
would sit behind the cursor on the next run, and a reach that later grew over the member would
never mail it. Measured on production on 2026-11-09 and 2026-11-10, for members who are on
immediate mail in one community and daily in another (so the reach mailer records exactly when the
reach covered them): of the posts whose reach reached the member after their daily digest ran,
about half were never sent, against about one in ten of the posts that the reach covered before.

With the flag as a column the rows come back, and the digest knows what the cursor is about to
pass.

## Held back, and the retention rule

When the cursor moves, every live post the gate held back that it passes is written to
`held_back` (newest `HELD_BACK_MAX` = 200). On the next run the held-back arm loads those ids and
applies the same gate:

- Now covered: it comes back unflagged and is sent like any other post. It sinks below the new
  posts (as carried posts do), and it leaves the list.
- Still not covered: it stays in the list.
- Gone (an outcome, deleted, no longer approved): it is not returned and leaves the list.
- Older than `HELD_BACK_MAX_AGE_DAYS` = 3 days from its arrival: the arm does not load it and the
  list drops it.

Three days is the retention rule. On production, about 93% of the members a reach covers are
covered within 7 hours, 99.7% within 72 hours, and the 120 and 168 hour steps add few. A post
three days old is not news. The bound also keeps the list, and so the cost of the primary-key
arm, small.

The list is replaced whole on every write, so an id cannot be sent twice: it is in the list only
while it has not been sent. A held-back post is not exempt from the "already covered by an older
copy" check (`dropCardsAlreadyCovered`), so a copy of an item that was sent on another post is not
sent again. A carried post is exempt, because nothing of it was sent.

## The cursor and the size of the window

The cursor goes to the newest row examined, held back or not, because those held back are in
`held_back`. Moving it over them is also what keeps the window bounded: if the cursor waited for a
post that passes the gate, a member whose newest posts were all out of reach would re-scan
the same days every run. `updateDigestTracker()` writes the cursor, `carryover` and `held_back`
together, and stamps `lastsent` only when a digest went out or posts were considered for one.

A run that examined only held-back posts moves the cursor and the list and sends nothing.

## Not covered here

The daily-posts push moves its own cursor (`mode = 'push'`) and does not keep a held-back list,
so a post the gate holds back from a member's push is not pushed later.
`test_a_reach_gated_post_is_sent_once_and_only_once_after_its_reach_grows` and the other held-back
tests in `UnifiedDigestServiceTest` pin the daily behaviour above.

## Deploy

`users_digests.held_back` is a nullable JSON column, added with an INSTANT `ALTER`. The raw SQL
is `2026_11_12_000001_add_held_back_to_users_digests_migration.sql`. Run it on production before
deploying the batch code, which writes the column.
