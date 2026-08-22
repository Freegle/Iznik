# Symmetric wedges: let starved areas see their market towns

Status: SUPERSEDED in large part by the adversarial review below (same day). The
problem analysis and the symmetry argument stand; the implementation shape does not.
See "Adversarial review outcome" at the end before acting on anything above it.

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

---

## Adversarial review outcome (2026-08-22, later the same day)

Ten-agent pass: six probe agents over 36 UK places against the live graph and member
index, four adversarial reviewers over this plan and the code, plus direct live-DB
membership queries and a national famine count. Full probe rows and review findings in
the session transcript; the numbers below are the ones that matter.

### Errors in this plan, found by the review

1. **The membership premise was wrong.** "A Hawes member is a member of the
   Carnforth/Lancaster groups" was inferred from /v1/reachable-groups, which is an
   OUTBOUND targeting signal (which groups have a member drivable from a point), not
   membership. Live DB: the whole upper Wensleydale box holds 2 active members; both
   belong to KENDAL (plus Penrith/Northallerton/Skipton one each), neither to Carnforth
   or Lancaster-Morecambe. The complaint's named groups cannot mail these members at all.
2. **The mail path claim was wrong.** UnifiedDigestService::getPostsForUser scopes
   digest candidates to the member's OWN memberships (whereIn messages_groups.groupid)
   BEFORE any ring test; rings only relax rejection within that set. "The digest follows
   the site automatically" is false for any post on a group the member has not joined.
   (The newly-reached push path, mailNewlyReachedForPost, DOES reach outside the capped
   polygon: verified 2026-08-20, 35% of notified members outside it, so overflow lanes
   do feed one mail surface. But the daily digest is group-scoped first.)
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

### What the 36-place probe showed

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

### Bus-route hypothesis (Edward's, same day)

Terminus = hub = natural place for out-of-town people to see posts from and have posts
seen in. Verdict: sound as a hub NOMINATOR, wrong as sole assignment (not every road
has buses). Division of labour: bus termini and frequency nominate and weight hubs
(BODS/NaPTAN open data; effectively national coverage at hub level); OSM place=town
tags from the already-loaded pbf fill nomination gaps; the road graph we already hold
generalises assignment to unbused cells (drive-time gravity, top 2-3 hubs per cell);
membership joins validate. The 234-row towns table is not a viable source (verified:
of Penrith/Hexham/Kendal/Lancaster/Carnforth/Wadebridge/Launceston/Northallerton it
contains only Northallerton).

### Where the evidence now points

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

### Still open (Edward)

- Population-floor targets and bounds for each side (post side ~1,000 was 10046's
  number; member side unset).
- Whether member-side growth gates on member pool, live-post supply, or both (Hull
  says: include supply).
- The membership/UX half of the original complaint (join suggestions for hub groups
  vs auto-membership vs leave as is).
- Sequencing against target_by_ru (the governor currently caps 55.9% of sparse-origin
  posts; that fix may matter more than any of this).
