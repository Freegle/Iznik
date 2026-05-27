# The Rippling Out Algorithm

This is the canonical reference for how Freegle notifies members about
new posts.  It's written so somebody with no knowledge of the codebase
can read it cover to cover and end up with a clear picture.  A
glossary of terms sits at the start of the technical detail section
further down; the layperson sections above it explain things in
plain words as they come up.

# Why change anything?

Freegle today works well in many ways.  Tens of thousands of items
change hands every week, a network of volunteer moderators keeps
things running, and most members get a perfectly reasonable
experience.  So before going any further it's worth being explicit:
*the things this document calls "wrong" with the current system are
not wrong from the perspective of the people running Freegle today*.
Volunteers and staff have grown up with the current setup, know how
to work around its quirks, and don't necessarily see them as
problems.

The issues are mostly invisible from inside the organisation but
real from the perspective of members.  And crucially, members
themselves may not even articulate them as problems either — they
don't know how Freegle's internals work.  They just know what posts
they get shown and what posts they don't.  Somebody who never sees
a great offer posted three streets away in the next-door group
doesn't know they missed it; they just continue to think Freegle is
"a bit quiet round here."  Somebody whose local group is buried in
auto-cross-posts they don't care about doesn't know what's making
that happen; they just unsubscribe.

So this work is fundamentally about making the system better for
members, with the understanding that the changes will look bigger
to volunteers and staff than to members themselves — and that some
of those changes may take getting used to even though they're
improvements.

## The three problems

Read carefully, there are really three distinct things Freegle has to
decide every time a post arrives:

1. **Which members *could* this post be relevant to?**  This is a
   question of geography and group boundaries: who lives close
   enough that they might want to collect it, regardless of which
   Freegle group they happen to have signed up to.  We call this
   *Problem 1* below.

2. **Of those members it could be relevant to, which ones should
   actually be shown the post?**  This is a question of volume,
   prioritisation and not overwhelming people.  Showing every
   eligible post to every eligible member would produce digests
   nobody reads.  We call this *Problem 2*.

3. **What about posts that didn't get collected the first time
   round?**  Autoreposting clearly helps — when we repost an
   unclaimed item, a good number of them do find a taker that they
   didn't find the first time round — but it also annoys members
   who *did* notice the post and aren't interested, and who now
   have to keep skipping it.  Today's autorepost mechanism is
   *group-centric* (the post gets re-broadcast to everyone in the
   group again); the right model is *member-centric* (re-show to
   members who haven't yet had a chance, leave alone those who
   already did and weren't interested).  We call this *Problem 3*.

Today's Freegle blurs these three together.  The whole picture is "a
member belongs to a group, all the group's posts go into their
digest, end of story."  That works adequately when each member's
only source of posts is their single home group, but it has two
specific failure modes:

- **Members near group boundaries miss out.**  Geography doesn't
  follow group polygons.  A member living a five-minute drive from
  a post in the neighbouring group probably can't see that post,
  while a member fifteen minutes away in the same group as the
  post will.  This is most acute around physical features (rivers,
  motorways, the Bristol Channel) where the natural catchment
  doesn't match the polygon at all.

- **Members get cross-posts they don't want.**  When cross-posting
  *does* happen, it's either a deliberate choice by the poster
  (rare) or a blanket rule (TrashNothing, which auto-cross-posts a
  lot).  In neither case is there any check about whether the post
  is realistically collectable by people in the receiving group.
  Local moderators end up dealing with complaints of the form
  "this post isn't relevant to our group."

Both of these have the same root cause: cross-posting today is
driven by *who's signed up where* and *what someone happened to
tick*, not by whether members could realistically collect the item.
The new design tackles Problem 1 first by replacing "tick boxes
and group membership" with a drive-time isochrone (more on this
below), then Problem 2 by deciding which of the eligible posts a
given member actually sees, and Problem 3 by moving autorepost from
a group-wide re-broadcast to a per-member "have they had a chance
yet?" decision.

# Problem 1 — which posts are theoretically available

## What's wrong today

Freegle posts belong to a single group, defined by a polygon on a map.
The polygons aren't drawn arbitrarily — they were originally chosen
with geography in mind (rough catchment areas, sensible council /
postcode boundaries), so today's setup already does *some* of the
work.  But mapping technology has moved on a lot since those polygons
were drawn, and we can now do considerably better than "a single
fixed polygon per group."

Cross-posting to neighbouring groups *is* possible today, but it's
manual and user-driven: a member can choose to cross-post when they
make an offer, and most don't bother — even when it would help them
find a recipient.  TrashNothing posts cross-post automatically through
the feed.  Net result:

- Members living near a group boundary often can't see items they'd
  realistically collect, because the user who posted didn't tick the
  cross-post box.
- The cross-posts that *do* arrive are unpopular with locals, because
  they're chosen by the poster (or by TrashNothing's blanket rules)
  rather than by anything the local members care about.  Common
  complaint: "this post shouldn't be in our group, the people here
  aren't interested in it."

Both complaints share a root cause — cross-posting today is driven by
group membership and human choice rather than by whether a member could
actually collect the item.  A motorway, river or lack of a bridge can
mean somebody inside the same group polygon as the post is actually a
much harder drive than someone in a neighbouring group.  Group
polygons gesture at geography, but they can't keep up with it the way
a real isochrone over the road network can.

## What we're doing about it

When a post arrives, we draw a **drive-time reach** around the post
location: anywhere a car could reach within roughly thirty real-world
minutes.  The reach is computed from a map of the UK and Northern
Ireland road network, using speed limits adjusted to approximate
typical real-world driving.  We don't have a live traffic feed and
don't need one — we're choosing who to email, not navigating.

Anyone whose home location falls inside that drive-time reach is
**eligible to see** this post, regardless of which Freegle group they
belong to.

> *"Eligible to see" is not the same as "will see."* It just means
> the member is in the candidate set for this post.  Whether the post
> actually lands in their digest, and where in the digest, is the
> separate selection problem covered in Problem 2.  Most members
> eligible to see a given post won't actually have it shown to them.

In effect, eligibility becomes the **automatic cross-posting rule**
— a Camden post is eligible to be shown to nearby Westminster,
Islington and Hackney members.  Unlike today's user-tickbox
cross-posts, the rule is "would this member realistically collect the
item?" rather than "did the poster happen to tick the right box?".
That directly addresses both of today's cross-posting complaints:

- Boundary members are no longer excluded from items they'd
  realistically collect just because the poster didn't think to
  cross-post.
- Members upstream don't end up eligible for cross-posts from outside
  their reasonable collection radius, because items they can't
  realistically collect aren't in the reach to begin with.

### What this looks like to moderators

The main visible change for moderators is in their approval queue.
Today, a moderator sees the posts originating in their own group.
Under the new rule they will additionally see, in their approval
queue, posts originating in neighbouring groups that are eligible to
reach some of their own group's members.  That's a real volume
increase on the moderator side — worth flagging clearly because it's
easy to mis-read it as "every cross-eligible post will hit every one
of my members," which is *not* what's happening: eligibility is a
candidate set, not a guarantee of delivery, and the selection in
Problem 2 decides which eligible posts each individual member
actually sees.

## Spreading reach over time, not all at once

Even within Problem 1, we don't just notify the whole reachable pool at
once.  We **ripple out** over the first day after the post arrives.
The shape below — the 70/30 split and the one-day window — wasn't
guessed: we picked it by replaying about ten thousand historical
posts through a simulator and trying many different shapes.  This one
won on every metric we cared about; the technical sections explain
the comparison.

1. **The big initial wave.**  As soon as the post arrives, the closest
   70% of the reachable pool is added to the notification list.
2. **A gentle ripple.**  The remaining 30% — the people further away —
   are added in small batches every 48 minutes or so across the next
   24 hours (30 batches, "ticks", over the day).
3. **Stop on success.**  As soon as the item is claimed, promised or
   withdrawn, the trailing ripple is cancelled and no more emails fire.

"Closest" means closest *by drive time*, not straight-line distance.

On that same historical sample (members on immediate notification
mode, so their reply time isn't distorted by digest delivery) this
shape **catches the eventual claimant before they reply 80% of the
time in urban areas and 65% rural**.

# Problem 2 — which posts should a specific member actually see

## What's wrong today

Even before Problem 1 is touched, Problem 2 already exists.  Today's
digest behaviour is "every post in your group goes into your digest"
(or fires an immediate email if you're on immediate mode).  That
sounds reasonable until you notice that a Freegle group's polygon is
often quite large — a whole town, a London borough, several rural
parishes — and where you happen to live inside that polygon matters
enormously for whether any given post is actually useful to you.

A member who lives in the north of a group sees posts from the south
of the same group all the time, even when those posts are an hour's
drive away with a river or motorway in between.  They're "in the
same group" but not realistically collectable, so they appear in the
member's digest but contribute only noise.  The result is that
whether your digest feels relevant to you depends mostly on which
corner of your group you happen to live in — and members on the
edges have the worst experience.  This is *the* main problem with
how digests work today, not something the new algorithm is creating.

Problem 1 (the wider drive-time reach replacing fixed polygons) makes
the picture quantitatively larger but doesn't change the underlying
question: out of all the posts theoretically available to a member,
which ones should they actually see in their digest?  The numbers
just get bigger:

- A central London member is on average a member of 4.5 Freegle groups,
  which between them produce ~43 posts a day in today's per-group
  digests — many of which are already irrelevant for the reason
  above.
- The drive-time reach around the same member covers ~10 groups;
  measuring directly at four central London locations across seven
  days, that reach contains around **144 posts per day** on average
  (range 77–239 depending on location and day).

If we just hand them everything in their reach, a digest user would
go from 43 posts a day to ~144 — roughly 3.5× more material.  Nobody
reads a digest with that many items.  And in practice we're pretty
sure most people don't scroll all the way through a long digest
even today — the experience already collapses to "whatever happens
to be near the top is what gets seen."  That makes the *order* of
posts in the digest at least as important as the *contents*: a
relevant post buried at position 80 is, for most members, the same
as a post that wasn't shown at all.  The irrelevance issue was
already present at 43 posts a day; Problem 2 is about fixing it
properly rather than making it disappear by having a smaller
candidate set.

## A design problem in its own right

This section records the direction we're taking and the questions
still open.  The simulator described later makes it cheap to try
different selection rules against real data, so we expect the
specific weights and ordering to evolve as we learn.

### The reply distribution reshapes the problem

A snapshot of ~57 000 posts (30 days):

| Replies | Share of posts |
|---------|---------------:|
| 0       | **66%**       |
| 1       | 18%           |
| 2       | 7%            |
| 3–5     | 6%            |
| 6+      | < 3%          |

Two-thirds of posts never get a reply.  Fewer than 1% get more than
ten.  So the headline problem isn't "popular posts get bombarded" —
it's "most posts never find someone willing to collect."  That tells
us where selection should put its effort: instead of trying to stop
the few popular posts from getting too much attention, the bigger
win is helping the many quiet posts find someone who'd take them.

### What we are taking forward

- **Fair distribution of attention.**  Each post has a notional
  "promotion budget" measured in *eyeballs reached*, not in *emails
  sent*.  A post depletes its budget when a real human actually sees
  it — an AMP open event for digest mode, a post-detail page view on
  the website — not when it gets inserted into a digest that nobody
  opens.  When we assemble a member's digest, posts whose eyeball
  count is low get prioritised over ones already widely seen.  This
  systematically pushes the posts that haven't yet found an
  interested member towards the top of digests — exactly what we
  want given that 66% of posts never get a reply.
  Budget is per-*post*, not per-poster: the same poster can have one
  item that's been seen 500 times and another that's been seen
  twice, and the second still has plenty of budget.  We also measure
  views *per hour* since the post arrived rather than as a raw total,
  so an 18-hour-old post with one view counts as just as "rare" as
  a 2-hour-old post with one view — the older post has had nine
  times longer to attract views and still hasn't, which we want to
  reward, not punish.

- **Drive-time isochrones, not straight-line distance.**  Wherever the
  algorithm needs a "closeness" signal — for filtering the reachable
  pool, for ordering inside the digest — it uses drive time from the
  post to the member, computed by the same isochrone service that
  drives Problem 1.  We do not use great-circle distance anywhere in
  selection.

- **Post desirability — once we can measure it.**  A great signal for
  selection would be how *desirable* a post is in its own right
  (clear photo, well-described, sought-after item).  Freegle has work
  in flight on quantifying this but no usable signal yet, so the
  design accepts a desirability score as a future input without
  depending on one for v1.

- **Promised / taken home-group posts still get a notification,
  framed as a nudge.**  If a post the member would have been notified
  about gets promised before their digest fires, the digest can still
  mention it in a tail "since last digest, these came and went" section
  ("you would have been told about this earlier on immediate mode") as
  a gentle prompt to switch.  Suppressing it silently would deny the
  member useful information about the algorithm's effectiveness for
  them.  This applies *only* to home-group posts; promised or taken
  posts that would have been rippled in from a neighbouring group are
  not shown — the member would never have known about them anyway,
  and switching to immediate mode wouldn't have caught them either.

- **No fixed cap.**  Every reachable post in the 24-hour window
  appears in the digest; the sliders only change the order.  This
  means posts that scored low today fall out of the window after 24
  hours rather than getting a second chance (giving missed posts a
  second chance would require either a longer window or per-member
  seen-tracking, neither of which is wired in).  The eyeballs-budget
  signal partially compensates by lifting low-view posts toward the
  top of *today's* digest, but only within the 24-hour window.

- **Discovery link for very long pools.**  In the densest urban
  areas a digest may exceed 100 posts; in that case the digest mock-
  up soft-truncates at 100 with a "+N more on the website" footer.
  The browse view on the website lets curious members explore the
  rest if they want to.

### Length: no fixed cap, sort order does the work

We considered a hard cap (e.g. "no more than 50 posts in a digest"),
but caps create more problems than they solve.  Once a post is
"capped out" it never reaches the member at all, and there's no good
threshold — busy central London genuinely has 144 posts to choose
from, while a quiet rural area has 20.  Pinning a cap at either end
makes the other end feel wrong.

The simpler model: the digest contains every reachable post; the
selection sliders set the *order*, and members stop scrolling on
their own.  If even that turns out too long (a Trafalgar Square
member might see 200+ posts), the digest soft-caps display at 100
with a "+N more on the website — amazed you've scrolled this far"
footer.

The maximum-reach slider in the simulator controls *what's in* the
digest (radius from the member), and the other sliders control
*sort order* within it.

### Home-group anchoring — still open

A natural conservative choice is to push every home-group post toward
the top of the digest before any cross-group items.  This has real
advantages:

- **No surprise for members.**  Today's experience is "I subscribed to
  this group, I see this group's posts."  Anchoring preserves that.
- **No surprise for volunteers.**  Volunteers expect that posts in
  their group will reach their members.  Anchoring keeps that contract.
- **Group conventions are visible.**  Local meet-up points, recurring
  posters, group-specific norms remain natural for members.
- **Members have explicitly chosen these groups.**  Cross-group reach is
  imposed by geography; group membership is opt-in.

The cost is fewer items finding a taker: a home-group post 4 miles
away may be a worse prospect than a cross-group post 1 mile away,
and anchoring pushes the worse prospect to the top.  Whether to
anchor on the home group, or to let closeness and attention-spreading
do all the work, is genuinely undecided — in the simulator the
anchor weight is a slider so we can compare side by side.

### Reducing post fatigue

Two ways to keep a digest from feeling like a wall of duplicates:

- **Group posts from the same user.**  If one member posts five items
  the same morning, the digest shows them as a single entry ("Jane
  posted 5 items — see all") rather than five separate rows.  This is
  cheap (we already have the poster ID) and needs no new
  infrastructure.
- **Group items in the same category.**  "1 sofa + 10 other furniture
  items, click to see" reduces the fatigue of ten near-duplicate
  sofas.  This depends on reliable category clustering, which Freegle
  has explored with a Vector-based approach on a separate branch with
  limited success so far.  Open to revisiting once that maturity
  improves.

### What we explicitly do *not* design here

- **Stopping the same post appearing in two different emails on the
  same day** ("don't tell the same member about the same post
  twice") — this is being handled by the unified-digest work
  already in progress.  Selection sits on top of that and assumes
  it's solved.
- **Tailoring posts to a member's past behaviour** (e.g. "you've
  taken a lot of furniture before, so let's show you more
  furniture") — needs tracking data we don't yet have in a usable
  form.  Out of scope for now.

### What we still need to settle

- Whether to boost home-group posts at all — the simulator has it
  as a slider so we can compare with-and-without side-by-side.
  Default is no boost.
- Default settings for the three sliders.  We have a defensible
  starting point (closeness moderate, attention-spreading
  moderate, home-group bonus off) but no data yet to say what's
  optimal.
- How the simulator should *evaluate* how well we're spreading
  attention.  Replaying past posts tells us which ordering catches
  the most claimants the fastest, but it doesn't directly tell us
  whether quiet posts are finding takers they wouldn't have found
  before.  The likely answer is to pair the replay with the
  fairness check described in the recommender-systems section
  ("what share of digest visibility goes to the bottom half of
  posts by attention?").

### Lessons from other systems that do this kind of thing

(This section is more technical and can be skimmed.)

Deciding which items to show which person is a well-studied problem
— Netflix decides which films you see first, YouTube which videos,
Spotify which songs.  Their setup is very different from ours (huge
scale, lots of personal history, optimising for time-on-platform
rather than spreading attention), but a handful of their lessons
transfer cleanly:

- **New members with very little information about them.**  Big
  systems fall back on "where does this person live, what
  communities have they joined, what's broadly similar to people
  like them?" when they have no individual history yet.  Our
  home-group bonus is the same idea in simpler form — for someone
  brand new, knowing which group their postcode is in is already a
  strong starting point.

- **A fairness check worth measuring.**  One standard way to tell
  whether a ranking is treating items fairly is to ask: of all the
  visibility a digest gives away, what share goes to the half of
  posts that have had the *least* attention so far?  Systems
  tuned only for engagement push this share down to a few percent
  (the popular items dominate); systems that try to spread
  attention fairly land closer to 30–40%.  Worth measuring once
  we can see what's actually getting opened.

- **Build it in three stages, not one.**  Bigger systems separate
  "rule out what's clearly ineligible" from "score what's left"
  from "smooth the final list so it doesn't look samey."  Our
  selection currently does the first two; adding a final pass that
  spreads things out — e.g. so the top of the digest isn't six
  near-identical sofas in a row — is the natural next step.

- **People click the top of a list more, regardless of what's
  there.**  If we ever train a scoring model on what people
  clicked, we'll inherit this — the model would conclude the top
  items are "better" simply because they were on top.  The cheap
  fix is to randomise the order slightly when scores are close, so
  different members see slightly different top picks.

- **A view that wasn't really a view doesn't count.**  Most
  systems only count "did this person see it" if they actually
  spent a few seconds on the page (often around 30 s).  If
  Freegle's web tracking captures that detail we should adopt the
  same threshold.

- **"I skipped past this" is rare data but very useful.**  If we
  can tell someone scrolled past a post without clicking it, that's
  a meaningful "not interested" signal — much stronger than the
  absence of a click.  Worth folding in if the unified digest
  exposes it.

- **For our goal, the standard accuracy metrics aren't quite
  right.**  The usual ways to score a ranking ask "did the user
  click the top items?" and reward systems that match user
  behaviour.  For our purpose — getting items collected, not
  predicting clicks — the metrics that matter are: what fraction
  of posts appear in at least one member's digest at all
  (*coverage*); how much of the digest's attention goes to the
  less-seen half of posts (the fairness check above); and whether
  our predicted scores actually correlate with how often things
  get collected.

- **Weighing views by how old the post is.**  An 18-hour-old post
  with one view is *rarer* than a 2-hour-old post with one view —
  it's had nine times longer to accrue views and still hasn't.
  Treating raw view counts as the signal would penalise the older
  one for having had more time; weighing by views per hour treats
  them similarly.  We've implemented this.

None of this changes our v1 direction (home-group bonus +
drive-time closeness + eyeballs-budget + per-poster grouping).
What it gives us is (a) a vocabulary for the metrics we should
measure once unified-digest reading data lands, and (b) a hint
that we should evolve the algorithm into three explicit stages —
filter, score, smooth — rather than letting one weighted scoring
formula do all the work.

For now the algorithm produces the *pool* of reachable posts.  Problem
2 — selection — is a separate piece of design work that lands on top
of the unified-digest project.

# Problem 3 — repeating posts that didn't get collected

## What's wrong today

Posts that haven't been claimed get reposted after a couple of
weeks: the system asks the poster "is this still available?", and
if it is, the post is bumped back into the group as though it were
new.

There's good evidence that this works in the way it's meant to.  A
useful number of items that didn't find a taker the first time round
do find one when they come round again, so taking the mechanism
away would mean losing genuine matches.

But it works at a cost.  The bump is to the whole group, not to
specific members, so anyone who already saw the post the first time
sees it again — often more than once if it gets reposted more than
once.  Members who weren't interested then aren't interested now,
and the repeated reappearance is one of the more common things
people complain about.

So Problem 3 is the same trade-off as Problems 1 and 2: today's
mechanism is too coarse to know who's already had a fair chance with
a given post.  It treats "the group" as one thing rather than
treating individual members as the unit that matters.

## What we're doing about it

The right model for autorepost in the new world is *per-member*, not
per-group.  Sketched as a direction (not yet designed in detail):

- **The post itself doesn't need re-broadcasting.**  As long as the
  post is still active (the poster has confirmed it's still
  available), it stays in the eligible pool indefinitely.  Selection
  picks it up fresh each day on the same terms as any other live
  post.
- **The poster's confirmation step stays.**  The "still available?"
  chase-up email is genuinely useful — it's the only way we know
  the post is still real.  That continues as today, just without
  the group-wide "bump" that follows confirmation.
- **Per-member chance state.**  Selection asks "has this member
  had a chance with this post yet?" — three cases:
    - **Not yet seen** (newer member, was on holiday, or simply
      missed it the first time): the post is treated as fresh for
      them and ranked accordingly.
    - **Seen but not engaged**: the post drops in their personal
      ranking.  Showing it to them again is unlikely to change
      their mind, and that's the source of the irritation today.
    - **Seen and engaged** (clicked through, replied): irrelevant —
      they're already in dialogue with the poster.
- **What "seen" means.**  The same signals we use for the
  eyeballs-budget (open events on digest emails, post-detail page
  views on the website, "Interested" replies), but tracked per
  (member, post) pair rather than added up across all members.
  This is the same per-member-per-post tracking the unified-digest
  project needs for stopping the same post appearing in two emails
  on the same day, so we should get it for free.

The net effect for members: a post they've not yet seen will keep
surfacing until they have a chance to engage with it, even if it's
a few days old.  A post they've already seen and skipped doesn't
keep coming back.  The poster's chase-up cadence determines how
long the post is allowed to *exist*; selection determines, per
member, how visible it is on any given day.

The net effect for posters: the explicit "yes, repost" action that
exists today becomes implicit — as long as you keep confirming
availability, the system keeps trying to find you a taker.  Whether
we also want an explicit "give this another push" action available
to the poster (for example: reset the per-member "seen but not
engaged" state for everyone, in case the post wasn't quite right
the first time round and the poster has now improved the photo or
description) is an open design question.

# What we built (in brief)

For Problem 1 (geographic reach):

- We loaded the UK and Northern Ireland road network from
  OpenStreetMap, giving us every road with its speed limit.
- We wrote a service that runs a standard route-finding algorithm
  over that map to figure out where a car could drive in N minutes
  from any starting point.
- It produces the rippling-out schedule for any post in about a fifth
  of a second.  Everything runs on Freegle's own servers; no calls to
  external services like Google Maps.
- A preview tool inside ModTools — "Who could see my post" — lets
  moderators drop a marker anywhere in the UK and see exactly what the
  algorithm would do, with an animated ripple across the map.

For Problem 2 (selection):

- A live scoring model running against production data: closeness
  (drive-time to the member), eyeballs budget (views and replies per
  hour since arrival), and an optional home-group anchor bonus.
- A second ModTools tab — "Digest preview" — that drops a marker for
  a hypothetical member and shows what *they* would see in their next
  digest: every reachable post in the last 24 h, scored and ordered by
  the sliders, rendered as numbered markers on the map and as a
  mock-up list in a side modal.  The mock-up lists each post by title,
  group name, distance, time, thumbnail and score breakdown, split into
  "top picks" / "promised" / "since last digest, these came and went"
  sections.
- A pie chart summarising the reachable pool by class (home active,
  rippled-in active, promised, completed) so the geographic-reach
  shape is visible at a glance.
- A URL-driven mode (`?view=inbound&postcode=OX1+1AA` or `&lat=&lng=`)
  so we can bookmark example locations for discussion.

Problem 2's *algorithm* is still a moving target — the simulator lets
us play with weights and instantly see the effect.  Production roll-
out is gated on the unified-digest project landing.

# Problems and mitigations

## On Problem 1 (geographic reach)

| Problem | Mitigation |
|---------|------------|
| OpenStreetMap gives us speed *limits*, not real driving speeds.  Junctions, urban congestion and traffic make real journeys 30–50% slower.  Without adjustment, our "30-minute reach" would correspond to ~45 real-world minutes — much further than people would realistically travel for a free item. | A per-OSM-road-class slowdown factor is applied: motorway/trunk 0.81×, A-roads 0.57×, residential/unclassified ~0.50× (estimated).  Factors come from the DfT congestion statistics (CGN0404 / CGN0503) for England, applied UK-wide.  Details below under "Drive-time accuracy." |
| Cross-posting automatically is a behaviour change.  Volunteers are used to cross-posting being deliberate. | Documented explicitly (this document).  The evidence — members near group boundaries currently disadvantaged — is strong; we don't see a reasonable alternative. |
| Northern Ireland has no equivalent of the speed-data products available for Great Britain, so we can't refine per-link congestion UK-wide. | The per-road-class factor table is uniform across UK + NI.  Future work could fold richer GB data in while leaving NI on the simpler factor. |
| Posts at TrashNothing centroids share a lat/lng with dozens of other posts, producing a clump of markers on the map. | The digest-preview map collapses posts at the same coordinate into a single marker with a comma-separated list of digest positions; clicking opens a per-cluster modal. |

## On Problem 2 (selection)

| Problem | Mitigation |
|---------|------------|
| Pool size can be 3.5× a member's current digest content in a dense city (~144 posts vs ~43 today). | Selection by weighted score over closeness + eyeballs budget + optional home-group bonus; no fixed cap — sort order does the work, soft-truncated at 100 with a "+N more on the website" footer.  Tuned once the unified digest gives us data on what members are actually opening. |
| Most posts (66%) get no reply at all. | The eyeballs-budget signal rewards posts few people have seen yet (views and replies per hour since arrival, not raw totals), pushing the posts most in need of finding a taker up the digest order. |
| Most members (about 84%) are on daily digest; the new algorithm could either dump cross-group items into separate emails or fold them into the digest. | Folding into the digest is the natural option, possibly with the digest delivered earlier than usual if a high-relevance post arrives.  See "digest pull-forward" below. |
| Pulling a digest forward more than once a day would spam the user. | Hard rule: at most one digest per user per day. |
| A digest of ten sofas feels like spam even if all ten are relevant. | Group entries from the same poster (cheap: just the poster ID); when category clustering improves, also collapse near-duplicate items by category. |
| A home-group post promised before the digest fires would silently disappear. | Still show it in the digest's tail "since last digest, these came and went" section, framed as "would have reached you earlier on immediate mode" — informative, and a soft nudge toward switching mode. |
| A cross-group promised/taken post would be a meaningless "missed it" — the member would never have known. | Filtered out at the backend.  Only home-group promised/taken posts reach the tail section. |
| Duplicate notifications about the same post across emails. | Out of scope here — handled by the unified-digest work this design lands on top of. |

## How to use the ModTools preview

The preview lives at `/rippling` inside ModTools and has two tabs:

- **Who could see my post** (outbound).  Drop a marker.  The map
  animates the rippling-out wave from that point — small dots are
  individual freeglers, the boundary expands as drive time grows.
  Useful for "if I post here, who gets notified and when?"

- **Digest preview** (inbound).  Drop a marker.  The map shows what
  *a member at that spot* would see in their next unified digest:
  every post inside their drive-time reach in the last 24 hours,
  numbered by digest position.  Two distinct controls:

  - **What's in the digest** (the maximum-reach slider) — controls
    *which* posts are eligible.  The pie chart and counts above
    update with this.
  - **What order is it in?** (the closeness / eyeballs-budget /
    home-group sliders) — controls *the order* of posts within the
    digest.  Doesn't change what's *in*, just where it appears.

  Marker colour: green = home-group post, blue = rippled in from a
  neighbouring group, amber = promised (in flight), grey = completed
  since last digest.  The "Show digest mock-up" button opens a side
  modal with the full ordered list, group names, distances, time
  posted, thumbnails and a per-post score breakdown.

URL parameters let you bookmark example locations:
`?view=inbound&postcode=OX1+1AA` or `&lat=...&lng=...`.

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

## Glossary

These are the technical terms used throughout the document.  Earlier
sections refer back here when they introduce one of these.

### General Freegle concepts

- **Group** — a Freegle community defined by a polygon on a map (e.g.
  Oxford-Freegle, Camden-Freegle).  Members belong to one or more
  groups; posts belong to one group at a time.
- **Home group** — the default Freegle group for a member's postcode.
  Each postcode in the UK maps to a single home group via the
  postcode-to-group lookup; the home group is therefore (almost
  always) a single group derived from where the member lives, not
  "all the groups they belong to."  A member may also belong to other
  groups they've separately signed up to, but those aren't their home
  group.  (The algorithm finds the home group by checking which
  Freegle group's polygon contains the member's location; in the rare
  case of overlapping polygons it returns more than one match, but
  the typical case is one.)
- **Cross-posting** — a post made in one group being shown to members
  of a different group.  Today this is rare and manual; the new
  algorithm makes it automatic and driven by drive-time reach.
- **Eligible to see** — a member is in the candidate set for a given
  post under Problem 1.  Does *not* mean they will be shown the post;
  selection (Problem 2) decides that.
- **Digest** and **Immediate** — the two notification modes, treated
  as complementary rather than mutually exclusive:
    - *Digest* is a periodic (typically daily) email summarising the
      posts a member is eligible to see.  Today almost all digests
      are per-group (one email per group per day); the new algorithm
      assumes a single *unified digest* per member per day.
    - *Immediate* is the supplementary mode where a post the member
      is eligible to see triggers its own email as soon as it
      arrives, rather than waiting for the next digest.
  A member could in principle have both — daily digest as the regular
  catch-up, immediate for posts that match a stronger interest.
  Today the production setting is one-or-the-other per group; the
  unified model lets the two be supplementary.
- **AMP email** — a Google email format that lets the email itself
  display live content (post photos, click-to-reply).  Freegle uses
  AMP for digest emails when the recipient's mail client supports
  it.

### Reach and geography

- **Drive-time reach** — the area a car can reach from a starting
  point within a given time budget (e.g. 30 minutes).  We compute
  this on the UK + Northern Ireland road network without calling any
  external service.
- **Isochrone** — synonymous with drive-time reach.  Used by mapping
  people to mean "polygon enclosing everywhere reachable within T
  minutes."
- **Reachable pool** — our rule-of-thumb shortlist of members who
  *might* want to respond to a given post.  Whether any particular
  member actually would respond depends on their circumstances, but
  the drive-time reach is a practical heuristic for "could they
  plausibly collect this?"  Because the reach is computed as a true
  isochrone over the road network rather than as a circle around the
  post, it respects real-world geography: the Bristol Channel, the
  Thames without a nearby bridge, a motorway with no junction, a
  mountain road that bottlenecks — all of these naturally shrink the
  reach in the right places without us having to model them
  explicitly.  Anywhere from a few hundred members in rural areas to
  tens of thousands in a city.

### Scheduler internals

- **Rank** — a member's position in the reachable pool when sorted by
  drive time.  Rank 1 = closest; rank N = furthest still inside the
  reach.  (Distinct from a post's *digest position*, which is also
  numbered 1 = top.  Context distinguishes the two.)
- **Tick / batch** — one firing of the scheduler.  Each tick emails (or
  selects for emailing) a group of members whose rank falls in some
  range.  We use 30 ticks over 24 hours, so a tick fires every 48
  minutes.
- **Lifetime** — how long the schedule runs from when the post arrived.
  Currently 1 day.
- **Curve** — the rule for how many members are added in each tick.
  Names below ("step-70", "front-loaded heavy") refer to specific
  shapes — see the table in "Why the 70% up front / 30% over a day
  shape."
- **Catch rate** — among historical replies, the fraction the schedule
  would have notified *before* the recorded reply.
- **Waste** — fraction of scheduled emails that would fire after the
  post had already been claimed / taken / withdrawn.  This is an upper
  bound; in production the scheduler checks status before firing each
  tick so most of these emails are simply cancelled.
- **Lead time** — how far ahead of their actual reply we'd have
  emailed the eventual replier.  Shorter (i.e. closer to "just in
  time") is better.
- **Counterfactual** — a "what would have happened if" question
  answered against data that's independent of which algorithm was
  running at the time.  Our simulator is a counterfactual evaluator.
- **RU class** — ONS Rural-Urban classification for a UK postcode.
  Coarse buckets: *urban* (A1, B1, C1 — major conurbations through
  small towns), *rural* (D1, E1, F1 — town fringe through hamlets),
  and *unclassified* (mostly Northern Ireland, where the ONS scheme
  doesn't reach).

## Why the "70% up front / 30% over a day" shape

We tested a wide range of shapes against historical data:

| Curve name we used | What it actually means in plain terms |
|--------------------|----------------------------------------|
| linear             | Equal-sized batches in each tick.       |
| back-loaded        | Tiny batches at first, ramping up — most members reached late. |
| front-loaded (gentle, medium, heavy) | Most members reached early; trailing batches get smaller. |
| **step-70**        | A single big batch up front (70%) and then equal small batches across the rest of the day for the remaining 30%.  *This is what we use.* |
| step-50            | Same idea but only 50% in the first batch. |

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

We tested lifetimes from 12 hours up to 30 days.  The catch rate
barely moves with lifetime — the big up-front batch catches almost
everyone who'd reply regardless of how long the trailing ripple is —
but waste grows roughly in step with lifetime (an email fired three
days after the post was claimed is wasted).

So 1 day gives essentially the same catch rate as a half-day lifetime
with low waste, and a much more sensible scheduler cadence than
firing batches a few minutes apart.

## Drive-time accuracy

OpenStreetMap gives us speed *limits*, not real driving speeds.
Without correction the limits are far too optimistic for our purpose
("would somebody actually drive this far for a free item?").  We
applied a flat global 0.7× factor as a first cut, then refined it to
a per-road-class factor — see the next subsection.

Two spot checks against Google Maps (with traffic) for ~30-mile UK
journeys:

| Route                      | Our raw routing | Flat 0.7× | Per-road-class | Google |
|----------------------------|----------------:|----------:|---------------:|-------:|
| Oxford OX3 8GH → Highclere | 30 min          | 43 min    | ~48 min        | 53 min |
| Newcastle NE1 → Alnwick    | 30 min          | 43 min    | ~37 min        | 45 min |

Both routes land closer to Google's numbers under the road-class
model.  The remaining 5–8 minute gap is plausibly that Google uses
typical-peak conditions while DfT data averages across all hours.

OpenStreetMap actually tags a lot of the data we'd need for proper
junction modelling (traffic lights, give-way signs, roundabouts);
extracting that and applying per-junction penalties is more work than
the road-class correction buys us, so we've left it for later.

### Road-class-aware scaling — calibrated against DfT data

We now apply per-road-class factors instead of the flat 0.7×.  The
factors come from real-world UK speed data published by DfT.

A natural objection to the original flat 0.7× factor was that real
driving is slower in cities than in the country.  We thought the
right answer might be a population-density modifier on top of the
flat factor — slower in dense postcodes, faster in rural ones.

Pulling the actual numbers from the May 2026 DfT congestion
statistics (CGN0404 for the Strategic Road Network and CGN0503 for
local A-roads) shows the picture is simpler than that.  Real
all-day average speeds vs typical speed limits:

| Road type                | Real average | Typical limit | Real ÷ limit |
|--------------------------|------------:|-------------:|------------:|
| Strategic Road Network (motorways + major A) | 56.4 mph | 70 mph | **0.81×** |
| Local A-roads, urban     | 17.2 mph     | 30 mph       | **0.57×**   |
| Local A-roads, rural     | 34.3 mph     | 60 mph       | **0.57×**   |

The urban-vs-rural difference in real *speed* is almost entirely
explained by the difference in *speed limit*.  Once normalised
against the limit, the urban factor and the rural factor are
indistinguishable (both 0.57×).  Cities aren't slower because they
have congestion *on top of* their lower limits — they're slower
*because* their limits are lower, and the over-the-limit shortfall
is the same proportion as in the country.

This is unexpected but the data are clear, and it means our
hypothesised density modifier would have spent effort modelling
something that doesn't really exist at the level we care about.

The correction that *is* worth making is **per-road-class**, since
OSM gives us that for free on every edge:

| OSM `highway=*` class                  | DfT-implied factor | Notes |
|----------------------------------------|------------------:|-------|
| motorway / trunk                       | **0.81**          | from SRN average |
| primary / secondary (A-roads)          | **0.57**          | from local-A average |
| tertiary / residential / unclassified  | ~0.50 (estimated) | not directly measured by DfT, conservatively lower than A-roads |

The flat 0.7× factor is wrong in *both* directions: too slow for
motorways (0.81 would be more accurate) and too fast for A-roads
(0.57 is closer).  Re-running our two spot checks with these
calibrated factors:

| Route                      | OSM raw | Flat 0.7× | Road-class | Google |
|----------------------------|--------:|----------:|-----------:|-------:|
| Oxford OX3 8GH → Highclere | 30 min  | 43 min    | ~48 min    | 53 min |
| Newcastle NE1 → Alnwick    | 30 min  | 43 min    | ~37 min    | 45 min |

Both routes land closer to Google's (with-traffic) numbers under the
road-class model than under the flat factor.  The remaining gap
(5–8 min) is plausibly explained by Google using typical-peak
conditions while DfT averages across all hours.

The road-class table is the one we currently use; no per-postcode
density modifier is layered on top.  The empirical results below were
all computed under this calibration.

Sources used: [DfT Average Speed CGN0404](https://www.gov.uk/government/statistical-data-sets/average-speed-delay-and-reliability-of-travel-times-cgn)
and [CGN0503](https://www.gov.uk/government/statistical-data-sets/average-speed-delay-and-reliability-of-travel-times-cgn),
both updated 14 May 2026, data through December 2025.

## How the simulator works

The simulator answers a "what if" question against past data: "if
we'd been running the new algorithm at the time of this historical
post, would the person who eventually claimed it have received our
email *before* they replied?"

Its only inputs are *facts about real members*: where the replier
lived at the time, when they replied, where the post was.  None of
that depends on which algorithm was actually running at the time,
so the simulator's answers stay valid even as we change the
algorithm.

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

Sample run: 10,521 historical posts (last 12 months), with the
DfT-calibrated per-road-class drive-time factors.  Restricted to
urban posts and to members who use immediate notifications (the
cleanest cohort to measure on, because their reply time isn't
distorted by digest delivery):

| Curve                | Catch rate | Waste (upper bound) | Lead time median | p75    | p90    |
|----------------------|-----------:|--------------------:|-----------------:|-------:|-------:|
| linear               | 42%       | 9.7%               | 10 h             | 64 h   | 284 h  |
| front-loaded mild    | 50%       | 6.9%               | 7 h              | 37 h   | 220 h  |
| front-loaded heavy   | 69%       | 4.6%               | 4 h              | 21 h   | 171 h  |
| front-x^0.15         | 79%       | 2.6%               | 3.2 h            | 17 h   | 130 h  |
| **step-70**          | **80%**   | **3.0%**           | **3.0 h**        | **16 h** | **122 h** |

Step-70 still wins.  The catch rate is lower than the previously
reported 92% figure (which was measured against the older,
over-generous flat 0.7× factor); under the new calibrated factors,
30-minute isochrones are slightly tighter in urban areas, so a few
extra replies fall just beyond the cutoff.  The 80% under
calibrated factors is what real-world drive times actually catch.

Breakdown by urban / rural / unclassified, immediate repliers only,
step-70 curve:

| RU class      | Catch rate | Waste | p50 lead |
|---------------|-----------:|------:|---------:|
| urban         | 80%       | 3.0% | 3.0 h    |
| rural         | 65%       | 2.1% | 5.4 h    |
| unclassified  | 66%       | 2.5% | 1.6 h    |

Rural's lower catch rate is expected: more replies come from
beyond the 30-minute drive-time cutoff in sparse areas.  If we want
to lift rural catch we'd extend max-minutes (currently 30), at the
cost of more emails per post.

Lead time = "how far ahead of their actual reply we'd have emailed
them."  Lower = more just-in-time.  Daily-digest users' lead times
look much longer because they include digest wait; those aren't in
this table.

## Mail-volume estimate in central London

Measured by querying the new `posts-for-member` endpoint at four
central London locations across seven recent days:

| Location   | Mean posts/day | Range  |
|------------|---------------:|-------:|
| Trafalgar  | 158            | 117–216 |
| Camden     | 112            | 80–150 |
| Brixton    | 187            | 126–239 |
| Hackney    | 121            | 77–176 |
| **Pooled** | **~144**       | 77–239 |

That is the size of the *reachable pool* before any Problem-2
selection — i.e. every post that arrived in the previous 24 h within
the member's 30-minute drive.  A typical central London member is
currently a member of about 4.5 Freegle groups producing ~43 posts a
day combined; the rippling-out reach widens that to ~144 posts a
day.

What that means depends on the member's notification setting:

| Member's setting              | Today                            | Under the new algorithm                            |
|-------------------------------|----------------------------------|----------------------------------------------------|
| Daily digest (today: per-group) | 43 posts in 4–5 digest emails | ~144 posts in one unified digest, scored and sorted; soft-truncated at 100 if larger |
| Immediate                     | ~43 emails/day                   | One immediate email per post the member is eligible to see (volume depends on which posts pass the selection filter) |

The whole pool — ~144 posts/day in central London — is in the digest
under the new model.  The selection sliders set the *order* so the
posts most worth scrolling to are at the top; nothing is *removed*
from the digest just because it scored low.

## Operational characteristics

- Routing service start time: ~3 min cold (loading the UK road graph
  into memory).
- Memory footprint at idle: ~4.5 GB.
- Schedule generation: ~200 ms per post.  100 parallel post lookups:
  ~0.6 s total.
- One UK-wide road-graph refresh per month is sufficient.

## Where the code lives

For readers who want to look at the implementation.

- Routing service: `iznik-routing-go/`
- Drive-time edge speeds and per-road-class factors:
  `iznik-routing-go/graph.go`
- Schedule endpoint (rippling-out, Problem 1):
  `iznik-routing-go/ripple.go`
- Digest-simulator endpoint (Problem 2):
  `iznik-routing-go/digest_simulator.go`
- Simulator (offline curve evaluation):
  `iznik-routing-go/cmd/ripplesim/main.go`
- Historical extractor (post + replier data dump from MySQL):
  `iznik-routing-go/cmd/rippleextract/main.go`
- ModTools preview (the two tabs described above):
  `iznik-nuxt3/modtools/components/RipplingExplorer.vue`
- Spatial index server (members + groups in-memory R-tree):
  `iznik-spatial-go/`
- Monitoring scaffold (not yet scheduled):
  `2026_05_27_000001_create_ripple_algorithm_metrics_table.php`
  and `MonitorAlgorithmCommand.php` under `iznik-batch/`.
