# Sharing reach polygons between posts

Design for cutting `rippling_reach` by de-duplicating identical geometry. All prod access
this session was read-only. Labels: **MEASURED** (observed 2026-08-23), **DOCUMENTED**
(from code or prior sessions), **UNVERIFIED** (believed, not yet shown).

## Why

`rippling_reach` is **47.7 GB of the 296 GB** on every database node and grew from 161 GB
of total data on 2026-07-27 to 201 GB on 2026-08-23 — about **+1.5 GB/day**, which is
roughly 30 days of headroom. It is the estate's binding disk constraint. Row count is
56,471 and *falling*; the size is per-row growth, not accumulation.

`data_free` is **0.0 GB** against a 50.2 GB `.ibd`, so today it grows by extending the
file. (MEASURED)

## What is duplicated

| key | distinct | rows per geometry | saving |
|---|---|---|---|
| rows | 56,471 | — | — |
| (lat, lng) | 21,825 | 2.59 | 61.4% |
| (lat, lng, tick) | 32,560 | 1.73 | **42.3%** |

**The duplication is exact, not approximate** (MEASURED): 40 rows at one origin spanning 4
ticks produced exactly **4** distinct `MD5(ST_AsBinary(polygon))` and 4 distinct
`outer_bound`. So `polygon = f(origin, tick)` byte-for-byte.

Where it pays: 15,998 rows (28.3%) live in origins with ≥10 posts and produce **51% of the
saving**. One origin carries 261 posts across 9 ticks. 12,824 origins are singletons
(22.7% of rows) and save nothing.

### Spatial clustering is NOT worth doing

`UserApproxLocService::BLUR_USER = 400` metres, and the blur **displaces** each poster
400 m in a direction derived from them rather than snapping to a grid. So a shared origin
means one poster or one fixed location posting repeatedly — the density is **temporal**,
and exact de-duplication already captures all of it.

Snapping nearby origins together adds almost nothing (MEASURED):

| key | distinct | saving |
|---|---|---|
| exact (origin, tick) | 32,560 | 42.3% |
| snap ~111 m + tick | 32,227 | 42.9% |
| snap ~444 m + tick | 30,401 | 46.2% |

Four percentage points, in exchange for up to 444 m of displacement **on top of** the
400 m blur, and a behaviour change to who a post reaches. Not worth it. 59% of origins
have exactly one post and are scattered across the UK; no plausible radius merges them.

## Design

Share the expensive columns. Leave the cheap index-driving ones alone.

```sql
CREATE TABLE rippling_reach_geom (
  hash        BINARY(16) NOT NULL PRIMARY KEY,   -- of the canonical WKB
  geom        GEOMETRY   NOT NULL /*!80003 SRID 3857 */,
  refs        INT UNSIGNED NOT NULL DEFAULT 0,
  SPATIAL KEY rippling_reach_geom_geom (geom)
) ENGINE=InnoDB;
```

`rippling_reach` gains `polygon_hash BINARY(16) NULL` plus an index. Row format is
Dynamic so `ADD COLUMN` should be INSTANT — **the operator confirms before running it**.

**`outer_bound` and `inner_bound` stay on `rippling_reach`, duplicated.** They are 42 KB
and 0.2 KB a row, about 2.5% of the row, so keeping them costs roughly 1% of the saving.
That 1% buys the entire index story below.

`refs` is INT UNSIGNED: the largest sharing group measured is 261, and `refs` is a live
count bounded by concurrent sharers. BIGINT would waste 4 bytes × 32,560 rows for nothing;
SMALLINT is a hostage to a refcount bug.

**Key on the content hash, not a surrogate id.** The 12 bytes saved by a BIGINT id is
677 KB against a ~27 GB saving — irrelevant. What decides it is that content-addressing
makes the write one idempotent statement with no read-back race, and `ExpandService`
computes schedules **concurrently, deduped by origin**, so several workers will hit the
same geometry simultaneously. The hash also self-verifies: the checker recomputes it from
the bytes. A surrogate id cannot be checked that way.

## How the queries change

Every read site was traced (DOCUMENTED). The finding that shapes the design:

> **Exactly one predicate in the codebase drives an index off these columns** —
> `MBRContains(rr.outer_bound, …)` in the browse feed (`rippling.ReachBrowseWhere`).
> Everything else is `msgid`-keyed and never touches a spatial index.

### Category 1 — unchanged, byte for byte

```sql
AND MBRContains(rr.outer_bound, POINT)      -- SPATIAL KEY rippling_reach_outer, untouched
AND ST_Contains(rr.outer_bound, POINT)
AND (COALESCE(ST_Contains(rr.inner_bound, POINT), 0) = 1 OR …)
```

This is the browse feed — the hot path, and the one that has already caused an outage:
splicing a JSON test into it on 2026-08-21 turned `key=rippling_reach_polygon rows=1` into
`key=NULL rows=62,534`, scanning a ~17 GB table on every uncached feed load. Under this
design that clause does not change at all, so that failure mode cannot recur.

### Category 2 — one extra primary-key join, no index risk

Every site touching the exact `polygon` is already `msgid`-keyed, including the correlated
subquery the sandwich uses to keep the 178 KB blob lazy:

```sql
-- before
EXISTS (SELECT 1 FROM rippling_reach r2
        WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, POINT))

-- after
EXISTS (SELECT 1 FROM rippling_reach r2
        JOIN rippling_reach_geom g ON g.hash = r2.polygon_hash
        WHERE r2.msgid = rr.msgid AND ST_Contains(g.geom, POINT))
```

Primary-key lookup then primary-key lookup. No spatial index was ever involved. Same
one-line change at:

- `Ripple/ReachQueryService::isWithinReach`
- `FirstReply/MaxReachService::isWithinMaxReach`
- `UnifiedDigestService` recipients (`JOIN rippling_reach mr ON mr.msgid = mg.msgid`)
- `Ripple/ExpandService` group retraction (`WHERE mg.msgid = ?`)
- `iznik-server-go/firstreply/passthrough.go`

Bonus: the lazy-BLOB `EXISTS` exists because *"lazy BLOB fetch does not cross OR items"*.
With the geometry in another table it is lazy by construction, so that could eventually be
simplified away — **separately, not in this change**.

### Category 3 — bulk readers get cheaper

spatial-go loads reach geometry to build rasters. Distinct geometries means **32,560
instead of 56,471** — 42% less parsing for identical output, on exactly the work that made
db3 CPU-bound.

### Category 4 — writes

```sql
INSERT INTO rippling_reach_geom (hash, geom, refs) VALUES (?, ?, 1)
  ON DUPLICATE KEY UPDATE refs = refs + 1;
```

then store the hash on the reach row. `outer_bound`/`inner_bound` keep being written as
now. The hash is computed in the writer from the exact bytes it is storing, so writer and
checker cannot disagree about canonicalisation.

## Stages

**Stage 0 — settle the unverified half.** `polygon`/`outer_bound` keying on (origin, tick)
is MEASURED. `max_polygon` and `overflow_bounds` are **78% of the bytes** and are believed
to key on origin *alone* — the max reach is final, and the rings are computed once at
schedule derivation, not per tick — but this is **UNVERIFIED**. The 40-row sample had NULL
for both, and the follow-up query had to be killed after 129 s when db3's load went from
10.8 to 21. Hash a handful of rows with `has_overflow=1 AND max_polygon IS NOT NULL`, on a
quiet node. **This decides whether the project is worth ~27 GB or ~4 GB. Do it first.**

**Stage 1 — table, column, dual-write.** Additive and invisible: writers populate both the
blob and the shared row, everything still reads the old column. Revertible by ignoring the
new one.

**Stage 2 — backfill and verify.** Same shape as `ripple:shrink-overflow-bounds`: bounded,
resumable, one row per statement, `updated_at` held still with a self-assignment (a bulk
reach backfill once generated 38k+ notification emails by bumping it). Then a checker that
recomputes the hash for a sample and compares — and **exits non-zero if it compared
nothing**, because a checker that silently examined an empty set is worse than none.

**Stage 3 — switch reads, one path at a time.** `EXPLAIN` each rewritten query and confirm
the plan before it ships. **The browse query is the one to prove**: that MySQL still picks
`rippling_reach_outer` with a join to `rippling_reach_geom` present in the same statement.
It should — the join is on a primary key and the driving predicate is unchanged — but
"should" is precisely what was wrong on 2026-08-21. Run the EXPLAIN on **db1, not db3**.

**Stage 4 — free the duplicates.** `UPDATE … SET polygon = NULL` returns the LOB pages to
the tablespace free list, one row at a time, pausable, no DDL. Note this does **not** shrink
the `.ibd`: `df` will not move, but `data_free` goes from 0 to ~27 GB and new rows consume
that instead of extending the file — roughly 18 days of growth absorbed at the current rate,
plus a lower rate thereafter. Compacting the file is a separate operator decision; a rebuild
needs ~50 GB against 52 GB free, so it wants headroom first.

## Risks

- **The browse query plan.** Covered above. This is the one that can take the site down.
- **Garbage collection.** The `messages` foreign key cascades into `rippling_reach`, so
  deletions bypass application code and `refs` cannot be trusted alone. A sweep for
  unreferenced hashes is safer, and should require **two consecutive passes agreeing**
  before deleting: removing a shared geometry wrongly loses it for every post sharing it —
  261 in the worst case observed.
- **Operator-only.** Schema changes and backfills on prod are the operator's, not
  Claude's. `polygon` and `outer_bound` are both spatial-indexed and must never be
  rewritten in one UPDATE.
- **db3 is the only active apiv2 backend** and was at load 10.8–21 during this work.
  Measure on db1.

## Interaction with the other work in flight

Independent of, and multiplies with:

- **PR #1400** — ring coordinates at 4dp instead of 14 significant digits (1.70×).
- **Compression** — 10.60× on `overflow_bounds` with no rounding at all, and ~4.6× on the
  binary geometry columns, at zero cost to precision. Worth more than any of this, but it
  costs CPU on every page read, so it needs measuring somewhere that is not serving the API.

Rounding may even raise the de-duplication hit rate slightly, as near-identical polygons
round to identical bytes. UNVERIFIED and not worth measuring separately.

## Estimated saving

- **polygon (VERIFIED key):** 19% of the table × 42.3% ≈ 8% ≈ **3.8 GB**.
- **max_polygon + overflow_bounds (UNVERIFIED key):** 78% × 61.4% ≈ 48% ≈ **23 GB**.

Total if Stage 0 confirms: roughly **27 GB of 47.7 GB**. If it does not, this is a ~4 GB
project and the compression route is the better use of the effort.
