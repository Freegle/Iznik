# db2 / db3 query candidates, measured 2026-09-18

Each candidate below has been **measured on production and its rewrite tested there**.
No application code has been changed for any of them. This list exists so the work can
be scheduled deliberately: pick a candidate, implement it, and the numbers to beat are
already here.

Method and harness: `analysis/2026-09-17-db-cpu/README.md`. Rank db2 from processlist
samples (its digest table sees 14.5% of the work); rank db3 from
`events_statements_summary_by_digest`, which is complete there because apiv2's Go driver
sends text protocol.

Node state while measuring: db2 mean 2.51 concurrent threads, db3 mean 1.87. Neither is
saturated. These are efficiency wins and user-facing latency, not a fire.

## Summary

| # | What | Node | Measured cost | After | Confidence |
|---|---|---|---|---|---|
| 1 | Chat room list `DISTINCT` | db3 | **0.39 cores**, 926k execs/day | 7.2× faster | proven, 15 members, 0 differences |
| 2 | Spatial reconcile | db2 | 28.8s avg **every 5 min** | 37.1s → 4.69s | proven, identical on 39,259 rows |
| 3 | Browse membership `EXISTS` | db3 | **0.41 cores** over 4 queries | 2.2–2.4× | proven, identical counts, 5 members |
| 4 | Digest active-user scan | db2 | 6.59s, ~5% of db2 | 6.59s → 1.55s | proven; better still with DDL |
| 5 | `purge:chats` | db2 (write) | 20,474,759 rows to delete 6 | needs DDL | operator only |
| 6 | Reach-mail recipient query | db2 | 12s max, ~4.8% of db2 | not yet analysed | open |

Already implemented, do not redo: **PR #1549**, message expiry candidate scan
(6,301,473 rows → 295,588; 60.93s → 2.45s).

---

## 1. The chat room list `DISTINCT` removes nothing and costs 7×

**Where:** `iznik-server-go/chat/chatroom.go:1184`, the `SELECT DISTINCT chat_rooms.id, …`
the comment above calls "a beast of a query".

**Cost:** the single largest consumer on db3.

| | |
|---|---|
| total | 756,013s over 22.7 days uptime = **0.39 cores continuously** |
| executions | 926,265 per day, ~10.7/second |
| rows examined | 2,584 per execution, to send 38.6 |
| average | 36ms, max 11.23s |
| temporary tables | 42,049,260 (≈2 per execution) |
| sort scans | 7,357,989,477 (≈350 rows sorted per execution) |

**The defect:** nothing in the query can multiply rows, so the `DISTINCT` has nothing to
remove. Every join is on a unique key or on `id = (SELECT … ORDER BY … LIMIT 1)`, and the
`rcm` derived table is `rn = 1` per `chatid`. What the `DISTINCT` does buy is a temporary
table and a sort of ~20 wide columns — including two `JSON_EXTRACT` results and several
correlated `COUNT(*)` subqueries — on every one of those 926,265 daily calls.

**Tested rewrite:** delete the word `DISTINCT`.

15 members with 5–57 rooms each, every one run both ways:

```
  rooms=9   distinct=9        28ms   plain=9         4ms
  rooms=16  distinct=16       42ms   plain=16        5ms
  rooms=32  distinct=32       75ms   plain=32       10ms
  rooms=37  distinct=37      113ms   plain=37       13ms
  rooms=57  distinct=57       96ms   plain=57       11ms
  …
  users compared: 15   row-count differences: 0
  total ms  with DISTINCT: 621   without: 86
```

**Equivalence:** `DISTINCT` can only ever *remove* rows, so an equal count is proof it
removed none. It removed none for any of the 15, and the count was always exactly one row
per chat room — which is what the join structure predicts.

**Risk:** low, but this is the chat list on every page load, so it wants a careful read of
the join list before the keyword goes, and a Playwright pass over the chat list.

**Watch for:** the same `SELECT DISTINCT` habit elsewhere in the Go API. Check each one
against its joins rather than assuming.

---

## 2. The spatial reconcile drives from a cardinality-21 index, every five minutes

**Where:** `iznik-batch/app/Services/MessageSpatialService.php:163` `upsertRecentMessages()`,
scheduled `everyFiveMinutes()` at `routes/console.php:1294`.

**Cost:** 34 runs caught in the 00:00–08:08 sample, mean **28.8s**, max 58s, at 288 runs a
day ≈ 8,294 query-seconds a day ≈ 0.096 cores continuously on db2. It is the largest single
batch consumer on that node.

**The defect:** the optimiser drives from `messages_groups` on the `collection` index,
which has 21 distinct values, and examines **5,524,838 rows at `filtered: 6.13`**. The
selective predicate is `messages_groups.arrival >= <cutoff>`, and there is an `arrival`
index — the window is only 626,197 rows. There is also `Using temporary` for the `DISTINCT`.

Per driving row it then runs a correlated `NOT EXISTS` against `messages_groups` with three
OR'd arms (the representative-membership pick) plus the latest-outcome subquery.

**Tested rewrite:** replace the representative-membership `NOT EXISTS` with a
`ROW_NUMBER() OVER (PARTITION BY msgid ORDER BY rippled_in ASC, arrival DESC, groupid ASC)`
derived table filtered to `rn = 1`, **and** `FORCE INDEX (arrival)` on `messages_groups`
inside it. Both are needed:

| form | real window | wide window |
|---|---|---|
| current | 37.10s | 50.59s |
| `FORCE INDEX (arrival)` only | 14.95s | 23.17s |
| `ROW_NUMBER` only | 32.43s | 28.32s |
| **`ROW_NUMBER` + `FORCE INDEX`** | **4.69s** | **7.50s** |

**Equivalence:** today's real window returns 0 rows, which proves nothing, so both forms
were run over a window widened to `arrival >= 2026-07-20`. Identical on all three measures:

```
  39259 rows   BIT_XOR(CRC32(id|groupid|arrival|msgtype)) = 1342925774
               SUM(CRC32(id|lat|lng))                    = 84420472292007
```

**Safe because:** `messages_groups.rippled_in` is `NOT NULL`, so `ROW_NUMBER`'s ordering
and the `NOT EXISTS` comparison cannot diverge on NULLs — that was the one semantic risk.
Exact ties across all three ranking columns would be kept by `NOT EXISTS` and cut to one by
`ROW_NUMBER`, but they collapse to the same output row under the existing `DISTINCT`.

**Note:** with `rn = 1` the outer `DISTINCT` becomes redundant. Leave it or drop it, but
measure again if dropping.

**Risk:** medium. `FORCE INDEX` is a hint that rots if the schema changes, so it wants a
comment saying what it is for. The `ROW_NUMBER` rewrite changes a shared predicate shape —
`stillQualifyForIndex()` uses the same `qualifyingMemberships()` base and must keep agreeing
with it, which is the whole point of that base existing.

---

## 3. Browse asks "is this post in one of your groups" the expensive way

**Where:** `iznik-server-go/isochrone/message.go:717` (`myGroupsMsgIDs`), `:887`, `:923`,
and `iznik-server-go/message/groups.go:58`.

**Cost:** four of db3's top spatial queries share the shape.

| cores | rows/exec | execs/day | what |
|---|---|---|---|
| 0.19 | 218,640 | 25,895 | mygroups browse feed |
| 0.10 | 220,009 | 14,283 | mygroups unseen `COUNT` |
| 0.06 | 165,623 | 13,208 | feed variant |
| 0.06 | 92,943 | 2,883 | derived-table variant |
| **0.41** | | | **total** |

**The defect:** measured clause by clause for a member in 12 groups:

```
  1. spatial scan only              13–50ms
  2. + not-viewed (messages_likes)  145–252ms
  3. + in my groups (EXISTS)        901–1080ms   <- 70% of the query
  4. + pending guard                1120–1161ms
```

The `EXISTS (SELECT 1 FROM messages_groups mg INNER JOIN memberships mem ON mem.groupid =
mg.groupid WHERE mg.msgid = ms.msgid AND mem.userid = ?)` runs for all 27,049 unsuccessful
spatial rows. Because the member's groups arrive through a join rather than as constants,
MySQL cannot push a groupid into the `(msgid, groupid)` index — so for each post it walks
every `messages_groups` row that post has, and a rippled post has many, before `FirstMatch`
can answer.

**Tested rewrite:** read the member's group ids first (they are in `memberships`, a handful
of rows) and pass them as a constant `mg.groupid IN (…)` list, dropping the `memberships`
join from the `EXISTS`. Same lesson as the microvolunteering antijoin fix: candidates first,
constant `IN` after.

| member | groups | current | constant `IN` | |
|---|---|---|---|---|
| A | 1 | 231ms | 251ms | wash |
| B | 1 | 292ms | 246ms | 1.2× |
| C | 12 | 925ms | 381ms | **2.4×** |
| D | 5 | 906ms | 411ms | **2.2×** |
| E | 12 | 928ms | 381ms | **2.4×** |

**Equivalence:** identical row counts for all five members (1662, 419, 5086, 1677, 1421).

**Risk:** medium. It adds a round trip, and the group list has to be bounded — a moderator
in hundreds of groups would build a very long `IN` list. Decide what happens above some
group count (fall back to the current form) and measure that case before shipping.

**Note:** the comment at `message.go:705` explains why this filters on `messages_groups`
rather than `messages_spatial.groupid`. The rewrite keeps that; it only changes how the
member's groups reach the predicate.

---

## 4. The digest active-user scan uses an index that selects 95% of the table

**Where:** the `users.lastaccess` scan in the digest path —
`select id from users where deleted is null and lastaccess >= ? and added <= ?`.
~5% of db2, max 15s live.

**The defect:** the optimiser picks `key: deleted`, examining **1,488,055 rows at
`filtered: 16.66`**. `deleted IS NULL` matches 2,732,883 of 2,872,858 users — 95% — so it is
using an index to select almost the whole table, then doing random primary-key lookups.

The selective predicate is `lastaccess >= <yesterday>`, which matches **3,850 of 2,872,858
users, 0.13%**. There is no index on `lastaccess`. The existing `(added, lastaccess)`
composite cannot help, because `added <= <a week ago>` matches nearly every account.

**Tested, no DDL:** `IGNORE INDEX (deleted)`, which makes it a full scan.

```
  current                6.59s   (key: deleted, 1,488,055 rows)
  IGNORE INDEX(deleted)  1.55s   (full scan, 2,976,111 rows)
  FORCE INDEX(added)     3.67s
```

A full scan of 2.9M rows genuinely beats 1.5M index-driven random lookups here, by 4.3×.

**Better, needs DDL — operator only:** an index on `users.lastaccess`. A bare
`SELECT count(*) FROM users WHERE lastaccess >= ?` with no usable index takes 1.35s; with a
`lastaccess` index it would be a range scan over 3,850 rows. `users` is a big hot table on a
Galera cluster, so this is an operator decision with the usual node-by-node dance, not
something to slip into a PR.

**Equivalence:** an index hint cannot change results, only the plan. Row counts drifted
between runs (3431, 3436, 3447, 3448) because `lastaccess` is written continuously — that is
live data moving, not a difference between the forms.

---

## 5. `purge:chats` scans 20.5M rows on the write node to delete 6

Carried over from the 2026-09-17 overnight profile, unchanged. `reviewrejected` has no
index, so the nightly purge full-scans `chat_messages` **on the write node**. It ran
02:00:26 → 02:10:00.

Needs DDL, so it is an operator decision. Worth pairing with any other `chat_messages`
index work rather than doing alone — see `.claude/rules/` on `chat_messages` DDL, which has
no `INSTANT ADD`.

---

## 6. Reach-mail recipient query — identified, not analysed

`iznik-batch/app/Services/UnifiedDigestService.php:1119`, the immediate-notification
recipient query. 12s max, ~4.8% of db2 across the day.

It joins every `Immediate` member of the post's group and then applies `ST_Contains`
per member, plus two `NOT EXISTS` ledger checks. Whether the cost is the spatial predicate
or the membership fan-out has not been measured. **Do not guess a rewrite** — profile the
clauses the way candidate 3 was profiled first.

---

## Tested and rejected

Recording these so nobody spends the afternoon rediscovering them.

- **Driving the browse feed from the member's groups instead of from
  `messages_spatial`.** Worse for 3 of 5 members (424ms vs 236ms, 385 vs 238, 1934 vs 784),
  better for 2. The member's groups contain their whole message history, so the derived
  table is larger than the 27,049-row spatial scan it replaces. Candidate 3 keeps the
  original drive order and only changes how the group ids reach the predicate.

- **`ROW_NUMBER` alone on the spatial reconcile.** 32.43s against 37.10s — barely moves,
  because the driving index is still `collection`. It only pays once the index is forced.

- **`FORCE INDEX (added)` on the active-user scan.** 3.67s, beaten by simply ignoring the
  `deleted` index (1.55s).

## Things to be careful of, learned here

- **An empty result set proves nothing about equivalence.** Both forms of the expiry query
  and the spatial reconcile return 0 rows on a healthy day. Widen the window until the set
  is non-empty, then compare — 882 rows and 39,259 rows respectively.
- **`CREATE TEMPORARY TABLE … AS SELECT` against a hot table on Galera deadlocks.** Compare
  with `count(*)` plus `BIT_XOR(CRC32(...))` and `SUM(CRC32(...))` in a single statement
  instead; `BIT_XOR` is order-independent and has no length limit, unlike `GROUP_CONCAT`.
- **`SUM_TIMER_WAIT` on db3 is cumulative since server start.** Divide by uptime (1,961,351s
  when this was taken) for a cores figure, or by 22.7 for a per-day figure. A raw total is
  meaningless on its own.
- **The processlist sampler covers ~18.7% of wall clock.** Use it for per-run duration
  (`max(time)` per connection id is a true duration) and for ranking, not for counting how
  many times something ran.
