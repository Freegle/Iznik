# The Rippling Out Algorithm

This is the canonical reference for how Freegle notifies members about new
posts.  The first part is in plain language and assumes no familiarity with
the system.  The technical sections after also avoid jargon where they can
and define terms as they go.

---

## The problem

When somebody posts an offer on Freegle (say, an unwanted bookcase), the
post needs to find a home.  We have to tell nearby members it exists.
But "nearby" is harder than it sounds:

- **Bombarding everyone at once is bad.**  If we email five thousand
  people the instant a bookcase appears, the fastest replier wins and
  everyone else gets a "sorry, taken" message.  That's wasted attention
  and a poor experience.
- **Telling only the closest people is also bad.**  Some items are
  niche; the right recipient might be ten miles away.  If we never reach
  them, the item goes to landfill.
- **Freegle's groups are mapped as polygons.**  Real travel patterns
  (motorways, rivers, lack of bridges) mean "two miles away" doesn't
  always mean "easy to collect."
- **Members have different preferences.**  Some want a per-post email
  the moment something appears (immediate mode).  Most prefer a daily
  digest summarising what's new.

The Rippling Out algorithm aims to balance "reach the right person
quickly" with "don't dump every message on every member."

## Who counts as "reachable"

Before we can notify anyone, we need to define the pool of people we
*could* notify for a given post.  We do this by drawing a **drive-time
reach** around the post: anywhere a car could get to within roughly
thirty real-world minutes.

The reach is computed from a map of the UK and Northern Ireland road
network.  It's based on speed limits adjusted to approximate typical
real-world driving (we don't have a live traffic feed and don't need
one — we're choosing who to email, not navigating).  Where exactly the
boundary falls is approximate; for the purpose of picking notification
recipients that's fine.

Anyone whose home location falls inside that reach is **reachable** for
this post.  Sorted by drive time, the closest people are at the centre
of the reachable pool; the furthest are right at the edge.

## The solution at a high level

When a post arrives we send notifications to the reachable pool in waves
across the first day:

1. **The big initial wave.**  As soon as the post arrives, we email the
   closest 70 % of the reachable pool — the people for whom collection
   is most plausible.
2. **A gentle ripple.**  The remaining 30 % — the people further away —
   are emailed in small batches every 48 minutes or so across the next
   24 hours.
3. **Stop on success.**  As soon as the item is claimed, promised, or
   withdrawn, the trailing ripple is cancelled.  Anybody who was due to
   be emailed in a later batch never hears about it.

"Closest" means *closest by drive time*, not straight-line distance.  A
member ten road-minutes away across a river is "further" than one
fifteen minutes away on the same side.

Analysing twenty thousand historical posts shows that this shape catches
the eventual claimant before they reply 93 % of the time in urban areas
and 87 % rural.

## What we built (in brief)

- We loaded the UK and Northern Ireland road network from
  OpenStreetMap.  This gives us every road with its speed limit.
- We wrote a service that runs a standard route-finding algorithm over
  that map to figure out where a car could drive in N minutes from any
  starting point.
- The service produces the notification schedule for any post in about
  a fifth of a second.  Everything runs on Freegle's own servers; no
  calls to external services like Google Maps.
- There's a tool inside ModTools that previews the algorithm's
  behaviour for any location, with an animated map.

## Problems and mitigations

| Problem | Mitigation |
|---------|-----------|
| OpenStreetMap gives us speed *limits*, not real driving speeds.  Junctions, urban congestion and traffic make real journeys 30-50 % slower.  Without adjustment, our "30-minute reach" would actually mean ~45 real-world minutes — much further than people would realistically travel for a free item. | A simple global slowdown factor (0.7×) is applied to all drive speeds at server start.  This brings drive times into line with other open-source route planners.  Set as a configurable environment variable so it can be tuned later if better data becomes available. |
| In dense cities, the 30-minute reach overlaps roughly ten Freegle groups.  A central London member who's signed up to four groups today would see notifications about posts in groups they don't belong to — about 4.5× more material than they currently see. | **Home-group only for the first wave.**  The initial big email goes only to people who are members of the post's own Freegle group.  Cross-group neighbours are reached gradually by the trailing ripple, capped at a few emails per user per day. |
| Most members (about 84 %) get notifications as a daily digest, so the apparent "time between notification and reply" for them is dominated by when their digest is delivered, not by our algorithm.  Mixing the two cohorts hides what the algorithm itself is doing. | All measurement and analysis separates the two cohorts and reports lead-time figures for immediate-mode users only. |
| Northern Ireland has no equivalent of the speed-data products available for Great Britain, so we can't refine per-link congestion UK-wide. | The 0.7× factor is uniform across UK + NI.  Future work could fold richer GB data in while leaving NI on the simple factor. |
| Geographic group boundaries don't line up with drive-time reach.  Historically only 1.3 % of replies came from members outside the post's home group, because the legacy notifier rarely reached them.  We change that; it needs care. | The home-group-first-wave mitigation above plus a per-user daily cap on cross-group notifications.  Watched closely once parallel-run evaluation (see below) is live. |

## An option still to investigate: pulling forward daily digests

Most members are on the daily-digest setting.  Their digest arrives at
a fixed time of day with a summary of new posts in their groups.  By
default that means up to 24 hours can pass before a digest member sees
a new post.

For an item that's likely to be picked up fast, that 24-hour gap means
we wait for the digest user even though they might have responded
within minutes.

The option: when we'd really like a digest user to know about a post,
**we trigger their daily digest early**.  Sending a daily digest twice
in one day would be spammy, so we keep a hard rule of *at most one
digest per user per day*.  But if the daily digest hasn't yet fired
today, pulling it forward is fine and reaches them much faster.

This sits alongside the rippling.  The trailing ripple still emails
immediate-mode users in small batches; for the digest cohort we instead
pull their digest forward (when policy allows), which contains the new
post along with anything else fresh.  Effectively the schedule becomes
"ripple out for immediate users; advance the digest for digest users."

What needs to be worked out:
- Which post in a busy day "wins" the right to pull a user's digest
  forward (since they only get one per day).  Probably the one with the
  highest reach-in-time benefit.
- Whether pulling a digest forward at, say, 11 am sends an emptier
  digest than the user is used to — and whether they care.
- How the simulator should model this — currently it assumes the new
  algorithm replaces the legacy notifier wholesale, but in this version
  the new algorithm sits alongside the existing digest infrastructure
  and triggers it earlier rather than replaces it.

A first cut at the data: of the 4.5× cross-group reach-expansion that
the new algorithm gives us in central London, ~84 % is digest users.
Pulling forward their digest is the natural way to capitalise on the
extra reach without inflating their inbox.

---

## Detailed technical sections

These avoid jargon where they can and define every term where they
can't.

### Terminology used below

- **Reachable pool**: every Freegle member whose home location is
  inside the post's 30-minute drive-time reach.  Today that's anywhere
  from a few hundred members in rural areas to tens of thousands in a
  city.
- **Rank**: a member's position in the reachable pool when it's sorted
  by drive time.  Rank 1 = closest; rank N = furthest still inside the
  reach.
- **Tick / batch**: one firing of the scheduler.  Each tick emails a
  group of people whose rank falls in some range.  We use 30 ticks
  spread across 24 hours, so a tick fires every 48 minutes.
- **Lifetime**: how long the schedule runs from when the post arrived.
  Currently 1 day.
- **Curve**: the rule for how many people are emailed in each tick.
  See next section.

### Why we picked the "70 % first / 30 % over a day" shape

We tested a wide range of shapes:

| Curve name we used | What it actually means in plain terms |
|--------------------|---------------------------------------|
| linear             | Equal-sized batches in each tick (1/30 of the pool per tick). |
| back-loaded        | Tiny batches at first, then ramping up — most emails sent late in the day. |
| front-loaded (gentle, medium, heavy) | Mirror image of back-loaded: most emails sent early; trailing batches get smaller. |
| **step-70**        | A single big batch up front (70 % of the pool) and then equal small batches across the rest of the day for the remaining 30 %.  *This is what we use.* |
| step-50            | Same idea but only 50 % in the initial batch. |

The numerical names like "x^0.3" and "√x" describe the mathematical
shape of the curve; they're not meaningful outside that comparison.
What matters in practice is the family they belong to: step-shaped
curves with most-up-front beat smooth curves; back-loaded curves are
worst on every measure.

For each candidate we ran every historical post through a simulator
(see below) and looked at three things:

- **Catch rate**: among historical replies, what fraction would our
  schedule have notified the replier *before* they actually replied?
- **Waste**: what fraction of the schedule's emails would fire after
  the post had already been claimed / taken / withdrawn?
- **Lead time**: how long ahead of their reply did the schedule email
  them?  Shorter is better — "just in time" beats "a week early."

Step-70 won on all three.  The detailed numbers are in the empirical
results section below.

### Why 1 day and not longer or shorter

We tested lifetimes from 12 hours up to 30 days.  Two findings:

- Catch rate barely moves with lifetime (93.7 % at 12 hours, 92.0 % at
  1 day, 91 % at 30 days).  This is because the big-up-front batch
  catches almost everyone who'd reply regardless of how long the
  trailing ripple is.
- Waste grows roughly in step with lifetime — at 30 days, 17 % of
  emails go out after the post is already gone; at 1 day, ~3 %.

So 1 day gives essentially the same catch rate as a half-day lifetime
with the same low waste, and a much more sensible cadence than a few-
minute-apart batches.

### Drive-time accuracy

OpenStreetMap data gives us *speed limits*, not real driving speeds.
A free-flow car going as fast as the speed limit permits would in
practice be slowed down by junctions, traffic lights, congestion and
roundabouts.  Two spot checks against more sophisticated routers for
30-mile UK journeys:

| Route                      | Our raw routing | Google Maps (with traffic) |
|----------------------------|----------------:|---------------------------:|
| Oxford OX3 8GH → Highclere | 30 min          | 53 min                     |
| Newcastle NE1 → Alnwick    | 30 min          | 45 min                     |

After applying our 0.7× global slowdown factor, both routes come out
40–45 min — close enough to Google's figures for the purpose of
"approximately who is reachable."

OpenStreetMap actually tags a lot of the data we'd need for proper
junction modelling (traffic lights, give-way signs, roundabouts), but
extracting that and applying per-junction penalties is more work than
the single global slowdown buys us — at least for now.

### How the simulator works (and why we trust it)

The simulator answers a counterfactual: "if we'd been running the new
algorithm at the time of this historical post, would the eventual
replier have received our email before they actually replied?"

What it needs as input is purely *factual* about real users — where
each replier lived at the time, when they replied, where the post
was.  None of that depends on which algorithm was running at the time
the data was gathered, so the simulator's answers stay valid even as
we change the algorithm.

There are three layers of evaluation:

- **Now (simulator only)**: we can sweep curve shapes and lifetimes
  cheaply.  This is where the step-70 / 1-day choice came from.
- **At rollout (parallel-run)**: not yet done.  Both old and new
  systems would notify in production, with each email tagged by source.
  Reply attribution then tells us whether the new system actually
  drives more / faster claims than the old one.
- **In steady state (continuous monitoring)**: also not yet wired up.
  A weekly job would re-run the simulator over the latest week of data
  and write the headline numbers to a metrics table so we can spot
  drift over time.

A note on "waste" reported by the simulator.  It counts every email
the schedule would fire *at a tick whose wall-clock time is after the
recorded outcome of the post*.  In production we expect this number
to be much smaller — the scheduler checks the post's status before
firing each tick, so most of those potential emails are simply
cancelled.  The metric is meaningful for comparing curve shapes against
each other but is an upper bound on what would actually happen.

### Empirical results

Full-sample run: 20,977 historical posts, 48,671 (post, replier) pairs
(every recorded reply across all those posts), with the 0.7× drive-time
factor.  Restricted to urban posts and to members who use immediate
notifications (the cleanest cohort to measure on, because their reply
time isn't distorted by digest delivery):

| Curve              | Catch rate | "Waste %" | Lead time, median | Lead time, p75 | Lead time, p90 |
|--------------------|-----------:|----------:|------------------:|---------------:|---------------:|
| linear             | 51 %       | 8.8 %     | 7 h               | 30 h           | 258 h          |
| front-loaded (mild) | 67 %      | 4.4 %     | 5 h               | 21 h           | 185 h          |
| front-loaded (heavy) | 84 %     | 3.8 %     | 4 h               | 16 h           | 140 h          |
| **step-70**        | **92 %**   | **2.5 %** | **3 h**           | **14 h**       | **98 h**       |

Lead time is "how far ahead of their actual reply we'd have emailed
them" — lower is better.  Daily-digest users' lead times look much
longer because they include their digest wait; those numbers are not
in this table.

### Mail-volume impact in central London

A typical central London member belongs to **4.5 Freegle groups** on
average.  Those groups collectively produce **43 posts/day**.

Today's mail flow for that member depends on whether they use
immediate or digest notifications:

- **Digest mode (default)**: today they receive *one digest email per
  group*, so 4-5 emails per day, each containing 9-10 posts.  When
  Freegle moves to a unified daily digest, that collapses to **1 email
  per day** with all 43 posts in it.
- **Immediate mode**: they receive an email per post in their groups.
  ~43 emails per day.

The new algorithm's 30-minute reach covers ~10 Freegle groups around
them.  Posts/day across those wider 10 groups: ~150-200 (vs the 43
inside their own 4-5).

| Member's setting          | Today (per-group digests)         | Under unified digest + step-70 | Notes |
|---------------------------|------------------------------------|--------------------------------|-------|
| Digest, no ripple changes | 43 posts in 4-5 digest emails      | 43 posts in 1 digest email     | Same content, fewer emails. |
| Digest, step-70 trailing ripple adds cross-group items as separate ripple emails | 43 posts in 4-5 digests + 1/week cross-group nearby email (legacy) | 43 posts in 1 digest + up to N cross-group ripple emails/day | Where N is the per-user daily cap. |
| Digest, step-70 + digest pull-forward (see option below) | 43 posts in 4-5 digests | up to 200 posts in 1 digest (pulled forward as soon as we want) | Same email count as unified-digest, much more content per digest. |
| Immediate, no ripple changes | ~43 emails/day                   | ~43 emails/day                 | No change. |
| Immediate, step-70 first wave home-group-only + trailing ripple cross-group | ~43 emails/day + 0–1/week cross | ~43 emails/day + up to N cross-group ripple emails/day | N capped to keep load tolerable. |
| Immediate, step-70 unthrottled cross-group | — | ~150-200 emails/day | This is the variant we don't want. |

The numbers above assume the home-group-first-wave mitigation is in
place.  Without it, even immediate-mode users without a cap would see
the ~150-200/day figure for their *first* batch alone.

For digest-mode users the practical question is whether step-70
introduces additional cross-group ripple emails (which would be on top
of their daily digest), or whether we instead pull their digest
forward early and include the cross-group items inside it — that's the
investigation in "An option still to investigate" above.

### Operational characteristics

- Routing service start time: ~3 min cold (loading the UK road graph
  into memory).
- Memory footprint at idle: ~4.5 GB.
- Schedule generation: ~200 ms per post (one route search + a
  per-tick polygon trace).  100 parallel post lookups: ~0.6 s total.
- One UK-wide road-graph refresh per month is sufficient.

### Where the code lives

- Routing service: `iznik-routing-go/`
- Drive-time edge speeds and global factor:
  `iznik-routing-go/graph.go`
- Schedule endpoint: `iznik-routing-go/ripple.go`
- Simulator: `iznik-routing-go/cmd/ripplesim/main.go`
- Historical extractor: `iznik-routing-go/cmd/rippleextract/main.go`
- ModTools preview:
  `iznik-nuxt3/modtools/components/RipplingExplorer.vue`
- Monitoring scaffold (not yet scheduled): migration
  `2026_05_27_000001_create_ripple_algorithm_metrics_table.php` and
  command `MonitorAlgorithmCommand.php` under `iznik-batch/`.
