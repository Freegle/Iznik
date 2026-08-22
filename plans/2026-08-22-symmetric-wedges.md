# Symmetric wedges: let starved areas see their market towns

Status: design agreed in principle with Edward 2026-08-22 (session: rippling explorer
merge). Not yet built. This captures the argument, the measurements behind it, and the
implementation shape, so the build can start without re-deriving any of it.

## The reported problem

DL8 3QX (Hawes, head of Wensleydale) does not see posts from Carnforth or Lancaster.
Measured against the live routing graph and live member index:

- Hawes band: sparse, cap 45 min (the ceiling). Already on the most generous setting.
- Drive times from Hawes: Ingleton 26, High Bentham 33, Kendal 52.5, Carnforth 54.5,
  Lancaster 62.5, Morecambe 64. Every named town is past the wall.
- Pool within 45 min of Hawes: 314 located active members. London equivalent: 15,870.
- `/v1/reachable-groups` for Hawes lists CarnforthFreegle and Lancaster-Morecambe-Freegle,
  because those polygons stretch east into the dales. The GROUPS are "reached"; the towns
  the groups are named after are not.
- No group polygon contains Hawes at all (checked via `/v1/groups/nearby` contains flags:
  60 nearby groups, contains=false on every one). Upper Wensleydale is white space on the
  group map, so its members are necessarily distant members of the dale-edge groups.

So a Hawes member is a member of the Carnforth/Lancaster groups, those towns' posts are on
her own groups, and the minute wall is what hides them from her.

## Why the existing machinery cannot fix it

- **Raise the sparse cap nationally**: measured audience gain at 55/65 min shows the lift
  goes mostly where it is not needed. Hawes 314 -> 1,091 (+247%) at 55; but
  Bourton-on-the-Water (already 2,290) -> 4,817, a larger absolute gain. Holt, Masham
  similar. A national rise is a volume increase everywhere to fix a famine somewhere.
- **rural_access lane**: fires only when the 4,000-member extent governor bound short of
  the ceiling. Hawes has 314; the governor never binds. Never fires here.
- **cluster-anchor lane (the wedges)**: fires only for a thin-pool ORIGIN
  (`total < cluster_floor`, shipped 1000). A Hawes POST gets wedges out to its towns
  (verified: 2-3 wedges fire). But a Carnforth post (pool 3,153) or Lancaster post
  (2,903) is blocked by the floor, and even with the floor lifted to 4,000 and the
  ceiling to 70, the wedges point at population clusters (Shap direction, Manchester
  fringe), 50-82 km from Hawes. Selection is by density; Hawes has 1 active member within
  a 10-minute drive, so it can never be selected as a target at any setting. Verified
  empirically across 9 origin/setting combinations: coversHawes=false in all of them.

## The design argument (Edward's, checked against the code and data)

### 1. Where the caps came from

Two mechanisms, both dense-motivated. Band caps (20/30/45) from the 887-post conversion
study: dense conversion collapses past 20-25 min (someone closer always takes it), and
the extra reach "costs mail and crossposts". The extent governor (4,000 nearest) is the
explicit anti-swamping control. Sparse 45 is where the MEASUREMENT stopped, not where
value stopped: 30-45 min converted at 20% vs 18% for 0-10, flat to the edge of the data.

### 2. Swamping is supply x audience at the recipient, and only dense->dense has it

| direction | supply added | recipient baseline | swamping risk |
|---|---|---|---|
| dense -> dense | huge | full | real (the cap's job) |
| rural -> dense | ~nil | full | none |
| dense -> rural | a town's worth | near-empty | none that matters: famine -> normal; digest capped at 65 and ranked |
| rural -> rural | ~nil | near-empty | none |

The cap earns its keep in row one and severs all four.

### 3. Wedges are topology edits, and adjacency has no direction

A wedge asserts "this starved area and that town are effectively adjacent": a measured
correction to the travel-time metric where it under-represents lived geography (a dale
and its market town). The current implementation attaches the wedge to the post's reach
at the thin origin, so the edge conducts one way (rural post -> town eyes). Read as
topology it should conduct both ways. Supporting evidence already in the codebase:

- #1308 (the cap belongs to the recipient): the replier's context predicts collection.
  The Hawes replier to a Kendal post is exactly the sparse-context replier the study
  measured converting at range.
- The outbound lane already breaches the 45 ceiling (cluster_max_minutes 60), so the
  precedent for "past the ceiling, along a wedge, on evidence of a real link" exists.
- Drive time is symmetric to within one-way-system noise.

### 4. Freegler density vs UK population density (Edward's addition)

One measurement (radius of nearest 400 Freeglers) currently drives three different
decisions that want different measures:

| role | really about | right measure |
|---|---|---|
| dense cutoff (20 min) | competition: someone closer takes it | Freegler density (correct today) |
| travel breadth (sparse end) | what "a reasonable distance" feels like | UK settlement geography: perception is set by where people and shops are, not where Freeglers are |
| wedge anchors | which town is "your town" | UK geography / towns table (234 rows, already used by /town/near), not Freegler cells |
| starvation gate | is your feed famine | Freegler/content density (correct today) |

Divergence cases: a strong-uptake market town can measure dense and get 20 min despite
being an island in empty country (harmful, wrong way); a weak-uptake suburb measures
sparse and gets 45 (mostly wasted mail). The 887 study bucketed by Freegler density, so
"sparse converts at range" was measured on a proxy for the causal driver (lived
geography). Where proxy and driver diverge, trust the driver.

## What survives of the hard numbers

- Dense 20 survives: best-evidenced number in the file, keyed to the density that drives it.
- A starvation criterion survives (without it, symmetry is a national cap raise by the
  back door), but restated as a RATIO of the governor's healthy-audience target (4,000)
  rather than a bare constant. Measured ladder: Leyburn 7%, Hawes 8%, Alnwick 8%,
  Holt 18%, Masham 19%, Bourton 57%, Lancaster 73%, Carnforth 79%. A dial like
  "starved = under ~25% of target" has meaningful units and inherits governor retunes.
  Exact threshold: Edward's call, with this ladder in front of him.
- The 45 wall does NOT survive as a wall for starved areas. Wedges are the surgical
  instrument for exceeding it, and shipped logged they finally measure past-45
  conversion where it matters.

## The resulting model

Isotropic part: how far you see is a function of your surroundings (dense end keyed to
Freegler competition, sparse end to settlement geography). Anisotropic part: a symmetric
starved-area <-> anchor-town adjacency, computed once per starved AREA (not per post),
read from both ends.

## Implementation shape

1. **Adjacency table** (new, small): for each starved area (blurred-origin cell whose
   pool at its band cap is under the ratio gate), its anchor towns. Anchors from the
   towns table / settlement geography, reachable within a bounded wedge ceiling
   (existing cluster ceiling 60 min is the starting point). The existing cluster finder
   seeded at the starved area already computes almost exactly this (verified: Hawes
   finds its wedges); swap its target selection from Freegler cells to towns.
2. **Outbound** (exists): a starved-origin post's reach gains wedges to its anchors.
   Unchanged in shape; target selection updated per (1).
3. **Inbound** (new): at expand time, a post lying inside an anchor town gains a rescue
   ring per starved area anchored to it (reverse index of (1): anchor -> starved areas).
   This runs POST-side, so every read surface keeps asking the single ring-admits gate
   (the one unified in the recent ripple work) and the digest follows the site
   automatically. Rural members live on the digest; a browse-only inbound lane would be
   a no-op for them.
4. **Members-only mail unchanged**: starved-area members already belong to these groups
   (there is nothing else to join), so this is an admission change, not a distribution
   change. No cold recipients.
5. **Logging/measurement**: stamp wedge-admitted impressions and replies with the lane,
   so past-45 conversion becomes a measurement. Ship dark or logged-only first, like
   every prior lane.

Costs: extra digest content only for starved-area members (bounded by the 65 cap and
closeness ranking); compute O(starved areas), cacheable like the reach cache; the
reverse index is small by construction.

## Open questions for Edward

- Starvation threshold: which rung of the ratio ladder (10% catches the truly stranded:
  Hawes/Leyburn/Alnwick; 25% adds Holt/Masham).
- UK density data source for the band/anchor work: towns table alone, ONS LSOA
  population (deprivation CSV precedent exists in the routing server), or road-graph
  node density as the in-memory proxy.
- Whether the inbound lane ships dark, logged-only, or live-by-default (house
  convention is live-by-default; this one changes what members see, so maybe logged
  first).

## Evidence appendix (all measured this session, live data)

- Hawes: band sparse cap 45; pool@45 314; nearest towns 52-64 min; 1 member within
  10 min drive; no containing group polygon.
- Pools@45: Carnforth 3,153; Lancaster 2,903 (band dense, cap 20); Kendal 2,796.
- Cap sweep (pool at 45 -> 55 -> 65 min): Hawes 314 -> 1,091 -> 3,319; Leyburn 267 ->
  853 -> 1,807; Masham 764 -> 1,221 -> 3,222; Alnwick 332 -> 1,329 -> 1,823; Holt 728 ->
  1,570 -> 1,791; Bourton 2,290 -> 4,817 -> 9,282.
- Wedge firing matrix (cluster_anchor=1): Hawes fires 2-3 wedges at all settings;
  Carnforth/Lancaster fire 0 at shipped settings, 1-2 only with floor 4,000 and
  ceiling 70, and their wedges centre 50-82 km from Hawes. coversHawes=false in all
  nine combinations.
- Gate location: iznik-routing-go/ripple.go:759
  (`cluster_anchor && total < clusterFloor`, in the cap-did-not-bind branch).
