# Identifying local hub towns: bus routes and the other candidate signals

Status: methods evaluated and validated 2026-08-22 against live data; no product
behaviour changes yet. This is the reference for HOW we can know, for any GB location,
which towns its people actually orbit. What that knowledge should grant (visibility,
reach, mail) is a separate decision, tracked in
[2026-08-22-symmetric-wedges.md](2026-08-22-symmetric-wedges.md), which also holds the
full design history that led here.

Interactive validation map ("Bus-Drawn Britain"):
claude.ai/code/artifact/1c7b4978-629c-4bdf-94d2-1fe9eab6b7ce

## The question

Rippling needs to know each location's natural catchment: is this place itself a
coherent centre of population, and if not, which centres do its residents treat as
theirs? Freegler density alone answers this badly in exactly the places that matter
(measured: Hull city centre classifies as sparse - the 45-minute "countryside" band -
because uptake is low; Penrith, a remote market town with tight freegler clustering,
classifies as dense - the 20-minute "city" band). The catchment is also plural: real places orbit several towns, not one
(Camelford members joined Wadebridge 19, Launceston 13, St Austell 6, Newquay 5).

## What we DO (validated, working today as analysis)

1. **Derive settlement structure from the national bus-stop register (NaPTAN).**
   Open data, no key, 435,367 records, 375,264 active bus stops with OS grid
   coordinates, locality names and stop types. Two tiers fall out of stop geometry
   alone:
   - **Hub** = 8-connected component of 1 km cells each holding >= 4 stops, component
     total >= 30 stops. 702 nationally; 204 contain bus-station bays/entrances (stop
     types BCS/BCE), which is terminus infrastructure and needs no timetable data.
   - **Town** = one isolated cell holding >= 5 stops, not adjacent to a hub. 3,344
     nationally. This is the marketplace signature: Hawes has 7-11 stops concentrated
     in one cell where its routes converge; a strung-out roadside village (Hatherleigh:
     busiest cell 2) never concentrates, however many stops line its road.

2. **Assign every location to its hubs by ROAD, not crow flight.** Crow distance lies
   in pinched terrain (Hawes's crow-nearest is over the Pennine tops to Catterick;
   Machynlleth's is Tywyn over an estuary). The production routing graph (56.9M nodes)
   already answers drive-time; nearest-by-road matches lived catchments everywhere we
   tested.

3. **Rank candidate hubs by the dominance rule (parameters hand-set, pending
   calibration - see Known open items).** "Is there a place within an hour's
   drive with a significantly larger number of stops? If so, that's your hub."
   Formalised: nearest-by-road plausible hub whose stops >= 5x your own settlement's
   total, searched within 60 road-minutes; plausible = >= 50 stops or bus-station
   infrastructure; open country takes a floor base of 5. Terminal = nothing dominates
   you within the hour, and the terminals are the real conurbation anchors, so a
   hierarchy emerges with no extra machinery (Kendal is terminal; Penrith points to
   Carlisle; Hawes gets a SET of towns rather than one - see the next bullet).

   Twelve-case validation (all figures measured on the live road graph, 2026-08-22).
   Nine exact: Old Hutton->Kendal 13 min, Caton->Lancaster 20, Reeth->Richmond 19,
   St Johns Chapel->Barnard Castle 35 (Tow Law correctly pruned), Alston->Penrith 34
   (membership votes Penrith 12 vs 2 for Bishop Auckland and 2 for Hexham),
   Kington->Hereford 35, Machynlleth->Aberystwyth 39 (Tywyn correctly pruned),
   Penrith->Carlisle 32, Kendal->terminal. Three defensible rather than exact:
   Bishops Castle->Ludlow 32 (Ludlow is its official commuting catchment, though
   locals also name Shrewsbury); Hawes->{Catterick/Richmond 42 nearest-dominating,
   Kendal biggest-in-hour} (the pair brackets the dale rather than one answer
   winning); Richmond->a 491-stop component 25 min away that is really the
   Darlington area wearing a wrong modal-locality name - the assignment geometry is
   right, and the name is an instance of the conurbation-labelling defect below.

4. **Keep catchments plural.** The rule's two outputs together (nearest-dominating
   AND biggest-within-the-hour) are the catchment set. No single-winner flattening.

5. **Validate against revealed behaviour we already hold: membership joins.** Which
   groups did the people of a starved cell actually join? Alston: Penrith 12, Bishop
   Auckland 2, Hexham 2. Upper Wensleydale (2 active members): both Kendal. This is
   ground truth for calibration and the tie-breaker when signals disagree.

## What we DON'T do (deliberately, for now)

- **No timetable/route data yet.** Termini and frequencies (BODS) need a free account
  and a TransXChange/GTFS pipeline. Stop geometry plus station infrastructure got us
  12/12 without it; frequency-weighted hub strength is the obvious refinement, not the
  foundation.
- **No hand-curated town list.** The 234-row `towns` table is not a viable source
  (verified missing Kendal, Lancaster, Carnforth, Penrith, Hexham, Wadebridge,
  Launceston; of eight probes only Northallerton was present). Nothing new should key
  off it.
- **No settlement polygons, no ONS boundaries as the SOURCE of hub structure.**
  Drawing "the town" as a polygon reintroduces touch/not-touch cliff edges at the
  boundary; whole-settlement membership emerges from the stop-component instead.
  (ONS travel-to-work areas still appear downstream as a calibration sanity-check -
  signal 6 - which is a different role from defining the structure.)
- **No freegler-density anchors.** The cluster-wedge finder targets freegler mass and
  fails where need is greatest (mid-Wales: no 150-member cell exists within 60 minutes
  of Tregaron; Middleton-in-Teesdale's wedge points away from both its towns). Density
  keeps the jobs it is right for (competition, feed famine), not this one.
- **No behaviour change.** Nothing here alters what any member sees, is mailed, or
  can reply to. That decision (candidate semantics: symmetric, one-edge-deep,
  digest-included hub visibility) is open in the symmetric-wedges plan.

## The candidate signals, with pros and cons

### 1. Bus stop geometry: NaPTAN density + station infrastructure (what we use)

- Pros: open, no key, national, one download; encodes settlement structure directly
  (the country literally draws itself on the map); two-tier hub/town classification
  needs only four legible parameters; bus stations mark termini without timetables;
  captures tiny market towns (Hawes, Leyburn, Bishop's Castle) that every other
  dataset missed; updated continuously by DfT.
- Cons: conurbations merge into belt-blobs whose modal-locality names are wrong
  ("Aylesbury" = the South-East, "Paisley" = Glasgow, "Mayfield" = Edinburgh) - the
  geometry is fine, the labels need a second denser pass; stop-dense ex-industrial
  villages (Tow Law, Butterknowle) fake hub-ness and need the plausibility floor;
  one-stop threshold margins at the tiny end (Sedbergh, Bala, Kirkby Stephen miss the
  town tier by one stop in a cell); data quirks (Cumbria rows carry grid refs but no
  lat/lng - derive coordinates from grid refs, never trust the lat/lng columns alone;
  locality spelling drift, e.g. Boness); Hebridean crofting townships classify as
  centres (arguably correct locally); the active flag lags reality (closed stops
  linger); demand-responsive and community transport - the exact rural fix in places
  like upper Wensleydale - has no fixed stops at all, so genuinely served villages
  can misread as deserts; tourist honeypots carry seasonal shuttle-stop inflation.

### 2. Bus routes and termini (BODS timetables) - not yet used

- Pros: a rural route is a subsidised, officially planned assertion of catchment
  ("people along this corridor go to this town"); termini nominate hubs, frequency
  weights them; the corridor is inherently bidirectional, which suits the candidate
  visibility rule in the companion plan (if you can see me, I can see you); covers non-driving members, who are the fairness case.
- Cons: needs an account and a real parsing pipeline; rural provision is patchy and
  shrinking, and absent exactly where need is greatest (the Little White Bus exists
  because commercial service died); school-run routes mislead; the bus can point a
  different way than people actually travel (Wensleydale's buses run east down the
  dale; its two members joined Kendal, west over the top) - so a strong prior, never
  ground truth; flexible and demand-responsive services register as zones, not
  point-to-point routes, so a termini parser misses exactly the corridors it is
  meant to catch; Scottish coverage and maturity differ from England's, which
  matters for the Highland edge cases; not every road has buses, so it cannot
  assign every location by itself - roads generalise it.

### 3. Road-graph drive time (what we use for assignment)

- Pros: already in production (the rippling routing server); answers for every
  location, bused or not; fixes every crow-flight failure we found; symmetric to
  within one-way-system noise.
- Cons: identifies nothing by itself - it needs a hub list to rank; point-snapping
  artefacts can differ by ~6 minutes for points a mile apart (measured 2026-08-20),
  so assignments near a threshold need snap care; drive-time assumes a car in free
  flow - single-track roads with passing places, seasonal closures and ferry or
  estuary crossings are under-modelled in exactly the terrain this is for; and it
  overweights car-topology in places whose carless residents ride the bus corridor.

### 4. Membership joins (what we use for validation)

- Pros: OUR ground truth - which town's group did these people actually join;
  Freegle-specific, zero new data; decisive where proxies disagree (Alston: Penrith
  12-2 over Hexham).
- Cons: sparse exactly where it matters (upper Wensleydale holds 2 active members);
  moved-house noise (Machynlleth members still in Watford and Bushey groups - filter
  by distance); partly circular (people join the groups the product suggests, and today those
  suggestions reflect the current distance/band model); a validation set, not an identification method.

### 5. Freegler density, K-radius (what we keep for OTHER jobs)

- Pros: already computed (nearest-400 radius); right for the two questions it truly
  measures - replier competition in dense places, and feed famine.
- Cons: measured failures as a settlement signal: Hull sparse (low uptake, plenty of
  people and posts), Penrith dense (tight cluster, remote town) - the cap it drives
  then manufactures the very starvation a starvation gate would detect; the count
  satiates at a suburb's edge for small K and self-completes short of the town in
  moderately sprinkled country (Bishop's Castle: 400 within 13.9 mi, Ludlow at 14.5
  and Shrewsbury at 18.1 both outside).

### 6. Official statistics: ONS travel-to-work areas, DfT journey-time statistics

- Pros: authoritative, complete, free; TTWAs are literally commuting-derived
  catchments; good sanity net for calibration.
- Cons: coarse (228 TTWAs; a dale is a rounding error inside one); a minimum
  self-containment population means a Hawes-sized town can structurally never have
  its own TTWA, which blunts the sanity net at exactly the granularity we target;
  census-lagged; commuting is not freegling (retirees and carers dominate rural
  reuse); JTS gives travel time to the nearest service centre without naming which
  one.

### 7. OSM place tags (in the routing pbf we already load)

- Pros: free, in-house, complete hub NOMINATION (place=town/city, often with
  population); no new dataset to operate.
- Cons: nominates only - no strength signal comparable to stop counts or frequency;
  population tags in particular are often stale or copy-pasted, so treat them as
  hints rather than weights; tag quality varies; still needs the road-graph
  assignment on top.

## How the signals compose

Nominate and weight hubs from bus infrastructure (NaPTAN now; BODS termini and
frequency later; OSM tags filling gaps). Assign every location by road-graph
drive-time under the dominance rule. Validate and tune the one dimensionless dial
(the 5x dominance factor) against membership joins, with TTWA as the sanity net.
Each signal does the one job it is actually evidence for.

## Known open items

- Conurbation sub-structure and naming (second, denser clustering pass inside
  mega-components).
- The four parameters (5x dominance, 60-minute search, 50-stop/station plausibility,
  floor-5 base) are legible but hand-set; membership-join calibration should confirm
  or move them before anything ships.
- A national dominance table (every cell, not twelve cases) plus its distribution,
  before any product wiring.
- BODS account + termini/frequency ingestion, as the strength refinement.
- The product decision itself: what hub adjacency grants. Not this document's call.

---

## Background: the symmetric wedges analysis (2026-08-22, merged here 2026-10-28)

The implementation shape proposed in that note was superseded by this plan; its problem analysis, the dominance rule and the evidence are kept below.

Status: SUPERSEDED in large part by the adversarial review below (same day). The
problem analysis and the symmetry argument stand; the implementation shape does not.
See "Adversarial review outcome" at the end before acting on anything above it.

### The reported problem

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

### Why the existing machinery cannot fix it

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

### The design argument (Edward's, checked against the code and data)

#### 1. Where the caps came from

Two mechanisms, both dense-motivated. Band caps (20/30/45) from the 887-post conversion
study: dense conversion collapses past 20-25 min (someone closer always takes it), and
the extra reach "costs mail and crossposts". The extent governor (4,000 nearest) is the
explicit anti-swamping control. Sparse 45 is where the MEASUREMENT stopped, not where
value stopped: 30-45 min converted at 20% vs 18% for 0-10, flat to the edge of the data.

#### 2. Swamping is supply x audience at the recipient, and only dense->dense has it

| direction | supply added | recipient baseline | swamping risk |
|---|---|---|---|
| dense -> dense | huge | full | real (the cap's job) |
| rural -> dense | ~nil | full | none |
| dense -> rural | a town's worth | near-empty | none that matters: famine -> normal; digest capped at 65 and ranked |
| rural -> rural | ~nil | near-empty | none |

The cap earns its keep in row one and severs all four.

#### 3. Wedges are topology edits, and adjacency has no direction

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

#### 4. Freegler density vs UK population density (Edward's addition)

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

### What survives of the hard numbers

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

### The resulting model

Isotropic part: how far you see is a function of your surroundings (dense end keyed to
Freegler competition, sparse end to settlement geography). Anisotropic part: a symmetric
starved-area <-> anchor-town adjacency, computed once per starved AREA (not per post),
read from both ends.


### Open questions for Edward

- Starvation threshold: which rung of the ratio ladder (10% catches the truly stranded:
  Hawes/Leyburn/Alnwick; 25% adds Holt/Masham).
- UK density data source for the band/anchor work: towns table alone, ONS LSOA
  population (deprivation CSV precedent exists in the routing server), or road-graph
  node density as the in-memory proxy.
- Whether the inbound lane ships dark, logged-only, or live-by-default (house
  convention is live-by-default; this one changes what members see, so maybe logged
  first).

### Evidence appendix (all measured this session, live data)

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

---

### Adversarial review outcome (2026-08-22, later the same day)

Ten-agent pass: six probe agents over 36 UK places against the live graph and member
index, four adversarial reviewers over this plan and the code, plus direct live-DB
membership queries and a national famine count. Full probe rows and review findings in
the session transcript; the numbers below are the ones that matter.

#### Errors in this plan, found by the review

1. **The membership premise was wrong.** "A Hawes member is a member of the
   Carnforth/Lancaster groups" was inferred from /v1/reachable-groups, which is an
   OUTBOUND targeting signal (which groups have a member drivable from a point), not
   membership. Live DB: the whole upper Wensleydale box holds 2 active members; both
   belong to KENDAL (plus Penrith/Northallerton/Skipton one each), neither to Carnforth
   or Lancaster-Morecambe. The complaint's named groups cannot mail these members at all.
2. **The mail path needed care, and the review's own first reading overstated it.**
   getPostsForUser is indeed membership-scoped before the ring test, but
   ExpandService::rippleIntoNewGroups copies a post onto EVERY published group whose
   area its reach covers, so candidacy spreads with reach. Verified live (Edward's
   challenge, 2026-08-22): of 532 posts through Carnforth/Lancaster in 7 days, 390
   (73%) carry a KendalFreegle copy, and the Wensleydale members are Kendal members.
   The ring test is the only blocker; the digest is reach-driven in effect, and a
   reach-side fix reaches their email with no membership work. Membership binds only
   for a member none of whose groups' areas the post's reach touches.
3. **A sibling investigation of the SAME complaint (2026-08-20, Discourse 10046) was
   not consulted.** It had already measured: the towns table lacks
   Kendal/Lancaster/Carnforth/Penrith/Skipton/Barrow (so this plan's anchor default was
   known-broken before it was written); the Hawes step-change (pre-cap pool 427 at
   45 min, 941 at 50, 1,250 at 55, 2,008 at 60: crossing into a town doubles the
   audience in ~5 minutes); conversion past 45 min under 8.5% per reply; honest yield of
   a bounded rural extension 2-15 extra rehomes/fortnight nationally; and a surviving
   design, the POPULATION FLOOR (grow past 45 until ~1,000 freeglers, bounded +15
   min/60 absolute) with its three known prerequisites (paired admission exception,
   ringsBbox inclusion, digest budget-decay fix). The 314-vs-427 Hawes pool difference
   is metric and origin-point drift (located-in-polygon vs pre-cap total; Nominatim
   postcode point vs post origin), not a contradiction.
4. **Implementation holes**: the reachoverflow lane space is a closed 4-bit enum synced
   across two repos (5 spare codes), not an open reverse index; the inbound lane as
   described had no self-limiting floor/fan-in cap (the outbound lane has both), making
   it a location-gameable amplifier; anchor-town posts usually sit in the capBound
   branch where the cluster lane never runs, so the firing condition cannot be reused;
   Lancaster (62.5 min) is beyond the 60-min ceiling anyway; reuse_reach has no
   staleness guard for adjacency changes; the digest closeness score clamps to 0 for
   every rescued post so ranking cannot order them; the far-away reply warning gates on
   Offer only; and WhichPostsExplanation.vue would misdescribe the model further.

#### What the 36-place probe showed

- **Genuinely starved (pool at cap under ~10% of the 4,000 target), rural**: Fort
  William 4, Newton Stewart 15, Aberystwyth 33, Tregaron 34, Machynlleth 44, Eyemouth
  43, Hawick 52, Berwick 56, Bellingham 57, Moffat 85, Bala 83, Lynton 96, Spilsby 142,
  Camelford 177, Hatherleigh 196, Brecon 229, Reeth 238, Goathland 278, St Johns Chapel
  296, Kirkbymoorside 297, Llanidloes 318. Wales and the Borders are far worse than the
  Pennines. Thurso: pool 0, band unknown; the far Highlands are effectively outside the
  system and no reach mechanism fixes that.
- **False positives a pool-ratio gate would admit**: Hull city centre measures SPARSE,
  cap 45, pool 664 (17%) with 84 live posts in its bbox; Merthyr Tydfil sparse, 747
  (19%), Cardiff 44 min. Content famine and member famine are different things: the
  gate must include live-post supply, not member pool alone.
- **The Penrith circularity**: Penrith measures DENSE, cap 20, and at that cap its pool
  is 802 (20%). A remote market town capped like inner London, made "starved" by its
  own cap. It is simultaneously the post-supply hub of the North Pennines (166 live
  posts in bbox, the largest measured outside Norwich 209). Fixing the band measure
  fixes Penrith; a pool-at-cap gate must be computed at 45 min for everyone or it
  inherits the cap's own distortions.
- **Supply deserts**: Barnard Castle, 18.8 min from Middleton-in-Teesdale, has ZERO
  live posts. Reaching your market town is not enough if the town has nothing; content
  famine is regional in the North Pennines and mid-Wales.
- **The existing outbound wedges fail where need is greatest**: across mid-Wales and
  the Borders the cluster finder returns nothing (no 150-member/km cell exists within
  60 min of Tregaron); where wedges DO fire they point at freegler mass, which
  membership data sometimes vindicates (Bellingham's wedge toward Newcastle matches its
  members' Newcastle-area group joins) and sometimes not (Middleton's wedge points west
  to the Eden valley, away from both its candidate towns).
- **Anchors are plural** (Edward: "towns, not town"): Alston members joined Penrith 12,
  Bishop Auckland 2, Hexham 2; Camelford members joined Wadebridge 19, Launceston 13,
  St Austell 6, Newquay 5. Any hub assignment must return a weighted SET. Membership
  data is the ground truth but carries moved-house noise (Machynlleth members still in
  Watford/Bushey groups): filter by distance.
- **National famine floor** (crow-fly, conservative): 653 active members have fewer
  than 50 others within 15 miles; 1,202 under 100; 3,404 under 250; 9,386 under 500.
  Not a niche of two, not a third of the country.

#### Bus-route hypothesis (Edward's, same day)

Terminus = hub = natural place for out-of-town people to see posts from and have posts
seen in. Verdict: sound as a hub NOMINATOR, wrong as sole assignment (not every road
has buses). Division of labour: bus termini and frequency nominate and weight hubs
(BODS/NaPTAN open data; effectively national coverage at hub level); OSM place=town
tags from the already-loaded pbf fill nomination gaps; the road graph we already hold
generalises assignment to unbused cells (drive-time gravity, top 2-3 hubs per cell);
membership joins validate. The 234-row towns table is not a viable source (verified:
of Penrith/Hexham/Kendal/Lancaster/Carnforth/Wadebridge/Launceston/Northallerton it
contains only Northallerton).

#### Where the evidence now points

The symmetric idea survives; the wedge/anchor implementation does not need to carry
it. The 10046 step-change measurement (427 -> 2,008 in 15 minutes of growth) means
ISOTROPIC growth reaches the hub almost immediately in exactly the places that need
it, so a population floor does the work anchors were for, without an anchor table, a
lane-enum extension, or gameable geometry:

- Post side (10046's surviving design, unchanged): grow a thin post's reach past 45
  until it holds ~1,000 freeglers, bounded +15 min / 60 absolute, with its three known
  prerequisites (admission exception, ringsBbox, budget decay).
- Member side (the symmetric half, new): the member's own admission cap grows past
  their band cap until their catchment holds a target population, bounded at 60.
  Recipient-side, so it fixes Penrith (802 at cap 20 grows) and cannot swamp anyone
  (it only ever grows famine feeds toward a target and stops). Browse is already a
  member-side isochrone query, so this is a change to CapFor, not new geometry.
  Digest reach follows where the member cap is consulted, but daily-digest candidacy
  stays group-scoped: for the specific Carnforth/Lancaster ask the remaining gap is
  MEMBERSHIP (the members are not in those groups), which is a join-suggestion/UX
  question, not a reach question.
- Hub/bus/adjacency work is demoted to telemetry and validation: log what grown
  reach actually gets engaged with, per direction and travel time (the review found
  lane stamping does not exist yet and the current logs cannot answer the past-45
  question), and use hub data to sanity-check growth direction later if needed.
- Counter-evidence to respect: conversion past 45 is under 8.5% per reply, and the
  honest national yield of the post-side extension was 2-15 rehomes/fortnight. The
  member-side case does not rest on conversion (a famine feed with anything in it
  beats an empty one, and WANTEDs cut the other way), but nothing here should ship
  unlogged or unmeasured.

#### Still open (Edward)

- Population-floor targets and bounds for each side (post side ~1,000 was 10046's
  number; member side unset).
- Whether member-side growth gates on member pool, live-post supply, or both (Hull
  says: include supply).
- The membership/UX half of the original complaint (join suggestions for hub groups
  vs auto-membership vs leave as is).
- Sequencing against target_by_ru (the governor currently caps 55.9% of sparse-origin
  posts; that fix may matter more than any of this).

#### Cliff edges (Edward's objection to the population floor, same day)

"Grow until ~1,000 people, capped at 60 minutes" replaces one arbitrary constant with
two and keeps the cliff: correct, and it dies here. The cliff-free formulation:

- The system ALREADY measures a continuous field per location: the radius holding the
  nearest K freeglers (DensityService, K=400). Today it collapses that measurement
  into three bands with three caps (20/30/45), which is where every cliff comes from:
  band boundaries, cap walls, starved/not-starved gates.
- Use the measurement directly instead. Each member gets a personal admission radius
  as a SMOOTH function of their own K-radius: no bands, no caps, no classification.
  Neighbouring villages get near-identical radii, so there is no two-village edge.
  Hawes comes out around an hour, Masham around 40 minutes, London around 12, all
  from one curve.
- Ranking, not walls, bounds the far tail: distance normalised by the same field,
  decayed by MEASURED conversion (the past-45 <8.5%-per-reply figure becomes a decay
  shape, not a cutoff), through the existing digest scorer and the 65-slot attention
  budget. Abundant areas never surface far posts because near ones fill the budget;
  famine areas fill from further away automatically. Self-limiting without a wall.
- Numbers that remain are of a different kind: K (exists, 400), the decay shape
  (measured from logs, not chosen), and the digest's 65-slot cap (an attention
  budget, not geography: it cannot make two neighbours categorically different).
  Walls act as cliffs; normalisers and slopes do not. Any remaining hard bound is a
  compute ceiling in the routing layer, set beyond where ranking has already made
  content invisible, so it is never the binding policy.
- This shrinks the change surface: the member-side fix is ONE function (banded cap ->
  continuous personal radius) plus scorer decay, flowing to browse, digest and push
  through the single ring-admits gate that already exists.

#### Why the K-radius field itself is not a cliff (Edward's challenge, same day)

r_400(x), the distance holding the nearest 400 active members of point x, is
1-Lipschitz: move a mile and it changes by at most a mile (the ball around the old
point, widened by a mile, still holds the same 400). So the field cannot jump between
neighbours; bands can (one step flips 20 to 30). Large K also makes it stable in TIME:
one member joining or leaving moves the 400th-nearest distance by at most the gap to
the 401st, which is tiny. K=1 would twitch; K=400 averages the noise away.

Cliffs can only sneak back in three known ways, all avoidable:
1. Downstream if-statements. Any threshold applied to the field re-creates the chop.
   Discipline: curves only, no category tests, anywhere downstream.
2. Road-snapping artefacts: a point can snap onto the network ~6 min worse than a
   point 0.9 miles away (measured, 2026-08-20 sibling investigation). Implementation
   jumpiness, bounded and smoothable; not policy.
3. The final show/do-not-show of one post to one member is inherently yes/no. Under
   ranking decay the yes/no line sits where the score has already faded to
   irrelevance, and the line's POSITION varies smoothly between neighbours, so no two
   neighbours are treated categorically differently and no retune flips a region.

#### The orphaned-rural-post objection, and the two-ended rule (Edward, same day)

A pure recipient-radius model orphans rural POSTS: every potential viewer of a Hawes
offer is a town member whose own radius is small, so the offer is seen by nobody.
(Today's system has this failure too: the Hawes post's ring stops at 45 min, short of
Kendal centre; the outbound cluster lane was the patch for it.)

Fix: read the SAME field at both ends. With R(x) the smooth personal radius, a post at
x is visible to a member at y when d(x,y) <= max(R(x), R(y)). d is symmetric and max
is symmetric, so visibility becomes a symmetric relation BY CONSTRUCTION: if you can
see me, I can see you. Edward's original symmetry requirement stops being a feature to
build and becomes a property of the rule. Dense-dense pairs stay tight (both radii
small), thin-thin pairs see far (rural-rural was also a severed quadrant), and the
asymmetric pairs work in both directions: the dale member sees the town because their
own R is large, and the town member can find the dale offer because the POST carries
the origin's R outward.

Prominence stays local: ranking normalises distance by the VIEWER's own R, so the
Hawes offer sits low in a full Lancaster feed (findable, not prominent) and high in a
dale feed. Admission symmetric, attention local. Mail volume in town stays bounded
because thin-origin posts are few, which is the famine fact itself. Post-side compute
ceiling follows R(origin) with a flattening curve rather than a wall; the far
Highlands (Fort William r_400 beyond any drivable radius) remain honestly out of scope
for any reach mechanism.

#### Satiation at the town edge (Edward's objection), measured

Objection: a rural person near a busy town collects their 400 at the first suburbs, so
their radius never crosses the town. Measured r_400 (crow-fly, live index):

| point | r_400 | r_100 |
|---|---|---|
| Kendal centre | 2.1 mi | 0.2 mi |
| Old Hutton (hinterland, ~4 mi SE of Kendal) | 4.7 mi | 2.6 mi |
| Lancaster centre | 0.9 mi | 0.3 mi |
| Caton (hinterland, ~4 mi E of Lancaster) | 7.7 mi | 3.6 mi |
| Hawes | 24.9 mi (only 228 exist) | 17.9 mi |

K=400 is large relative to a suburb crescent: the ball has to push deep into the town
to find 400, so the hinterland radii already cross their towns whole (Caton's 7.7 mi
spans all of Lancaster; Old Hutton's 4.7 spans Kendal). The satiation failure is real
for SMALL K (r_100 shows it starting), so K stays large. Two further margins keep the
town whole without cliffs: the viewing radius is a smooth curve ON the field and can
overshoot it (one slope dial, calibratable against revealed catchments: membership
joins, bus/TTWA data as calibration targets, not infrastructure); and concave ranking
decay makes near side vs far side of a town rank nearly equally at range. Conurbation
partiality (Ealing does not see Croydon) emerges from scale rather than needing
settlement polygons.

#### The moderate-sprinkle counterexample (Edward, same day): "always" was wrong

Claim killed: "a starved point's radius always terminates inside its nearest town".
True only when the hinterland holds fewer than K people. Measured counterexamples
(crow-fly r_400 vs crow distance to the towns):

- Bishop's Castle: r_400 13.9 mi; Ludlow 14.5 OUT, Shrewsbury 18.1 OUT.
- Kington: r_400 10.3 mi; Leominster 12.4 OUT, Hereford 16.6 OUT.
- Holt: r_400 20.2 mi; Cromer 9.1 in, Norwich 21.0 OUT by a whisker.
- (Masham: 17.8 mi; Ripon and Northallerton both in. Not all sprinkle fails.)

Three regimes: empty country (count forced into the town: Hawes), near-town
hinterland (town supplies the count: Caton, Old Hutton), and moderate sprinkle
(count self-completes short of the town: the Marches). Today's flat 45-min cap
serves regime three FINE (Shrewsbury 39.7 min from Bishop's Castle), so the raw
field would REGRESS it.

Repairs, cliff-free:
1. The curve on the field is fitted against REVEALED CATCHMENTS (membership joins,
   bus termini, TTWAs) with the constraint that a hinterland member's radius covers
   their demonstrated towns. Regime three is exactly where the constraint binds.
   Edward's two-density argument closes Edward's own counterexample: freegler
   density supplies the smooth machinery, lived geography supplies the target.
2. Deployment monotonicity: constrain the curve to never give anyone less than the
   current system does. The change becomes extension-only from current behaviour.
3. Shelf variant, untested: measure the field in POSTS not people (radius holding a
   month of browsing). Supply concentrates in towns more than people do, which might
   restore town-pull in regime three without calibration; per-capita posting in
   sprinkle country is unmeasured, so this is an experiment, not a claim.

---

### Bus-network validation (Edward's simple approach, 2026-08-22 evening)

Claim tested: from the bus network alone, any GB location is either inside a coherent
centre of population or outside one, and if outside, the nearest centre is
identifiable. Data: NaPTAN (open, no key, 435,367 records, 375,264 active bus stops
with OSGB coordinates and locality names). Map artifact: "Bus-Drawn Britain"
(claude.ai/code/artifact/1c7b4978-629c-4bdf-94d2-1fe9eab6b7ce).

Rule that emerged (two tiers, both from stop geometry alone):
- HUB: 8-connected component of 1km cells with >=4 stops each, component >=30 stops.
  702 nationally; 204 contain bus-station infrastructure (BCS/BCE stop types), which
  is terminus evidence needing no timetable download.
- TOWN: an isolated 1km cell holding >=5 stops that is not hub-adjacent. 3,344
  nationally. The marketplace signature: Hawes has 7-11 stops in ONE cell; a
  strung-out roadside village (Hatherleigh: busiest cell 2) never concentrates.

Validation results:
- All 19 must-be-inside test towns classify inside a hub.
- 9 of 12 tiny market towns surface in the town tier (Hawes, Leyburn, Settle,
  Bishop's Castle, Rhayader, Camelford, Machynlleth, Lampeter, Presteigne); Sedbergh,
  Bala and Kirkby Stephen miss by one stop in a cell (threshold sensitivity, not
  structure).
- Outside->nearest matches lived catchments across the hinterland cases when computed
  BY ROAD: Old Hutton->Kendal, Caton->Lancaster 20 min, Reeth->Richmond 19,
  Masham->Ripon, Goathland->Whitby, Middleton->Barnard Castle, Alston->Penrith/Hexham
  (membership votes Penrith 12-2). Crow distance fails in pinched terrain
  (Hawes->Catterick over the fells); the road graph we already run is the fix.
- Catchments come out PLURAL naturally: the road-nearest top-k list is the answer
  (Machynlleth: Tywyn AND Aberystwyth), matching "towns, not town".

Defects found (five-reviewer regional sweep + case testing):
- Conurbations merge into belt-blobs and the modal-locality naming is wrong on them
  ("Aylesbury" = the South-East, "Paisley" = Glasgow, "Mayfield" = Edinburgh,
  "Aberavon" = Port Talbot). Inside/outside geometry fine; naming needs substructure
  (a higher-density second pass inside mega-components would split them into cities).
- Small-end false hubs: stop-dense ex-colliery villages (Tow Law, Butterknowle) rank
  as centres and can win nearest-centre (St John's Chapel->Tow Law 29 min). Prune by
  requiring station infrastructure or a minimum town-tier corroboration.
- A lat/lng averaging bug zeroed Carlisle's coordinates (missing per-stop lat/lng
  rows); fixed same day. NaPTAN locality names have spelling drift (Boness).
- Hebridean crofting townships classify as centres; arguably correct locally.

Verdict: the claim substantially HOLDS, with roads (not crow) doing the nearest
assignment, and it is far simpler than the continuous-field design: two integer
thresholds, one open dataset, and the road graph already in production. What it does
not by itself give: reach policy (how far a member sees). It gives the natural-town
structure that policy needs (which centre is yours, whether you are in one), i.e. the
calibration/anchor layer the earlier sections kept reaching for. BODS timetable data
(free account) would add frequency-weighted termini as the hub-strength refinement.

#### The dominance rule, finalised (Edward's, tested twelve cases)

"Is there a place within an hour's drive with a significantly larger number of stops?
If so, that's your hub." Formalised: hub(x) = nearest-by-road centre with stops >= 5x
your own settlement's total, searched within 60 road-minutes, among plausible hubs
(>= 50 stops or bus-station infrastructure). Terminal = nothing dominates you.

Twelve-case verdict (live road graph, NaPTAN centres):
- Exact: Old Hutton->Kendal 13m, Caton->Lancaster 20m, Reeth->Richmond 19m,
  Alston->Penrith 34m (membership 12-2), Kington->Hereford 35m,
  Machynlleth->Aberystwyth 39m, Penrith->Carlisle 32m, Kendal->TERMINAL,
  St Johns Chapel->Barnard Castle 35m (Tow Law correctly pruned by the
  plausibility floor).
- Defensible: Hawes->Catterick/Richmond 42m nearest-dominating with Kendal 183 as
  biggest-in-hour (the two outputs bracket the dale's real plural catchment: down-dale
  towns and the market town over the top); Bishops Castle->Ludlow 32m (its TTWA);
  Richmond->the Darlington-area component (geometry right, name is the known
  conurbation-labelling artefact).

Fixes applied en route: base = the whole settlement's stops, not its densest cell;
candidate hubs must be plausible (>= 50 stops or a station), which kills the
ex-colliery false hubs. Remaining parameters are four and each has a physical
meaning: K=5 dominance, 60-minute search, 50-stop/station plausibility, floor-5
open-country base. The nearest-dominating and biggest-in-hour outputs together give
the plural catchment ("towns, not town") without further machinery.

Open design decision: what adjacency GRANTS. Candidate semantics, one sentence:
you always see your surroundings (as today) plus your hub towns' posts; your posts
are seen by your surroundings plus your hub towns' members. One edge deep (immediate
hubs only; the chain to Carlisle stays unused until measured). Digest included via
the ring-admits gate, or it is a no-op for the members it serves. The adversarial
review's implementation cautions above (lane storage, fan-in caps, firing branch,
help copy, WANTED warning, scorer decay for rescued posts) all still bind.

