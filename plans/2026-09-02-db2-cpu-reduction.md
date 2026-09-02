# db2 CPU reduction — measured findings and proposed changes

Investigation only; **nothing here has been applied**. Measured 2026-09-02, 07:15-09:30 UTC.

Goal: halve db2's CPU.

## Baseline

| node | cores | mysqld lifetime CPU-min | load during window | role |
|---|---|---|---|---|
| db1 | 8 | 1,601 | 0.23 - 0.80 | **deliberately idle — leave alone** |
| db2 | 8 | **61,601** | 3.85 - 6.75, mysqld 244-800% | all batch reads |
| db3 | 12 | 20,130 | 5.51 - 12.04 | write node + all API traffic |

db2 is **99.8% batch traffic** (171,358 processlist samples over 5 min; apiv2 contributed 0.1%).

### db1 is not a lever

An earlier draft of this plan proposed adding db1 to the batch read pool as the single biggest
win. **That is ruled out — db1 is kept idle deliberately.** Everything below therefore has to come
out of reducing the work itself, not spreading it.

(The timings in this document were taken on db1 because it was quiet enough to measure against.
That was for measurement only; no traffic was moved. Future measurement should avoid it.)

### Measuring caveat

`events_statements_summary_by_digest` sees only ~18% of db2's work:
`statement/sql/select` = 917,300 s vs `statement/com/Execute` = **4,209,872 s**. Laravel's prepared
statements are not aggregated into the digest table. **All rankings below come from processlist
sampling, not from the digest table** — ranking from the digest table gives the wrong answer.

Also noted: `Com_stmt_prepare` ≈ `Com_stmt_execute` ≈ `Com_stmt_close` ≈ 1.374 billion — every
statement is prepared, executed once and closed. 139,513 s of that is pure parse/plan (~3%).

## Load accounting

Categorised sample, 61,080 active-thread observations over 90 s (08:58-09:00 UTC):

| item | share | proposal |
|---|---|---|
| reach recipient query | 47.0% | A |
| daily digest scan | 17.9% | C + D |
| illustrations cleanup | 6.3% | B |
| active-user scan | 4.1% | D |
| reach labels/cells | 1.9% | — |
| long tail (nothing above 3.9%) | 22.8% | — |

Concurrency: **20 digest workers** in 18 of 40 samples (mean ≈ 14, peak 20) against **8 cores**.

**Off-peak the reach query is even more dominant.** Second sample at 13:27 UTC, after the
07:00-12:00 BST daily-digest window closed (46,881 observations; db2 load 3.88, mysqld 200%,
8 workers):

| item | peak (08:58) | off-peak (13:27) |
|---|---|---|
| reach recipient query | 47.0% | **68.0%** |
| daily digest scan | 17.9% | — (window closed) |
| illustrations cleanup | 6.3% | 4.9% |
| active-user scan | 4.1% | 2.2% |

The reach recipient query is the only item that tops both regimes. **Proposal A alone clears the
halving target off-peak**, and is the largest single item at peak too.

## Findings

### 1. Reach recipient query — 47-51% of db2

`UnifiedDigestService.php:973`, driven by `mail:digest:unified --mode=reach`.

`sendReachDigests` (`UnifiedDigestService.php:637`) selects every `rippling_reach` row updated in
the last **60 minutes**; the cron fires **every minute** (`routes/console.php:824-828`).

| window | rows |
|---|---|
| 1 min | 10 |
| 3 min | 14 |
| 5 min | 27 |
| 15 min | 100 |
| 60 min | **435** |

Each pass re-processes 435 posts when ~10 changed. 8,224 posts sit in `expanding`, with
`next_expansion_at` spread ~3-9 per minute.

**Measured execution rate and duration.** Two samples of `information_schema.processlist`; the
second polled 7,840 times in 74 s (~106 polls/s) so concurrency is measured, not estimated:

| | 13:42 (119 s) | 13:57 (74 s) |
|---|---|---|
| distinct executions | 370 | 313 |
| throughput λ | 187/min | **254/min** |
| mean concurrency L | — | **2.99 in flight** |
| mean duration L/λ | — | **0.71 s** |

`processlist.time` corroborates the duration: 13,198 observations at 0 s, 9,643 at 1 s, 525 at 2 s,
99 at 3 s.

**The same query measured 65 ms on an idle node** (msgid 121725017, 4 KB polygon, 976 candidates).
Under load on db2 it averages 0.71 s — **~11× slower purely from contention**, so cutting the count
pays back more than proportionally: fewer queries means the survivors get faster too.

**Repeat rate measured directly**, not inferred. The samples above were shorter than one sweep, so
no msgid could repeat inside them. A 10-minute sample (599 s, 188,038 observations, executions
separated by a >5 s gap in the same msgid):

| | |
|---|---|
| distinct msgids | 757 |
| total executions | 2,238 |
| throughput | **224/min** |
| mean executions per msgid | **2.96 per 10 min** |

| repeats in 10 min | msgids |
|---|---|
| 1× | 78 |
| 2× | 75 |
| 3× | **406** |
| 4× | 198 |

Most posts are re-processed 3-4 times per 10 minutes — a sweep every ~2.5-3 min, so **~18
executions per post per hour** in the window. (An earlier draft inferred 26× from the window size
and throughput; the measured figure is 18×.)

**The cost is spread evenly across posts — there is no fat tail to trim.** From the same 10-minute
sample (observations ≈ DB time):

| slice | share of the query's DB time |
|---|---|
| top 1% of msgids (7) | 3.8% |
| top 10% (75) | 24.6% |
| top 25% (189) | 48.5% |
| top 50% (378) | 75.9% |

The heaviest single post is 0.62%; median 209 observations against a mean of 248. That is close to
uniform, and it rules out a whole class of fixes — there is no "cap the worst offenders",
no giant-group or giant-polygon outlier to special-case. Combined with the per-query rewrites
already tested and found ineffective, the *only* lever on this query is how often it runs.

Against ~10 posts genuinely changing per minute, that means **roughly 95% of executions re-do
unchanged work**. Proposal A takes ~224/min down to ~12/min — an **~18× reduction**, or in DB-time
terms **2.99 concurrent threads → ~0.2**.

**The waste is not empty results.** 20 sampled window msgids all returned candidates (1-37 each).
The SQL returns the **outer-bound superset**; PHP then refines against routing labels
(`UnifiedDigestService.php:1003-1010`). The anti-join excludes only users in
`rippling_reach_notified` — those actually *mailed*. Candidates inside the outer bound but outside
the true reach are never notified, never recorded, and so are re-fetched and re-refined every pass,
with a routing call per pass evaluating every candidate point. Posts 17-53 minutes into the window
still carry 18-37 such candidates.

Two hypotheses tested and **rejected**:

- Collapsing the 31 per-row `JSON_EXTRACT` calls to 7 via nested `NO_MERGE` derived tables:
  2× faster on one msgid (0.10 s → 0.05 s), **no difference** on another (0.07 s vs 0.07 s).
  Output verified identical both times. Not the general fix.
- `MBRContains` short-circuit before `ST_Contains`: **no effect** (0.066 s vs 0.068 s, identical
  rows). MySQL already does the MBR pre-check internally.

Individual queries are not pathological — live msgid 121725017 (4 KB polygon, 217 vertices,
976 candidates) runs in **65 ms**. The volume is the problem.

### 2. Daily digest recipient scan — 17.9% of db2

```sql
select users.* from users
left join users_digests as ud_ord on ud_ord.userid = users.id and ud_ord.mode = 'daily'
where deleted is null and lastaccess is not null and lastaccess > ?
  and tnuserid is null and bouncing = 0
  and CRC32(users.id) % 8 = ?          -- function on the PK: non-sargable
  and exists (select 1 from memberships ...)
  and (JSON_EXTRACT(users.settings,'$.simplemail') IS NULL or ... != 'None')
  and not exists (select 1 from users_digests ...)
  and ud_ord.lastsent is null and users.id > ? order by users.id asc limit 500
```

- `EXPLAIN`: driving table `users` via **`tnuserid`**, **1,422,677 rows examined**, filtered 0.17%
- Eligible pool is only **103,931 users** — ~14× the whole pool examined per call
- Measured: **3.00 s cold, 1.63 s warm**, per call, × 8 shards, `->everyMinute()` 07:00-12:00 BST
- `users` has no usable index: no `bouncing`, no standalone `lastaccess` (only `added,lastaccess`,
  wrong leading column)

`routes/console.php:644-651` explains the 8 shards:

> *"each shard runs at only ~42% of a core — … a worker spends ~58% of its time waiting on the DB
> … extra shards mostly overlap DB latency for near-free, and the box sits ~60% idle with 4."*

That tuning measured the **batch host's** CPU, not the database's. "58% waiting on the DB" was the
signal that db2 was already the constraint. The same file records the symptom: *"The daily
population (~79k, ~tripled by rippling) can't fully clear in 4h at current throughput."*

### 3. Illustrations cleanup — 6.3% of db2, returns nothing

`MessageIllustrationsService.php:34-44`, scheduled `->everyMinute()` (`routes/console.php:1227`).

- `EXPLAIN`: `ma_ai` → **type ALL, 33,224,660 rows, Using temporary**
- Measured: **15.93 s, 0 rows returned**
- ~960 s of DB time per hour to find nothing
- `messages_attachments` indexes are `PRIMARY(id)`, `incomingid(msgid)`, `hash`, `externaluid` —
  nothing serves `JSON_EXTRACT(externalmods,'$.ai')`

### 4. Active-user scan — 4.1% of db2

```sql
select id from users where deleted is null and lastaccess >= ? and added <= ?
```

- `EXPLAIN`: falls back to the **`deleted`** index (cardinality **15**), **1,422,686 rows
  examined**, filtered 16.66%
- Measured: **7.75 s / 8.29 s**, returning ~2,704 rows

Same root cause as finding 2 — no index on `lastaccess`. Both queries report the identical
1,422,686-row estimate.

### 5. Server configuration

- `sort_buffer_size = 256000000` (244 MB; default 256 KB), per-connection. **8.5 G of 10 G swap in
  use, 3.37 G of it mysqld's own pages.** Oversized sort buffers are also slower, not faster.
- `table_open_cache = 3177` — `Table_open_cache_overflows` **1,768,426** of 1,773,715 misses
  (99.7% evictions). Shows as 3-5% of samples in `Opening tables`.
- `innodb_flush_method = fsync` — double buffering; 14.7 G in page cache against a 6 G buffer pool
  on a 24 G box.
- `innodb_io_capacity = 200`, `innodb_adaptive_hash_index = OFF`.

## Checked and found healthy — not targets

- **Ripple expand candidate scan** (`ripple:expand`, ~507 groups per pass): `range` on
  `groupid(groupid,collection,deleted,arrival)`, "Using index", **0.01 s** per call. It appears in
  samples because it runs once per group, not because it is slow.

- **The 22.8% long tail** — nothing exceeds 3.9%, and the supporting indexes all exist:

  | query | index needed | present? |
  |---|---|---|
  | reachable-groups `ST_Intersects(g.polyindex, …)` | `polyindex` SPATIAL | yes — `groups` is 783 rows |
  | digest post selection, `chat_messages` on `refmsgid` | `msgid(refmsgid)` | yes (41 M rows, 5.9 GB) |
  | same, `messages_likes` subqueries | `msgid(msgid,type)`, `msgid_2(msgid,userid,type)` | yes (92 M rows, 7.6 GB) |

  The digest post-selection query carries **5 correlated subqueries per row**, so its cost is
  volume × rows rather than a missing access path. No further low-hanging fruit below the items
  above.

## Proposed changes

### A. Reach mail — trigger on either side changing

**Design decision (2026-09-02): a plain watermark on `rippling_reach.updated_at` is rejected.**
It would stop catching members who become eligible *after* a post's reach last changed — someone
joining the group, moving, or returning after a long absence. Picking those people up is wanted
behaviour, not an accident of the 60-minute window.

Instead, treat **both sides as change feeds** and evaluate a (post, member) pair when either moves:

1. **Post side** — posts whose reach changed since the last pass. Watermark on
   `rippling_reach.updated_at`, with a small overlap for tick safety. Evaluate all their
   candidates, exactly as now. Replaces the 60-minute look-back: 435 posts/pass → ~14.

2. **Member side** — members who newly became eligible since the last pass. Evaluate *those
   members* against the live posts for their groups, rather than re-sweeping every post.

The member-side feed is small enough to make this cheap — measured:

| signal | 1 min | 5 min | 60 min |
|---|---|---|---|
| `memberships.added` | **1** | 7 | 364 |
| `users.lastupdated` | **0** | 2 | 29 |

So roughly **1-2 member changes per minute**, against 435 posts currently re-scanned per minute.
Indexes exist for both feeds: `memberships.added_groupid (added, groupid)` and `users.lastupdated`.

**Two things to settle before implementing:**

- **What actually bumps `users.lastupdated`?** 29/hour looks low for the site's activity, so it
  may not capture a location or settings change. If it doesn't, the member-side feed needs another
  signal for "member moved". Verify before relying on it.
- **Returning-after-90-days members.** Eligibility is `lastaccess IS NULL OR lastaccess >
  now()-90 days`. Someone inactive for over 90 days who comes back becomes newly eligible, but
  `lastaccess > watermark` matches *every* active member (thousands/minute), so it is too broad to
  use as the trigger. Either store an "was eligible" flag to detect the crossing, or accept that
  these members are picked up on the post's next expansion. This is the one gap in the design.

Expected effect: removes most of the 47-51% of db2 that this query represents, while preserving
the late-joiner behaviour the current window provides.

### B. Illustrations cleanup — drive from new attachments

Zero product tradeoff; the current query returns 0 rows, so the historical backlog is already
clear.

```sql
SELECT DISTINCT ma_ai.id, ma_ai.msgid
FROM messages_attachments ma_real
JOIN messages_attachments ma_ai ON ma_ai.msgid = ma_real.msgid
WHERE ma_real.id > :watermark          -- PRIMARY key range scan, not a full scan
  AND (ma_real.externalmods IS NULL
       OR JSON_EXTRACT(ma_real.externalmods,'$.ai') IS NULL
       OR JSON_EXTRACT(ma_real.externalmods,'$.ai') = FALSE)
  AND JSON_EXTRACT(ma_ai.externalmods,'$.ai') = TRUE
```

Store `:watermark` (max `messages_attachments.id` seen) in cache between runs. Turns a 33 M-row
full scan into a range scan over rows added since the last pass.

### C. Daily digest — sargable shard predicate

`users.id` spans 15 - 45,091,925. Replace the non-sargable `CRC32(users.id) % 8 = :shard` with a
PK range per shard:

```php
$span = intdiv($maxId - $minId, $shards);
$lo = $minId + $shard * $span;
$hi = ($shard === $shards - 1) ? $maxId : $lo + $span - 1;
// ... ->whereBetween('users.id', [$lo, $hi])
```

Composes with the existing keyset pagination (`users.id > :watermark ORDER BY users.id LIMIT 500`),
so each shard walks its own eighth of the PK instead of all 8 walking the whole table.

Distribution is slightly less even than CRC32, which does not matter — the shards are independent
and the work rebalances across the 07:00-12:00 window.

### D. Operator-only: index on `users (deleted, lastaccess)`

**Schema change on Galera — for the operator to apply, not from CI and not from Claude.**

```sql
ALTER TABLE users ADD INDEX deleted_lastaccess (deleted, lastaccess);
```

`deleted IS NULL` is the leading equality (MySQL indexes NULLs), `lastaccess` the range. Serves
**two** hot queries, both currently examining the same 1,422,686 rows:

| query | current access path | share of db2 |
|---|---|---|
| daily digest scan (finding 2) | `tnuserid` index, filtered 0.17% | 17.9% |
| active-user scan (finding 4) | `deleted` index (cardinality 15), filtered 16.66% | 4.1% |

`users` is 2.85 M rows. Worth doing even with C, because C only splits the daily-digest walk across
shards — it does not give either query a usable access path.

### E. Reduce digest worker concurrency

20 workers against 8 cores, with the shard counts tuned by watching the batch host's idle CPU
rather than the database's. With A, C and D landed the per-query cost drops sharply, so the shard
counts should be re-derived from db2's CPU — not raised further on the "embarrassingly parallel"
reasoning in `routes/console.php:644-651`, which measured the wrong box.

### F. my.cnf

**Swap is not causing the slowness — checked, and it is a negative result.** It would have been
the tidy explanation for the 65 ms → 0.71 s gap, but `vmstat` over 10 s shows swap-in of
**23, 0, 2, 2, 0 pages/s** and iowait of 5, 0, 1, 1, 1% while `us` sits at 57-77%. mysqld's
3.47 GB of swapped-out pages are cold and staying out. db2 is CPU-saturated, not thrashing.

So treat F as memory hygiene and headroom, **not** a latency fix — the latency lever is cutting the
execution count (proposal A), not tuning memory.

mysqld's **371 M cumulative major faults** look alarming but are a 12-day accumulation, not a live
problem: measured over 60 s, mysqld's `maj_flt` rose 2,617 (**43/s**) while system-wide
`pgmajfault` rose only **71** and `pswpin` only **16**. There is no disk-backed memory pressure
happening now. Thread closed — do not re-open it on the cumulative figure alone.

- `sort_buffer_size`: 256 MB → 2 MB. Reclaims most of the 3.47 G mysqld has swapped out.
- `table_open_cache`: 3177 → 8000+, to stop the 1.77 M overflow evictions.
- Consider `innodb_flush_method = O_DIRECT` (stops double buffering) and raising
  `innodb_io_capacity` from 200 if the volume is SSD-backed. Both need a restart, so they belong in
  a planned maintenance window.

## Expected outcome

A + B + C + D address **75.3%** of measured load, and there is no longer a spread-the-load option
to fall back on, so the halving has to come from them. A alone is worth 47-51% if the member-side
feed works as measured.
