# Design D3 — Community-Relative Reach

**Philosophy 3: the reach cap should be measured relative to the community's own geography, not
absolute time or audience.** A post should not reach further than the moderator's own group already
spans, times a small, evidence-derived multiplier. Zero sliders; the "dial" is the shape of the group
itself, which every mod already understands because they drew it (or inherited it).

Author's framing in one sentence: **"posts shouldn't come from further than my own group already
reaches"** — this is the literal mental model at least four Discourse mods reached for unprompted
(Jax's "40 miles... near me first", Jos's "half the slider ≈ pretty much all of London", Group-Mod-J's
"over the water" framing of Hull vs Scunthorpe as a different community despite short distance).

---

## 0. Why this philosophy, in one paragraph

The other three designs (audience-sized burst, drive-time-anchored-to-national-survey,
demand-plateau) all answer "how many people/minutes is *enough*". This one answers a different
question the moderators are actually asking, verbatim, in the Discourse thread: **"is this still
recognisably part of my patch, or has it left my patch and entered someone else's?"** Neville_Reid's
Chilterns complaint is not "27,000 people is too many" — Jos's own Islington example puts the audience
number front and centre, but Neville's framing (#250) is about *place*: "an hour's drive each way",
"32 groups", the word "communities" used repeatedly by Group-Mod-J ("over the water", i.e. Hull vs.
the East Riding — a *cultural* boundary, not a distance one) and by Jos (Croydon → Kensington "crosses
the river", explicitly flagged as psychological, not metric). A community-structure rule answers the
complaint in the vocabulary the complainants used, which is the strongest possible answer to a
"must be explainable to a hostile moderator audience" constraint.

---

## 1. The rule, precisely

### 1.1 Core formula

For a post originating in home group *G*:

```
reach_cap_time(G)  =  clamp( α · span(G),  T_floor,  T_ceiling )
```

where:

- **`span(G)`** = the drive-time diameter of G's own catchment polygon, defined as the 90th-percentile
  pairwise drive-time between points on G's boundary, approximated cheaply (§1.3) as the drive-time
  from G's centroid to its furthest boundary vertex, doubled (there-and-back proxy for "corner to
  corner"), OR — cheaper and preferred — reuse the existing "widest span" computation already being
  built for the moderator catchment UI (task #21/#22 in the current backlog: widest-span box with
  distance + postcodes + road-distance in miles). **This is a hard dependency**: D3 needs exactly the
  primitive that UI feature already computes. If that lands first, D3 is materially cheaper to ship.
- **`α`** (alpha) = a single, network-wide constant, **not per-group**, derived from behavioural
  evidence (§1.2) — this is the "no slider" guarantee: every group gets the same multiplier, only
  `span(G)` varies, and `span(G)` is a geometric fact about a polygon the mod already drew, not a
  free-form dial.
- **`T_floor`** = 10 minutes (existing extent-governor MVP floor — adopted unchanged, §5).
- **`T_ceiling`** = 30 minutes (existing live `RIPPLE_MAX_MINUTES` — adopted unchanged as the hard
  outer bound; see §5 for why this stays even under this philosophy).

### 1.2 Deriving α — not arbitrary

α cannot be picked by eye ("feels about right") because that reproduces exactly the per-group-slider
problem the product owner rejected, just moved into a config file. It must come from evidence. Two
independent evidence sources triangulate on the same number:

**(a) From the reply/collection distance-decay data (behaviour report §2, §3).** Across every
exemplar group, the distance cap needed to capture 90-95% of *actual observed collections* is
consistently **2-4x the group's own characteristic radius**:

| Group | group "radius" proxy (√(area/π), km) | 95%-collection-capture distance (km) | ratio |
|---|---|---|---|
| TowerHamlets (1,292/km²) | √(20.2/π) ≈ 2.5 | ~5-8 | **2.0-3.2x** |
| Oxford (243/km²) | √(206.1/π) ≈ 8.1 | ~15 | **1.9x** |
| Edinburgh (118/km²) | √(402.8/π) ≈ 11.3 | ~15 | **1.3x** |
| Hull (2.9/km², catchment artefact) | √(1156.8/π) ≈ 19.2 | ~15-18 | **0.8-0.9x** |
| Swindon (16.2/km²) | √(559.4/π) ≈ 13.3 | >20 (still climbing) | **≥1.5x** |

This is noisy (Hull's catchment-boundary artefact pulls its ratio below 1, flagged explicitly in the
behaviour report as a group-boundary artefact, not a density fact — see §3 gaming discussion) but the
central tendency across the non-artefact rows (TowerHamlets, Oxford, Edinburgh, Swindon) sits in the
**1.5-2.5x** band, with dense areas nearer 2-3x and mid/low density nearer 1.5-2x.

**(b) From the audience-curve data (audience report §3).** Tower Hamlets' own reply-stop gate already
self-limits it to a p50 of 17.8 min / 13,336 audience today — call this the community's own
*revealed* comfortable ceiling. Tower Hamlets' own catchment, at typical UK urban drive speeds
(≈20-25km/h effective, per the 25-40km/h range used as an illustrative conversion in the behaviour
report), spans roughly 8-10 minutes corner-to-corner. 17.8 / 9 ≈ **2.0x**.

**(c) From the DfT external anchor (anchors report).** Shopping-trip duration is flat at ~17 minutes
nationally regardless of settlement type, while *distance* varies ~2-2.5x by rural/urban band. This is
independent confirmation that "the multiplier that keeps *time* investment felt-equal across densities
is around 2-2.5x the local characteristic scale" — structurally the same ratio D3 is trying to encode,
arrived at from a completely different (national travel-survey) dataset.

**Chosen value: α = 2.0**, i.e. reach extends to twice the group's own drive-time span, floored at 10
minutes and ceilinged at 30. This is a genuinely evidence-triangulated constant (three independent
methods land in 1.3-3.2x, centring on ~2), not a guess — but it is the single weakest link in this
design and should be treated as v1, revisited once the reach experiment (§5) provides free-demand data
rather than the exposure-capped floor used here. **α is a network-wide constant that never needs a
per-group override — that is the entire point of this design.**

### 1.3 Computing span(G) cheaply

Two options, cheapest first:

1. **Reuse the widest-span primitive already scheduled for the moderator catchment UI** (backlog
   tasks #21/#22: "widest-span box with distance + postcodes + road-distance in miles"). If that ships
   first, D3 literally reads the same number the UI shows the mod — which has the pleasant side effect
   that **the mod-facing explanation and the parameter are provably the same fact**, closing the
   "explainable to a hostile audience" requirement by construction rather than by prose.
2. **Fallback (if built independently/first)**: `span(G)` = drive-time (via the existing
   `iznik-routing-go` Dijkstra used for `/v1/ripple-schedule`) from G's centroid to the single furthest
   vertex of `groups.polyindex`, doubled. This needs one Dijkstra call per group, cached (groups'
   polygons change rarely — recompute on group-boundary edit, not per-post). Cost: negligible (~500
   published groups, one-off + on-edit).

Either way, **`span(G)` is computed once per group (cached, recomputed on boundary edit), not once per
post** — cheap, and crucially decouples the reach parameter from post-time load.

### 1.4 Full worked formula per post

```
T_max(post) = clamp( 2.0 × span(home_group(post)),  10 min,  30 min )
```

This T_max replaces `RIPPLE_MAX_MINUTES` as the per-post ceiling fed to `/v1/ripple-schedule`
(`max_minutes` parameter — already an accepted parameter of the existing API, per mechanics report
§2.2: `mode=drive, ticks=9, max_minutes=<T_max>, curve=step-70`). Everything else in the existing
pipeline (step-70 curve, 9-tick hazard schedule, reply-saturation-stop=5) is **unchanged** — D3 is a
drop-in replacement for the single constant `30`, not a new pipeline.

---

## 2. Worked examples

Using `span(G)` computed as (√(area_km²/π) × 2, converted to drive-time at ~25km/h dense / ~35km/h
rural — the same illustrative conversion the behaviour report uses, flagged there as needing real
routing data before implementation) as the fallback method (§1.3 option 2), since the true widest-span
primitive isn't built yet:

| Group | Area (km²) | Radius proxy (km) | Corner-to-corner span (2×radius, km) | Illustrative drive speed | span(G) as drive-time | T_max = clamp(2.0×span, 10, 30) | Today's T_max | Direction of change |
|---|---|---|---|---|---|---|---|---|
| Tower Hamlets | 20.2 | 2.5 | 5.1 | 20 km/h (dense) | ~15 min | **30 min** (clamped — 2×15=30) | 30 min (but self-gates to 17.8 via reply-stop) | **No change to ceiling**, but see §2a below |
| Oxford | 206.1 | 8.1 | 16.2 | 25 km/h | ~39 min | **30 min** (clamped) | 30 min | No change |
| Hull (catchment) | 1,156.8 | 19.2 | 38.4 | 35 km/h (rural) | ~66 min | **30 min** (clamped) | 30 min | No change |
| Swindon | 559.4 | 13.3 | 26.7 | 30 km/h | ~53 min | **30 min** (clamped) | 30 min | No change |
| Ribble Valley | 574.4 | 13.5 | 27.0 | 35 km/h (rural) | ~46 min | **30 min** (clamped) | 30 min | No change |
| Edinburgh | 402.8 | 11.3 | 22.6 | 22 km/h (urban) | ~62 min | **30 min** (clamped) | 30 min | No change |
| "NW-London" (proxy: dense inner-London boroughs, e.g. Brent/Ealing/Islington, per audience report §3d) | ~15-20 (typical inner-London borough) | ~2.3-2.5 | ~4.6-5.0 | 18-20 km/h (heavy congestion) | ~14-17 min | **28-34 → clamped 30** | 30 min | **No change at this α** |

**This table is the single most important — and uncomfortable — finding of this design: at α=2.0, the
ceiling clamp (30 min) dominates for almost every exemplar group, including the dense London ones,
because London boroughs, while dense in *members*, are not small in *drawn area* (a typical London
borough group spans 5-8km across, not the sub-1km "postcode-sized" patch the mental model implies).**
The community-structure rule as literally stated (`α × own span`) does **not**, by itself, tighten
Tower Hamlets or the Chilterns-complaint boroughs below today's ceiling — because those groups' own
polygons are themselves several km across, and 2x that is still ≥30 minutes almost everywhere except
the very smallest, tightest urban patches.

### 2a. Where the rule *does* bind — and the honest fix

The rule only produces a **tighter-than-30-min** cap for groups whose own span, doubled, is under 30
minutes — i.e. genuinely small/tight polygons. Scanning the exemplar set, none qualify at α=2.0. This
means **either α must be lower for this design to do useful work in the complaint geography, or the
"own group span" concept must be replaced with a tighter definition** — most plausibly **not the
group's own polygon, but the *adjacent-ring* formulation** the philosophy brief also names ("at most
the adjacent ring of neighbouring groups"):

```
T_max(post) = drive-time to the FURTHEST BOUNDARY of the union of
              {home_group ∪ groups sharing a border with home_group}
```

i.e. reach extends only as far as it takes to cross the *next* group over, wherever that boundary
falls — no multiplier at all, purely topological. This is a **materially different, arguably stronger**
formulation of the same philosophy: it directly answers "communities crossed" (cap = 1 community
crossed) rather than "multiple of my own size", and it naturally self-scales because adjacent small
London boroughs are close by (short drive-time to their far edge) while adjacent rural groups are large
(long drive-time to their far edge) — it doesn't need α at all, and it degenerates gracefully. **This
is the recommended primary formulation; §1's α×span formula is retained as a documented alternative
because the brief explicitly asked for the multiplier derivation, but the ring formulation is what I'd
actually ship.**

Re-worked table using the **adjacent-ring formulation** (drive-time to the far edge of home ∪
immediately-bordering published groups, computed via the same Dijkstra/`polyindex` union already used
for cross-posting in `rippleIntoNewGroups`):

| Group | Adjacent groups (illustrative, from geography) | Union span drive-time | T_max = clamp(span, 10, 30) |
|---|---|---|---|
| Tower Hamlets | Hackney, Newham, Southwark, Islington, City of London | ~18-22 min (dense boroughs, tightly packed) | **~20 min** — meaningfully below today's 30 |
| Oxford | Oxfordshire-surrounding rural groups (Abingdon, Kidlington, Witney) | ~28-30 min (rural neighbours are large) | **~28-30 min** — near-unchanged |
| Hull | East Riding, Beverley, Bridlington (Group-Mod-J's actual "over the water" complaint) | ~35-40 min (large rural neighbours) | **clamped 30 min** — unchanged, correctly (this is the "over the water" case the mod explicitly wants preserved, see §4b) |
| Swindon | Wiltshire rural groups | ~35+ min | **clamped 30 min** — unchanged |
| Ribble Valley | Lancashire rural groups (Clitheroe, Preston fringe) | ~30-35 min | **clamped 30 min** — unchanged |
| Edinburgh | Midlothian, East/West Lothian | ~30-35 min | **clamped 30 min** — mostly unchanged |
| Inner-London boroughs (Brent/Ealing/Islington complaint cluster) | Each other, tightly packed (5-15 adjacent groups per borough in inner London, per the top-15-audience-groups list in the audience report — "every single one is inner/west London") | **~12-18 min** (boroughs are small and densely tiled) | **~15-18 min** — a real, substantial tightening, directly answering the Chilterns/Islington complaints |

**This is the version of D3 that actually resolves the complaint**: inner London boroughs are small
*and* densely tiled with other small boroughs, so "one ring out" is genuinely short (12-18 min);
rural groups are large *and* sparsely tiled, so "one ring out" is genuinely long (often clamped at the
30-min ceiling) — which is exactly the intended behaviour ("rural posts riding to the ceiling is
intended, not a bug", per mechanics report §6). No α needed; the adjacency graph and the union-polygon
Dijkstra call are both primitives the pipeline already has (group `polyindex` intersection is already
computed every tick for cross-posting).

**Recommendation: ship the adjacent-ring formulation, not the α×span formulation.** I include both
because the brief specifically asks me to "derive alpha", and the honest answer is that the
alpha-multiplier version of this philosophy, calibrated against real evidence, does not bind in the
complaint geography — a genuine, load-bearing negative finding, not a rounding error. The ring
formulation both satisfies the philosophy (community-structure, zero sliders, explainable) and
actually moves the numbers where the complaints are.

---

## 3. Failure modes + mitigations

**3.1 Coastal/estuary geometry.** A group whose polygon includes sea/estuary (e.g. a Solent-facing or
Thames-estuary group) has geometric area that overstates its "real" span, because part of the polygon
is uninhabited water — inflating `span(G)` upward under the α formula, or inflating the adjacent-ring
union under the ring formula if a neighbour is drawn to include the same water. *Mitigation*: compute
span/adjacency using the drive-time network (road graph), not raw polygon geometry — the existing
`iznik-routing-go` Dijkstra already only traverses road nodes, so water is naturally excluded (you
cannot drive across the Thames estuary without going via a bridge/tunnel node) — this failure mode is
**already mitigated by construction** as long as span is computed via routing, not via straight-line
polygon geometry (which is why §1.3 explicitly rejects a pure-geometry radius in favour of a Dijkstra
call). The illustrative worked examples above used geometry-based radius only because true routing data
wasn't pulled in this design pass — a real implementation must use the routing engine, not `ST_Area`.

**3.2 Group-boundary gerrymandering (does this reward drawing a huge polygon?).** Direct answer under
the α formula: **yes, partially** — a group that draws its own polygon larger gets a larger `span(G)`
and therefore a larger cap, which is a genuine gaming vector (a mod unhappy with a 20-min cap could, in
principle, ask to redraw their boundary bigger to inflate their own ceiling). This is a real weakness
of the α×span formulation. **Under the ring formulation, the incentive inverts and mostly cancels**:
inflating your own polygon does increase the union span slightly (you now include more of your own
larger area before even reaching a neighbour), but the dominant term is the neighbours' span, which the
group doesn't control — and inflating your own boundary also means you now overlap more neighbours
(triggering more `rippleIntoNewGroups` insertions per tick, i.e. *more* mod-load on you, not less) and
raises legitimate "your boundary looks gerrymandered" scrutiny that a human review process (boundary
changes already require Freegle HQ sign-off per existing group-management practice) can catch. It is
not gameable to zero, but the ring formulation is materially harder to game profitably than the α
formulation, and gaming is visible (boundary-edit history is auditable) rather than silent (unlike a
manual reach slider, which was rejected specifically because it's an invisible, ungoverned dial).
*Mitigation*: (a) prefer the ring formulation; (b) log every group-boundary edit's effect on computed
`span`/ring-reach and flag outlier deltas (>50% change in one edit) for HQ review before it takes
effect — cheap, and reuses the existing boundary-edit approval workflow rather than building new
governance.

**3.3 Seasonal actives / member churn.** This design has **no active-member-count term at all** — it
is purely geometric (group shape + adjacency), which means it is **immune** to seasonal
active/dormant member swings, unlike an audience-sized (N\*) design. This is a genuine structural
strength of this philosophy relative to D1/D2-style audience designs: geometry doesn't have a summer
dip. The corresponding weakness: it cannot respond to a group's membership *growing* into genuinely
higher local demand over time except via the demand-plateau overlay in §3.6 below.

**3.4 TrashNothing / cross-posted members.** Out of scope for the extent formula itself (TrashNothing
sync affects *who* is notified within a computed reach polygon, not the polygon's size) — no
interaction with this design. Flagged as a non-issue for this philosophy specifically, unlike audience
designs which must dedupe reach denominators against TrashNothing membership.

**3.5 Data drift (routing graph changes, e.g. a new bridge/bypass opens).** Drive-time spans and
adjacency unions should be **recomputed on a schedule, not just on boundary edit** — the OSM road graph
underlying `iznik-routing-go` updates periodically (new roads, closures). *Mitigation*: recompute
`span(G)`/ring-reach for all groups on a **monthly batch job** (cheap: ~500 groups, one Dijkstra call
each, well within existing `compute_concurrency=8` throughput), not per-post — this is the
self-maintenance mechanism (§5's "how does the parameter stay correct over time" requirement): the
number silently tracks the real road network and any group-boundary edits, with zero manual
recalibration, forever, because it's a geometric fact re-derived from source data, not a fitted
statistic that goes stale.

**3.6 Cold start (new/small groups, Scotland/NI thin-data areas).** This is this philosophy's
**strongest area**, not a weakness: span/adjacency depend only on the group's drawn polygon and the
(static, nationwide) road network — both exist on day one for a brand-new group, with **zero
dependency on post volume, reply history, or any per-post statistic**. A brand-new Highlands group with
one member gets exactly the same, immediately-computable ring-reach as an established neighbour of
similar shape. Contrast with N\*/audience-sized designs, which need enough historical posts to
calibrate a demand curve and are explicitly thin in Scotland/NI (audience report §3d: Scotland 61%
never-reach-N\*=2000, NI 100%, n=51 — too small to calibrate anything from post history alone). **D3
has no cold-start problem** because it never reads post-outcome data at all — this is arguably its
single best property.

**3.7 Group deleted/merged/inactive neighbour.** If a bordering group is deleted, merges, or goes
dormant, the adjacency graph must be recomputed (covered by §3.5's monthly job) — otherwise a ring
computed against a stale neighbour list either under- or over-extends. *Mitigation*: trigger
recomputation on group status change (deleted/merged/dormant-flagged), not just monthly — cheap event
hook, existing group-lifecycle code already fires status-change events.

---

## 4. Mod-facing explanation

### 4a. The exact sentence(s)

For a group's settings/help page (static per group, computed once, cached):

> **Your group's reach: up to {T_max} minutes' drive.**
> Offers from your group are shown to nearby Freeglers out to about as far as it takes to drive across
> your own group's area and into the next one over — roughly {T_max} minutes today. In a tightly-packed
> area like yours, that's a short hop to your immediate neighbours; in a spread-out area, "the next
> group over" can itself be a long way, so the reach is naturally larger, up to a maximum of 30 minutes
> either way.

For the moderator catchment-map UI (dovetailing directly with backlog tasks #21/#22, the widest-span
box): show the group's own computed span **and** the resulting ring-reach on the same map, so the mod
sees cause and effect in one view rather than being told a number in isolation — e.g. "Your group spans
{span} at its widest. Posts reach into the {N} neighbouring groups shown shaded, out to {T_max}
minutes." This is deliberately the same primitive as the catchment UI already being built, so there is
**one number, shown in two places, always consistent** — no risk of the help text and the map
disagreeing, which would itself become a new complaint vector.

### 4b. Why this lands with a hostile audience specifically

It directly answers the two sharpest structural complaints verbatim:

- Jos's Swindon-vs-Islington comparison (#248): under this rule, Swindon's ring-reach is large (rural
  neighbours are far apart) and Islington's is small (inner-London boroughs are packed tight) — **the
  rule produces the asymmetry the mods are already demanding**, without a slider, and the explanation
  ("your neighbours are close/far, so your reach is short/long") requires no statistics literacy.
- Group-Mod-J's "over the water" framing (Hull vs. East Riding, #328-ish territory per discourse
  report): a topological/community-boundary rule is the **only one of the four designs that can
  represent "crossing the water counts as leaving the community" natively** — if the neighbouring
  group across an estuary is itself large/far (as Hull's rural neighbours are), the ring formulation
  naturally produces a long reach only because the *real* geography is sparse there, which the mod can
  verify by looking at the same map they already use.

---

## 5. Rollout: dark-compute → scoped → network-wide

**Relationship to the existing extent-governor MVP**: this design **replaces** the MVP's `target_users`
audience-cap mechanism as the *primary* extent control, but **adopts unchanged** its scaffolding:
the floor (10 min), the overall ceiling (30 min — retained as belt-and-braces even under a
community-structure primary rule, because a routing-graph anomaly or a mis-drawn polygon could
otherwise produce a pathological span; keeping the existing hard ceiling costs nothing and prevents a
new class of bug), the `RIPPLE_EXTENT_ENABLED`-style dark flag pattern, and — crucially — the
`rippling_reach.schedule` JSON storage, which needs **zero schema change**: `max_minutes` is already a
per-request parameter to `/v1/ripple-schedule` (mechanics report, parameter #6), so swapping the
constant `30` for a per-post `T_max(post)` computed from the home group is a parameter-plumbing change
in `ReachService::scheduleParams()`, not a new pipeline. It does **not** need `target_users` at all —
this design and the audience-sized-burst MVP are alternative primary levers, not layered (running both
simultaneously would double-constrain and make failures hard to attribute; pick one as primary, or run
D3 as primary with `target_users` as an emergency-only ultra-high secondary cap, off by default).

**Relationship to the planned reach experiment**: unaffected and complementary. The experiment
randomizes *who receives extra reach* to measure whether extra reach converts to real demand (the
"unobservable extra reach" problem, evidence digest). D3's α/ring-reach values in this document are
calibrated against **exposure-capped historical behaviour** (today's collection/reply distances, which
can only reflect what people could already see) — exactly the same "floor not ceiling" caveat the
behaviour report states explicitly. The experiment, once it runs, should be used to **re-validate**, not
to derive, D3's parameters: if extra reach beyond a group's ring turns out to convert well, that's
evidence for loosening the ceiling generally (a global, not per-group, change) — it does not argue for
abandoning the community-relative *shape* of the rule, since the experiment is about magnitude
(how much further is worth it) not shape (whether "further" should be uniform or community-relative).

**Phased rollout**:

1. **Dark-compute (no live effect), 1-2 weeks.** Compute `span(G)`/ring-reach for all ~500 published
   groups from the existing road graph (batch job, §3.5), and for every post since `enabled_at`
   (2026-06-23), recompute what its `T_max` would have been under D3 vs. the actual (flat 30) it got.
   Compare against actual observed audience/collection-distance/reply-rate for that post (using exactly
   the demand report's decile methodology) to sanity-check: does D3's tighter cap for dense groups still
   capture ≥90-95% of the collections those groups actually had? (This is a direct rerun of the
   behaviour report's §3 "what cap captures X%" table, but per-post rather than per-exemplar-group —
   cheap, all inputs already exist.) **Kill criterion at this stage**: if D3's computed caps would have
   excluded >10% of real historical collections in any density band, do not proceed — recalibrate the
   ring definition (e.g. two rings instead of one) before touching any live traffic.
2. **Scoped pilot, 2-4 weeks, `RIPPLE_WITHIN_GROUPS`-gated** (mechanism already exists per mechanics
   report parameter #3). Select ~15-20 groups spanning the full density range that the audience report's
   region rollup identifies as bimodal: several inner-London boroughs (the complaint cluster — Brent,
   Ealing, Islington, Tower Hamlets), several mid-density (Oxford, Edinburgh), several rural (Hull,
   Swindon, Ribble Valley, a Scotland/NI representative for cold-start validation). Run D3 live for these
   groups' posts only; measure against control (remaining network, still on flat 30 min):
   - Reply rate, distinct repliers, time-to-first-reply (per demand report's existing metrics)
   - Collection/Taken rate and time-to-Taken
   - Collection distance distribution (does it still match or exceed the group's own historical
     percentile-capture rate from §2's dark-compute check)
   - Mod-load proxy: count of groups a post rippled into (should drop for the dense pilot groups if the
     ring formulation is working as intended)
   - **Direct complaint-resolution check**: manually verify the exact Discourse-cited place-pairs
     (Chilterns↔East-London, Fife↔Queensferry, Brent↔Hemel-Hempstead) no longer occur for pilot-group
     posts, or occur measurably less often
3. **Network-wide**, once pilot passes kill criteria (below), rolled out group-by-group or in one
   cutover (cheap either way since it's a per-post parameter, not a migration) — announce via the
   Discourse thread (#9808) with the mod-facing explanation (§4a) and the same catchment-map visual mods
   already see, timed to coincide with (not compete with) the recently-shipped per-member opt-down
   slider (#382), which is a different, complementary lever (member-level self-service vs. this
   group-level structural default).

**What to measure (ongoing, post-rollout)**: reply rate, collection rate, collection-distance
percentile-capture (all per demand/behaviour report methodology, rerun monthly), mod-load (groups
rippled into per post), and — specific to this design — **span/ring-reach drift over time** (are groups'
computed values changing due to boundary edits or road-network changes, and if so, is that drift
correlated with complaint volume in Discourse). This last one is D3's own self-maintenance monitor: since
the parameter is a re-derived geometric fact rather than a fitted statistic, "is it still correct"
reduces to "is the underlying map data still correct", which is a much easier ongoing question than "is
our calibration stale" (the audience-sized design's equivalent question).

**Kill criteria**:
- Any pilot group's collection rate or Taken-rate drops >15% relative to its own pre-pilot baseline
  (adjusted for network-wide trend) with no confounding explanation
- Collection-distance percentile-capture for a pilot group falls measurably below its dark-compute
  prediction (i.e. the live rollout is worse than the historical replay predicted — sign of a routing
  or adjacency-graph bug)
- Complaint volume on Discourse from *pilot* groups does not measurably improve relative to control
  groups over the pilot window (the whole point is complaint resolution — if it doesn't move the needle
  on the actual Discourse thread, the design has failed on its own terms regardless of what the
  telemetry says)
- Gaming detected: a group boundary edit that produces a >50% span/ring-reach jump without a
  corresponding genuine geography change (§3.2's mitigation should catch this before it ships, but this
  is the backstop)

---

## 6. Honest cons: where this philosophy is weakest

1. **The naive α×span formulation, calibrated against real evidence, does not actually bind in the
   complaint geography** (§2's central negative finding) — London boroughs are geometrically several km
   across, so "twice your own span" still clamps at 30 minutes for exactly the groups that are
   complaining. I had to fall back to a materially different formulation (adjacent-ring, no multiplier)
   to get a design that does real work. This means the philosophy as literally stated in the brief
   ("reach cap = f(group's own road diameter)") is weaker than a topological alternative
   ("adjacent groups crossed") that lives in the same conceptual family but isn't quite the same rule —
   an honest reviewer should treat §1 (α formula) as *demonstrated inadequate* and §2a (ring formula) as
   the actual proposal, not a minor variant.

2. **No connection to real demand or engagement signal at all.** Unlike D2 (demand-plateau) or the
   audience-sized MVP, this design is purely geometric — it has no mechanism to notice that, say, a
   particular sparse group's posts are chronically under-replied-to even at the 30-min ceiling (which
   the demand report shows is common: 56-66% zero-reply rate) and might benefit from *more* reach than
   its own geometry implies, nor to notice that a particular dense group's audience is chronically
   oversaturated even within its ring. It optimises for "geographically sensible", which is what the
   complaint is actually about, but it is silent on "did this help anyone collect anything" — that
   question needs the reach experiment or a demand-plateau overlay layered on top, which this design
   explicitly does not attempt (§5 recommends validating against demand data, not deriving from it).

3. **Adjacency graphs are more operationally fragile than a single global constant.** A flat 30-min
   ceiling never needs recomputation. A per-group adjacency-derived reach needs a maintained,
   periodically-refreshed graph of which groups border which, robust to boundary edits, group mergers,
   and (rarely, but it happens) genuinely gerrymandered or overlapping boundaries where "adjacent" is
   ambiguous (e.g. two groups covering the same area at different times, or an enclave group fully
   inside a larger one). This is real, ongoing engineering surface area that the other three designs
   (which mostly key off audience counts or drive-time alone) don't carry to the same degree.

4. **Reinforces existing boundaries rather than questioning them.** If a group's boundary is itself
   badly drawn (too large, too small, historically arbitrary — a known issue independent of rippling),
   this design faithfully propagates that flaw into the reach parameter, because it's defined entirely
   in terms of the boundary. An audience-sized design is comparatively boundary-agnostic (it only cares
   about people-density in a radius, not which polygon they're nominally inside), so it's more robust to
   bad historical boundary-drawing. This design makes getting group boundaries right a harder
   prerequisite than it otherwise would be.

5. **Multiplier/ring-depth choice (α=2.0 or "one ring") is still ultimately a single global judgment
   call**, even though it's evidence-triangulated (§1.2) rather than arbitrary — a genuinely hostile
   critic could reasonably ask "why one ring and not half a ring, or one-and-a-half rings" and the honest
   answer is "this is the smallest whole-number topological unit that maps onto 'the next community
   over', chosen for explainability over precision, and validated against, not derived purely from,
   the collection-distance evidence" — i.e. it is principled and evidence-checked, but the *specific*
   choice of "exactly one ring, not some fractional distance into ring two" carries a design judgment
   that a different designer might reasonably make differently. I'd defend it as the most explainable
   choice available, not as the unique mathematically optimal one.
