# Rippling reach polygon dedup: implementation plan

Implements `plans/2026-08-23-rippling-reach-polygon-dedup.md` (the design doc), with the
deviations listed below. Branch `feature/rippling-reach-polygon-dedup`, worktree
`FreegleDocker-reach-dedup`.

Instruction from Edward: **no clustering** (exact byte dedup only), and do not let a refs
count get out of step in the Galera cluster.

## Deviations from the design doc, and why

Each of these was forced by code/schema reality found in the mapping pass, not preference:

1. **No `refs` column at all.** The design doc's `refs INT UNSIGNED` cannot be kept
   accurate: the `messages` FK cascade deletes reach rows inside InnoDB with no hook, three
   PHP DELETE sites and one Go DELETE site bypass any counter, and no increment+decrement
   refcount precedent exists in the codebase (all existing ODKU `+1`s are loss-tolerant
   metrics). Instead: geometry upserts are idempotent no-op ODKU
   (`ON DUPLICATE KEY UPDATE hash = hash`), and GC never counts - it proves
   non-reference by anti-join, twice, with an age grace, and the DELETE itself re-checks
   atomically. FK RESTRICT from the hash columns makes deleting a still-referenced
   geometry physically impossible.
2. **`overflow_bounds` is OUT OF SCOPE.** It is a JSON column holding multiple WKT text
   strings under lane keys, not a GEOMETRY - it cannot share `rippling_reach_geom` as
   designed. Also `has_overflow` is a virtual generated column on `overflow_bounds IS NOT
   NULL` with its own index; draining the column flips the whole overflow read path.
   Follow-up work, documented at the end.
3. **`max_polygon_hash` added.** The design doc only names `polygon_hash`, but
   `MaxReachService::isWithinMaxReach` and `MatchMailService` read polygon AND max_polygon
   in the same statement. max_polygon is GEOMETRY, write-once, msgid-keyed - it fits the
   same design for free.
4. **Drain uses a degenerate sentinel POINT, not NULL, for `polygon`.** `polygon` is
   NOT NULL with a live SPATIAL index (`rippling_reach_polygon`) that
   `message/search.go nearbyFeedMsgIDs` still drives off; NULLing it needs a 50 GB
   shadow-copy rebuild on prod. Setting `polygon = ST_GeomFromText('POINT(0 0)', 3857)`
   reclaims the LOB pages with zero DDL, using the same sentinel idiom `outer_bound`
   already has. `max_polygon` is nullable and unindexed so it IS drained to NULL.
5. **Every existing SQL trick preserved**: `updated_at = updated_at` self-assignment on
   every backfill/drain UPDATE (a bulk backfill without it once sent 38k emails and
   spatial-go delta-polls on updated_at); one row per statement, no multi-row spatial
   UPDATEs; polygon and outer_bound never rewritten in one statement where the 1713
   undo-log ladder applies.

## Core invariants (the whole design in four lines)

- I1: a non-NULL `polygon_hash` always equals `UNHEX(MD5(ST_AsBinary(polygon)))` of the
  polygon written by the same statement (until drained; after drain the geom row is the
  source and the sentinel marks the blob as gone).
- I2: any statement setting a non-NULL hash is preceded by the geom upsert (FK RESTRICT
  enforces this at the DB level).
- I3: `polygon_hash IS NULL` means "read `polygon`"; readers always
  `LEFT JOIN rippling_reach_geom g ON g.hash = ... ` + `COALESCE(g.geom, <blob column>)`.
  So the code is correct BEFORE backfill, DURING it, and AFTER drain, in any order.
- I4: geometry mutation (the ST_Difference clips) NULLs the hash in the same atomic
  statement, then re-points via upsert-from-row + hash-from-row. A crash between leaves
  hash NULL = safe fallback to the blob.

Orphan geom rows (crash between upsert and reference, or 1713 retries with different
bytes) are expected and harmless; GC sweeps them.

## Schema

Laravel migration (dev/CI source of truth) + paired idempotent `*_migration.sql` for prod
(information_schema-guarded; index adds documented as RSU node-by-node per this table's
own precedent; FK add validates over an all-NULL indexed column so it is cheap, but is
called out for the operator).

```sql
CREATE TABLE rippling_reach_geom (
  hash      BINARY(16) NOT NULL PRIMARY KEY,     -- UNHEX(MD5(ST_AsBinary(geom)))
  geom      GEOMETRY NOT NULL SRID 3857,         -- NB: stores raw lng/lat degrees, same as source columns
  createdat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- GC age grace
  SPATIAL KEY rippling_reach_geom_geom (geom)
) ENGINE=InnoDB;

ALTER TABLE rippling_reach
  ADD COLUMN polygon_hash BINARY(16) NULL,
  ADD COLUMN max_polygon_hash BINARY(16) NULL;         -- INSTANT
-- separate, RSU node-by-node on prod:
ALTER TABLE rippling_reach ADD INDEX rippling_reach_polygon_hash (polygon_hash);
ALTER TABLE rippling_reach ADD INDEX rippling_reach_max_polygon_hash (max_polygon_hash);
ALTER TABLE rippling_reach
  ADD CONSTRAINT rippling_reach_polygon_hash_fk FOREIGN KEY (polygon_hash)
      REFERENCES rippling_reach_geom (hash) ON DELETE RESTRICT,
  ADD CONSTRAINT rippling_reach_max_polygon_hash_fk FOREIGN KEY (max_polygon_hash)
      REFERENCES rippling_reach_geom (hash) ON DELETE RESTRICT;
```

No surrogate id: hash-as-PK is an explicit, argued decision in the design doc (idempotent
one-statement writes, self-verifying, no read-back race).

## Canonical SQL fragments (one definition per language, no copy-paste)

PHP: `App\Services\Ripple\GeomShareService` with constants/builders for:
- upsert-from-bind: `INSERT INTO rippling_reach_geom (hash, geom) SELECT
  UNHEX(MD5(ST_AsBinary(g.g))), g.g FROM (SELECT ST_GeomFromText(?, 3857) AS g) g
  ON DUPLICATE KEY UPDATE hash = hash`
- upsert-from-row + hash-from-row (backfill/re-point after clip)
- reader fragment: `LEFT JOIN rippling_reach_geom {alias} ON {alias}.hash = {row}.{col}_hash`
  + `COALESCE({alias}.geom, {row}.{col})`

Go: helpers in `iznik-server-go/rippling` (same SQL text).

## Write sites (all must carry the hash; density_band went unpopulated on 2/3 paths once)

iznik-batch `ExpandService`: initialiseNew (INSERT ODKU), advanceDue (UPDATE),
backfillReach, recomputeReach, storeWithUndoLogShrink 1713 ladder (hash recomputed per
attempt from that attempt's WKT; upsert precedes each attempt), advanceSplitForUndoLog
(hash rides the polygon statement), reapplyClips (ST_Difference: hash := NULL in the same
statement, then upsert-from-row + hash-from-row).
iznik-batch `MaxReachService`: populate/populateForPost (write-once WHERE max_polygon IS
NULL - guard becomes `max_polygon IS NULL AND max_polygon_hash IS NULL`).
iznik-server-go `message.ClipReachForRejectedGroup`: same clip pattern as reapplyClips;
the empty-polygon DELETE path is unchanged.

## Read sites

PHP (LEFT JOIN + COALESCE, all msgid-keyed):
ReachQueryService::isWithinReach ladder; MaxReachService::isWithinMaxReach (both cols);
UnifiedDigestService recipients join + reachRadiusMetres/primeReachRadiusCache;
MatchMailService scout query; ExpandService retraction + schedule-reuse reads (only where
they touch polygon); ReachBoundsService derive/verify; BackfillInnerBoundsCommand
health-check; RippleReplyService (via ReachQueryService).
Presence guards: every `whereNull('max_polygon')` becomes hash-aware.

Go:
- `rippling.ReachBrowseWhere` / `ReachInReachExpr`: **BYTE-FOR-BYTE UNCHANGED** (golden
  SQL tests must not change).
- isochrone/message.go x3 (`ST_AsText(ST_Envelope(rr.polygon))` in nearby, own-posts arm,
  mygroups): LEFT JOIN + COALESCE.
- message/search.go nearbyFeedMsgIDs: keep `ST_Contains(rr.outer_bound, ...)` as the
  index driver; exact check via COALESCE join. EXPLAIN must show rippling_reach_outer.
- Legacy !ReachBoundsReady fallbacks (isochrone/reachbounds.go, message/reach.go,
  chat/chatmessage.go): COALESCE rewrite too (still-live code, tests exercise them).
- firstreply/passthrough.go.
- iznik-spatial-go dataset_reach.go raster reads: COALESCE join (distinct-hash parse
  optimisation is a follow-up, not this PR).

## Operator commands (all: bounded --limit, resumable config-table mark, --dry-run,
one row per statement, updated_at self-assignment)

1. `ripple:dedup-geometry` - backfill: per row, upsert-from-row then hash-from-row, for
   polygon and max_polygon.
2. `ripple:verify-geometry-dedup` - checker: samples rows with hashes set; verifies
   hash == MD5(WKB(blob)) where blob not drained, geom row exists, and
   MD5(WKB(geom)) == hash; **exits non-zero if it compared nothing**; reports dangling
   hashes.
3. `ripple:drain-deduped-blobs` - Stage 4: polygon -> sentinel POINT / max_polygon ->
   NULL, ONLY where hash set + geom row present + bytes verified in the same statement's
   WHERE.
4. `ripple:gc-reach-geometry` - two-pass sweep: pass N records unreferenced candidate
   hashes (age > grace, anti-join on both hash columns) in the config mark; pass N+1
   deletes only hashes still unreferenced AND in the previous pass's candidate set, one
   row per DELETE with the anti-join re-checked inside the DELETE statement; FK 1451 is
   caught and skipped (the backstop, not the mechanism).

## Prod rollout order (operator, documented in the migration SQL)

1. CREATE TABLE (TOI-safe) + INSTANT column adds; then ONE combined ALTER per node under
   RSU (indexes + FKs, foreign_key_checks=0, ALGORITHM=INPLACE).
   **DONE ON LIVE 2026-08-24 (Edward). Schema is in; inert until code deploys.**
2. Deploy code (dual-write + COALESCE reads live from this point; correct with zero
   backfilled rows).
3. `ripple:dedup-geometry` until clean; `ripple:verify-geometry-dedup`.
4. EXPLAIN browse + nearby-search on db1 (never db3): rippling_reach_outer must be the
   driver with the geom join present.
5. `ripple:drain-deduped-blobs` (the actual disk win; file stops growing).
6. `ripple:gc-reach-geometry` on cron.

## Test plan

- Laravel: extend ReachQueryServiceTest / ExpandServiceTest / MaxReachService tests for
  dual-write asserts + post-drain-shaped fixtures (sentinel polygon + geom row); new
  command tests for backfill/checker/drain/GC incl. crash-window states (hash NULL,
  dangling hash, orphan geom). Real DB (iznik_batch_test), no DDL in tests.
- Go: golden-SQL tests unchanged for ReachBrowseWhere; extend
  TestClipReachForRejectedGroup + NullsInnerBound for hash re-point; new fixtures proving
  the three feed envelope reads + nearbyFeedMsgIDs + legacy fallbacks return identical
  results pre-backfill / post-backfill / post-drain.
- spatial-go: dataset_reach_test.go with deduped + drained fixtures.
- EXPLAIN checks in dev for the browse/search plans.

## Status

| # | Task | Status |
|---|------|--------|
| 1 | Migration + prod SQL | ✅ applied + schema verified in worktree dev DB |
| 2 | PHP GeomShareService + unit coverage | ✅ code; tests via php-tests fork |
| 3 | ExpandService dual-write incl. clips + 1713 ladder | ✅ code |
| 4 | MaxReachService dual-write + presence guards | ✅ code (storeMaxPolygon shared write) |
| 5 | PHP read rewrites | ✅ ReachQuery/Digest/MatchMail/Bounds/Reply/BackfillInner/retraction/recompute |
| 6 | Go helpers + read rewrites + clip rewrite | 🔄 core done (geomshare.go, builders+share bool, 3 call sites, clip); go-readers fork doing feeds/search/passthrough/tests |
| 7 | spatial-go read rewrite | ✅ fork done (unconditional join per module precedent; text-shape tests) |
| 8-11 | Backfill/checker/drain/GC commands | ✅ code + FULL LIVE SMOKE: dedup across posts AND columns, verify green, drain sentinel/NULL + readers correct on drained rows via tinker, FK 1451 blocks referenced delete, messages-cascade orphans swept by two-pass GC. Tests via php-tests fork |
| 12 | EXPLAIN verification (dev) | ✅ rippling_reach_outer drives; eq_ref PK then eq_ref hash. db1 EXPLAIN stays an operator gate |
| 13 | Docs freshness | ✅ green on committed branch |
| 14 | Full suites (laravel, go) | ✅ Go 4245, Laravel 5968, all pass |
| 15 | Review workflow + PR | ✅ 7 confirmed findings fixed; **PR #1402 open**, CI #11188 running. Humans merge |

## Follow-ups (explicitly not this PR)

- overflow_bounds dedup (JSON-of-WKT shape; has_overflow generated-column dependency;
  spatial-go JSON_EXTRACT readers). ~6 GB.
- spatial-go distinct-hash parse optimisation (Category 3's 42% parse saving).
- Simplify the lazy-BLOB EXISTS sandwich (design doc says separately, not in this change).
- Prod DDL to actually drop rippling_reach_polygon index / make polygon nullable if the
  sentinel approach is ever replaced by true NULLs (shadow-copy scale).
- `schedule` LONGTEXT can embed per-tick WKT (dormant slim-mode exception) - not touched.
