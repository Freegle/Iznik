# Candidate: Shrink the routing server's memory

Status: draft
Last reviewed: 2026-11-03

## Goal

The routing server holds the UK road graph and the reach engine in memory on each database
node. When this is done it uses about 1.8 GB resident instead of about 3.2 GB, with the same
answers and no slower requests. The other 1.4 GB on each node goes back to the database. This
is for the database nodes and the members whose requests they serve, not for any one screen.

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
  sets `GOMEMLIMIT` 9GiB and `GOGC` 50. The production unit on the database nodes is not in the
  repo; its settings are unknown.
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
- **No request may get slower and no answer may change,** apart from what question 2 allows.
  Memory is not worth a slower or different result.

## Open questions for Edward

Recommended answer first.

1. Is the aim to keep the db2 standby inside its 4 GB reservation, or to cut db3's busy-hour
   peak? Recommended: db3's busy-hour peak. The standby already fits (about 3.2 GB against
   4 GB), and task 2 mostly helps an idle node, so it ships as ordinary work rather than being
   the goal.
2. Is a one-off change to every blurred location members see acceptable? Recommended: yes,
   once, with the blur's sort key changed from node id to the node's coordinates in the same
   release, so no later renumbering moves it again. A new node order is what makes task 8 pay:
   without it, packing edges saves about 50 MB, not 277 MB. Keeping the old order would need a
   4-byte rank per live node, about 140 MB (est.). Someone who saw both the old and new blur of
   one post could narrow down its location, but both points are within 1.5 times the blur
   distance by road, so only a little.
3. Will you accept one rewrite of the stored labels (a 7.39 GB table) for the format change?
   The partition cannot be rebuilt the same, so the labels must be translated, not rebuilt.
   Recommended: yes, once, in the same release as the format change, through the existing
   `REACH_DIR_PREV` rolling migration so there is no window without labels, rehearsed on a
   copy of the table first.
4. What are the production router's `GOGC` and `GOMEMLIMIT`? They are not in the repo.
   Recommended: read them from `/debug/memsummary` on db2 and db3 before the build starts. It
   prints both. This is also listed under prerequisites.
5. Is `REACH_DIR_PREV` used in practice, and do artifact deploys copy to a temporary name and
   rename? Recommended: keep the version 2 reader for one release whatever the answer, so a
   map refresh across the change still works. If deploys copy in place, change them to copy
   and rename before task 7, because a snapshot overwritten during a load gives a mixed graph.
6. Must the fallback route search, used when the reach engine is not loaded, stay?
   Recommended: yes. It is the only path when `REACH_DIR` is unset (`main.go:39-41`), so if
   Phase 4 is funded it takes the option that keeps `Graph.Edges`.
7. Stop after task 8, at about 1.8 GB resident, or fund Phase 4 (task 9)? Recommended: stop
   after task 8 and measure. Phase 4 is 2 to 6 weeks for about 0.3 to 0.4 GB more. One option
   makes batches of 1,000 targets 0.5 to 4 ms slower (est.); the other removes the fallback in
   question 6.

## External prerequisites

- [ ] Edward, before the build: `/debug/memsummary`, `GOGC` and `GOMEMLIMIT` from db2 and db3,
      and a heap profile from db3 at its busiest hour. Decides questions 1 and 4.
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
| 7 | Snapshot version 3: drop dead nodes and renumber, in the order question 2 settles; carry the old overlay fingerprint; translate `originChain`; keep the version 2 reader (about 295 MB more) | 1, 5, 6 | B | `reach_snapshot.go`, `reach_overlay.go`, `reach_labels.go`, `reach_server.go` |
| 8 | Edges packed into 4 bytes: an 18-bit id step and 14-bit seconds (277 MB) | 1, 7 | B | `graph.go`, `dijkstra.go`, `reach_snapshot.go` |
| 9 | Phase 4, one option, only if question 7 funds it (about 390 MB, or 260 MB) | 8 | B | `reach_overlay.go`, `reach_isochrone.go`, `graph.go` |

Tasks 1, 2, 3 and 6 can start at once. Resident memory after each step, est.: about 2,960 MB
after task 2, about 2,360 MB after task 6, about 2,060 MB after task 7, about 1,780 MB after
task 8.

## Acceptance

- Each PR states heap in use and resident memory from `/debug/memsummary` after a local boot
  on the full UK graph, before and after, and the saving is near the figure in this file, or
  the PR says why not.
- On the replayed requests, reach checks, drive times for 1,000 targets, isochrones,
  catchments, ripple scheduling and boot time are no slower than master at the median and the
  99th percentile, beyond the spread of two master runs.
- For the same inputs, snaps to the nearest node, isochrones and reach verdicts are the same
  as master's. Blurred locations are the same too, unless question 2 allows one change.
- Saving, loading and saving a snapshot gives the same bytes. A version 2 snapshot still loads
  while the version 3 one is new.
- Every stored label in the production sample decodes to the same reach under version 3 as
  under version 2.
- The old and new drive-snappable sets match on the Bristol fixture and on a graph made of
  many small fragments.

## Review burden

- PRs: 8, or 9 with Phase 4. Tasks 2 and 3 are small; tasks 7 and 8 carry the risk.
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
