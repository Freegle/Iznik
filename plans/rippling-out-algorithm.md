# The Rippling Out Algorithm

This is the canonical reference for how Freegle notifies members about new
posts.  The first part is in plain language and assumes no familiarity
with the system.  The technical sections after avoid jargon where they can
and define terms as they go.

---

There are really two distinct problems here:

1. **Which posts are theoretically available to a member?**  This is
   about geography, group boundaries and cross-posting.
2. **Which of those available posts should a member actually see?**
   This is about selection, volume and not overwhelming people.

Today's Freegle blurs the two — a member belongs to a group, all the
group's posts go into the digest, end of story.  The new algorithm
needs both questions answered separately and explicitly.

# Problem 1 — which posts are theoretically available

## What's wrong today

Freegle posts belong to a single group, defined by a polygon on a map.
A member living right at the edge of one group's polygon is
geographically much closer to many of the *neighbouring* group's posts
than to most of their own group's.  Today they don't see those
neighbouring posts at all unless a volunteer manually cross-posts the
item — a slow, ad-hoc process.  Members near group boundaries are
systematically disadvantaged: items they could realistically collect
remain invisible to them.

There's also the converse: each group's polygon was drawn without
reference to roads.  A motorway, river or lack of a bridge can mean
that someone inside the same group polygon is actually a much harder
drive than someone in a neighbouring group.  Geography doesn't follow
group boundaries.

## What we're doing about it

When a post arrives, we draw a **drive-time reach** around the post
location: anywhere a car could reach within roughly thirty real-world
minutes.  The reach is computed from a map of the UK and Northern
Ireland road network, using speed limits adjusted to approximate
typical real-world driving.  We don't have a live traffic feed and
don't need one — we're choosing who to email, not navigating.

Anyone whose home location falls inside that drive-time reach is
**reachable** for this post, regardless of which Freegle group they
belong to.  In effect this means the algorithm **cross-posts
immediately** — a post in Camden will appear in the reachable pool of
neighbouring Westminster, Islington and Hackney members, with no
volunteer intervention.

This is a big change from current behaviour and volunteers will need to
adjust.  The evidence for doing it is strong: members near group
boundaries are disadvantaged today and we have the spatial data to fix
that.  Volunteers are no longer the bottleneck for cross-group
visibility; the algorithm is the gatekeeper.

## Spreading reach over time, not all at once

Even within Problem 1, we don't just dump the whole reachable pool with
the email at once.  We **ripple out** over the first day after the
post arrives:

1. **The big initial wave.**  As soon as the post arrives, the closest
   70 % of the reachable pool is added to the list.
2. **A gentle ripple.**  The remaining 30 % — the people further away —
   are added in small batches every 48 minutes or so across the next
   24 hours.
3. **Stop on success.**  As soon as the item is claimed, promised or
   withdrawn, the trailing ripple is cancelled.

"Closest" means closest *by drive time*, not straight-line distance.

Analysis of twenty thousand historical posts shows that this shape
catches the eventual claimant before they reply 93 % of the time in
urban areas and 87 % rural.  Why we pick this particular 70/30 / 1-day
shape is covered in the technical sections.

# Problem 2 — which posts should a specific member actually see

## What's wrong today

Today is simple: every post in your group goes into your digest (or
fires an immediate email if you're on immediate mode).  That works
when your only source of posts is your own group.  Once we cross-post
across drive-time reach, simple-and-exhaustive doesn't scale.

A central London member is on average a member of 4.5 Freegle groups,
which between them produce ~43 posts a day.  The drive-time reach
around them covers ~10 groups producing ~200-270 posts a day.

If we just hand them everything in their reach, a digest user goes from
43 posts a day in their digest to ~250.  Nobody reads a digest with
250 posts in it.

## A design problem in its own right

We don't know yet what the right selection rule is.  We do know what
shape it has to take:

- **The volume can't be allowed to balloon.**  As a starting principle,
  a member shouldn't see more than ~20 % more posts than they're used
  to seeing today.  If today's digest is 43 posts, tomorrow's
  cross-post-aware digest should be no more than ~50.
- **When there are more available posts than the budget allows, we
  pick the most relevant ones.**  Plausible signals: how close the post
  is to the member, how many people have already viewed/claimed it,
  whether it's in a category they've responded to before, how long it's
  been live.
- **For the long tail, offer a discovery link, not a dump.**  A digest
  that ends "and there are 200 other posts within your reach today —
  see them all" lets the curious member explore without burying the
  shortlist.
- **The website needs to do the same thing differently.**  On the web
  the member is actively browsing, so we can show a sensible top-N
  with "show more" available, instead of dumping everything.

We will need real data to drive these choices.  Once unified digests
land we can measure **how far down a digest a member actually reads** —
open rates per item position, click-through depth, scroll telemetry if
available — and tune the cap and the selection from there.

For now the algorithm produces the *pool* of reachable posts.  Problem
2 — the selection mechanism that turns that pool into a member's
actual digest or notification stream — is a separate piece of design
work, blocked on Freegle's unified-digest project landing.

# What we built (in brief)

For Problem 1:

- We loaded the UK and Northern Ireland road network from
  OpenStreetMap, giving us every road with its speed limit.
- We wrote a service that runs a standard route-finding algorithm
  over that map to figure out where a car could drive in N minutes
  from any starting point.
- It produces the rippling-out schedule for any post in about a fifth
  of a second.  Everything runs on Freegle's own servers; no calls to
  external services like Google Maps.
- A preview tool inside ModTools lets moderators see exactly what the
  algorithm will do for any location, with an animated map.

For Problem 2 (selection):

- Nothing yet.  The pool produced by Problem 1 is the input; the
  selection algorithm hasn't been designed.

# Problems and mitigations

## On Problem 1 (geographic reach)

| Problem | Mitigation |
|---------|------------|
| OpenStreetMap gives us speed *limits*, not real driving speeds.  Junctions, urban congestion and traffic make real journeys 30-50 % slower.  Without adjustment, our "30-minute reach" would actually correspond to ~45 real-world minutes — much further than people would realistically travel for a free item. | A simple global slowdown factor (0.7×) is applied to all drive speeds at server start.  Set as a configurable environment variable so it can be tuned later if better data becomes available. |
| Cross-posting immediately is a behaviour change.  Volunteers are used to cross-posting being deliberate. | Documented explicitly (this document).  The evidence — members near group boundaries currently disadvantaged — is strong; we don't see a reasonable alternative. |
| Northern Ireland has no equivalent of the speed-data products available for Great Britain, so we can't refine per-link congestion UK-wide. | The 0.7× factor is uniform across UK + NI.  Future work could fold richer GB data in while leaving NI on the simple factor. |

## On Problem 2 (selection)

| Problem | Mitigation |
|---------|------------|
| Pool size can be 5-6× a member's current digest content in a dense city. | Selection algorithm with a ~20 %-over-current-volume budget; tuned once unified digests give us reading-behaviour telemetry.  Not yet designed. |
| Most members (about 84 %) are on daily digest; the new algorithm could either dump cross-group items into separate emails or fold them into the digest. | Folding into the digest is the natural option, possibly with the digest delivered earlier than its usual time if a high-relevance post arrives.  See "digest pull-forward" below. |
| Pulling a digest forward more than once a day would spam the user. | Hard rule: at most one digest per user per day. |

## Digest pull-forward (idea, not implemented)

For digest-mode users, instead of sending cross-group ripple items as
separate emails, we could trigger their daily digest early when a
high-relevance post lands — including that post and anything else
fresh.  Daily limit of one digest per user means we can do this at
most once per day.  This effectively becomes the selection mechanism
for digest users: the digest is the cap.

Open questions:

- Which post in a busy day "wins" the right to pull a user's digest
  forward.
- Whether pulling a digest forward at, say, 11 am means a noticeably
  emptier digest than the user is used to.
- How the simulator should model this — currently it assumes the new
  algorithm replaces the legacy notifier wholesale; the digest pull-
  forward variant sits alongside the existing digest infrastructure.

---

# Technical detail

The sections below are deeper but try to define every term as they
use it.

## Terminology used below

- **Reachable pool** — every Freegle member whose home location is
  inside the post's 30-minute drive-time reach.  Anywhere from a few
  hundred members in rural areas to tens of thousands in a city.
- **Rank** — a member's position in the reachable pool when sorted by
  drive time.  Rank 1 = closest; rank N = furthest still inside the
  reach.
- **Tick / batch** — one firing of the scheduler.  Each tick emails (or
  selects for emailing) a group of members whose rank falls in some
  range.  We use 30 ticks over 24 hours, so a tick fires every 48
  minutes.
- **Lifetime** — how long the schedule runs from when the post arrived.
  Currently 1 day.
- **Curve** — the rule for how many members are added in each tick.

## Why the "70 % up front / 30 % over a day" shape

We tested a wide range of shapes against historical data:

| Curve name we used | What it actually means in plain terms |
|--------------------|----------------------------------------|
| linear             | Equal-sized batches in each tick.       |
| back-loaded        | Tiny batches at first, ramping up — most members reached late. |
| front-loaded (gentle, medium, heavy) | Most members reached early; trailing batches get smaller. |
| **step-70**        | A single big batch up front (70 %) and then equal small batches across the rest of the day for the remaining 30 %.  *This is what we use.* |
| step-50            | Same idea but only 50 % in the first batch. |

For each candidate we replayed every historical post through a
simulator (described below) and measured three things:

- **Catch rate** — among historical replies, what fraction would our
  schedule have notified the replier *before* they actually replied?
- **Waste** — what fraction of the schedule's emails would fire after
  the post had already been claimed / taken / withdrawn?
- **Lead time** — how long ahead of their reply did the schedule reach
  them?  Shorter is better — "just in time" beats "a week early."

Step-70 won on all three.  Numbers below.

## Why 1 day and not longer or shorter

We tested lifetimes from 12 hours up to 30 days:

- Catch rate barely moves with lifetime (93.7 % at 12 hours, 92.0 % at
  1 day, 91 % at 30 days).  The big-up-front batch catches almost
  everyone who'd reply regardless of how long the trailing ripple is.
- Waste grows roughly in step with lifetime.  At 30 days, 17 % of
  emails would fire after the post is already gone; at 1 day, ~3 %.

So 1 day gives essentially the same catch rate as a half-day lifetime
with low waste, and a much more sensible scheduler cadence than
firing batches a few minutes apart.

## Drive-time accuracy

OpenStreetMap gives us speed *limits*, not real driving speeds.  Two
spot checks against more sophisticated routers for ~30-mile UK
journeys:

| Route                      | Our raw routing | Google Maps (with traffic) |
|----------------------------|----------------:|---------------------------:|
| Oxford OX3 8GH → Highclere | 30 min          | 53 min                     |
| Newcastle NE1 → Alnwick    | 30 min          | 45 min                     |

After applying the 0.7× global slowdown factor, both routes come out
40–45 min — close enough to Google for the purpose of "approximately
who is reachable."

OpenStreetMap actually tags a lot of the data we'd need for proper
junction modelling (traffic lights, give-way signs, roundabouts);
extracting that and applying per-junction penalties is more work than
the single global slowdown buys us, so we've left it for later.

## How the simulator works

The simulator answers a counterfactual: "if we'd been running the new
algorithm at the time of this historical post, would the eventual
replier have received our email before they actually replied?"

Its only inputs are *facts about real users*: where the replier lived
at the time, when they replied, where the post was.  None of that
depends on which algorithm was running at the time, so the simulator's
answers stay valid even as we change the algorithm.

Three layers of evaluation:

1. **Now** — simulator only.  We can sweep curve shapes and lifetimes
   cheaply.  This is where the step-70 / 1-day choice came from.
2. **At rollout** — parallel-run.  Not yet done.  Both the old
   notifier and the new algorithm would notify in production, with
   each email tagged by source.  Reply attribution then tells us
   whether the new algorithm actually drives more / faster claims.
3. **In steady state** — continuous monitoring.  Also not yet wired
   up.  A weekly job would re-run the simulator over the latest week
   of data and write the headline numbers to a metrics table so we can
   spot drift over time.

A note on the "waste" number the simulator reports.  It counts every
email the schedule would fire *at a tick whose wall-clock time is
after the recorded outcome of the post*.  In production we expect this
number to be much smaller — the scheduler checks the post's status
before firing each tick, so most of those potential emails are simply
cancelled.  The metric is useful for comparing curves against each
other but is an upper bound on what would happen in practice.

## Empirical results

Full-sample run: 20,977 historical posts, 48,671 recorded replies,
with the 0.7× drive-time factor.  Restricted to urban posts and to
members who use immediate notifications (the cleanest cohort to
measure on, because their reply time isn't distorted by digest
delivery):

| Curve                | Catch rate | Waste (upper bound) | Lead time median | p75    | p90    |
|----------------------|-----------:|--------------------:|-----------------:|-------:|-------:|
| linear               | 51 %       | 8.8 %               | 7 h              | 30 h   | 258 h  |
| front-loaded mild    | 67 %       | 4.4 %               | 5 h              | 21 h   | 185 h  |
| front-loaded heavy   | 84 %       | 3.8 %               | 4 h              | 16 h   | 140 h  |
| **step-70**          | **92 %**   | **2.5 %**           | **3 h**          | **14 h** | **98 h** |

Lead time = "how far ahead of their actual reply we'd have emailed
them."  Lower = more just-in-time.  Daily-digest users' lead times
look much longer because they include digest wait; those aren't in
this table.

## Mail-volume estimate in central London

Sample from the live database:

- Typical central London member is in **4.5 Freegle groups**
- Those groups produce **43 posts/day** in total
- The drive-time reach around the member covers **~10 groups**
  producing **150-200 posts/day**

What that means depends on the member's notification setting and
which of the two-problem decisions we make:

| Member's setting              | Today                            | Under Problem-2 selection capped at +20 % | Under no selection (problem) |
|-------------------------------|----------------------------------|-------------------------------------------|------------------------------|
| Daily digest (today: per-group) | 43 posts in 4-5 digest emails  | ~52 posts in 1 digest (with unified-digest project) | 150-200 posts in 1 digest (nobody reads it) |
| Immediate                     | ~43 emails/day                   | ~52 emails/day                            | 150-200 emails/day (untenable) |

The "no selection" column is what we get if Problem 2 isn't addressed:
roughly **3.5-5× more material in front of each member**.  Problem 2's
job is to bring that down to the +20 % column.

## Operational characteristics

- Routing service start time: ~3 min cold (loading the UK road graph
  into memory).
- Memory footprint at idle: ~4.5 GB.
- Schedule generation: ~200 ms per post.  100 parallel post lookups:
  ~0.6 s total.
- One UK-wide road-graph refresh per month is sufficient.

## Where the code lives

- Routing service: `iznik-routing-go/`
- Drive-time edge speeds and global factor:
  `iznik-routing-go/graph.go`
- Schedule endpoint: `iznik-routing-go/ripple.go`
- Simulator: `iznik-routing-go/cmd/ripplesim/main.go`
- Historical extractor: `iznik-routing-go/cmd/rippleextract/main.go`
- ModTools preview:
  `iznik-nuxt3/modtools/components/RipplingExplorer.vue`
- Monitoring scaffold (not yet scheduled):
  `2026_05_27_000001_create_ripple_algorithm_metrics_table.php`
  and `MonitorAlgorithmCommand.php` under `iznik-batch/`.
