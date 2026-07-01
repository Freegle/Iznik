# Connectivity-friction reach model

Branch: `feat/connectivity-friction-isochrone`  ·  Worktree: `conn-friction` (status :12073, traefik :12076)

## Problem

Rippling reach is currently a **pure travel-time isochrone** (single mode, uniform minutes,
a hard blob). It ignores that different areas travel differently and have different taker
density. We want reach shaped by the **DfT Transport Connectivity Metric 2025** (OA/LSOA/LAD,
England & Wales, scores 0-100 per journey-purpose × mode: walking/cycling/PT/driving).

The metric is an **area-attribute accessibility index**, not an OD matrix. So it can't produce
corridors on its own - it must **modulate** the routing engine's travel-time wavefront.

## Core model: friction on the travel-time wavefront

The routing server (`iznik-routing-go`) already runs a Dijkstra wavefront over an OSM graph,
edge cost = `Seconds[mode]`. It already tags each node with an area attribute
(`Node.Quintile` from `Deprivation`) and has `FairnessIsochrone` (a per-node **destination**
multiplier on the budget). We add a **path-integrated** friction: scale each edge's cost by the
local connectivity as the wavefront traverses it.

```
effectiveEdgeCost(edge, mode) = Seconds[mode] × friction(nodeConnectivity, mode)
```

Unlike the fairness destination-multiplier, friction is **integrated along the path**, so a
high-friction area slows everything *beyond* it → anisotropic, corridor-following reach.

### Sign (this is the trap - see below)

Two distinct frictions pull opposite ways in connectivity. Conflating them re-introduces the
"dense city balloons" inversion (a v0 prototype gave Tower Hamlets a 38km car reach).

- **Absorption friction — sets EXTENT. Increases with connectivity.**
  Dense/high-connectivity ground burns the budget fast (takers are plentiful) → reach stops
  sooner. Sparse ground burns it slowly → reach runs on. Uses **grand/overall** connectivity.
  This is the primary reach-shaper and gives dense→tight, sparse→wide.
- **Traversal friction — shapes the CORRIDOR/mode. Decreases with mode connectivity.**
  Where the active mode is poorly served, impedance spikes and that arm dies (walk/PT in a
  rural gap); where well-served it flows (rural car corridor, Oxford cycling arm). Uses the
  **mode-specific** connectivity.

Both are `effectiveEdgeCost` multipliers; they just encode different signals. Corridor +
asymmetry (rural→centre common, centre→rural rare) then **emerge** from where the wavefront
starts + the absorption field - no explicit directional bias needed.

### Parameterisation (no calibration - chicken & egg)

The algorithm changes what people see, which changes what they collect, so **historical
collection distances cannot validate it** (the algorithm invalidates its own history). Friction
functions are therefore **principled + parameterised**, not fitted:

```
friction = clamp( (connectivity / REF)^gamma , fmin, fmax )
```

REF ≈ national midpoint (~50/60), gamma = strength, sign per component above. Exposed so the
Explorer can show behaviour, not hard-code a guessed curve.

## Willingness asymmetry (user Q) — the primary extent mechanism

Travel tolerance is a property of the **collector's home area**, not geometry or the offer's
location, and it is **asymmetric**: an urban collector (short-trip norms) won't travel out to a
rural area; a rural collector (long-trip norms) will come into a city. So rural→urban collection
happens and urban→rural does not, at equal distance.

Implement as a **destination-side budget multiplier** keyed on the *collector* node — exactly the
`FairnessIsochrone` shape (`include if travel_time ≤ base × multiplier(node)`), but keyed on
connectivity/rurality:

```
willingness(collector) = clamp( (REF / connectivity_collector)^gamma_w , wmin, wmax )   // DECREASES with connectivity
```

Rural collector (low connectivity) → multiplier > 1 → reached from further; urban → tight. Because
the budget rides on the collector, outbound reach becomes asymmetric with no special-casing:
urban offers stretch out into rural areas, rural offers don't count on urban ones.

**Consolidation:** willingness (destination multiplier) carries EXTENT + ASYMMETRY; traversal
friction (edge-cost, path-integral) carries CORRIDOR shape; publicity budget scales the base.
Absorption is path-symmetric so it can't produce the asymmetry — DROP it as a separate term to
avoid double-counting; willingness supersedes it.

## Absorption × publicity budget (user Q — superseded by willingness for extent)

The isochrone's budget should BE the post's **publicity/extent budget** (the existing
extent-governor / `Budget` score term that decays with engagement = views + 3×replies). Then:

- reach expands until **accumulated absorption cost = remaining publicity budget**;
- fresh, ignored post = large budget → pushes reach far (through sparse) or thoroughly (dense);
- post with replies = small budget → reach barely extends (it's being dealt with).

This **unifies** the temporal extent-governor (when to keep rippling) with the spatial absorption
(how far in space). Consistency check: dense areas generate views fast → publicity budget
decays fast → reach self-limits; absorption is the *spatial prior* on that same effect. Caution:
**don't double-count** - absorption sets the instantaneous reach SHAPE for a given budget level;
the budget decays over time with actual engagement. Compose, don't stack two identical taxes.

## Per-group catchment tab (user Q)

New Explorer tab (separate from the point-based view): select a group (e.g. Hull), show the
**AREA** (polygon, not dots) from which posts would ripple far enough to reach it - the inbound
catchment, with and without ripple reach.

Compute as a **reverse friction-isochrone** from the group: `cost(O→G)` on the directed graph =
`cost(G→O)` on the reversed graph, so a reverse wavefront from G, with the friction field, marks
every origin whose reach includes G. Needs reverse adjacency (incoming edges) in the graph.
Answers "what is this group's ripple catchment?" - impossible to see with point clicks.

## Coverage gap

DfT data is **England & Wales only** (Edinburgh/Scotland/NI absent). Unscored nodes get
friction = 1 → clean fallback to today's plain isochrone.

## Build plan

1. `iznik-routing-go`: `Connectivity` index (mirror `Deprivation`), per-node connectivity tag.
2. Friction-weighted isochrone (edge-cost integral); optional `friction=` param on `/v1/isochrone`.
   TDD with synthetic graphs (`BuildGraphFromRaw`): friction=off ≡ plain; uniform REF ≡ plain;
   high-conn patch tightens reach *beyond* it (path-integral, not destination-multiplier).
3. Reverse-isochrone for catchment; `/v1/catchment` or param.
4. `RipplingExplorer.vue`: friction checkbox (point view) + per-group catchment tab.
5. Real data: DfT LSOA scores ⋈ ONS LSOA centroids → `uk_lsoa_connectivity.csv`.
6. Run routing tests via status-API; deploy worktree dev-live vs prod; verify.

## Status
- [x] Understand routing plumbing  [x] Worktree + branch
- [ ] Connectivity index + friction isochrone (TDD)  [ ] param  [ ] catchment reverse-iso
- [ ] Explorer checkbox + catchment tab  [ ] real data  [ ] dev-live verify
