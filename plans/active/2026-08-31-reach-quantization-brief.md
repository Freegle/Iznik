# Brief: shrink the reach engine's working set by removing excess precision

2026-08-31, from session "Live" for a dedicated session. Motivation: the morning's
thrash incident — the reach engine's ~10GB warm working set (6.1GB heap + ~4GB of
mmap'd page cache) collided with the batch.slice memory budget and turned reclaim
into a 1.4-2GB/s refault storm (see memory `project_host_pause_rock36_resize` and
commit ef075a1a6). ~2GB of that working set is precision nobody consumes. Removing
it buys back the exact margin that separated healthy from collapsed, and shrinks
every catchment query's page-touch count.

## Targets (measured 2026-08-31, /data/reach on the FD host)

1. **leaftables.snap — 1.5GB, 8 bytes/entry, ~188M entries** (`reach_leaftables.go`:
   per leaf, two parallel `[]float32` arrays returned by `table()` as `dist, met`).
   VERIFY the semantics and value ranges first (write a small scan over the artifact:
   max/min/NaN/sentinels of both arrays). Expected fit: `uint16` deciseconds +
   `uint16` metres → 4 bytes/entry, file → ~750MB, **per-query page touches halve**.
   If a range exceeds uint16 (long ferry legs?), decide per-array: whole seconds, or
   keep that array f32 — halving only one array still saves 375MB.
   - Lesson on the loader: `fail()` must build its error string BEFORE `Munmap`
     (mmap-backed bytes in the format args SIGSEGV after unmap — bitten 2026-08-30).

2. **graph.snap — 4.7GB.** `Node{Lat, Lng float32}` is already lean; the fat is
   `Edge.Seconds [3]float32` (walk/cycle/drive, `-1` = unusable) — 12B of the 16B
   edge. Quantize to `[3]uint16` deciseconds, sentinel 65535 → ~6B back per edge,
   est. **~1.2GB** across the edge set (VERIFY edge count + max per-mode seconds
   from the snap before choosing units; deciseconds cap at 1.8h/edge — check ferry
   edges). 0.1s granularity makes accumulation bias negligible over any route
   (±0.05s random per edge). Keep Dijkstra's accumulator float — quantized INPUTS,
   exact math.

3. **Adjacent, DB-side (separate PR if pursued):** rippling_reach schedule/bounds
   emissions — check current WKT decimal places (12dp was the 2026-08-23 shrink
   finding, memory `project_rippling_reach_shrink_analysis_20260823`); 5dp (~1.1m)
   is enough for every consumer. Shrinks the 7.8GB table, Galera buffer pool share,
   and the 600KB average row that advanceDue must fetch.

## Constraints and rollout

- Snapshot format changes = version bumps; a loader that sees an old version must
  fall back to rebuild, never misread. Artifact rebuild transient sizing:
  ~23GB (memory `reference_rippling_reach_rebuild_sizing`) — prebuild artifacts and
  rsync like the 08-28 stage-2 deploy (scripts/reach-artifact-*.log pattern), don't
  rebuild in place on a loaded node.
- Deploy to BOTH targets: native routing on db1/2/3 (one node at a time, the
  unmonitor/manual-start/monitor dance — `monit restart` silently no-ops on routing,
  see `reference_prod_deploy_procedure`) and the local `spatial` compose container.
- **Parity gate before any cutover**: replay a catchment corpus (varied budgets,
  urban/rural, coarse + exact) old vs new — minutes deltas ≤ quantization bound,
  WKT/grid outputs identical or within one cell. No red replay ships.
- **Perf gate**: catchment latency and throughput unchanged or better (uint16→f32
  widening in inner loops is effectively free; the page-cache halving should WIN).
  Measure warm `memory.current` + page-cache share before/after; expect ~2GB drop.
  Do NOT lower the batch.slice budgets afterwards — keep the reclaimed margin as
  headroom (that margin being too thin is what caused the incident).
- Tests run via the status API suites only (never on the FD host). The
  PartitionOverlay fixtures share one partition build — keep that pattern.

## Explicitly out of scope

- Node coordinate precision (already float32; fine).
- The places index (~10MB of coordinate floats — irrelevant, see memory
  `project_geocoder_places_cutover_20260831`).
- Any change to eligibility/ranking semantics.
