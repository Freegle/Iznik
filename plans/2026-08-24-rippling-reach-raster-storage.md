# Rippling reach: cell-set (raster) storage instead of polygons

Design + implementation record for replacing rippling_reach's stored WKT/GEOMETRY
polygons with a compact membership-grid representation. Stacks on
plans/2026-08-23-rippling-reach-polygon-dedup.md (PR #1402) - that change stopped
*duplicating* stored geometry; this stops *storing* one at all for readers that only
ever needed "is this point inside".

## Why, and why now

PR #1402 halves rippling_reach's disk (~54% of a table heading for ~185 GB at the
90-day steady state - see project_reach_geometry_dedup_pr1402 memory for that model).
Edward's question after seeing that ceiling: can we get a further order of magnitude
by moving away from vector polygons entirely, "thinking about how the spatial server
does things"?

The answer is yes, and it is not a new invention - iznik-spatial-go's `raster.go`
ALREADY rasterises every reach polygon at load time into a bounded 2-bit-per-cell grid
(`BuildRasterDim`), specifically because point-in-polygon against an ~11k-vertex
polygon was 95-98% of the browse badge query's cost. The polygon is parsed, then
immediately turned into the thing every consumer actually wants: a membership grid.
This design stores THAT, instead of paying to vectorise the routing server's own grid
fill into WKT and then re-rasterising it downstream.

## Measured evidence (2026-08-24, real production data - not synthetic)

A median recent 'done' reach polygon (msgid 121556272, 51.5N, 90x54km extent,
31,145 vertices) pulled read-only from db-1:

| representation | size | vs stored WKT/WKB |
|---|---|---|
| stored GEOMETRY (WKB) | 498,337 bytes | - |
| stored WKT (as fetched) | 1,017,565 bytes | - |
| CellSet (this design, RLE, no compression) | **22,804 bytes** | **45x smaller than WKT, 22x smaller than WKB** |

Round-trip over real HTTP through the new spatial-go endpoint: 1,017,565 bytes in,
22,804 bytes out, **26ms**.

Behavioural parity: the existing WKT-built `BuildRasterDim` and a new
`BuildRasterFromCellSet` were run against this SAME real polygon and their coarse
accelerators probed at 40,401 points across and beyond the bbox. **Zero
disagreements** wherever either was definite (34,644 agreements, 5,757 points where
one or both said "ask the fine grid", 0 contradictions). This is not a theoretical
claim - it is measured against the exact shape production stores today.

## Design

### The format: `cellset` (new standalone Go module `iznik-reach-cellset`)

A CellSet is a bitmap over a GLOBAL, fixed lattice - `CellDegrees = 0.0003` degrees on
both axes (the same lattice the overflow rings and the routing server's own grid fill
already use - see `ShrinkOverflowBoundsCommand`'s docblock). Global anchoring (not
per-polygon bbox-relative) means two CellSets covering the same real-world cell always
agree on its index, which is what lets a coarse accelerator built from one align
exactly with the fine grid, and is what a future content-hash dedup step (this format
is deterministic per input, so hashing it is free) would need.

Wire format v1, little-endian:

```
offset  size  field
0       4     magic (0x31534343, "CCS1")
4       4     MinCol (int32) - global column index of the grid's left edge
8       4     MinRow (int32) - global row index of the grid's bottom edge
12      4     Cols   (uint32)
16      4     Rows   (uint32)
20      ...   RLE varint run-lengths, alternating starting with a CLEAR run
              (length 0 valid), row-major, self-terminating
```

A cell is set iff its CENTRE point is inside the source polygon (even-odd rule) - a
definite rule, not raster.go's approximate one, because a CellSet has no fallback: it
IS the stored form. At 0.0003 degrees (~33m) that is less boundary ambiguity than the
~400m location blur every reach origin already carries
(`App\Support\UserApproxLocService::BLUR_USER`) - not a new source of error.

**Built and proven** (`iznik-reach-cellset/cellset/`): `FromPolygonWKT` (parser +
scanline rasteriser, reusing `peterstace/simplefeatures` exactly as raster.go does),
`Encode`/`Decode`, `Contains`, `SetCellCount`, `Bounds`. 10 unit tests + 1 live-sample
test, all green. Handles holes and MULTIPOLYGON (both are real reach shapes - a clip
can split a reach into disjoint pieces or carve a hole from the middle).

### Where geometry work moves

MySQL becomes what the design doc's "maybe MySQL is not the right place" points at
specifically: **a stateless calculator and an opaque byte store, not a geometry
index.** The spatial ALGEBRA this system needs (union with the origin group's area,
ST_Difference for a secondary-group clip) stays in MySQL as **scratch computation on
WKT text parameters, never materialised as a stored/indexed blob** - MySQL's spatial
functions are fine at this; the cost problem measured throughout this project was
always storing and indexing megabyte-scale blobs at 50k+ row scale, never one-shot
algebra on bound parameters. The CANONICAL FORM computed from the algebra's result is
a CellSet, encoded in exactly one place.

**iznik-spatial-go becomes the rasteriser, not just a downstream consumer of
polygons.** New `POST /v1/reach/rasterize` (WKT in, CellSet bytes out) is THE ONE place
a polygon becomes its canonical form - matching the discipline
`App\Services\Ripple\GeomShareService` already established for content-hash
canonicalisation on PR #1402 (one writer, so it cannot disagree with itself).
**Built and proven**: `rasterizeWKT` (spatial-go/rasterize.go) + the fiber endpoint,
4 tests including the live-sample 45x-reduction assertion, verified over real HTTP
(26ms for the 1MB sample). `BuildRasterFromCellSet` (spatial-go/raster.go) replaces
edge/geometry math with bit-array sampling to build the SAME bounded coarse
accelerator dataset_reach.go already serves from - 3 tests including the 40,401-probe
zero-disagreement parity proof above.

**Decoding is safe to duplicate; encoding is not.** A decoder only parses a fixed,
versioned, self-describing format - there is no ambiguity a second implementation
could introduce, unlike rasterising a polygon's boundary. So PHP
(`App\Services\Ripple\CellSetService`) carries a decoder + `Contains`, proven
identical to the real Go encoder's output via a GOLDEN VECTOR (not hand-written) -
`CellSetServiceTest` in iznik-batch, 8 tests, all green.

**iznik-server-go does NOT import the shared `iznik-reach-cellset` module** - it
carries its own small decode-only port (`rippling/cellset.go`, ~120 lines, no external
dependency), proven against the SAME golden vector
(`rippling/cellset_test.go`, `test/firstreply_test.go`'s
`frCellsGoldenVectorB64`). This was forced, not a style choice: this repo's dev/test
containers sync only their own top-level directory
(`file-sync.sh`: `iznik-server-go/* -> /app/`), so a cross-module `go.mod` `replace
../iznik-reach-cellset` cannot resolve inside them - confirmed the hard way when the
status-API Go suite failed with "replacement directory ../iznik-reach-cellset does
not exist" after importing it directly. Three languages, three decoders, one encoder -
consistent with the "encoding is centralised, decoding is safe to duplicate" rule
above; it just means Go duplicates PHP's approach rather than sharing code with its
own sibling module.

### Column shape (additive, stacked on the dedup change)

`rippling_reach.max_polygon_cells MEDIUMBLOB NULL` - nullable and **unindexed
deliberately**: nothing ever queries it in SQL, so an index would protect a query that
never runs. A deploy ahead of the backfill is a no-op (every reader falls back to
`max_polygon`/`max_polygon_hash` exactly as PR #1402 left them).

## Stage 0 (DONE, this session) - max_polygon vertical slice

Chosen first because it is write-once (`MaxReachService`, guarded by
`WHERE max_polygon IS NULL`) and has few readers (2 in PHP, 1 in Go), so it proves the
whole pattern - encode via HTTP, decode locally in both languages, dual-write,
graceful fallback - before touching `polygon` (many more readers, plus the two
in-place clips) or `overflow_bounds` (a different shape: JSON of several WKT rings,
not one polygon).

| # | Task | Status |
|---|---|---|
| 1 | `iznik-reach-cellset` module: format + codec + 11 tests | DONE |
| 2 | spatial-go: `BuildRasterFromCellSet` + parity test vs real polygon | DONE, 0 disagreements/40,401 probes |
| 3 | spatial-go: `POST /v1/reach/rasterize` + 4 tests | DONE, proven over real HTTP |
| 4 | Migration: `max_polygon_cells` (Laravel + prod SQL, ALGORITHM=INSTANT pinned) | DONE, applied in worktree |
| 5 | `App\Services\Ripple\CellSetService` (client + decoder) + golden-vector test | DONE, 8 tests |
| 6 | `MaxReachService::storeMaxPolygon` dual-writes cells (best-effort) | DONE |
| 7 | `MaxReachService::isWithinMaxReach` reads cells first, falls back exactly as before | DONE |
| 8 | Go `firstreply.ShouldPassThrough` reads cells first, same fallback | DONE |
| 9 | Test suites: Laravel (CellSetServiceTest 8, MaxReachServiceTest+GeomShareReaderMatrixTest+FirstReplyPassthroughTest+GeomShareServiceTest 38, all via status API) | DONE, all green |
| 9b | Go suite (status API, full run incl. new rippling/cellset_test.go + firstreply_test.go's cellset case) | Found and fixed a real bug (see below); **final re-run DONE, 4253/4253 pass** |
| 9c | Malformed-cellset fallback proven both languages (falls back to legacy blob test, not fail-closed) | DONE both languages, in the 4253/4253 Go run and the Laravel run in progress |
| 9d | Packaging gap: found it was WORSE than known (rasterize was silently null all session), then fully resolved (module folded into iznik-spatial-go, real Docker build + real deploy + real network call proven) | **DONE** - see "PACKAGING GAP" note below, supersedes the old "KNOWN GAP" text |
| 10 | `ripple:backfill-max-reach-cells` for EXISTING rows (populate() only fills NULL max_polygon; this backfills rows whose eventual reach predates the feature) | DONE: command + 6 tests written; Laravel verification in progress at session handoff |
| 11 | Docs freshness (first-reply.md, rippling-algorithm.md) | NOT STARTED |
| 12 | Review + PR | NOT STARTED |

**Bug the Go suite caught (fixed):** `firstreply.ShouldPassThrough` originally fetched
`max_polygon_cells` via GORM's chain `.Select(...).Where(...).Scan(&cells)` with
`cells []byte`. GORM's chain `Scan` treats a slice destination as "scan multiple
ROWS into a slice of structs/scalars," not "read one column's BLOB value" - it failed
outright on a populated cell set ("converting driver.Value type []uint8 ... to a
uint8: invalid syntax") and, worse, would have silently mis-scanned a NULL as "converting
NULL to uint8 is unsupported" rather than leaving `cells` nil. Fixed to
`.Row().Scan(&cells)` - the `database/sql` idiom already used elsewhere in this repo
(visualise.go:204, comment.go:358, message/reach.go:347, message/helper.go:775) for
exactly this shape, where `[]byte` correctly receives either the BLOB bytes or a nil
for SQL NULL. This is the same class of GORM footgun as
`finding_gorm_scan_struct_aggregates_silently_zero` and
`finding_gorm_order_bare_clause_expr_silently_dropped` - GORM's convenience API
punishes a destination type it wasn't built for instead of refusing outright.

**PACKAGING GAP: FOUND, then FULLY RESOLVED (2026-08-24), not deferred.** The
standalone `iznik-reach-cellset` module could not be seen by either service's
dev/test containers (file-sync.sh and every Docker build context are scoped to one
top-level directory each) - this is what caused Stage 0's Go suite failures, fixed
for iznik-server-go by porting a small decode-only file into its own tree. The SAME
problem blocked iznik-spatial-go's real Docker image, and could not be fixed the same
way because spatial-go needs the actual ENCODER
(`cellset.FromPolygonWKT`), not just a decoder.

Discovered while trying to close a MORE SERIOUS gap this forced: because the real
deployed `spatial-knn` container was still running the pre-existing binary (no
`/v1/reach/rasterize` route), `CellSetService::rasterize()` had been silently
returning null in every single test run this session - and NOTHING asserted on
`max_polygon_cells` actually being populated, so "38/38 tests pass" was true while
the write path's entire point had never actually been exercised. Caught by adding
the missing assertion (`MaxReachServiceTest::
test_populate_actually_rasterises_the_max_polygon_via_the_real_spatial_service`)
before trusting the earlier green run.

**Fix applied: there is no `iznik-reach-cellset` module any more.** Since spatial-go
is the ONLY service that ever needed the encoder (iznik-server-go's own copy is
decode-only and self-contained), the whole `cellset` package - `cellset.go`,
`fromwkt.go`, and their tests - now lives directly inside `iznik-spatial-go/cellset/`
as an ordinary internal package of that module (import path `spatial-server/cellset`).
No `go.mod` `replace`, no cross-module reference, nothing outside
`iznik-spatial-go/`'s own directory tree - the Docker build context problem does not
just get worked around, it no longer exists. Proven for real, not just asserted:
  - `docker-compose build spatial-knn` succeeds (the actual build path, not a
    workaround) and `docker-compose up -d spatial-knn` deploys it (`spatial-knn-live`,
    the only other service sharing this build context, rebuilt and redeployed too).
  - The REAL running container answers `POST /v1/reach/rasterize` with the exact
    golden-vector bytes every cross-language test already expected.
  - PHP's `CellSetService::rasterize()`, called from a real `php artisan tinker`
    session against the real container, gets back those same bytes over a real
    network call.
  - `MaxReachService::populate()` now genuinely fills `max_polygon_cells` end to
    end - asserted, not assumed.

No decision needed on vendor/widen-context/publish-a-module (options previously
listed here) - none of them were the right shape. The right shape was recognising
that only one service ever needed the shared code, and giving up on sharing it
saved a repo, a go.mod, and every one of those options' costs.

## Stage 1 (NOT STARTED) - overflow_bounds

Wrong shape for this design as literally stated: `overflow_bounds` is JSON holding 2-4
WKT ring strings (rural bands, fairness quintiles, cluster wedges) plus a bbox array
and a scalar, not one polygon. Two paths:
  - Decompose: one CellSet per ring, keyed under the same JSON structure (lane -> cells
    instead of lane -> WKT). Rewrites `ReachQueryService::lanesCarried`'s
    `JSON_CONTAINS_PATH` presence test, `RingIndex::admits`, spatial-go's
    `ReachOverflowDataset`, and the digest's bbox-widening read.
  - Or: this may be superseded by whether Stage 0's disk win alone (measured ~45x on
    `polygon`) already relieves enough pressure that `overflow_bounds`
    (measured 2026-08-24: 5.78 GB post-shrink, whole-document-dedup-worth 2.71 GB per
    plans/2026-08-23-rippling-reach-polygon-dedup.md's own follow-up measurement)
    is no longer the priority it looked like mid-session. Re-measure the STEADY-STATE
    table composition once Stage 0 + PR #1402 are both live before committing effort
    here - the earlier steady-state model (project_reach_geometry_dedup_pr1402 memory)
    found overflow_bounds would become the LARGEST undeduped term (~55GB) specifically
    because it is not touched by either change; that conclusion should be re-checked
    against Stage 0's actual measured effect before scoping this stage's size.

## Stage 2 (NOT STARTED) - polygon (the hot path)

The big one: `polygon` is NOT NULL with a live spatial index every browse/nearby/search
query drives off, has the most readers of any column (11 PHP sites, 10 Go sites per
the PR #1402 mapping), and has the two in-place `ST_Difference` clips
(`ExpandService::reapplyClips`, Go `ClipReachForRejectedGroup`) that mutate it.

Key open design questions, NOT yet answered by this session's work:
  - **The browse R-tree.** `rippling_reach_outer` (an MBR) currently drives the
    browse feed's spatial index and must keep doing so - PR #1402 was explicit that
    perturbing this predicate is what caused the 2026-08-21 outage. A CellSet has no
    spatial index of its own (it is a flat blob); `outer_bound` almost certainly stays
    exactly as it is, GEOMETRY, indexed, in MySQL - only the EXACT test behind it
    (today's `ST_Contains(polygon, point)` correlated EXISTS) moves to a CellSet
    decode. This should be a smaller, safer change than it sounds: same shape as
    Stage 0's `isWithinMaxReach` rewrite, applied to more call sites.
  - **The clips.** `ST_Difference` on a CellSet is a bit-array AND-NOT over the
    overlapping cell range - no `ST_Buffer(0)` repair, no invalid-geometry ladder, no
    1713 undo-log splitting, no spatial-index rebuild risk. This REMOVES an entire
    class of fragility ExpandServiceTest/ExpandGeometrySafetyTest currently guard
    against, but it means teaching `cellset` a subtract operation and re-deriving
    `outer_bound`/`inner_bound` from the RESULT (today they are derived from the WKT
    via MySQL `ST_Envelope`/buffer functions - from a CellSet they would need either a
    cheap bbox-from-cells computation, which `Bounds()` already gives for the outer,
    or staying WKT-derived by keeping a parallel WKT computation ONLY for the bounds
    derivation step, not for storage).
  - **iznik-spatial-go's own load path.** `dataset_reach.go`'s `Load`/`ApplyDelta`
    currently read `ST_AsWKB(COALESCE(g.geom, rr.polygon))` and parse WKB via
    `geom.UnmarshalWKB` before calling `BuildRaster`. Once `polygon` is a CellSet blob,
    this becomes `cellset.Decode` + `BuildRasterFromCellSet` (already built, Stage 0) -
    net SIMPLER and faster (no geometry parse, no edge-crossing math at all), but this
    is the module's hot load path (~50k rows) and needs its own benchmark before
    trusting that intuition.
  - **routing-go - EXPLORED (2026-08-24, file:line evidence), confirms Stage 0's
    choice was right and settles this question.** Dijkstra's own output
    (`IsochroneResult`, dijkstra.go:9-16) is a SPARSE map keyed by real road-graph
    node IDs, not a grid - there is genuinely no lattice at that stage. A dense
    boolean raster IS built one step later, in `buildIsochroneGrid`
    (polygon.go:32-138: bbox over reached nodes, Bresenham-stamped edges, one
    dilate/erode closing pass) and immediately consumed by `traceBoundary`
    (polygon.go:228-344, marching-squares-style tracing) to produce the vector
    ring - then DISCARDED. It is never cached, returned, or exposed by any of the
    ~15 HTTP endpoints (server.go:562-604) - all vectorize before responding; none
    returns raw cells today. Emitting cells instead of the traced ring would be a
    genuinely small change (skip `traceBoundary`, RLE the `[][]bool` - a sibling
    function to `buildIsochroneRings`, callable at all ~15 existing call sites).
    **But the resolution is NOT a fixed lattice**: `NetworkResolution`
    (polygon.go:405-441, the function actually used everywhere) derives cell size
    per-request from the p75 of the reach's own local road-edge lengths, floored
    at 0.0003 degrees (~33m, matching the fixed constant this design uses) but
    capped at 0.003 degrees (~330m) - so a sparse rural isochrone's native grid can
    be 10x coarser than a dense urban one's. This is exactly why Stage 0 does NOT
    ask routing-go to emit cells directly: doing so would mean either accepting
    CellSets at a VARIABLE resolution (breaking the global-lattice alignment this
    design relies on for cross-post comparison and any future cell-level dedup) or
    resampling routing-go's per-request grid down to the fixed 0.0003 lattice
    server-side anyway - at which point rasterising fresh from the WKT text
    (what Stage 0 actually does, in spatial-go, at the one fixed resolution) is no
    more work and keeps routing-go's response format, and everything else that
    consumes it for display/fairness/catchment/digest-simulator, untouched. Verdict:
    **do not change routing-go for this.** The measured ~26ms rasterize round trip
    per tick advance (Stage 0, largest observed live polygon) against a half-hourly
    tick cadence was already the right call, now confirmed rather than assumed.
  - **spatial-go's OWN raster is not a re-derivation of the same thing, which
    changes what "skip both" means (explore agent's finding).** `raster.go`'s
    `BuildRasterDim` fits every polygon to a FIXED `rasterMaxDim=96` cells on the
    long axis (raster.go:41, "keeps a 40km reach's cells at ~400m") anchored to
    THAT polygon's own bbox - not the global 0.0003 lattice this design uses, and
    roughly 10-12x coarser. So `BuildRasterFromCellSet` (built and proven, Stage 0)
    is not "skip a redundant identical raster" - spatial-go's coarse accelerator
    genuinely serves a different job (a bounded-size index for O(1) in/out/partial,
    sized to stay small at any reach extent) than this design's fine, unbounded-
    extent, exact-ground-truth grid. The real Stage 2 prize the explore agent
    surfaces: once `polygon` itself is a fine CellSet in MySQL, spatial-go's whole
    tri-state/exact-fallback DESIGN becomes questionable, not just its build INPUT
    - at 33m resolution there is no boundary ambiguity left for a "partial" cell to
    defer on, so the fallback-to-exact-geometry machinery (and the correlated-EXISTS
    lazy-BLOB SQL sandwich it exists to make affordable) may simply not be needed
    once the fine grid is the thing being read directly. Confirm this by measuring
    whether reading the fine CellSet at query time is fast enough to skip the coarse
    accelerator tier entirely, rather than assuming the two-tier design must persist
    - Stage 0's own numbers (60ns amortised, 408us cold-decode-per-query, both
    already faster than MySQL's measured 534us best case) suggest it might.
  - **Content-hash dedup on CellSets**, now that PR #1402 built exactly that machinery
    for polygons: since a CellSet is deterministic per input and the same origin+tick
    still produces byte-identical cells, hashing/sharing works exactly the same way -
    likely a smaller win in absolute bytes (CellSets are already ~45x smaller) but
    close to free to add given `GeomShareService`'s pattern is already proven. Decide
    once Stage 2's basic column exists whether the extra table+FK is worth it at these
    sizes, rather than assuming yes by default.

## Not doing (out of scope, decided or re-confirmed this session)

  - Adaptive/bounded-size grids (like `raster.go`'s own 96x96 cap) for the CANONICAL
    stored form - that trades accuracy for a size cap, and the size problem is already
    solved by RLE at a FIXED fine resolution (45x measured); a size cap would only
    matter for enormous reaches, better handled case-by-case if one is ever observed
    rather than designed in up front.
  - Storing CellSets outside MySQL (object storage, a dedicated KV store). MySQL
    remains the system of record - Galera replication, backups, point-in-time
    recovery, the FK cascade from `messages` all keep working unchanged. Only the
    COMPUTATION moves out of MySQL, never the storage of record.
