# Reach engine occupancy: shrink the working set

2026-09-01. Supersedes the 2026-08-31 brief. Everything below the "Measured" heading
is a full scan of the live production artifacts, not an estimate. The brief's three
targets survive; two of them are worth roughly twice what it thought, its third target
is already done, and the two largest levers were not in it at all.

## Status: built, on branch feature/reach-occupancy

Measured on the committed Bristol extract, master against the branch:

| artifact | master | branch | saved |
|---|---|---|---|
| graph.snap | 13,933,275 | 6,257,653 | 55.1% |
| partition.snap | 335,837 | 240,214 | 28.5% |
| leaftables.snap | 3,396,904 | 2,548,820 | 25.0% |
| matrices.snap | 10,557 | 10,573 | -0.2% |
| **total** | **17,676,573** | **9,057,260** | **48.8%** |

Projected on the live artifacts, from the exact census counts (both "before" figures
reconstruct the real file sizes to the byte, so the "after" figures rest on the same
arithmetic):

| artifact | before | after | saved |
|---|---|---|---|
| graph.snap | 4,711.5MB | 2,249.7MB | 52.3% |
| leaftables.snap | 1,506.5MB | 1,130.2MB | 25.0% |
| partition.snap | 92.1MB | 66.2MB | 28.1% |
| **total** | **6.32GB** | **3.45GB** | **45.3%** |

graph.snap and partition.snap are heap-loaded, so 2,488MB comes off the unreclaimable
side; the leaf tables are mmap'd, so their 376MB is 376MB more room for the pages that
were being refaulted.

Done: the drive-only graph, every quantisation below, the Grid rewrite, the
Idx/ChainEndA merge, the leaf-table metres, the LeafOf narrowing, the artifact
fingerprints, the loopback pprof listener, the GC limits, the cache bounds, and the
`isochrones.source` enum fix.

Not done, and why:

- **Leaf-table SECONDS stay float32.** Quantising them to anything that fits a uint16
  across the real leaf-size distribution costs more error than the engine's 0.01s
  exactness gate allows: 1,477 of 23,675 leaves exceed 655.34s, so most cells would
  need 0.1s units, five times the tolerance. That would have been another 376MB. The
  gate is worth more, especially now the anon heap that was starving the mapping has
  itself halved.
- **Node pruning (Track B2 below) is a separate change.** It renumbers nodes, which
  changes the partition, which invalidates every stored reach label. Everything shipped
  here leaves the overlay numbering byte-identical to master's, verified by decoding
  both artifacts and comparing.
- **mmapping graph.snap** stays a follow-on, for the reasons in the last section.

Two things turned up while building it that are worth knowing independently:

1. **The partition is not deterministic.** `buildDriveUG` collects its undirected edges
   by ranging a Go map, so two runs over the identical graph give different leaf
   assignments. Verified by building it three times from one graph: all three differ.
   Every rebuild therefore invalidates stored labels, whatever else changes.
2. **`isochrones.source` could not hold the value the code writes.** Proven against a
   real MySQL: the insert fails with "Data truncated for column 'source'" before the
   migration and succeeds after.

## Why

The morning of 2026-08-31 the reach engine's working set collided with the batch.slice
memory budget and turned reclaim into a refault storm (commit ef075a1a6). The engine is
still one bad allocation from the same place.

## Measured (production, container `freegledocker-spatial` on the FD host)

Process state:

| | |
|---|---|
| cgroup memory.max | 12,884,901,888 (12 GiB) |
| memory.current | 12,671,717,376 (98.3% of the limit) |
| anon | 11,594,481,664, later steady at 10.47 GB |
| file / file_mapped | 868,175,872 / 795,688,960 |
| pgmajfault | 4,181,209 |
| workingset_refault_file | 93,474,038 |

`GOGC`, `GOMEMLIMIT` and `GODEBUG` are all unset. There is no pprof endpoint.
`REACH_DIR_PREV` is unset, so there is no second engine resident (this was worth
checking: it would have been 4.8 GB).

The shape of the problem is the opposite of what the brief assumed. Page cache is not
the bulk of the working set, it is the *victim*: 11.6 GB of unreclaimable anon heap has
squeezed the 1.5 GB mmap'd leaf tables down to 868 MB of resident file pages, and the
engine is paying 93.5 million file refaults and 4.2 million major faults to keep
re-reading an artifact that would fit if there were room for it.

Artifacts on disk:

| file | bytes | how it is loaded |
|---|---|---|
| graph.snap | 4,711,525,465 | heap, `readSlice` into `make([]T,n)` |
| leaftables.snap | 1,506,507,656 | mmap |
| partition.snap | 92,066,470 | heap (already CSR, no duplication) |
| matrices.snap | 6,525,917 | heap |

### graph.snap census (full O_DIRECT scan; the slice chain ends exactly at the file size)

| slice | count | elem | bytes |
|---|---|---|---|
| Nodes | 56,874,452 | 12 | 682,493,424 |
| EdgeStart | 56,874,453 | 4 | 227,497,812 |
| Edges | 117,157,737 | 16 | 1,874,523,792 |
| DriveSnappable | 56,874,452 | 1 | 56,874,452 |
| ov.BaseNode | 12,927,439 | 4 | 51,709,756 |
| ov.Idx | 56,874,452 | 4 | 227,497,808 |
| ov.EdgeStart | 12,927,440 | 4 | 51,709,760 |
| ov.Edges | 31,461,366 | 20 | 629,227,320 |
| ChainEndA | 56,874,452 | 4 | 227,497,808 |
| ChainEndB | 56,874,452 | 4 | 227,497,808 |
| OffFromA | 56,874,452 | 4 | 227,497,808 |
| OffFromB | 56,874,452 | 4 | 227,497,808 |

Value ranges over every element:

- `Edge.Seconds`: no NaN, no Inf, the only negative is exactly -1. Max walk 3,483.82 s,
  cycle 1,741.91 s, drive 638.06 s. All three fit uint16 deciseconds with 45x headroom.
- `Node`: all 56,874,452 nodes carry 3 zero pad bytes. That is 170,623,356 B of padding.
  Quintile only ever takes 0..5.
- `DriveSnappable`: 34,870,883 true (61.31%).
- `ov.Idx`: 22.73% non-zero, max 12,927,438. `BaseNode[Idx[b]] == b` for every non-zero
  entry, zero failures, so the array is a pure inverse of ov.BaseNode and is derivable.
  ov.BaseNode has exactly one descent, at overlay index 12,923,799, i.e. it is two
  ascending runs with a 3,640-entry tail appended by a later pass.
- `ChainEndA/B`: 12,927,439 zeros, exactly the junction count, so 43,947,013 base nodes
  (77.27%) are absorbed chain nodes. `ov.Idx` and `ChainEndA` are non-zero in exactly one
  of the two at every index.
- `OffFromA/B`: max 961.20 s and 957.90 s, -1 sentinel, no NaN/Inf. uint16 deciseconds
  fits with 6.8x headroom.
- `ov.Edges`: walk max 16,854.26 s, cycle 16,615.01 s, drive 963.96 s, Metres max
  23,261.00. Drive and Metres fit uint16 comfortably. Walk and cycle do *not* fit
  deciseconds (108 and 32 values overflow).

### leaftables.snap census

188,266,103 cells at 8 B (f32 dist + f32 met). 23,675 leaves, of which 22,181 are empty;
max 53 entries and 10,000 nodes per leaf. dist max 14,725.59 s, met max 238,689.02 m,
unreachable is +Inf (1,775,263 cells, 0.94%). Only 10 leaves exceed the decisecond range
and only 47 exceed 65,534 m, so a global uint16 pair needs 0.25 s and 4 m units, while a
per-leaf scale byte would give 93.8% of leaves centisecond precision and 99.8% of them
1 m precision for the same 4 B per cell.

### The Grid, which nobody had counted

`LoadReachSnapshot` rebuilds `map[[2]int16][]NodeID` over every node on every boot.
Measured from the real coordinates: 397,091 distinct cells, 227.5 MB of payload,
309.2 MB of backing arrays once append growth is included, 9.5 MB of slice headers and
about 17 MB of map, so **335.8 MB**. It is a map of slices, so the GC scans all 397,091
of them every cycle. The production boot path uses the naive single-pass `append`
builder even though a two-pass pre-sized one already exists at graph.go:554-577.

### Where the rest of the anon is

Artifacts plus Grid account for about 5.15 GB of the 10.5 to 11.6 GB anon. The remainder
is Go runtime headroom (GOGC=100 targets twice the live set and the scavenger returns
pages lazily) plus caches that are bounded in entries and not in bytes:

- `regionTableCache.src` is capped at 16,384 rows of `make([]float32, len(nodes))`,
  which at the measured 10,000-node maximum is a 655 MB ceiling. The engine's own sizing
  comment covers the main cache and never mentions this one.
- `evalLabels` holds up to 20,000 decoded `*ReachLabels`, evicted by a full reset rather
  than an LRU and never swept on TTL. It looks worse than it is: `DecodeLabels` allocates
  only `Reached` and `Seeds`, leaving `OriginArr`, `OriginMet` and `SeedMet` as nil maps
  costing 8 B each, so this is not a target.
- `Isochrone` allocates two `map[NodeID]float32` per call sized by reached nodes, and
  `/v1/isochrone` fans out three of those concurrently, at up to 120 minutes.

This gap is inferred by subtraction, not measured. It is the reason step 0 below is a
heap profile and not a rewrite.

## The walk/cycle question

Nothing exposes walk or cycle any more, and confirming that is worth more than every
quantisation in the brief combined.

- The ModTools Rippling Explorer hardcodes `const currentMode = 'drive'`, with the
  comment "Walk and cycle were dropped from the panel: rippling is a drive-time model".
- `/v1/ripple-schedule`, `/v1/reachable-groups` and `/v1/ripple-eval` all default to
  drive. `deploy-prod.sh` probes with drive.
- apiv2's `engineIsochroneGeoJSON` decodes only the `drive` member of the response.
- apiv2's `FetchIsochroneWKTFromRoutingServer` can select walk or cycle from the
  `isochrones.transport` column, but all 193,831 rows in production have
  `source = 'Mapbox'`. The code sets `source = "RoutingServer"` when our router answers,
  and that string is not a member of the column's enum, so the routing server has never
  landed an isochrone row. Walk and cycle isochrones exist (22,610 and 1,727 rows, 53
  Walk in the last 30 days) but they are all bought from Mapbox.

So the only live surface is the three-mode fan-out in `/v1/isochrone` itself, whose two
callers use at most one mode each. `/v1/catchment` and `/v1/nearby-freeglers` still
*default* to walk when `mode` is omitted, which is a trap worth closing whatever else
happens.

Two loose ends this turned up, both separate from occupancy:

1. `source = "RoutingServer"` is not a valid `isochrones.source` enum value. The moment
   `ROUTING_EVAL_URL` is set for apiv2, that insert fails or stores an empty string.
2. We are paying Mapbox for isochrones our own router serves free, which is the exact
   bug `iznik-server-go/isochrone/routing.go:38-45` documents as fixed.

## The savings

All three tracks assume the same layout work. Bytes are anon heap unless stated.

### Track A, layout only, keeps walk and cycle

| change | now | after | saved |
|---|---|---|---|
| Node: Quintile to a parallel byte slice, 12 B to 8 B | 682.5 | 511.9 | 170.6 |
| Edges: parallel arrays, `[3]uint16` deciseconds, 16 B to 10 B | 1,874.5 | 1,171.6 | 703.0 |
| DriveSnappable to a bitset | 56.9 | 7.1 | 49.8 |
| ov.Idx to a junction bitset plus rank | 227.5 | 8.0 | 219.5 |
| ov.Edges to 12 B | 629.2 | 377.5 | 251.7 |
| ChainEnd + OffFrom, sparse over absorbed nodes, uint16 offsets | 910.0 | 527.4 | 382.6 |
| **graph.snap** | **4,711.5** | **2,934.4** | **1,777.2** |

Note the alignment trap the brief walked into: `struct{To uint32; Secs [3]uint16}` is
12 B, not 10, because Go rounds the struct to its 4-byte alignment. Array-of-structs
saves 468.6 MB; only parallel arrays get the full 703.0 MB, at the cost of rewriting
`EdgesFrom` and every `*Edge` pointer-holding site. The same trap bites `ov.Edges`:
narrowing `Metres` on its own saves nothing, because 4 + 12 + 2 still rounds to 20. The
whole struct has to go to `{To uint32; Secs [3]uint16; Metres uint16}`, which is exactly
12 B, to collect the 251.7 MB.

### Track B1, drive only, no node pruning

Drop the walk and cycle time fields. Edges become `To uint32` plus one uint16, ov.Edges
become 8 B.

graph.snap 4,711.5 to **2,340.0 MB, saved 2,371.5 MB (50.3%)**.

### Track B2, drive only, pruned to the drive subgraph

Measured: 69,413,653 of 117,157,737 edges are drive-usable (59.25%), 35,098,788 of
56,874,452 nodes are incident to one (61.71%), and 21,139,991 of 31,461,366 overlay
edges are drive-usable (67.19%).

graph.snap 4,711.5 to **1,420.7 MB, saved 3,290.8 MB (69.8%)**.

This renumbers nodes, so it needs the partition, matrices and leaf tables rebuilt with
it, and it changes what non-drive endpoints snap to. It is the right end state, not the
first step.

### Independent of track

| change | saved | kind |
|---|---|---|
| leaftables.snap to a uint16 pair, per-leaf scale | 753.4 | page cache |
| Grid to CSR, pointer-free, pre-sized | ~95 to 107 plus 397,091 fewer GC-scanned slices | anon |
| `ReachPartition.LeafOf` int32 to int16 | 25.9 | anon |
| `regionTableCache.src` bounded in bytes not rows | up to 491 | anon |
| `reach_isochrone.go` stops pre-sizing every map for a nationwide reach | 2.5 per call | anon |

Track B2 plus these is roughly **4.2 GB off a 12 GiB budget**, before any GC tuning.

## Order of work

0. **Measure before rewriting.** Add a loopback-only `net/http/pprof` listener bound to
   127.0.0.1, on a port not in `docker-compose.ports.yml`, reachable only by `docker exec`.
   Take a heap profile. About half the anon is currently unattributed and every estimate
   above for the non-artifact half is arithmetic, not measurement.
1. **`GOMEMLIMIT`, no code change.** Nothing sets it, so GOGC=100 lets the heap grow to
   roughly twice a live set that is much smaller than resident anon. Start around 9 GiB,
   which leaves the leaf tables room to stay resident, and load-test for GC CPU before
   trusting it. Needs a container recreate, not a restart. This is the cheapest lever in
   the document and it needs approval before touching the prod container.
2. **Confirm walk and cycle are dead** and land the drive-only change. Make
   `/v1/isochrone` compute only the modes asked for, and make `/v1/catchment` and
   `/v1/nearby-freeglers` default to drive rather than walk, before removing anything.
3. **Layout and quantisation** (Track A items), one version bump, one rebuild, one parity
   replay.
4. **Grid to CSR**, and the cache bounds.
5. **Track B2 pruning**, once the rest is proven.

Consider mmapping graph.snap in place, the way leaftables.snap already is, as a
follow-on. It moves whatever is left from unreclaimable anon into reclaimable page
cache, which is precisely the pressure that caused the incident. The header is 9 B magic
plus 4 B version, so every slice currently lands 1 mod 4 and would have to be re-padded
to a 4-aligned header, and `Graph` would need a Close/unmap lifecycle it does not have.

## Gates

- Parity replay before any cutover: `reach_parity.go` already exists to compare the
  labelling engine against a flat base-graph Dijkstra and demands exactness. Varied
  budgets, urban and rural, coarse and exact. No red replay ships.
- Perf: catchment latency and throughput unchanged or better. Measure warm
  `memory.current`, anon and file share before and after.
- Do not lower the batch.slice budgets afterwards. The reclaimed margin is the point.
- Build the error string before `Munmap`. Formatting mmap-backed bytes after unmap is a
  SIGSEGV instead of a refusal (reach_leaftables.go:104-110).
- Rebuild artifacts off-host and rsync, as in the 08-28 stage-2 deploy. Do not rebuild in
  place on a loaded node.
- Deploy to both targets: native routing on db1/2/3 one node at a time with the
  unmonitor, manual start, monitor dance, and the local `spatial` compose container.
- Tests run through the status API only.

## New risk found while surveying

`partition.snap` and `matrices.snap` validate only their own magic string. Neither
carries a fingerprint back to the graph build, and `reach_server.go:80-99` only
re-derives them on a hard load failure, never on staleness. Any change that renumbers or
reshapes overlay indices must bump those two magic strings as well, or a stale file will
be silently misread against the new numbering. That is the failure mode the brief's own
"never misread" rule exists to prevent, and it is currently unguarded.

## Dropped from the brief

Section 3, the `rippling_reach` WKT decimal places, is done. The fix shipped in PR #1400
on 2026-08-23, coarser than the brief proposed, and the column it targeted was dropped on
2026-08-25 by the cell-set migration, which is also where the 7.8 GB table shrink the
brief credits to WKT precision actually came from. Do not open it as a PR. The remaining
geometry lever there is `outer_bound` / `inner_bound`, about 19 KB per row of
full-precision GEOMETRY and now roughly 64% of a row, whose size is driven by
`ReachBoundsService.php` TOLERANCE=0.002. Coarsening it trades R-tree prefilter accuracy
for row size, which is a different kind of change and unsized.

The brief's suggestion of a degree-byte plus sampled prefix-sum CSR for `EdgeStart` was
assessed and is not worth it: it replaces a guaranteed one-cache-line read on the hottest
per-node operation in the codebase with a data-dependent summation, for less than either
edge-quantisation candidate.

## Method

The census numbers come from full sequential passes over the live artifacts, streamed
through `dd iflag=direct` so scanning polluted no page cache on a node that was already
at 98.3% of its limit. Scan scripts are in the session scratchpad. Twenty-three candidate
savings were each checked against the code by an independent adversarial pass; all
twenty-three survived, with corrected byte figures folded in above.
