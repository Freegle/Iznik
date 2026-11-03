# Candidate: Shrink the routing server's memory

Status: ready
Last reviewed: 2026-11-03

## Goal

The routing server holds the UK road graph and the reach engine in memory on each database
node. The aim is to cut db3's busy-hour peak. When this is done the live heap is about 1.2 to
1.3 GB instead of about 2.6 to 3.4 GB, with the same answers and no slower requests, and the
memory goes back to the database. This is for the database nodes and the members whose
requests they serve, not for any one screen.

**Before any of this, db3 needs the same Go settings as db2** (see "What exists today").
That is a production setting, not part of the build, and it is worth more than every task
here put together: db3 holds 6.6 GB of heap for about 3.4 GB that is live.

All the figures here are arithmetic from a heap profile of the production router taken on
2026-11-03 and from the code. They are not benchmarks. "Est." marks anything not measured.
MB are decimal. An analysis run checked each saving independently against the code and the
real UK extract. The ones that did not hold up are under "Decisions settled".

## Why it qualifies

- Token-heavy because: it changes the graph, grid, overlay and snapshot format in
  `iznik-routing-go`, with parity tests on a 57-million-node graph at each step, and the
  analysis puts it at 5 to 12 weeks of an engineer's time, most of it coding and testing.
- Unattended because: everything builds and is checked locally from
  `iznik-routing-go/data/uk-latest.osm.pbf` plus a copy of the production reach artifacts;
  deploying and rewriting the stored labels are separate steps for Edward.
- Worth it because: the router sits inside a 4 GB reservation on each database node that the
  kernel may not reclaim (`docs/ops/domains-services-and-runbooks.md:40-45`), and a map
  refresh holds a second graph beside it (`iznik-routing-go/README.md:24`), so every GB it
  gives up is a GB the database gets.

## What exists today

Where the heap goes, from the profile: 2,788 MB in use, plus 425 MB of Go slack.

| Structure | MB | Defined at |
|---|---|---|
| Base edges, 69.4M x 8 bytes | 555 | `graph.go:58-61` (`To uint32`, `Secs uint16`, padded to 8) |
| Nodes, 56.9M x 8 bytes | 455 | `graph.go:40-42` (two `float32`) |
| Grid | 234 | `graph.go:123-130`, built at `graph.go:162-202` |
| Overlay `Ref` | 228 | `reach_overlay.go:71-86` |
| Overlay `ChainEndB` | 228 | `reach_overlay.go:90-95` |
| Base `EdgeStart` | 228 | `graph.go:212` (`int32` per node) |
| Overlay `OffFromA` and `OffFromB` | 227 | `reach_overlay.go:96-97` |
| Overlay edges, 21.1M x 8 bytes | 169 | `reach_overlay.go:88-89` |
| Overlay `BaseNode` and `EdgeStart` | 103 | `reach_overlay.go:70`, `:88` |
| Quintile | 57 | `graph.go:211` |
| Partition, matrices, boundary index | 74 | unknown split |
| Decoded label cache, up to 20,000 labels, and maps | about 210 | `reach_eval.go:24-30`, cap at `reach_eval.go:66` |

- About 38% of nodes (est. 21.9M) have no drive edge but still cost 25 bytes each across the
  per-node arrays. 70% of edges sit inside chains. About 46% of the chain-table slots are used.
- `dijkstra.go:241-243` - `hasDriveEdge` is "has any edge", read through
  `EdgesFrom`, which slices `Edges` by `EdgeStart` (`graph.go:243-245`).
- `graph.go:802-854` - `computeDriveSnappable` builds a `parent` array and a separate `size`
  array, one entry per node each, at boot.
- `main.go:39-46` - boot from the prebuilt artifacts when `REACH_DIR` is set; the build from
  the PBF is the fallback and the only path when it is not.
- `reach_snapshot.go:37` - snapshot format version 2. A version mismatch is refused and sends
  the caller down the rebuild path (`reach_snapshot.go:33-36`).
- `reach_labels.go:16-45` - the stored label format "FRL2". It carries `originChain`, a base
  node id, so renumbering nodes changes what stored labels mean.
- `reach_server.go:352-356` and `README.md:24` - `REACH_DIR_PREV` loads the previous build as
  well, so a map refresh is a rolling label migration. The README puts the second build at
  about 5 GB.
- `reach_server.go:650-719` - `roadBlurPoint`. It sorts candidate road nodes by node id
  (`reach_server.go:706`) and picks one from a seed, so a renumbering moves every blur.
  Members see it: `iznik-server-go/message/matches.go:110` and `postmatches.go:189` call it
  through `iznik-server-go/roadblur`, which caches only in memory (`roadblur.go:78-103`).
- `reach_isochrone.go:20` - `ReachedNodes`, which scans the chain tables on every catchment and
  fairness call.
- `debug_listener.go:44-59` - `/debug/memsummary` prints heap figures, the memory limit and
  `GOGC`.
- `reach_cli.go:27`, `:106` - `reach stats`, which already prints junction and absorbed counts.
- `docker-compose.yml:963-972` - the local `spatial` container (built from `iznik-routing-go`)
  sets `GOMEMLIMIT` 9GiB and `GOGC` 50, and `REACH_DIR` is empty by default
  (`docker-compose.yml:981`), so local dev and CI (the Bristol extract,
  `.circleci/orb/freegle-tests.yml:503`) boot without the reach engine.
- Production, read on 2026-11-03 from each process's environment and `/debug/memsummary`.
  Both run the same binary and the same 3 September artifacts:
  - db2: the `iznik-routing-go` systemd unit, `MemoryMin` 4G, `GOGC` 50, `GOMEMLIMIT` 5GiB.
    Booted 04:49 that day: 2.57 GB of heap in use, 2.8 GB resident.
  - db3: no systemd unit, `GOGC` unset (so 100) and no memory limit. Up since 22 October:
    6.66 GB of heap in use, 7.1 GB resident. Its heap profile shows about 3.36 GB live at the
    last collection, so the rest is garbage waiting for a collection at twice the live heap.
    The live heap is above db2's because of request-time caches: leaf subgraphs (362 MB) and
    region tables (125 MB).
  - `REACH_DIR_PREV` is set on neither. Binary deploys keep a dated
    `iznik-routing-go.bak-predeploy-*` copy; how the artifacts were copied is not recorded.
- Tests that guard the change: `reach_snapshot_test.go`, `grid_test.go`,
  `drive_snapping_test.go`, `ripple_nearestfix_test.go`, `reach_overlay_test.go`,
  `reach_query_test.go`, `ripple_eval_engine_test.go`, and the parity sweep in
  `reach_parity.go`. Their DeepEqual checks on the arrays will have to move to accessors.

## Decisions settled

- **No memory-mapped snapshot.** It saves about nothing on a node serving traffic.
  `ReachedNodes` scans the chain tables on every catchment and fairness call, the grid build
  at boot touches every node, and a cold leaf build faults 1,000 to 20,000 pages. A file
  rewritten in place under a mapping gives a crash or a silently mixed graph.
- **No dropping the snapshot from the page cache (fadvise).** It saves no resident memory and
  makes every restart read 2.3 GB cold. Go's scavenger already returns the boot scratch within
  minutes, so the bundle it came in saved about 145 MB and not for long. The parts that do
  hold up (one union-find array, `FreeOSMemory` after boot) are task 2.
- **No `GOMEMLIMIT` with `GOGC` off.** It cannot lower resident memory. It turns off the
  forced collection every 2 minutes; a safe limit sits above today's figure, and a tight one
  adds collection work on the request path.
- **One format change, not two.** Dropping dead nodes and any new node order go into the same
  snapshot version, because each change of format means rewriting the stored labels.
- **The dense-live-ids idea is not shipped on its own.** It is a subset of dropping dead nodes.
- **A chain node is kept only if it is absorbed and has a usable offset.** Offsets alone also
  count junctions and halve the saving.
- **The compact `EdgeStart` comes with `hasDriveEdge` reading the degree directly.** Without
  that, snapping is 7 to 26% slower (est.).
- **Sink nodes stay out of the main grid,** so the snap loops need no check per candidate.
- **No request may get slower and no answer may change,** apart from the one blur move below.
  Memory is not worth a slower or different result.
- **The aim is db3's busy-hour peak.** Edward, 2026-11-03. The db2 standby already fits its 4 GB
  reservation, so task 2, which mostly helps an idle node, ships as ordinary work rather than
  being the goal. Acceptance is measured on db3 at its busiest hour.
- **Every blurred location moves once, and never again because of numbering.** Edward,
  2026-11-03. The blur lists the road points 200 to 600 m away by road and picks one from a seed,
  in node-number order. Today that order follows OpenStreetMap ids, so a map refresh only moves
  points where roads changed. A new node order is what makes task 8 pay (277 MB instead of about
  50 MB), and it reorders every list. So in the same release the pick is made in order of each
  point's coordinates instead of its number. The moved point stays in the same ring and is never
  closer to the true spot. Keeping the old order would cost about 140 MB (est.).
- **The stored labels are rewritten once, in the release that changes the format.** Edward,
  2026-11-03. The partition cannot be rebuilt the same, so the labels are translated, not
  rebuilt. It goes through the `REACH_DIR_PREV` rolling migration so there is no time without
  labels, rehearsed on a copy of the 7.39 GB table first. `REACH_DIR_PREV` is unused today, so
  task 7's deploy steps say how to turn it on for the change and off afterwards.
- **Phase 4 is funded, and the fallback route search goes.** Edward, 2026-11-03, choosing the
  faster option: chain interiors laid out next to each other, which removes `Graph.Edges`
  and with it the search used when the reach engine is not loaded. Local dev and CI boot that
  way today (`REACH_DIR` empty), so task 9 makes them boot with the engine, deriving the
  artifacts from the extract at first start, before the fallback is removed.
- **The version 2 snapshot reader is kept for one release,** so a map refresh across the
  change still loads. Task 7's deploy copies new artifacts to a temporary name and renames
  them, because a snapshot overwritten while it loads gives a mixed graph.

## Open questions for Edward

None.

## External prerequisites

- [ ] Edward, now: run db3's router like db2's, under the `iznik-routing-go` systemd unit with
      `GOGC` 50 and `GOMEMLIMIT` 5GiB, so it stops holding twice its live heap. Then a heap
      profile from db3 at its busiest hour becomes the baseline for acceptance.
- [ ] Edward or a session with production log access, before the build: a day of sampled
      routing requests exported from Loki to a file, so speed can be compared locally.
- [ ] Edward, before task 7: a copy of the production reach artifacts (`REACH_DIR`) and a
      sample of stored label rows, for the decode-back test.

## Tasks

One PR each. Tasks sharing a letter touch the same files and run in sequence.

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | `reach stats` also prints dead nodes, the largest out-degree, drive chains and the one-way share, and a histogram of edge seconds; run on a local UK build | - | A | `reach_cli.go` |
| 2 | Tidy boot: one union-find array with sizes at the roots, run before the overlay reads, then collect and return memory after boot. Small enough for ordinary work | - | B | `graph.go`, `main.go` |
| 3 | The label cache holds the 2.3 KB stored blob, not the decoded tree (about 125 to 130 MB, not independently checked). Small enough for ordinary work | - | C | `reach_eval.go` |
| 4 | Grid as runs of consecutive ids, snappable nodes only, with a dense cell table (180 MB) | 2 | B | `graph.go` |
| 5 | `EdgeStart` as one degree byte per node plus a base every 16 (192 MB) | 4 | B | `graph.go`, `dijkstra.go` |
| 6 | Chain tables kept only for chain nodes the drive graph uses (235 MB) | - | D | `reach_overlay.go`, `reach_isochrone.go` |
| 7 | Snapshot version 3: drop dead nodes and renumber in a spatial order, with the blur picking by coordinates; carry the old overlay fingerprint; translate `originChain`; keep the version 2 reader (about 295 MB more) | 1, 5, 6 | B | `reach_snapshot.go`, `reach_overlay.go`, `reach_labels.go`, `reach_server.go` |
| 8 | Edges packed into 4 bytes: an 18-bit id step and 14-bit seconds (277 MB) | 1, 7 | B | `graph.go`, `dijkstra.go`, `reach_snapshot.go` |
| 9 | Phase 4: chain interiors laid out next to each other (about 390 MB); local dev and CI boot with the engine first, then `Graph.Edges` and the fallback search go | 8 | B | `reach_overlay.go`, `reach_isochrone.go`, `graph.go`, `main.go`, `docker-compose.yml`, `.circleci` |

Tasks 1, 2, 3 and 6 can start at once. Resident memory after each step, est.: about 2,960 MB
after task 2, about 2,360 MB after task 6, about 2,060 MB after task 7, about 1,780 MB after
task 8, about 1,400 MB after task 9. These are for db2's settings; db3 matches them only once
it runs with the same `GOGC` and `GOMEMLIMIT`.

## Acceptance

- Each PR states heap in use and resident memory from `/debug/memsummary` after a local boot
  on the full UK graph, before and after, and the saving is near the figure in this file, or
  the PR says why not.
- On the replayed requests, reach checks, drive times for 1,000 targets, isochrones,
  catchments, ripple scheduling and boot time are no slower than master at the median and the
  99th percentile, beyond the spread of two master runs.
- For the same inputs, snaps to the nearest node, isochrones and reach verdicts are the same
  as master's. Blurred locations are the same too, except for the one move in task 7.
- Saving, loading and saving a snapshot gives the same bytes. A version 2 snapshot still loads
  while the version 3 one is new.
- Every stored label in the production sample decodes to the same reach under version 3 as
  under version 2.
- The old and new drive-snappable sets match on the Bristol fixture and on a graph made of
  many small fragments.

## Review burden

- PRs: 9. Tasks 2 and 3 are small; tasks 7, 8 and 9 carry the risk.
- Each UI PR carries: none. No screen changes, so `PR_NO_SCREENSHOT=1` with that reason.
- Each migration PR carries: none in the database schema. Task 7's PR carries the deploy
  steps: the order of artifacts and API, the label rewrite and how to roll it back.
- Docs page(s) to add or update, with `covers:`: `docs/developers/reference/spatial-servers.md`
  in every PR that touches a file it covers (`graph.go`, `dijkstra.go`, `reach_overlay.go`,
  `reach_query.go`, `reach_server.go` and more), and `iznik-routing-go/README.md` for the
  format change.

## Out of scope

- The approaches under "Decisions settled" that did not hold up.
- A new partition. The current one is kept and translated.
- `iznik-spatial-go`, the separate search server.
- Moving the router off the database nodes.

## Related plans

- None. The analysis that produced this ran on 2026-11-03; its raw heap profile is not in
  the repo.
