# The Rippling Out Algorithm

This is the canonical reference for how Freegle notifies members about new
posts.  The first part is in plain language and assumes no familiarity with
the system.  Technical detail follows after.

---

## The problem

When somebody posts an offer on Freegle (say, an unwanted bookcase), the
post needs to find a home.  We have to tell nearby members it exists.  But
"nearby" is harder than it sounds:

- **Bombarding everyone at once is bad.**  If we email five thousand
  people the instant a bookcase appears, the fastest replier wins and
  everyone else gets a "sorry, taken" message.  That's wasted attention
  and a poor experience.
- **Telling only the closest people is also bad.**  Some items are
  niche; the right recipient might be ten miles away.  If we never reach
  them, the item goes to landfill.
- **Each Freegle group is just a polygon on a map.**  Real travel
  patterns (motorways, rivers, lack of bridges) mean that "two miles
  away" doesn't always mean "easy to collect."
- **Members have different preferences.**  Some want a per-post email
  the moment something appears (immediate mode).  Most prefer a daily
  digest summarising what's new.

The Rippling Out algorithm aims to balance "reach the right person
quickly" with "don't dump every message on every member."

## The solution at a high level

When a post arrives, we send the notifications out in waves over the first
day:

1. **The big initial wave** — to roughly the closest 70 % of people we'd
   ever notify.  These are the people most likely to pick up the item.
2. **A gentle ripple** — the remaining 30 % spread evenly across the next
   24 hours, in small batches every 48 minutes.  These are members
   further away who might still be interested.
3. **Stop on success** — as soon as the item is claimed, promised, or
   withdrawn, the trailing ripple stops.  Nobody is told about an item
   that's already gone.

"Closest" means *closest by drive time*, not straight-line distance.  A
member ten road-minutes away across a river is "further" than one fifteen
minutes away on the same side.

After analysing twenty thousand historical posts, this shape (70 % first
wave, 30 % over 24 hours) catches the eventual claimant in time 93 % of the
time in urban areas and 87 % rural — while sending only 2-3 % of mails
after the post has already been taken.

## The technology

- **OpenStreetMap road graph** for the UK and Northern Ireland (~57 M
  nodes, ~117 M edges).  Loaded once at server start.
- **Custom Go service** (`iznik-routing-go`) running Dijkstra over that
  graph to compute drive-time isochrones (the area reachable from any
  point within N minutes).
- **HTTP endpoint** `GET /v1/ripple-schedule` returns the full per-tick
  schedule for any post location — drive-time radius and cumulative user
  count at each tick.  One call per post.
- **Modtools visualiser**: `/rippling` in ModTools shows a moderator
  exactly what the algorithm will do for a given location, with a smooth
  animation of the expanding boundary.

There is no machine learning, no per-user model, and no external API
call.  All routing is local; all notification decisions are deterministic
given the inputs.

## Problems and mitigations

| Problem | Mitigation |
|---------|-----------|
| OSM road graph uses speed *limits*; real-world driving (junctions, urban congestion, traffic) is 30-50 % slower.  Our 30-min isochrone would otherwise reach 28 miles when the realistic drive is 45-50 min. | Apply a 0.7× global speed factor (env var `ROUTING_DRIVE_SPEED_FACTOR`, default 0.7).  Brings drive times into line with OpenRouteService and similar mature routers. |
| In dense cities, our 30-min reach covers ~10 Freegle groups.  A central London immediate-mode member would receive ~270 emails/day vs ~60 today (≈ 4.5×). | **Home-group-only first wave**: tick 1 notifies only members of the post's home group.  Trailing ripple adds cross-group reach gradually, subject to a per-user daily cap. |
| Most replies come from members on daily digest (84 %); only 7.5 % are on immediate.  Daily-digest delivery happens up to 24 hours after our notification fires, so apparent "lead time" looks artificially huge unless we separate the cohorts. | Measurement code separates the two and reports lead times for immediate users only.  For digest users, lead time is whatever their digest schedule dictates — independent of our algorithm. |
| Northern Ireland has no equivalent of OS's "Average Speeds" dataset, so per-link congestion refinement isn't possible UK-wide. | Accept the 0.7-factor compromise across UK + NI.  Future work could fold in OS NGD data for GB only. |
| Geographic group boundaries don't line up with drive-time isochrones, so historical "cross-group" notifications are rare (1.3 %).  Step-70 would change that by reaching ~10 groups in some cities. | Tune via the home-group-first-wave mitigation above; revisit once Layer-2 (parallel-run with legacy) data is in. |

---

## Detailed technical sections

### Schedule shape: step-70 + linear

Tick 1 (immediate) sends to the closest 70 %; tick 2 brings the cumulative
to ~71 %, tick 3 to ~72 %, …, tick N to 100 %.  With N = 30 and a 1-day
lifetime, ticks fire every 48 minutes.

Validated against 20,977 historical posts (10,005 reachable (post, replier)
pairs), step-70 dominates linear, x², √x, x^0.3 and several other shapes
on both "did we reach the first replier in time?" and "what fraction of
notifications were wasted (sent after the post was taken)?".

### Lifetime: 1 day

Lifetime sensitivity sweep showed step-70's first-replier reach rate is
robust across lifetimes (90.9 % at 30 days through 93.7 % at 12 hours)
but the wasted-notification rate scales linearly with lifetime (17 % at
30 days, 2.6 % at 1 day, 1.5 % at 12 hours).

1 day is the sweet spot: only 0.4 pp behind the 12-hour curve on catch
rate, with a sensible cron cadence (48 min/tick) and 2.6 % waste.

### Drive-time accuracy

OSM speed-limit-derived free-flow times are systematically optimistic.
Spot checks against the public OSRM demo and Google Maps for two
~30-mile UK routes:

| Route                       | Ours raw OSM | OSRM (no traffic) | Google (with traffic) | Ours × 0.7 |
|-----------------------------|-------------:|------------------:|----------------------:|-----------:|
| Oxford OX3 8GH → Highclere  | 30 min       | 40 min            | 53 min                | 40–45 min  |
| Newcastle NE1 → Alnwick     | 30 min       | 40 min            | 45 min                | 40–45 min  |

OSM tags present in the UK PBF that mature routers use but we currently
ignore:

| Tag                            | UK PBF coverage |
|--------------------------------|----------------:|
| `junction=roundabout`          | 65,530 ways (comprehensive) |
| node `highway=traffic_signals` | 88,750 (comprehensive)      |
| node `highway=give_way`        | 153,195 (comprehensive)     |
| node `highway=stop`            | 31,341                      |
| turn-restriction relations     | 40,648 (mostly main roads)  |
| `lanes=*`                      | 7.8 % (too patchy)          |
| `traffic_calming=*`            | 0.1 % (essentially absent)  |

The junction and roundabout coverage is good enough for a future
junction-penalty implementation.  Until then, the 0.7 factor approximates
the same effect uniformly across the network.

### Evaluation pipeline

The simulator (`iznik-routing-go/cmd/ripplesim`) takes a one-off extract
of historical (post, replier) records (`iznik-routing-go/cmd/rippleextract`)
and answers, for any candidate curve / lifetime / max-min combination:
"would the new algorithm have reached this historical replier before they
actually replied?"

The crucial property is that the simulator's inputs (where replied, when
replied) are *facts about users*, independent of any algorithm.  So even
after we change the algorithm, the same simulator can keep producing
valid numbers from new historical data.

Three layers of evaluation:

1. **Layer 1 — simulation against history.**  Runs in seconds against a
   cached evaluation set; lets us sweep curves cheaply.  Reach-in-time
   for step-70: **83 % overall, 92 % urban + immediate** at 1-day
   lifetime / 30 ticks / 30 max-min with the 0.7 speed factor.
2. **Layer 2 — parallel-run with legacy in production.**  Not yet wired
   up.  Both algorithms would notify, with tagging on the notification
   record so we can attribute replies.  This is the only way to answer
   "is the new algorithm *better* than legacy" rather than "would it
   have caught the same people."
3. **Layer 3 — continuous monitoring.**  Schema in place
   (`ripple_algorithm_metrics` migration); weekly Laravel command
   (`ripple:monitor`) shells out to the extractor + simulator and writes
   trend data.  Not yet scheduled.

### Empirical results

Full-sample run (20,977 posts, 48,671 (post, replier) pairs, factor=0.7).
Urban, first-replier, immediate-setting users:

| Curve              | 1st caught | Waste % | p50 lead | p75 lead | p90 lead |
|--------------------|-----------:|--------:|---------:|---------:|---------:|
| linear             | 51.1 %     | 8.8 %   | 7.3 h    | 30.0 h   | 257.8 h  |
| front-cubic        | 67.5 %     | 4.4 %   | 5.1 h    | 21.2 h   | 185.0 h  |
| front-heavy x^0.3  | 83.6 %     | 3.8 %   | 3.8 h    | 15.7 h   | 140.0 h  |
| **step-70**        | **92.0 %** | **2.5 %** | **3.3 h** | **13.9 h** | **97.8 h** |

"Lead time" = how long before their reply we'd have emailed them.  Lower
is "more just in time."

### Mail volume in central London

A reference user at Trafalgar Square is a member of ~4 Freegle groups
(the average for the area).  Those four groups produce ~60 posts/day.
Step-70's 30-min isochrone (factor = 0.7) reaches ~270 posts/day, drawn
from ~10 Freegle groups.

| Member setting        | Posts seen / day | Emails / day  | vs legacy                   |
|-----------------------|-----------------:|--------------:|-----------------------------|
| Daily digest, legacy  | ~60              | 1 (digest)    | 1×                          |
| Daily digest, step-70 | ~270             | 1 (digest)    | 1× emails / 4.5× content    |
| Immediate, legacy     | ~60              | ~60           | 1×                          |
| Immediate, step-70    | ~270             | ~270          | **~4.5×**                   |

The 4.5× multiplier is entirely driven by cross-group reach.  Home-group
only first wave (above) neutralises this for daily-digest users and
roughly halves it for immediate users.

### Operational characteristics

- Routing service start time: ~3 min cold (OSM PBF load into memory).
- Memory footprint at idle: ~4.5 GB RSS.
- Schedule generation: ~200 ms per post (one Dijkstra + N polygon
  traces).  100 parallel post lookups: ~0.6 s total.
- One UK-wide PBF refresh per month is sufficient for the road graph.

### Where the code lives

- Routing service: `iznik-routing-go/`
- Drive-time edge speeds: `iznik-routing-go/graph.go` (`highwaySpeed`,
  `driveSpeedFactor`)
- Schedule endpoint: `iznik-routing-go/ripple.go`
  (`handleRippleSchedule`)
- Simulator: `iznik-routing-go/cmd/ripplesim/main.go`
- Historical extractor: `iznik-routing-go/cmd/rippleextract/main.go`
- ModTools visualiser:
  `iznik-nuxt3/modtools/components/RipplingExplorer.vue`
- Monitoring scaffold (not yet scheduled): migration
  `2026_05_27_000001_create_ripple_algorithm_metrics_table.php` and
  command `MonitorAlgorithmCommand.php` under `iznik-batch/`.
