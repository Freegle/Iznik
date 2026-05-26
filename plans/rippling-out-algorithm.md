# Freegle Rippling Out Algorithm — Plain English Guide

_Last updated: May 2026. Technical references: `iznik-routing-go/` (Go spatial server), `iznik-nuxt3/modtools/components/RipplingExplorer.vue` (browser tool)._

---

## What is "rippling out"?

When someone posts a free item on Freegle, we want to notify nearby members. But "nearby" is more nuanced than simple distance:

- A member 2 km away by road might be unreachable in 15 minutes because there's a river with no bridge
- A member 10 km away might be reachable in 8 minutes on a fast A-road
- A member in a deprived area across town might be less likely to receive notifications if the algorithm only reaches affluent neighbourhoods

The rippling-out algorithm addresses this by computing **travel-time reachability** (not straight-line distance) and **adjusting for deprivation** so that deprived communities get a fairer share of notifications.

The **Rippling Explorer** in ModTools lets moderators and support staff visualise this: set a location, watch the reachable area expand minute-by-minute, see which Freegle groups are crossed, and tune the fairness knob.

---

## How the algorithm works

### Step 1 — Road graph

We load the entire UK road network from OpenStreetMap (~57 million nodes, ~117 million edges). Each edge has travel times for three modes:

| Mode | Typical use |
|------|-------------|
| Walk | ~5 km/h on footways and roads |
| Cycle | ~15 km/h on cycleways and roads |
| Drive | ~30–80 km/h depending on road class |

### Step 2 — Dijkstra's algorithm

Starting from the nearest road node to the selected location, we run a shortest-path search (Dijkstra). After N minutes, every road node that can be reached within that time budget is "inside" the isochrone. We then draw a polygon around the reachable nodes.

The polygon is smoothed (Chaikin algorithm, 3 iterations) so it looks like a natural travel-time boundary rather than a jagged hexagonal grid.

### Step 3 — Deprivation data (IMD quintiles)

Every road node is tagged with the **Index of Multiple Deprivation (IMD) quintile** of the Lower Super Output Area (LSOA) it falls in:

| Quintile | Meaning |
|----------|---------|
| Q1 | Most deprived 20% of LSOAs |
| Q2 | Deprived |
| Q3 | Middle |
| Q4 | Affluent |
| Q5 | Least deprived 20% |

---

## The fairness adjustment

Without adjustment, the standard isochrone reaches everyone within N minutes equally. In practice this tends to favour:
- Affluent areas (better road connectivity, faster travel)
- Areas with dense motorway/A-road networks

The fairness adjustment gives **deprived areas a longer time budget** so they can "compete" on equal footing.

### The multiplier formula

For a road node in quintile Q, with fairness weight W ∈ [0, 1]:

```
time_budget = base_minutes × (1 + W × (5 − Q) / 4)
```

| Quintile | W=0 (no fairness) | W=0.5 | W=1 (full fairness) |
|----------|-------------------|-------|---------------------|
| Q1 (most deprived) | 1× base | 1.375× base | 2× base |
| Q2 | 1× base | 1.28× base | 1.75× base |
| Q3 | 1× base | 1.19× base | 1.5× base |
| Q4 | 1× base | 1.09× base | 1.25× base |
| Q5 (least deprived) | 1× base | 1× base | 1× base |

So at full fairness (W=1) and base time 15 minutes:
- Q1 nodes are reachable up to 30 minutes away
- Q5 nodes are only reachable up to 15 minutes away

The coloured polygons in the Explorer show each quintile's reach separately. "Islands" (dashed polygons) are disconnected deprived areas that the fairness boost extends to, even though they're not contiguous with the main polygon.

### The Dijkstra runs once

The algorithm runs a single Dijkstra pass with `max_time = base × (1 + W)` — the absolute maximum for Q1 at full fairness. Each reached node is then categorised into its quintile's bucket. This means one road traversal computes all five quintile polygons simultaneously.

---

## Tunable parameters

### Travel mode
Controls which road edges can be used and at what speed. The animation always uses **drive** mode because it gives the widest coverage; the static view can use walk or cycle to explore pedestrian reach.

### Base travel time (1–30 minutes)
The time budget for the standard (no-fairness) isochrone. The animation steps through 1–30 minutes one frame at a time. Longer time = larger area.

### Fairness weight (0–100%)
How aggressively to extend time budgets for deprived areas.
- **0%** = pure distance. Every area gets the same time budget. This is the standard isochrone.
- **50%** = moderate adjustment. Deprived areas get up to 1.5× the time.
- **100%** = maximum adjustment. Most deprived areas get 2× the time; least deprived unchanged.

The **⊕ Proportionate** button auto-tunes this weight to achieve the local baseline deprivation fraction (see below).

---

## The deprivation swingometer

The swingometer shows what fraction of Freeglers within the current reach polygon are in deprived areas (Q1–Q3).

### What is "balanced"?

**Crucially, "balanced" is NOT a national average.** It is the fraction that results from reaching everyone within a 30-minute standard drive (fairness=0) from this specific location. We call this the **local baseline**.

- In **Stockton-on-Tees** (one of England's most deprived areas), a 30-minute standard drive might naturally reach 78% Q1–Q3 Freeglers. "Balanced" for that location is ~78%.
- In **Guildford** (affluent Surrey), a 30-minute standard drive might only reach 28% Q1–Q3. "Balanced" there is ~28%.
- The national average (historically ~60%) is irrelevant to either location.

Using the local baseline means:
- The needle measures **algorithmic effect** — how much does the fairness adjustment shift the distribution relative to what you'd get with no adjustment at all?
- Pointing left (affluent bias) means the algorithm is reaching a less deprived population than you'd get just driving everywhere equally
- Pointing right (deprived bias) means the algorithm is successfully extending reach into more deprived areas than plain distance would

### How the baseline is computed

When you set a location in the Explorer, it silently fetches `/v1/fairness?minutes=30&mode=drive&fairness=0` and reads `fairness_score`. This is the natural fraction of deprived road nodes within 30 minutes drive with no adjustment. That score becomes the swingometer centre.

### The "Balanced" band

Currently ±8 percentage points around the local baseline. A result within that band is considered proportionate for the area.

---

## Freegler count estimate ("~N would be notified")

### How freeglers are counted

We query the `userapproxlocs` table — user approximate locations — for all Freeglers whose stored location falls within the current standard isochrone. These are "located Freeglers" (users who have set a location).

### The 35% correction

Approximately 35% of Freegle users have not set a location. They would still receive notifications (because they're members of the relevant group), but they can't be spatially mapped. The total estimate inflates the located count by 1/0.65 ≈ 1.54× to account for this:

```
total_estimate = located_freeglers / 0.65
```

The 35% is an approximation based on historical patterns. It's the same everywhere — there's no area-level adjustment for now.

### The 2000-point display cap

For performance and visual clarity, the map shows at most 2000 freegler dots. For large urban areas there may be 20,000+ located Freeglers within 30 minutes drive. The server returns the actual total count (`total_located`) alongside the sampled dots, so the estimate is based on the real count, not the 2000 display cap.

### TrashNothing members

TrashNothing members are NOT counted here — they use a separate notification algorithm and a separate database. The "~N would be notified" figure is Freegle-only.

---

## Cross-posting detection

During the ripple animation, as the isochrone expands outwards, we check whether the boundary has crossed into an adjacent Freegle group's territory. When that happens at ≥24 hours (the ripple maps 30 minutes of drive time to 30 days of post lifetime), a cross-posting suggestion is logged.

Group boundaries come from the `groups.polyindex` column in the database. Each group has a polygon defining its area.

---

## What the Explorer shows

| Layer | Colour | Meaning |
|-------|--------|---------|
| Standard isochrone | Red outline | Area reachable within current time, no fairness adjustment |
| Q1 polygon | Dark red fill | Area where Q1 (most deprived) nodes are reached with fairness time budget |
| Q2 polygon | Orange fill | … Q2 |
| Q3 polygon | Yellow fill | … Q3 |
| Q4 polygon | Light green fill | … Q4 |
| Q5 polygon | Dark green fill | … Q5 (least deprived) |
| Dashed islands | Matching colour | Disconnected fairness-bonus areas (deprived enclaves reached by the boost) |
| Freegler dots | Small red dots | Located Freeglers (only shown at zoom ≥ 11) |
| Group polygons | Green outline | Freegle group boundaries (home group = solid, neighbours = faint) |

The coloured quintile polygons are clipped to only show OUTSIDE the standard red boundary — this keeps the interior clean and emphasises which areas are being extended into by the fairness adjustment.

---

## Density-aware ripple (planned)

A future improvement: during the ripple animation, if a minute step covers an area with zero Freegler activity, skip ahead faster rather than dwelling on empty space. This would make the animation focus on meaningful expansions.

---

## Limits and caveats

1. **Road network only**: The algorithm knows about roads (OpenStreetMap). It doesn't know about train or bus routes. Someone near a train station with good rail connections may be much more reachable in practice.
2. **Static OSM snapshot**: The road graph is loaded from an OSM PBF file (updated monthly). New roads don't appear until the next data update.
3. **Approximate freegler locations**: `userapproxlocs` stores locations at postcode-sector precision (~1 km). Dot positions are jittered within the sector; individual users are not identified.
4. **IMD quintiles are LSOA-level**: Each LSOA is ~1,500 people. In rapidly changing areas (new developments, student areas) the quintile may lag reality.
5. **35% unlocated assumption**: The correction factor is area-averaged, not computed per-area. It may be higher or lower in specific communities.
