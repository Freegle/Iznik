---
last_reviewed: 2026-08-14
covers:
  - iznik-routing-go/graph.go
  - iznik-routing-go/dijkstra.go
  - iznik-routing-go/cmd/calibrate/**
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
reach in that time on foot, by bike, or by car — following real roads and paths,
not just a circle on a map. (That reachable-area shape is called an
**isochrone**.)

This is what lets Freegle think in terms of "20 minutes away" instead of "in the
same group", which is much closer to how people actually decide whether to go and
collect something.

It also has a **fairness** setting. Members in more deprived areas are less likely
to have a car, so the mapper can stretch the reachable area for them — a small,
deliberate thumb on the scale so the service works for people who rely on walking
and public transport, not just drivers.

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

The calibration tooling lives in `iznik-routing-go/cmd/calibrate` and can be
re-run if road conditions or OpenStreetMap data shift materially.

## How this adds up: "rippling out"

Putting those two together lets Freegle introduce a post to people gradually —
starting with those closest and rippling outwards over time, so a post gets seen
by enough people to find a home without spamming everyone at once. The full
thinking behind that is written up in
[the rippling algorithm reference](rippling-algorithm.md).

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
