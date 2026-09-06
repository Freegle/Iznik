# Reach mail: change feeds on both sides

**Date:** 2026-09-06
**Status:** DESIGN. Nothing implemented.
**Supersedes:** proposal A in `plans/2026-09-02-db2-cpu-reduction.md`, which shortens the
post-side window instead.

## The problem

`UnifiedDigestService::sendReachDigests` picks the posts to work on with a time window:

```php
$windowMinutes = (int) config('freegle.ripple.reach_mail_window_minutes', 60);
DB::table('rippling_reach')
    ->whereIn('status', ['expanding', 'done'])
    ->where('updated_at', '>=', now()->subMinutes($windowMinutes));
```

Four shards run this every minute. It is the largest single consumer on db2, at 47% of the node
at peak and 68% off peak, holding 2.6 to 3.0 threads continuously. About 95% of its executions
re-examine posts nothing has changed about.

The window is doing two unrelated jobs at once, which is why it cannot be tuned:

1. **A scan bound.** How far back to look for posts whose reach moved.
2. **A grace period.** How long a post stays open to a member who becomes eligible *after* its
   reach settled. Once a post reaches `done` its `updated_at` freezes, and the post leaves the
   window for good N minutes later. Nothing else revisits it.

Shortening the window improves (1) and damages (2). The db2 study measured (1) carefully and
described the change as a delivery delay, which it is not: a member who becomes eligible 30
minutes after a post settles is mailed under a 60 minute window and is never mailed under a 5
minute one.

## Why the study concluded the member side was impossible

It looked for a member-side signal in tables and found none good enough:

| signal | verdict |
|---|---|
| `memberships.added` | real time, usable |
| `users_approxlocs.timestamp` | daily only, written by a `dailyAt('04:45')` job |
| `users.lastupdated` | no `ON UPDATE`, set only in user-merge flows |
| returning after 90 days idle | no signal at all |

Two of the three eligibility routes would regress, so it fell back to shortening the window.

The signals do not exist in the tables. They do exist in the **codepaths**, which we own.

## Design

Split the two jobs the window was doing.

### Post side: a watermark, not a window

Replace the window with a per-shard high water mark on `rippling_reach.updated_at`, which is
already indexed (`2026_08_13_000001_add_rippling_reach_updated_at_index`). The four shards
partition on `MOD(msgid, 4)` and are disjoint, so each carries its own mark with no coordination.

Take `updated_at >= mark`, then store **the time the pass started**, not the highest `updated_at`
seen. Timestamps are second granular, so several rows can share a second and storing max-seen
would drop any that landed in the same second after the read. Pass-start guarantees that anything
touched during the pass is `>= mark` next tick. The overlap this leaves is harmless: the
`rippling_reach_notified` ledger already dedupes, which is what makes today's window overlap safe.

| | window 60 | window 5 | watermark |
|---|---|---|---|
| posts per pass, steady state | 435 to 754 | about 40 | about 8 |
| after a three hour stall | loses everything older than 60 min | loses everything older than 5 min | loses nothing |
| constant to tune | yes | yes | none |

The watermark provides no grace period, and none is needed. The window's afterglow was only ever a
crude stand-in for the member side, a timer hoping the member would change soon after the post
did. With a real member feed it is redundant, not lost.

### Post-side conditions that flip without the polygon moving

The recipient test in `mailNewlyReachedForPost` has post-side terms that can change without the
reach geometry changing. Each was traced:

| term | what happens | covered? |
|---|---|---|
| `mg.collection = 'Approved'` | approval calls `addApprovedMessageToSpatialIndex` (`message.go:2616`), the post enters `messages_spatial`, and `initialiseNew` creates its reach row. That is an `updated_at` write, so the watermark fires. | yes |
| `mr.status <> 'held'` | `held` is terminal by design. `FreezeReachIfOriginPending` says so: *"initialiseNew's anti-join can never re-reach + re-notify it if a moderator later re-approves a copy."* No unfreeze path exists in Go or PHP. | yes, deliberately never |
| `NOT EXISTS (outcome)` | a repost clears the outcome rows: `handleRejectToDraft` (`message.go:3532`) and `JoinAndPostAs` (`message.go:3767`). Whether reach is signalled depends on the outcome. **Withdrawn** leaves `messages_spatial`, the retract path drops the reach row, and the repost re-enters through `initialiseNew` like a first approval. **Taken and Received stay in the index** (`MessageSpatialService.php:130`), so the reach row survives with its old `updated_at` and nothing signals. | **Withdrawn yes; Taken/Received no.** The 60 minute window misses it too unless the repost lands inside the window. Closed below. |

### Fifth signal: repost of a Taken or Received post

The repost path, `JoinAndPostAs`, bumps the post's reach row when one exists. `handleRejectToDraft`
also clears outcomes, but the post it leaves is a draft, not live, so signalling reach there would
only make the pass examine an unmailable post; the moment that matters is when the draft is posted
again.

```sql
UPDATE rippling_reach SET updated_at = NOW() WHERE msgid = ?
```

A no-op when the row was already dropped (the Withdrawn case), so it is safe to run
unconditionally in both handlers. The watermark then picks the post up and
`mailNewlyReachedForPost` re-evaluates it. Everyone mailed in the post's first life is in the
ledger and is not mailed again. The members this reaches are those who became eligible while the
post carried an outcome: their queue row was drained against a post that was not mailable at the
time, so nothing else revisits them.

Bumping `updated_at` here matches its existing meaning. `ReachBoundsService.php:411` deliberately
preserves it on a bounds reset, and the only readers treat it as "this post is newly mailable",
which a repost is.

So relative to today nothing is lost, and the repost route is covered for the first time.

### Member side: a queue written from the codepaths

```sql
CREATE TABLE rippling_reach_member_pending (
  id     BIGINT AUTO_INCREMENT PRIMARY KEY,
  userid BIGINT NOT NULL,
  reason ENUM('joined','moved','returned','frequency') NOT NULL,
  added  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY userid (userid)
);
```

Upserted on `userid`, so repeated signals for one member collapse to a single row. Expected volume
is 1 to 2 rows a minute, drained every minute, so the table stays near empty.

Rejected alternatives:

- **`logs`**, the pattern the leave-check uses. It is 42.6M rows, the leave-check already needs a
  watermark to survive driving from it, and `purge:logs` would delete unprocessed work.
- **A column on `users`.** An extra index on a 2.85M row table, and every write costs all three
  Galera nodes. That is the write pressure proposal D exists to relieve.

### The four hooks

| route | file | trigger |
|---|---|---|
| returns after 90 days | `iznik-server-go/user/authMiddleware.go:113` | the old `Lastaccess` is already in hand there, behind a 10 minute SQL guard. Write only on the transition from older than 90 days, so at most once per return. |
| moves or sets a postcode | `iznik-server-go/user/user.go`, `ProcessSettingsUpdate` | beside the existing `PostcodeChange` log |
| joins a group | `iznik-server-go/membership/membership.go:1364` and `:1425`, `iznik-server-go/user/user.go:2166`, ripple auto-join in `ExpandService`, `AddMembershipCommand.php` | on membership create |
| switches to immediate mail | `memberships.emailfrequency` update paths | on change to immediate |

### The drain

Runs in the reach mail job beside the post-side pass. For each pending member:

1. Find live posts whose stored reach outer bound covers the member's point. This is a bounding
   box test, already indexed, and is the same narrowing `mailNewlyReachedForPost` does today in
   the other direction.
2. Call the **existing** `mailNewlyReachedForPost($msgid)` for each candidate.
3. Delete the queue row.

Step 2 reuses the post-side function rather than adding a member-to-posts containment query. There
is then one implementation of "is this point inside this post's reach", not two that can drift.
The ledger stops any other member being mailed twice by the extra call.

Volume: 1 to 2 members a minute, each with a handful of candidate posts, against the 225 post
sweeps a minute this replaces.

### Reconciliation backstop

A daily pass queues any member with `memberships.added` since yesterday, or a `PostcodeChange` log
since yesterday, who has no ledger row for a post now covering them.

This matters more with a watermark than it would with a window. A window leaves a few minutes of
accidental cover for a hook that is missing or wrong; a watermark leaves none, so a missed hook
means those members are never picked up and nothing reports it. One indexed query a day, off the
per minute path, covering the two highest volume routes.

## Testing

- Post side: the pass is bounded by a mark, the mark is the pass start rather than max-seen, a
  post updated during a pass is picked up by the next one, and a stall loses nothing.
- Member side: each hook writes exactly one row, the returns-after-90-days hook fires only on the
  transition, repeated signals collapse to one row.
- Drain: a queued member is mailed about a post that already existed and whose reach did not
  change, and is not mailed twice.
- Reconciliation: a member whose hook did not fire is still picked up within a day.
- Repost: a member who joined while a post was Taken is mailed when it is reposted, and a member
  mailed in the post's first life is not mailed again.

## Risks

These are implementation risks. The design itself is complete relative to today.

- **A missed or wrong hook is silent.** The backstop covers joins and moves. It does not cover the
  returns-after-90-days route, which has no table evidence to reconcile against. That route is
  the one with the least safety net and needs its test to be convincing.
- **Candidate post count per member is unmeasured.** The bounding box narrowing should leave tens,
  not thousands, but it should be measured on production before the window is removed.
- **The repost bump is in Go, the drain is in PHP.** Two codebases share the meaning of
  `updated_at`; the test for the repost route has to run end to end, not per side.
