---
last_reviewed: 2026-09-04
covers:
  - iznik-routing-go/graph.go
  - iznik-routing-go/dijkstra.go
  - iznik-routing-go/cmd/calibrate/**
  - iznik-routing-go/cmd/placesextract/**
  - iznik-routing-go/reach_overlay.go
  - iznik-routing-go/reach_partition.go
  - iznik-routing-go/reach_query.go
  - iznik-routing-go/reach_server.go
  - iznik-routing-go/reach_labels_export.go
  - iznik-routing-go/reach_labels_apply.go
  - iznik-spatial-go/places.go
  - iznik-spatial-go/places_search.go
  - iznik-spatial-go/places_api.go
---

# Freegle's spatial servers — a plain-English overview

*This page is for everyone — volunteers, moderators, staff. It explains, in
ordinary language, what Freegle's two "spatial" servers do and why they exist.
You don't need to know anything about code to read it. There are links to the
technical documentation at the bottom for developers.*

---

## The one-sentence version

Freegle has two small background services that answer geography questions —
*"what's near here?"* and *"how far can someone realistically travel?"* — so the
site can show each member the posts that are genuinely relevant to **them**,
wherever they live.

## Why we need them

When someone posts an item, Freegle has to decide who to tell about it. The
honest old answer was roughly "everyone in the same group". That works, but it
has two rough edges:

- **People miss things just over a boundary.** A great sofa three streets away
  might be in the *next* group, so you never hear about it — even though it's
  closer than half the things in your own group.
- **People get buried in things too far away.** If a group covers a big area, you
  can be shown items that are technically "in your group" but a 40-minute drive
  away, which isn't much use without a car.

The spatial servers let Freegle answer the better questions: *who could actually
get to this item?* and *what's genuinely close to this person?* — rather than
just *who is in the same group?*

## The two services, in plain terms

Think of them as two helpers that the rest of Freegle asks questions of.

### 1. The "finder" — what's near a point

Give it a spot on the map and it instantly tells you the nearest Freegle things
to it: the nearest members, posts, groups, jobs, and place names. It keeps a
constantly-updated mental map of where everything is, so these lookups are fast
even though Freegle has millions of locations.

It's used for everyday things like turning a member's postcode into a place, and
for answering "which freeglers are inside this area?".

### 2. The "travel-time mapper" — how far can you reach

Give it a spot and a number of minutes and it draws the area you could actually
drive in that time — following real roads, not just a circle on a map. (That
reachable-area shape is called an **isochrone**.)

It used to answer for walking and cycling too, and worked all three out on every
single call. Nothing asked for them: rippling is a drive-time model, the Rippling
Explorer only ever requests drive, and the API that fetches an isochrone reads
only the drive answer. Carrying them cost two extra searches per call and, more
expensively, a third of the road network in memory — footpaths, steps and
cycleways that no car can use. The mapper is drive-only now.

This is what lets Freegle think in terms of "20 minutes away" instead of "in the
same group", which is much closer to how people actually decide whether to go and
collect something.

It also has a **fairness** setting. Members in more deprived areas are less likely
to have a car, so the mapper can stretch the reachable area for them — a small,
deliberate thumb on the scale so the service works for people who rely on walking
and public transport, not just drivers. (That stretch is applied to the drive-time
budget; it is not a walking route.)

### How accurate are the travel times?

The travel-time mapper doesn't just divide distance by a speed limit. Its
drive-time model was calibrated in August 2026 against roughly 2,500 real
journeys from the Google Routes API, sampled across the whole UK — cities,
London, ordinary towns, sparse rural areas, and awkward estuary crossings.

The model prices the two things that actually consume driving time separately:

- **link speed** — how fast traffic really flows on each class of road (a rural
  A-road really does run near the national limit; a 20 mph zone really doesn't),
  and
- **stopping** — a few seconds for every traffic signal, junction, pedestrian
  crossing and roundabout the route passes through, plus a fixed
  minute-ish of "setting off" overhead per trip.

On journeys the calibration was never shown, half of all estimates are now
within about 7% of Google's answer, and the typical error halved compared with
the previous model. Two honest caveats remain: we deliberately don't route cars
over toll crossings (nobody pays the Mersey tunnel toll to collect a free
toaster, so estimates near those crossings assume the long way round), and we
don't model ferries, so islands are treated as unreachable by road.

The model also knows three British road facts that generic speed tables miss:
the national speed limit is 60&nbsp;mph on ordinary two-way roads and only
70&nbsp;mph on dual carriageways; single-track roads with passing places (the
Highlands, the islands, much of rural Wales) are driven at about 28&nbsp;mph
whatever the signs say; and unpaved lanes are slower still.

The calibration tooling lives in `iznik-routing-go/cmd/calibrate` and can be
re-run if road conditions or OpenStreetMap data shift materially.

## How this adds up: "rippling out"

Putting those two together lets Freegle introduce a post to people gradually —
starting with those closest and rippling outwards over time, so a post gets seen
by enough people to find a home without spamming everyone at once. The full
thinking behind that is written up in
[the rippling algorithm reference](rippling-algorithm.md).

## The reach engine (new)

The newest part of the travel-time mapper changes *how* a post's reach is
worked out. Instead of re-drawing the reachable area from scratch every time a
post's reach grows — a full road search each time — the road network is
prepared once: runs of road with no junctions are folded together, and the
country is cut into a few hundred regions along its naturally narrow places
(the computer discovers for itself that only 8 roads connect across the
Severn, 6 across the Forth). A post's reach then becomes a small note per
region — fully reached, partly reached (with arrival times at the region's
entrances), or not reached — and "is this member's address within reach?" is
answered exactly from those notes in microseconds, including the case the old
drawn shape got wrong: the far bank of an unbridged river is *out*, because
you cannot drive there.

Each region's internal distances — "from this region's entrances, how long to
every road inside it?" — used to be worked out the first time anyone asked
about that region, which made the very first question about a cold corner of
the country noticeably slower than the second. Those tables are now computed
for the whole country when the artifacts are built (`leaftables.snap`,
built automatically in the background the first time a server starts without
it, or explicitly with `reach leaftables`) and memory-mapped, so the answer
speed is the same everywhere, first question included, without the server
holding the whole file in memory.

### What the artifacts cost, and why they are small

The mapper holds the whole road network in memory, so the shape of that data is
the difference between a server that fits and one that does not. Every number in
the artifacts is stored at the precision that is actually used and no more: travel
times to a tenth of a second, road distances to the metre, and one array rather
than two wherever two facts can never both apply to the same road junction. Roads
no car can use are not stored at all.

Together those took the artifact set on the test extract from 17.7MB to 9.1MB, a
48.8% cut, with the same reduction expected on the real one. The travel times
themselves are unchanged: the search still adds up in full precision, and the
engine is still checked against a plain, slow, exhaustive search for agreement to
within a hundredth of a second.

Each artifact records which build it came from. The region layout is not
deterministic — building it twice from the same road network gives two different
but equally valid layouts — so a file left over from an earlier build has to be
detected rather than assumed compatible, and is rebuilt instead of being read
against a layout it never matched.

### The artifacts and the stored notes are one versioned pair

Every post's per-region notes are stored in the database (`reach_labels`), and
they only mean anything against the region layout they were written for. Load a
different layout and every stored note silently answers "not reached" — on
2026-09-03 that emptied every member's nearby feed at once. So the server treats
the two as a pair, and three things enforce it (`reach_server.go`):

- **The pairing record.** `config.reach_partition_fp` holds the layout
  fingerprint the stored notes were built against. At boot the server compares
  its own fingerprint with it and **refuses to serve** a layout that disagrees
  (`reachPublish`) — a loud 503 rather than a quiet wrong answer, which the
  deploy gate stops on. No record means no guard.
- **"Unavailable" is never permanent.** A boot that cannot load its artifacts
  used to answer 503 for the life of the process; it now keeps retrying in the
  background (30 s, doubling to 10 min). The retry never rebuilds — an
  unattended rebuild is exactly what renumbers the regions.
- **Changing layout without a gap.** Notes for the *next* layout are computed
  offline (`reach labels-export`, minutes for the whole country) and staged
  beside the live ones (`reach labels-apply`, into `reach_labels_next` stamped
  with their fingerprint; region rows for both layouts coexist in
  `rippling_reach_leaves` because its key includes the fingerprint). Every
  reader — the server's own row loader, and the batch paths that hand a note to
  it — picks the staged note **only when its stamp is the live fingerprint**, so
  the cutover is the pairing record changing and the artifacts swapping: every
  post switches together, and nothing is rewritten to get there. Posts keep
  arriving while the notes are staged, so the switch is preceded by a top-up:
  re-export, then `labels-apply --skip-staged`, which reads the already-staged
  set once and passes those posts without touching the database, so the top-up
  takes seconds and the switch can follow at once. A post initialised in the
  remaining gap carries a note for the old layout; clearing its note makes it
  new again to the online path, which re-labels it against the live layout.

`/health` reports `reach_partition_fp` so an operator can see every node serving
the same layout as the notes.

The road network also fixed a small unfairness in privacy blurring: locations
shown to other members are deliberately made approximate, and the old circular
blur could accidentally move a point across a river it has no bridge over —
which matters now that distances are road distances. Blurring is road-aware
now: the approximate point is chosen along the roads, so it always stays on
its own side of the water, and if the travel-time mapper is unavailable the
old blur is used automatically.

The full plain-English walkthrough, with the measurements and the
multi-million-check verification against both a plain road search and
production's stored answers, is in
[`iznik-routing-go/REACH-ENGINE.md`](../../../iznik-routing-go/REACH-ENGINE.md).

## Looking up place names (the geocoder)

When a member types a town into the place box, or the jobs feed says a
vacancy is in "Kendal", something has to turn that name into a spot on the
map. That used to be a separate third-party program (Photon, with its own
search database — two large Java services on the production host). It is now
part of the finder.

How it works: every time the map data for the travel-time mapper is
refreshed, a small extraction tool (`cmd/placesextract` in
`iznik-routing-go`) reads the same map file and writes out every named UK
place — cities, towns, villages, hamlets, suburbs, counties and regions,
about 200,000 in all — with its position, its bounding box where it has one,
and which county and nation it sits in. The finder loads that file into
memory (a second or so, a couple of hundred MB) and answers name searches
from it directly: exact names first, then "starts with" for as-you-type
searching, then a little typo tolerance. Searches can be limited to a box on
the map, to certain kinds of place, or nudged towards the map's current
centre, because that is what the website's search boxes ask for.

The answers come out in exactly the format the old geocoder used, so nothing
that asks the question — the member site's place search, the maps, the jobs
feed import — needed to change. Instances without the places file (the
database servers run a copy of the finder too) simply say "not available" if
asked.

Regenerating the file after a map refresh:

```
docker exec freegle-spatial go run ./cmd/placesextract \
    -pbf /data/uk-latest.osm.pbf -out - | gzip > iznik-routing-go/data/places.jsonl.gz
```

(With `-out -` the tool streams plain JSONL to stdout — the container's data
folder is mounted read-only, so the compressed file is written host-side; a
direct `-out something.gz` compresses itself.) The finder notices the changed
file within a minute and reloads it without a restart, and will not replace a
working index with a truncated or unreadable file.

## What it does **not** do

- It doesn't decide whether a post is allowed — moderation is unchanged.
- It doesn't store anyone's exact home address; member locations are kept
  approximate on purpose.
- It doesn't send any emails or notifications itself; it just answers the
  geography questions that other parts of Freegle ask.

---

## For developers

The two services are separate Go programs in this repository:

| Folder | Role | Technical docs |
|--------|------|----------------|
| [`iznik-spatial-go`](../../../iznik-spatial-go/README.md) | The "finder" — nearest-neighbour / within-area index over six datasets | [`iznik-spatial-go/README.md`](../../../iznik-spatial-go/README.md) |
| [`iznik-routing-go`](../../../iznik-routing-go/README.md) | The "travel-time mapper" — isochrones, fairness, nearby-freeglers, the Rippling Explorer | [`iznik-routing-go/README.md`](../../../iznik-routing-go/README.md) |

Each service publishes a live, browsable API reference (OpenAPI / Redoc) at
`/swagger` when running — e.g. `http://spatial-knn.localhost/swagger` and
`http://spatial.localhost/swagger` in local dev.

Design background for the rippling side is in
[the rippling algorithm reference](rippling-algorithm.md).
