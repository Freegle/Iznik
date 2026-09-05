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

That measurement is a one-off: it needs a ~1MB real polygon that is not checked in
(`TestBuildRasterFromCellSet_AgreesWithPolygonBuild` now runs when `REACH_SAMPLE_WKT`
points at one; it previously named a path that could never exist again, so it could
only ever skip). **Parity is now also enforced on every run**, by
`TestBuildRasterFromCellSet_AgreesWithAStaircaseBoundary`: a generated lattice
staircase - the shape class production actually stores, since every reach and every
ring is a marching-squares tracing of a routing-server raster, with hundreds of
right-angle corners sitting exactly on cell edges - probed at the same 40,401 points.
38,552 definite agreements, 1,849 deferred to the fine grid, **0 contradictions**.

The rings are the largest prize. Measured 2026-08-23 by
`ripple:shrink-overflow-bounds`' own dry run: `overflow_bounds` was **HALF the table at
860KB a row**, its rings average **37,000 vertices**, and every one of those vertices
already sits on the exact 0.0003-degree lattice a cell set uses - because the rings are
traced from a routing-server raster. The read path then downsamples that tracing into a
~130m coarse raster anyway. So the stored precision is parsed once, thrown away, and
never used by the surface that reads it most.

**The ring reduction is INFERRED, not measured.** 860KB/row and 37,000 vertices are real
production figures, and a ring's boundary density is close to the reach polygon's
(31,145 vertices) that was measured at 45x - but no real ring has been through this
encoder, because the prod DB tunnel was not up when this stage was built. Do not quote
45x for the rings.

**And the ratio is very shape-dependent, which is worth knowing before predicting a
disk saving.** Cell bytes scale with AREA and boundary complexity; WKT bytes scale with
VERTEX COUNT. Measured against this encoder during Stage 2:

| shape | vertices | WKT | cells | stored (base64) | reduction |
|---|---|---|---|---|---|
| real production reach polygon | 31,145 | 1,017,565 | 22,804 | n/a (blob) | **45x** |
| generated traced blob, reach-sized | ~4,000 | 66,375 | 11,884 | 15,848 | 4.2x |
| generated traced blob, ring-sized | ~4,000 | 66,215 | 23,052 | 30,736 | 2.2x |
| generated lattice staircase (adversarial) | 18,003 | 294,727 | 64,379 | 85,840 | 3.4x |

The generated shapes under-sell it because they carry a tenth of production's boundary
detail for the same extent - the real win comes precisely from the fact that production
stores an enormous number of vertices to describe an area a grid describes cheaply. The
adversarial staircase is the RLE worst case (a run boundary in nearly every row) and is
included as a floor, not a forecast. The honest way to size the ring saving is to run
`ripple:backfill-ring-cells` against production data and measure the column.

## Design

### The format: `cellset` (an internal package of `iznik-spatial-go`)

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

**Built and proven** (`iznik-spatial-go/cellset/`): `FromPolygonWKT` (parser +
scanline rasteriser, reusing `peterstace/simplefeatures` exactly as raster.go does),
`Encode`/`Decode`, `Contains`, `SetCellCount`, `Bounds`, `Subtract`. Unit tests plus a
live-sample test, all green. Handles holes and MULTIPOLYGON (both are real reach shapes
- a clip can split a reach into disjoint pieces or carve a hole from the middle).

**Which operations may be duplicated in another language, and which may not.**
`FromPolygonWKT` may not: turning a vector boundary into cells involves a real
scanline-fill judgement call, and two independently-written rasterisers could disagree
at a boundary cell in ways nothing would ever catch. It stays in one place, reached over
HTTP (`POST /v1/reach/rasterize`) - the same discipline `GeomShareService` established
for hash computation in PR #1402. `Decode`, `Contains`, `Encode` and `Subtract` may:
parsing a fixed versioned format, testing a bit, serialising an already-computed grid,
and AND-NOTing two grids on a shared fixed lattice are all deterministic arithmetic with
nothing to canonicalise. So the Go API carries a self-contained port of everything
except the rasteriser, and PHP carries the same, and a reader on a hot path never pays a
network round trip to test one point.

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

**Everything except the rasteriser is duplicated in PHP and in the Go API**, per the
rule above: `Decode`, `Contains`, `Encode` and `Subtract` in
`App\Services\Ripple\CellSetService` and in `iznik-server-go/rippling/cellset.go`, each
proven against the SAME golden vector generated by the real encoder rather than
hand-written (`CellSetServiceTest`; `rippling/cellset_test.go` and
`test/firstreply_test.go`'s `frCellsGoldenVectorB64`).

Every decoder also refuses a grid larger than `MaxCells` (2^28, about 16,384 cells a
side and a 32MB bitmap - far beyond any real reach, whose largest measured example was
4.4 million cells). `Cols` and `Rows` are each uint32, so a corrupt header can claim
1.8e19 cells; a decode failure has a defined meaning to every caller (fall back to the
polygon) where an exhausted process does not. The limit is the same number in all
three, so a value one language accepts is never rejected by another.

**iznik-server-go does NOT import iznik-spatial-go's `cellset` package** - it carries
its own small self-contained port with no external dependency. This was forced, not a
style choice: this repo's dev/test containers sync only their own top-level directory
(`file-sync.sh`: `iznik-server-go/* -> /app/`), so a cross-module `go.mod` `replace`
cannot resolve inside them - confirmed the hard way when the status-API Go suite failed
with "replacement directory does not exist" after importing it directly. Three
languages, one rasteriser.

### Column shape (additive, stacked on the dedup change)

Three columns, one per geometry the table stores, all nullable and all **unindexed
deliberately**: nothing ever queries the bytes in SQL - they are opaque to MySQL and
decoded entirely in application code - so an index would only protect a query that
never runs. A deploy ahead of a backfill is a no-op in every case, because every
reader falls back to the geometry (or, since PR #1402, its content hash).

| Column | Type | Mirrors | Read by |
|---|---|---|---|
| `max_polygon_cells` | MEDIUMBLOB NULL | `max_polygon` - the eventual reach | the first-reply passthrough gate, PHP and Go |
| `polygon_cells` | MEDIUMBLOB NULL | `polygon` - the reach so far | `ReachQueryService::isWithinReach` |
| `overflow_cells` | JSON NULL | `overflow_bounds` - the rings, per lane | iznik-spatial-go's ring index build |

`overflow_cells` is JSON rather than a blob because it holds one cell set per lane on
the same JSON paths its rings use, so a consumer asks for a lane with the identical
`JSON_EXTRACT` it already used. Its values are base64, since a JSON column's value must
be valid UTF-8.

None of the three retires the geometry it mirrors. The clips still run `ST_Difference`
against `polygon`; `outer_bound`/`inner_bound` are still derived from a transient WKT;
the map overlay still draws the ring vectors; and `has_overflow` is still GENERATED from
`overflow_bounds IS NOT NULL`.

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
| 11 | Docs freshness (rippling-algorithm.md §9b) | DONE - rewritten for all three columns; `iznik-spatial-go/cellset/**` and `dataset_reachoverflow.go` added to its `covers:` |
| 12 | Review + PR | DONE - PR #1406 |
| 13 | The single-point reader must NOT decode: `decode()` builds one entry per covered cell (measured 317ms / 128MB on a production-sized reach, on the reply gate). `containsEncoded` / `CellSetContains` walk the run stream instead (0.002ms, zero allocation, identical answers) | DONE both languages, and applied to `isWithinMaxReach` too |

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

## Stage 1 (DONE) - polygon, and both in-place clips

`polygon` is NOT NULL, carries a live spatial index the browse feed drives off, has
the most readers of any column (11 PHP sites, 10 Go sites per the PR #1402 mapping),
and has the two in-place `ST_Difference` clips that mutate it. What it now has
alongside it is `polygon_cells`, on exactly the terms Stage 0 established for
`max_polygon_cells`: nullable, unindexed, additive, and never the thing a reader
insists on.

| # | Task | Status |
|---|---|---|
| 1 | Migration `polygon_cells` (Laravel + prod SQL, ALGORITHM=INSTANT pinned) | DONE, applied and re-applied in the worktree (idempotent) |
| 2 | `cellset.Subtract` in all three implementations (spatial-go, the Go API's port, PHP) | DONE, cross-checked against each other |
| 3 | `CellSetService::encode()` so a clipped grid can be written back | DONE, proven byte-identical to the real encoder's golden vector |
| 4 | Every `polygon` write also writes `polygon_cells` - init upsert, advance UPDATE, recompute shrink, backfill rewrite | DONE, one helper so the four cannot drift |
| 5 | `ExpandService::reapplyClips` clips the cells with `subtract`, not by re-rasterising | DONE |
| 6 | Go `ClipReachForRejectedGroup` does the same, via `spatial.RasterizeWKT` for the rejecting group's own area | DONE |
| 7 | `ReachQueryService::isWithinReach` answers from the cells when present | DONE, falls back to the exact sandwich path unchanged when NULL |
| 8 | `ripple:backfill-reach-cells` for rows whose reach predates the change | DONE |
| 9 | `CellSetService::rasterize` rejects a 200 that is not a cell set | DONE - an empty or truncated body was previously STORED, which would have looked converted while every reader decode-failed and fell back for the life of the row |

**Why the clips subtract rather than re-rasterise.** After `ST_Difference` the surviving
WKT is frequently BIGGER than the rejecting group's own area - which is the whole reason
this format exists - so re-rasterising the result would cost more than the write it is
meant to make cheap. Subtracting two grids is a bitwise AND-NOT over the overlapping
cell range, and because `CellDegrees` is fixed rather than per-blob both operands are
already on the same lattice: no resampling, no reprojection, and no ambiguity for two
implementations to disagree about. That last point is why `Subtract`/`Encode` are safe
to duplicate in every language that reads a cell set, while turning a polygon's VECTOR
BOUNDARY into cells stays centralised in the one rasteriser.

**What the clips do NOT touch.** `outer_bound` and `inner_bound` are still GEOMETRY,
still spatially indexed, still derived MySQL-side from the transient WKT in the same
statement as the polygon. PR #1402 was explicit that perturbing the browse feed's
driving predicate is what caused the 2026-08-21 outage, and nothing here goes near it.

**Failure direction.** Anywhere the cells cannot be produced or clipped - rasteriser
down, bytes that will not decode, a row not yet backfilled - the column is left or set
NULL and the reader falls back to the polygon. It is never left holding a stale grid,
because a stale grid is MORE permissive than the polygon it disagrees with: it would
admit members the reach no longer covers.

## Stage 2 (DONE) - overflow_bounds, the rings

The rings are the table's worst case and by some distance. Measured 2026-08-23
(`ripple:shrink-overflow-bounds`' own docblock): `overflow_bounds` was HALF the table
at 860KB a row, and each ring averages 37,000 vertices.

Two facts settle the design, and both were already written down in this repo before
this stage started:

  - **The rings' vertices already sit on the 0.0003-degree lattice**, because they are
    traced from a routing-server raster. A cell set is therefore a recovery of the
    ring's own source grid, not a new approximation of it.
  - **The read path already throws that precision away.** `iznik-spatial-go`'s
    `ReachOverflowDataset` downsamples every ring into a ~130m coarse raster
    (`ringRasterDim = 192`) at load, and that raster - not the WKT - is what answers
    every admission question. The stored 37,000 vertices exist only to be parsed once
    and discarded.

So `overflow_cells` mirrors `overflow_bounds` exactly: same nesting, same JSON paths,
each ring's WKT replaced by base64 cell bytes. Same paths so spatial-go asks for a lane
with the identical `JSON_EXTRACT` it already used, and no consumer needs a second lane
table. Base64 because a JSON column's value must be valid UTF-8; the ~33% inflation is
nothing against the reduction.

| # | Task | Status |
|---|---|---|
| 1 | Migration `overflow_cells` JSON NULL (Laravel + prod SQL, INSTANT pinned) | DONE, applied and re-applied; `has_overflow` VIRTUAL GENERATED verified intact |
| 2 | `ExpandService::overflowCellsJson` - one encoder, so every write path agrees | DONE |
| 3 | Both ring write paths write it: the init upsert and the recompute shrink | DONE (`advanceDue` writes no rings - it moves a tick pointer along a stored schedule, and the rings belong to the reach as a whole) |
| 4 | The reuse path carries the cells across, so a reused row costs no rasterise calls | DONE |
| 5 | spatial-go builds each lane's coarse raster from the cells when present, else parses the WKT | DONE, per lane |
| 6 | `ripple:backfill-ring-cells` for rings that predate the change | DONE |

**What the coarse raster deliberately keeps.** The 192-cell raster is unchanged: 2 bits
a cell, ~9KB per ring, ~62MB across the ~6,700 live ring items - a measured figure this
must not quietly inflate, and a fine cell set held in the index instead would be
megabytes per ring decoded. So the cells replace the PARSE, not the accelerator, and
the in/partial/out contract every caller relies on is the same one
`BuildRasterFromCellSet` and `BuildRasterDim` both implement.

**Per lane, not per row.** A lane's cells are filled in independently, so a
partly-converted table is a normal state rather than a migration window: any lane whose
cells are absent, malformed, or unrasterisable falls back to its WKT, and
`overflow_bounds` stays the authority. That fallback is asserted both ways - the cells
are preferred even when the WKT slot holds something that would fail to parse, and a
deliberately corrupt cells blob still leaves the lane served by its ring.

**What still reads the ring vectors, and must.** The map overlay
(`iznik-server-go/message/reach.go`) draws the rings, so it genuinely needs the vector;
"which lanes does this post carry" (`ReachQueryService::lanesCarried`) is a
`JSON_CONTAINS_PATH` test; and `has_overflow` is GENERATED from
`overflow_bounds IS NOT NULL` and indexed, with both the spatial-go ring load and its
delta hanging off that index. None of those changes.

**Deploy ordering.** `dataset_reachoverflow.go` queries `overflow_cells`
unconditionally, following the stance `dataset_reach.go` already documents for
`polygon_hash` (this module has no information_schema readiness gate, and the migration
runs before any service is redeployed). That makes the ordering load-bearing: run the
migration first, then redeploy spatial-go.

## Stage 2's earlier open questions, and where they landed

Recorded before Stages 1-2 were built; kept because several were answered by doing the
work rather than by deciding not to.
  - **The browse R-tree. ANSWERED: it is untouched, and stays untouched.**
    `outer_bound` is still GEOMETRY, still indexed, still derived MySQL-side from the
    transient WKT in the same statement as the polygon. Only the single-point exact
    test behind it moved to a cell-set decode (`ReachQueryService::isWithinReach`),
    exactly the shape Stage 0's `isWithinMaxReach` rewrite took. The browse feed's own
    SQL (`ReachBrowseWhere`) was deliberately NOT converted: it is an expression
    embedded in a larger query whose plan hangs off that index, and the 2026-08-21
    outage is what perturbing it looks like.
  - **The clips. ANSWERED: `Subtract`, in all three implementations.** A cell-set
    difference is a bitwise AND-NOT over the overlapping cell range - no
    `ST_Buffer(0)` repair, no invalid-geometry ladder, no 1713 undo-log splitting, no
    spatial-index rebuild risk. `outer_bound`/`inner_bound` did NOT need re-deriving
    from the result: the clip statement already NULLs `inner_bound` and leaves
    `outer_bound` stale-loose (safe, since the exact test decides), and the next tick
    re-derives both from the WKT. So the parallel-WKT option this question worried
    about was never needed.
  - **iznik-spatial-go's own load path. PARTLY ANSWERED - done for the RINGS, not yet
    for the reaches.** `dataset_reachoverflow.go` now builds each lane's coarse raster
    from the cells when present (Stage 2), which is where the parse cost actually
    was: 37,000 vertices and ~0.8MB per ring. `dataset_reach.go` still reads
    `ST_AsWKB(COALESCE(g.geom, rr.polygon))` and parses WKB. Switching it to
    `cellset.Decode` + `BuildRasterFromCellSet` is the same one-line-per-lane change,
    but it is this module's hot load path (~50k rows against the rings' ~4,400) and
    should be benchmarked rather than assumed - and unlike the rings it cannot fall
    back per item, since `polygon` is NOT NULL and every row has one.
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

## Stage 3 (BUILT 2026-08-25) - stop storing the polygons at all

Edward's direction, replacing the earlier Stage 3 sketch (which only re-pointed the
spatial index's build input and kept every polygon): the point of this design is disk,
and a cell grid stored ALONGSIDE its polygon saves none. So the endgame is that
`rippling_reach` stores NO fat geometry at all. `polygon`, `max_polygon` and
`overflow_bounds` go; the cells columns become the only stored form; the spatial
server answers containment on the grid; anything that genuinely needs a vector gets
one traced from the cells on demand. PR #1403 (dedup maintenance schedule) is closed
as obsolete; #1402's dedup machinery is unwound by this stage. #1402 merged to master
2026-08-24, so this branch now targets master directly.

### What goes, what stays

Goes: `polygon` (+ its R-tree `rippling_reach_polygon`), `max_polygon`,
`overflow_bounds`, `polygon_hash`, `max_polygon_hash`, both FKs, the whole
`rippling_reach_geom` table, `GeomShareService` (PHP), `rippling/geomshare.go` (Go),
the dedup/drain/GC/verify commands, the Douglas-Peucker simplify-on-oversize ladder
and the split-advance two-spatial-index workaround in ExpandService (cells are 22KB
and there is only one spatial-indexed column left to update, so neither problem can
recur), `ripple:shrink-overflow-bounds` and the WKT `ripple:backfill-rings`.

Stays, deliberately:
  - `outer_bound` (NOT NULL, R-tree) and `inner_bound`. These are NOT envelopes:
    outer = `ST_Buffer(ST_Simplify(polygon, 0.002), 0.002)`, inner the negative
    buffer (ReachBoundsService). ~19KB a row, they are the R-tree access path for
    every SQL-side prefilter and the cheap-decide ladder, and at these sizes they
    are noise next to what is being removed. The sandwich contract
    (outer ⊇ reach ⊇ inner) is unchanged.
  - `lat`/`lng`, `status`, `schedule`, tick bookkeeping - untouched.
  - `has_overflow` - regenerated FROM `overflow_cells` instead of `overflow_bounds`
    (DDL: drop and re-add the generated column and its index; it is VIRTUAL, small).
  - MySQL stays the system of record for the cells bytes; spatial algebra on scratch
    WKT params (never stored) remains allowed, e.g. deriving outer/inner in the same
    statement that writes a tick, from the WKT the routing server just returned.

### Where each question is answered afterwards

One writer rule is unchanged: boundary -> cells happens only in spatial-go
(`POST /v1/reach/rasterize`). Fixed-format arithmetic on the bytes (probe, subtract,
encode, and the new streaming measures below) may be duplicated per language.

| Question | Today (this branch) | Endgame |
|---|---|---|
| Reply gate "is this point in reach" (PHP ReachQueryService, Go chat gate) | cells probe, polygon fallback | cells probe; fallback only while the column still exists (guarded), then none |
| Max-reach passthrough gate | cells probe, max_polygon fallback | same treatment |
| Feed universe (isochrone/message.go fetchReachCandidates) | SQL sandwich + exact ST_Contains (the 2026-08-21 query) | spatial server id-list (badge pattern, SPATIAL_REACH_MODE made always-on); degraded mode below |
| Badge (reachspatial.go) | id-list, `partial` resolved by SQL exact test | id-list, `partial` gone (fine grid decides exactly); legacy partial resolved by fetching cells + probe |
| Search reach arm (message/search.go) | outer_bound + polygon ST_Contains driving the polygon R-tree | msgid IN (spatial id-list), outer_bound conjunct kept as belt |
| Digest recipient selection (one message, many member points) | ST_Contains(mr.polygon, point) per member in SQL | NEW `POST /v1/reach/admits` (msgid + points -> admitted keys), mirroring /v1/reachoverflow/admits which the digest already calls fail-closed; outer_bound prefilter kept in SQL |
| Digest unmailed gate (one recipient, many messages) | correlated sandwich EXISTS + exact | id-list from /v1/reach/containing spliced in, exactly as ringRescueIds already splices ring admissions |
| Digest reach radius (score denominator) | ST_AsText(polygon) + vertex walk in PHP | streaming `MaxDistanceFrom(origin)` over the run stream (touches run endpoints only, no decode); PHP port, deterministic arithmetic |
| milesOutsideReach (RippleReplyService) | ST_Distance on re-tagged polygon | streaming `DistanceToNearestCell`: min point-to-run-segment over runs, O(runs), exact at lattice resolution (33m, well inside the miles rounding it feeds) |
| Map overlay: reach GeoJSON (message/reach.go) | ST_AsGeoJSON(polygon, 5) | NEW `POST /v1/reach/vectorize` (cells -> simplified MULTIPOLYGON, tolerance param): marching-squares trace ported from routing-go + DP simplify, ONE implementation |
| Map overlay: ring GeoJSON per lane | ST_AsGeoJSON(ST_Simplify(GeomFromText(ring))) | vectorize per lane from overflow_cells |
| Group-intersect tests (clip eligibility, retraction, crosspost count, origin-inside) | ST_Intersects(reach polygon, g.polyindex) | rasterize the group's polyindex (scratch, already done in the Go clip) + grid ops: Intersects = any common cell, Within = A subset of B; pure cell arithmetic |
| Rejection clip | ST_Difference in SQL + cells Subtract alongside | cells Subtract ONLY, then re-derive outer/inner from vectorize(clipped cells) via the existing outerExpr/innerExpr on the scratch WKT |
| outer/inner derivation at tick write | outerExpr/innerExpr over the polygon being written | same expressions over the routing WKT as a scratch param in the same statement; the WKT is in hand every place the polygon used to be written |
| spatial-go reach index build (dataset_reach.go) | ST_AsText + WKT parse + 96-cell raster per row | read polygon_cells, store the cells as the item blob, probe the run stream at query time: EXACT, `partial` ceases to exist; per-row fallback to the WKT path while the column survives |
| Reach envelope shipped to the feed client (reach_wkt x3 sites) | ST_AsText(ST_Envelope(polygon)) | ST_AsText(ST_Envelope(outer_bound)): display-only, 0.002 deg wider, still one small expression per row |

### Failure/degraded modes (the polygon fallback no longer exists)

  - Writes: rasterize is now load-bearing on the tick path. A failed rasterize FAILS
    the tick advance (post keeps its previous reach, retried next sweep) - it must
    never write a row with NULL cells, because NULL no longer means "read the
    polygon", it means the post has no reach. Reach growth pausing while spatial-go
    is down is acceptable (half-hourly cadence, resumable); a reach silently
    vanishing is not.
  - Feed/search/badge with spatial-go down: outer_bound-superset candidates from SQL,
    exactness restored by probing polygon_cells for JUST the returned page (~20 rows,
    ~450KB blob fetch, 0.002ms/probe). Bounded, correct, slower - a degraded mode,
    not a second authority.
  - Digest: /v1/reach/admits fails CLOSED like RingIndex::admits (nobody admitted
    beats mailing someone the site would turn away).
  - Corrupt cells bytes: the probe's "cannot answer" return now surfaces as
    not-in-reach plus a logged error (fail closed), not as a polygon retry.

### Schema and rollout (one PR, two-state code, operator-ordered DDL)

The code ships column-existence-guarded (the codebase's existing pattern:
Schema::hasColumn memoized in PHP, GeomShareReady/ReachBoundsReady-style
one-shot checks in Go): with the old columns present it still writes/reads
them as fallback; with them absent every fallback branch is dead. Dev/CI
migrations DROP the columns, so CI proves the post-drop world; prod keeps them
until the operator runs the drop DDL. Order on prod:

  1. Merge + deploy (batch bind-mount, apiv2, spatial-go). New rows now carry cells.
  2. Run the backfills to 100%: ripple:backfill-reach-cells,
     ripple:backfill-max-reach-cells, ripple:backfill-ring-cells. They read the old
     columns (COALESCE through the geom table for drained rows), so they run BEFORE
     any drop. They refuse politely post-drop.
  3. Verify coverage (the drop SQL's own guard re-checks: it refuses while any live
     row has NULL polygon_cells).
  4. Run the idempotent drop SQL: drop FKs, hash columns, R-tree, polygon,
     max_polygon, overflow_bounds, regenerate has_overflow, drop rippling_reach_geom.
     DROP COLUMN rebuilds the table INPLACE, which is what finally returns the
     ~50GB .ibd (plus ~19.4GB/node rippling_reach_geom) to the OS.
  5. A later trivial PR deletes the then-dead fallback guards.

Steady-state size afterwards, rough: ~154k rows x (cells ~23KB + outer ~19KB +
inner + meta) plus overflow_cells (unmeasured on real rings, see Stage 2 caveat):
order 10-15GB against the ~185GB the un-deduped model was heading for.

### Interactions with open PRs

  - #1404 (FORCE INDEX in MaxReachService::populate): the pathology survives the
    column swap ("lacks a max reach" becomes max_polygon_cells IS NULL and still
    matches nothing once caught up), so the rewrite here carries the same
    FORCE INDEX (rippling_reach_status_index) and comment; whichever merges second
    resolves trivially. The suggested composite becomes (status) alone - a blob
    column cannot usefully join it.

### MEASURED 2026-08-25 - what the parity run actually found

`ripple:verify-cells-parity` (new) asks the OLD question and the NEW question of the same
row at the same points, per read case. Sampling is weighted to the boundary band - the
polygon's own vertices, jittered by fractions of a cell - because uniform points over a
bounding box are dominated by cases where a lattice and a boundary cannot disagree, so a
report built on them reads as 100% agreement and proves nothing.

Run against 8 real drive-time isochrones straight from the routing server (1,615 to 34,471
vertices, 45KB to 965KB of WKT), with three seeded group areas - one containing the
reaches, one overlapping their eastern half, one far away - so the group cases have
something real to compare:

| Read case | Result |
|---|---|
| point-in-reach | 640 probes, 88 differ - 87 boundary probes at **exactly 0.000m**, one interior probe at **7.98m**, **none beyond a cell**, exterior 0/80. Probes at 0.5 and 1.5 cells off the edge all AGREED |
| point-in-max-reach | 9/640 differ, worst 8.0m |
| reach radius | worst 0.63% relative; 7 of 8 rows under 0.2% |
| distance-outside-reach | worst 94.2m absolute, on a value reported in miles |
| reach extent | outer_bound's envelope is 223m/side WIDER - the 0.002-degree buffer, as designed, so still a superset |
| traced boundary | coverage identical on all 8 rows |
| group intersects/within | 15 tests, **15/15 agree on both** intersects and within, 0 failures |
| clip comparison | 7 tests, **0 cells** of symmetric difference: `rasterise(ST_Difference(poly, group))` and `Subtract(cells(poly), cells(group))` produce identical coverage |

Direction: 87 are polygon-out / grid-in at 0.000m, which is not lattice error at all -
ST_Contains excludes a point lying ON the boundary while the grid includes the cell whose
centre is inside. ONE is the other way (polygon-in / grid-out, 7.98m inside the edge), so
the grid does occasionally miss a point the polygon covered. Eight metres, against a 33m
lattice and the ~400m of location blur every origin already carries.

An earlier run of this reported "87, all one direction, all at exactly 0.000m". That was
partly an ARTEFACT of two harness bugs (segments invented across ring boundaries, which
shrinks measured distances; and bbox-derived "interior" points that were really outside).
Both fixed; these are the numbers from the corrected harness.

Compression on those same 8 Bristol isochrones: 36.2x to 43.7x. **But measured on SIX REAL
PRODUCTION polygons (read-only over the live tunnel, 2026-08-25): 19.5x to 22.0x, 20.2x
overall**, across reaches of 7,787 to 33,819 vertices. THAT is the number to quote. The 45x
from one earlier polygon and the 36-44x here are both the top of the range.

Production column sizes, mean over the 200 most recent rows (computed server-side, blobs
never shipped): polygon 297KB, max_polygon 416KB, overflow_bounds 366KB, outer_bound 39KB -
~1,118KB a row, which matches the ~1.2MB/row in the steady-state model. Applying 20x to the
three fat columns and keeping outer_bound gives ~92KB a row: **~12x smaller overall**, ~60GB
to ~5GB today and ~164GB to ~14GB at steady state. Note what that leaves: **outer_bound is
then 42% of the row**, so the geometry that STAYS becomes the biggest single term, and
squeezing the cells further would be the wrong place to look next.

**Two flaws in the measurement itself, found by running it rather than trusting it.** Both
are the reason to distrust a clean-looking verification report:
  - distance-to-boundary was first asked of `ST_Boundary`, which returns NULL on the ~94%
    of real reach polygons that are technically invalid. Every distance came back null,
    the guard skipped the threshold check entirely, and the report printed "worst
    disagreement 0.0m, 0 beyond one cell" while measuring NOTHING. It now computes exact
    point-to-segment distance in PHP, and a difference it cannot measure is a FAILURE.
  - the first fallback was nearest-VERTEX, an upper bound loose enough to report two
    spurious failures at exactly 50.0m - which was the 1.5-cell offset of the probes
    themselves.
  - and `spatial-knn` was still running the OLD binary (404 on vectorize and
    groups-intersecting), the same stale-container trap as the previous stage. Rebuilt and
    redeployed before any of the above was believed.

### The drop, and why it is opt-in in dev/CI for now

The migration and its production SQL both REFUSE while any live row has no
`polygon_cells`. Proven by execution against a clone of the real table structure: refuses
correctly on an uncovered row, drops everything on the first pass, does nothing on the
second, leaves `has_overflow` regenerated from `overflow_cells` and
`rippling_reach_outer` intact while the polygon R-tree and both hash indexes are gone.

It is gated on `RIPPLE_DROP_LEGACY_GEOMETRY` and off by default, which is a decision about
TEST COVERAGE rather than caution about the DDL. The transition era - columns present,
cells preferred - is what production runs FIRST, for as long as the backfills take. Letting
dev/CI drop the columns now would force every polygon-writing fixture to be converted and
would leave that era with no tests that execute its SQL. Trading away coverage of the era
that runs first, to gain coverage of the era that runs later, is the wrong way round. The
cells-only branches are covered instead by forcing the era guard (`LegacyGeometry::fake`,
`rippling.SetLegacyGeomForTest`), which works precisely because those branches never name a
dropped column - and `PostDropEraTest` asserts both the answers and, by reading the SQL
actually issued, that no dropped column appears in it.

The follow-up PR deletes the then-dead legacy branches (including `GeomShareService` and
`rippling/geomshare.go`, which survive here only as the read path for a legacy row),
converts the fixtures, and turns the migration on by default - so the schema and the code
stop diverging at the same moment.

### Test/measurement obligations before this stage is called done

  - Both suites green via the status API with the columns DROPPED in the test schema
    (fixtures/factories that wrote polygon write cells; golden vectors unchanged).
  - Feed parity: id-list universe vs the old SQL universe on real viewer points,
    mirroring the 40,401-probe methodology; zero membership disagreements expected
    (both derive from the same cells).
  - Vectorize round-trip: rasterize(vectorize(cells)) == cells on real grids
    (the trace must not lose cells at this resolution).
  - EXPLAIN on the rewritten feed/search/digest queries on the dev DB: the id-list
    shapes must stay keyed lookups (the 2026-08-21 regression shape is an EXISTS
    that decorrelates the id list - see isochrone/reachspatial.go's comment).
  - Perf spot-checks: digest recipient pass per message, reply gate, map overlay
    endpoint, tick advance including the rasterize round trip.

## Not doing (out of scope, decided or re-confirmed)

  - Adaptive/bounded-size grids for the canonical stored form (fixed fine lattice
    is the design; RLE already solved size).
  - Storing CellSets outside MySQL. Only computation moves out of MySQL, never the
    storage of record.
  - Content-hash dedup on CellSets (the earlier future-idea bullet): dead, the
    polygons it would dedup are being removed instead, and 23KB rows do not earn a
    hash table + FK.
  - Emitting cells directly from routing-go (variable-resolution grids break the
    global lattice; re-confirmed, see the Stage 2 exploration notes above).
