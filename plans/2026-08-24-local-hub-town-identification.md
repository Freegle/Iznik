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
