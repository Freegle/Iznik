# Plan: rippling crosses uncrossable barriers (reach polygon overshoots water)

Status: proposed (2026-07-06). Implementation to be done elsewhere; this is the design + backfill plan.

## Problem

Posts ripple into groups you cannot drive to. Canonical case: msgid 120898144
"OFFER: Car Tow Bar Bike Rack (Corringham SS17)" (ThurrockFreegle, north bank of
the Thames) rippled into Gravesend_Freegle (21439, south bank). North-of-Thames
groups (Havering RM3, Basildon, Southend) also ripple into Gravesend.

It is **not** a routing bug:
- The Dartford toll tunnel is correctly excluded from car routing
  (`iznik-routing-go/graph.go:520`, `toll=yes → Drive=-1`).
- Ferries are not in the graph at all (ways admitted only if they carry a known
  `highway` tag, `graph.go:492`; `route=ferry` has none — Tilbury–Gravesend ferry
  absent).
- Live proof: `group-proximity` drive Corringham→Gravesend = `{"reachable":false}`
  even at 240 min.

### Root cause: the reach POLYGON over-approximates and bridges the river

`ExpandService::rippleIntoNewGroups` (iznik-batch) selects target groups purely by
`ST_Intersects(g.polyindex, reach_polygon)` (+ taken/TN/banned/rippled-then-left
filters). There is no road-reachability guard.

The reach polygon comes from `IsochronePolygon` (`iznik-routing-go/polygon.go`),
which rasterises reached road nodes onto a grid and traces the boundary. Two
compounding overshoots push the boundary past the actual reachable nodes:
1. **whole-cell fill** (`polygon.go:60-67`) — a node fills its entire grid cell;
   `AutoResolution` clamps drive cells to **0.01° ≈ 1.1 km** (`polygon.go:315`).
2. **morphological closing** (`polygon.go:69-99`) — empty cells with ≥2 filled
   orthogonal neighbours are filled, bridging the estuary where the north-bank
   road wraps the river mouth.

The Thames at Tilbury is only ~0.8 km wide, so the polygon steps ~1–2 cells south
across the water. Measured live: a **15-minute** drive isochrone from Corringham
already has boundary points at **lat 51.4506 on the south bank**, clipping the
northern edge of Gravesend's `polyindex` (~2% overlap) → the whole post ripples in.

### Why cheap geometric proxies do not fix it (rejected)

- **Feature-presence** ("are there roads in reach ∩ group?"): fails. The overshoot
  lands on Gravesend's built-up riverfront — the intersection contains **26 Road
  features** (`locations` type='Road'). Roads present but severed from the origin.
- **Roads crossing the group boundary within the reach**: better (Gravesend **2**
  vs 47–67 for genuine groups) but still non-zero, because it re-consumes the
  overshooting polygon. Needs a threshold; not exact.
- Fundamental reason: reachability is decided by two facts that live **only in the
  routing graph** — the network severance (topology; `locations` has road segments
  but no connectivity) and the toll exclusion (the Dartford road geometry exists and
  crosses the river; only the graph knows it is `toll=yes`). No pure-geometry check
  can see either.

### The exact signal already exists — and is discarded

The isochrone is computed from a set of **reachable road nodes** (Dijkstra). Those
nodes cannot cross the river (no edge crosses it) and cannot use the tunnel (toll
excluded). "Is group G reachable?" = "does any reachable node fall in G's polygon?"
— threshold-free and correct for both severance and tolls. The bug exists only
because `ReachService` smooths that node set into a lossy polygon and throws the
nodes away before the group test.

**Confirmed on the Gravesend example** (reach msgid 120229462, origin 51.519/0.2888
West Thurrock, 30-min budget):

| group | polygon `ST_Intersects` (current) | node check `reachable` (proposed) |
|---|---|---|
| Gravesend (21439) | targeted — the bug | **false** — dropped |
| Havering (21458) | targeted | true |
| Basildon (390665) | targeted | true |
| Thurrock origin (21656) | targeted | true |

The node check drops exactly the uncrossable-river false positive and keeps every
genuine target. `group-proximity` is the stand-in: `groupSeedNodes`
(`iznik-routing-go/groups.go:341`) samples the graph node nearest each group
boundary vertex + centroid, so its `reachable` flag *is* "a node in the group is
reachable."

## Forward fix

Target ripple groups by the reachable node set, not the smoothed polygon.

### 1. Routing server (`iznik-routing-go`)
- After the origin isochrone Dijkstra produces the reached node set, resolve the
  set of **candidate group IDs that contain ≥1 reached node**. The server already
  loads group polygons for `group-proximity`/`groupSeedNodes`; add a reached-node →
  group point-in-polygon pass (index groups spatially, or test group polygons
  against the reached-node set) done once per reach.
- Return the reachable group-ID set alongside the reach (extend the reach/schedule
  response, or add `/v1/reachable-groups?lat=&lng=&minutes=&mode=`). Keep the
  smoothed polygon for map display only.
- Cost: one pass over nodes already in hand per reach recompute — cheaper than N
  per-group `group-proximity` calls, far cheaper than any `locations` scan. No
  change to `graph.go` tolls/ferries (already correct).

### 2. Batch (`iznik-batch` ReachService / ExpandService)
- `ReachService` stores/consumes the reachable group-ID set with the reach.
- `ExpandService::rippleIntoNewGroups` — add the reachable set as an **AND gate** on
  the existing `ST_Intersects` target select (belt-and-braces: keep the polygon
  prefilter for the cheap spatial index scan, then require the group be in the
  reachable set). A group with zero reached nodes can never be targeted.
- `removeGroupsNoLongerReached` (`ExpandService.php:~450`) — extend its "retract
  groups the reach no longer covers" logic to also retract groups now excluded by
  the reachable set, so recompute self-heals.

### 3. Gating
- Put the new targeting behind a config flag (e.g. `freegle.ripple.reachable_gate`,
  default off) so it can be enabled after validation without touching
  `RIPPLE_ENABLED`. Mirrors `freegle.ripple.proximity_notes`.

## Identify existing problem reaches (detection)

Bad row = a live `messages_groups.rippled_in=1` (msgid, group) whose group is not
road-reachable from the reach origin within the reach budget.

- **Pre-filter (cheap, pure SQL):** overlap fraction
  `f = area(reach ∩ g.polyindex) / area(g.polyindex)`. Data shows **f ≥ 1% ⇒ 100%
  reachable (60/60 probed); the unreachable cases are all in f < 1%** (that band is
  ~13% of pairs, ~58% of it unreachable). So only test f < 0.01 pairs. Overlap SQL
  must be GEOMETRYCOLLECTION-safe:
  `CASE WHEN ST_GeometryType(ST_Intersection(...)) IN ('POLYGON','MULTIPOLYGON')
   THEN ST_Area(...) ELSE 0 END` (edge-touch pairs return a collection → ST_Area
  errors 3516).
- **Confirm:** for each f < 1% pair, call the new `reachable-groups` check (or
  `group-proximity` at the reach's own `max_drive_min`); `reachable=false` ⇒ bad.
- Emit a report: count of bad rows, by origin group and target group, so the scale
  and hotspots (river/estuary/island borders) are visible before any deletion.
- Rough scale: ~13% of rippled_in rows are < 1% overlap × ~58% unreachable ≈ ~7–8%
  of live rippled_in rows are bad (of ~10k+ live, ≈ 750+), plus history.

## Fix existing (retraction)

- Retract via the **existing audited retraction path** (`removeGroupsNoLongerReached`
  / `pulled_on_removal`), not ad-hoc deletes: soft-delete the `rippled_in=1`
  `messages_groups` row and remove the **ripple-joined** membership only (never a
  genuine membership — the existing guards distinguish `logs text='Rippled'` joins).
- **Galera-safe: one row at a time** (`feedback_prod_deletes_one_at_a_time`), no
  bulk `INSERT…SELECT`/`DELETE` (`project_ripple_insertselect_lock_storm_20260626`).
- Deliver as a dedicated throttled command `ripple:retract-unreachable`
  (`--dry-run`, `--limit`, `--shard`), run **off-peak, single shard** — do NOT run a
  full 3-shard reach recompute for the backfill
  (`project_batchprod_recompute_overload_20260701`). New/ongoing ripples self-heal
  via the forward fix + `removeGroupsNoLongerReached`.

## Validation & rollout

1. Land routing-server change; deploy to db1/2/3 + the local `spatial` container
   (see below); verify `reachable-groups` returns Gravesend-excluded / genuine-kept
   on the Corringham example.
2. Land batch change behind the flag (off). Run `ripple:retract-unreachable
   --dry-run` to produce the detection report; sanity-check hotspots.
3. Enable the flag on a scoped experiment first if desired; confirm new ripples no
   longer cross barriers (spot-check Thurrock↔Gravesend, and other estuary/island
   borders — Mersey, Humber, Solent, Clyde, island groups).
4. Run the retraction backfill off-peak, single shard, `--limit` capped; monitor
   Sentry + digest volume.

## Deployment notes

- Routing server: native on db1/2/3 (rebuild + the **unmonitor / manual restart /
  monitor** dance; ~7-min UK graph reload per node, one node at a time) AND the
  local `freegledocker-spatial` container the batch reach path calls
  (`docker compose build spatial && up -d --no-deps spatial`). See
  `reference_prod_deploy_procedure` and `project_routing_server_deploy_haproxy`.
- Batch: `iznik-batch` is bind-mounted into `batch-prod`; code goes live on git
  pull (no restart), env changes need `--force-recreate`.

## Risks / edge cases

- **Budget dependence:** the node check uses the reach's `max_drive_min`, so a group
  reachable only via a long detour (e.g. Gravesend via central-London bridges at
  ~1.5 h) correctly qualifies only when the reach is that large — desired.
- **Seed-node sampling:** `groupSeedNodes` samples boundary vertices + centroid; the
  server-side reached-node→group pass should test the full reached-node set against
  group polygons for exactness, not just seeds.
- **Sparse groups:** node-set membership is presence-based (≥1 reached node), so a
  legitimately reachable small group is kept as soon as one node is reached — no
  threshold to mis-tune.
- **Islands/ferries:** the same fix correctly stops rippling onto ferry-only islands
  (no drivable connection) — intended; if ferry-served reuse is ever wanted, that is
  a separate routing-profile decision, not this targeting change.
- Do not regress `IsochronePolygon` for display consumers (catchment, map explorer,
  digest scoring) — this change leaves the polygon untouched and only adds a
  node-based targeting signal.
