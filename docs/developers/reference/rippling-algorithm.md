# How Rippling Works - Technical Reference

The technical companion to [../../moderators/rippling-out.md](../../moderators/rippling-out.md)
(the non-technical guide). This describes how the algorithm computes and spreads a post's
reach, including the approaches that were tried and rejected, and why.

Rippling lets an OFFER or WANTED posted on one community also appear on neighbouring
communities, so a giver and a taker who are close on the ground but in different Freegle
groups can still find each other, without spamming distant communities. Every design choice
below serves one rule: **show a post to the people who could realistically get to it, and no
further.**

## 1. The reach: an expanding drive-time area

Each live post has a **reach**: the area from which someone could travel to the post within a
time budget. It is not a circle. It is an **isochrone** - the set of places you can get to
from a point within a given travel time using the real road network. Ten minutes' drive gets
you a long way down a motorway and not far down a farm track, so an isochrone is an irregular
blob that follows the roads, stops at rivers with no crossing, and skips tolled crossings.

The reach **grows over time** rather than appearing at full size:

- A fresh post reaches only its immediate neighbourhood. Most replies come from very close by
  - measured across 23,738 replies since rippling went live, conversion falls smoothly and
  monotonically with distance, with the median reply about 2km away - so nearby people get
  first chance, and most posts complete without ever travelling far.
- If the item is still available later, the reach steps outwards: a wider audience is now
  worth the extra travel.

The growth steps follow a **hazard schedule** of hours since the post arrived:
`[1, 3, 6, 12, 24, 48, 72, 120, 168]` (`freegle.ripple.hazard_hours`). The shape mirrors when
interest actually arrives: a burst in the first hour or so (people online right now), a bump
each time a daily digest email lands, then a long tail. It also respects a practical limit:
each digest a post sits in unanswered makes the offerer a little less likely to still respond
- interest goes stale, and hand-overs arranged late fail ("ghosting") more often - so most of
the spreading happens early, while the offerer is still engaged. (We have clear figures for
distance decay; the time-decay effect is observed but not separately quantified.)

Those hours are **elapsed time, not wall-clock guarantees**: expansion only runs during
active hours (`active_start_hour` 6 to `active_end_hour` 23, server local time - see
`ExpandService::run`, which gates `advanceDue` on `inActiveHours()`). A tick falling
overnight waits until 06:00, so a post made late in the evening does its 6h and 12h steps
in the morning rather than at 02:00 and 08:00. Only expansion is gated - `initialiseNew`
runs at any hour, so a post still gets its initial reach immediately whenever it is made.

State lives in one row per active post in `rippling_reach` (msgid, origin lat/lng, current
polygon, cached per-tick schedule, tick, status). `ripple:expand` runs every minute, so a new
post starts spreading almost immediately (roughly 70% of its initial audience at tick 0), not
on an hourly boundary.

## 2. Computing the reach (the routing server)

`iznik-routing-go` (the `spatial` container) loads the UK OpenStreetMap road network (~57M
road points) into memory, with a travel time for every road segment in each mode (walk /
cycle / drive). Segments unusable in a mode carry no time; toll links (e.g. the Dartford
Crossing) carry no drive time at all, so the algorithm never routes a car across them.

From the post's location it runs **Dijkstra's algorithm** - the classic shortest-path method
(Dijkstra, 1959): repeatedly take the not-yet-visited road point with the smallest known
travel time, and relax its neighbours' times through it. Stopping when the smallest time
exceeds the budget yields every road point reachable within the budget, each with its exact
travel time: `IsochroneResult.ReachedNodes`. Two properties of this set carry the whole
design:

1. **A reached point has a real route.** It is only in the set because Dijkstra found a
   drivable path to it within budget. Nothing in the set is across an uncrossable river or an
   excluded toll.
2. **Each reached point has an exact travel time**, so "who is reachable within X minutes" is
   a lookup, not a new computation.

### 2a. Drawing the reach: reached points into a polygon

Consumers need an area, not 5 million points: the map draws it, and browse/digest ask "is
this member inside the reach?" (`ST_Contains`). The conversion (`polygon.go`,
`IsochronePolygon`) works on a grid:

1. **Cell size from the road network itself.** `NetworkResolution` sets the grid cell to the
   75th-percentile length of the reached road segments (floor ~33m, cap ~330m). Where roads
   are dense (a town) cells are small, so a river between two banks of streets is resolved;
   on open motorway cells are bigger, where there is nothing narrow to represent. There is no
   fixed magic cell size to tune wrong.
2. **Stamp what is reached.** Every reached point's cell is filled, and every reached road
   segment's line of cells is filled (Bresenham line walk between its endpoints), so the
   road network stays continuous however long individual segments are. Water carries no road,
   so it is never stamped: the filled area stops at the near bank.
3. **Grow by one cell.** Roads in a settlement are usually only a cell or two apart, so one
   cell of growth merges them into the solid area a person can actually be in, and covers a
   member whose postcode centre sits a little back from their road. This is a pragmatic
   compromise, not a guarantee: one cell of growth could still bridge a barrier that is both
   narrower than one cell and separately reachable on each side (a regression test,
   `TestIsochronePolygon_DoesNotBridgeRiver`, guards the case we know about), and genuinely
   sparse road areas keep their holes - which is accurate, since nobody lives in them.
4. **Trace the boundary - and stop.** The filled cells' outline becomes the polygon, with
   only exactly-collinear points removed (lossless). There is **no shape smoothing anywhere**:
   not server-side simplification, not client-side corner-rounding. The displayed boundary is
   exactly the computed one.

Why so strict about smoothing: the drawn reach is how moderators and developers debug
rippling. Any smoothing step (server-side line simplification, client-side corner-rounding)
moves the boundary by design - and where the boundary hugs a river bank, the rounded curve
bulges across the water, showing the reach touching a far bank it cannot actually reach. A
display that does not match the computation cannot be debugged against it.

### 2b. Rejected polygon approaches

- **Convex hull of the reached points.** A convex hull fills every concavity, so it always
  spans water and bays.
- **Budget-scaled grid cells** (cell size grows with the time budget, capped ~1.1km for a
  30-min drive). The Thames at Gravesend is ~700m wide - narrower than one cell - so the
  grid could not represent the river at all and the drawn reach always crossed it somewhere.
  No amount of post-processing can fix a raster whose cells are wider than the barrier.
- **Uniformly fine cells everywhere.** Memory and CPU proportional to the bounding box, almost
  all of it open country with nothing to resolve.
- **Node-fill without segment stamping.** At fine cell sizes, long motorway segments leave
  multi-cell gaps, fragmenting the reach into disconnected islands.
- **Heuristic hole-filling** ("skip a gap whose only filled neighbours are opposite",
  "bridge only within a grid component"). Grid adjacency is not road connectivity: both
  fragmented genuinely-connected reaches. The information about what is connected lives in
  the road graph, not the raster.
- **Douglas-Peucker simplification + client corner-smoothing.** Rejected as above: any
  approximating step can move the boundary across a barrier. The cost of removing them is a
  larger polygon (~4x the vertices), paid knowingly.

## 3. Sizing the reach: the extent governor

A fixed drive-time over-reaches in cities (thousands of people in 10 minutes) and
under-reaches in the countryside. The **extent governor** normalises by audience, not
distance: each post targets roughly a fixed number of nearby freeglers (`target_users`,
`freegle.ripple.extent.*`). Because every reached road point carries its exact travel time,
the server can compute each nearby freegler's drive-time and cap the schedule at the
~`target_users` nearest. Where the reachable pool is already below the target, the drive-time
ceiling applies unchanged. Two further stops:

- **Reply saturation.** A post with `reply_saturation_stop` (default **5**) distinct
  Interested repliers stops expanding - it has plenty of interest already.
- **Outcome.** A taken, withdrawn or received post stops immediately.

## 4. Targeting: which groups receive the post

A group receives a rippled copy only if **at least one active freegler who lives in that
group's area can actually travel to the post**. Concretely, a group is a target iff all of:

- it is a live Freegle group (`publish=1`, `listable=1`, not a playground), and
- it has at least one member who is **active** (Approved membership, used Freegle in the last
  90 days - the same definition the Rippling Explorer shows), and **lives inside the group's
  own polygon**, and whose **street is road-reachable** from the post within the current
  budget - their nearest road point is in the Dijkstra reached set,
- and the poster is not re-joining a group they previously opted out of (below).

Each condition kills a distinct real failure:

- **Road-reachable street** kills geometry errors: no member's street is across severed water
  or a tolled crossing, however the drawn polygon behaves - reached points cannot be (the
  canonical case: a Corringham offer can no longer target Gravesend across the Thames).
- **Lives inside the group's polygon** kills membership noise: someone who is a member of
  Gravesend_Freegle but lives in Essex (common for previously-rippled memberships) does not
  make Gravesend "reachable".
- **At least one active member** kills empty targeting: touching an inhabited-by-nobody
  corner of a group's area no longer ripples a copy nobody will see.

The member location used is their **postcode centre** (`users.lastlocation`), not the
privacy-blurred point used for display (~0.06% of active members lack one and fall back to
the blurred point). The blur matters here: a blurred point can land in a river channel, where
the nearest road is on the wrong bank - a postcode centre sits on its own street. Postcodes
are assumed not to span rivers or similar barriers. This is all server-side; only group ids
ever leave the server.

**The candidate members come from `users_approxlocs`** - the query in
`iznik-routing-go/reachable_groups.go` drives off that table's spatial index and joins
outwards, so a member with no row there is invisible to targeting whatever their postcode
says. That table is a cache, refreshed nightly by `users:update-approx-locs`
(`UserApproxLocService`), holding one ~400m-blurred point per member active in the last six
months. Nothing else notices when the refresh stops: reach still computes, still looks
plausible, and just quietly stops seeing newer members. It went unwritten from V1's removal
until 2026-08-10, by which point 38,325 of 112,548 active members (34%) had no row. If reach
ever looks like it is under-targeting, check `MAX(timestamp)` on that table first - and the
job's row on the SysAdmin cron dashboard, where a missed run shows as overdue.

The same decision is computed **per tick**: every entry in the ripple schedule carries
`reachable_group_ids` for its drive-time (a threshold over the already-computed member
drive-times - no extra routing). The Rippling Explorer tints groups from exactly this field,
so the animation you watch is the targeting decision at each step, not a geometric
approximation of it. On by default; `RIPPLE_REACHABLE_GATE=false` is the killswitch, reverting
targeting and retraction to the polygon-overlap test.

### Rejected targeting approaches

- **Polygon overlap** (`ST_Intersects(group polygon, reach polygon)`). Inherits every raster
  artifact of the drawn reach, and counts groups whose overlapping sliver contains no people.
- **"A reached road point inside the group's polygon."** Better (the point itself is genuinely
  reachable), but group polygons are catchments that can straddle a river: a reachable
  north-bank point inside Gravesend's polygon would count Gravesend without any Gravesend
  resident being reachable. And it still ignores whether anyone lives there.
- **Counting roads inside `reach ∩ group`, or roads crossing the boundary.** Needs a threshold,
  and re-consumes the overshooting polygon. Reachability is decided by facts only the road
  graph knows (severance, tolls); no geometric test sees them.
- **Blurred-location member test.** The privacy blur can push a member's point across mid-river,
  where its nearest road is the wrong bank's - producing exactly the false positive the member
  test exists to kill. Hence postcode centres.
- **"Verify the route from the member lies within the reach polygon."** Redundant when the
  member's street is honestly identified (being in the reached set already proves a route),
  and defeated when it is not: a wrongly-snapped far-bank member yields a genuine near-bank
  route that passes the check. The fix belongs at the snap (postcode centre), not the route.

## 5. Spreading mechanics

For each due post, `ripple:expand`:

- **`initialiseNew`** (tick 0) fetches the post's schedule in slim form (per-tick drive-time,
  audience count and reached-group ids - no polygons, which kept a dense-city schedule call
  to a few KB instead of ~24MB), fetches the first tick's polygon as a single catchment
  call, creates the `rippling_reach` row and does the first ripple-in.
- **`advanceDue`** advances to the next hazard tick: one catchment call materialises that
  tick's polygon, and the stored per-tick reached-group ids drive the ripple-in - no
  schedule recomputation. The target is normally elapsed time alone, but
  `rippling_reach.min_tick` raises a floor under it (capped at the post's schedule length):
  a scout who replies was outside the reach when we mailed them, so their reply is evidence
  the item is wanted that far out and the people around them should get the same chance
  rather than waiting on the clock. See
  [first-reply.md](first-reply.md#a-scout-who-replies-pulls-the-reach-out-to-them).
- **`rippleIntoNewGroups`** resolves target groups with a non-locking snapshot `SELECT`, then
  inserts each `messages_groups` membership as its own `INSERT IGNORE` (Galera-safe; avoids
  the lock-wait storms a single `INSERT ... SELECT` caused). Rippled copies carry the post's
  `msgtype` and are approved at ripple-in by default (`rippled_in_pending_hours = 0`): the
  post was already vetted on its home group, so copies never flicker through Pending.

**Rejoin suppression.** If a freegler's most recent Group/Joined log for a group is a
ripple-join (`logs.text = 'Rippled'`) and they then left, rippling does not re-add them: they
opted out of a rippled membership. A later ordinary join-then-leave does not block rippling.

## 6. Retraction

As a capped reach shrinks or the reachable set changes, `retractOutOfReachCopies`
soft-deletes rippled-in copies no longer in reach, and removes the ripple-join membership
when the poster has no other live post there. A **held** reach (from a report or
Back-to-Pending) is frozen: its copies persist for per-group moderation and are never
retracted, so re-approval restores the copy without re-rippling.

## 7. Consumers of the reach

- **Browse / Nearby feed:** members see rippled-in posts via
  `ST_Contains(rippling_reach.polygon, member point)`, filtered by the member's own distance
  preference (`browseMaxDistance`), which also filters their mail. `browseMaxDistance` is
  applied in **both directions**: INBOUND (the viewer only sees/receives posts within their
  own distance) and OUTBOUND (a post is only shown/mailed to people within the *poster's*
  distance of it - the author-side cap in `isochrone/message.go`'s `authorReachCapWhere` and
  the digest's `DistancePreferenceFilter::passesBothPreferences`).

  **The viewer's own posts always come first.** A member's own open posts are included in the
  feed regardless of reach (`isochrone/message.go` own-posts arm) and flagged with `mine`
  (`MessageSummary.Mine`, set when `messages.fromuser` = the viewer). The client
  (`composables/useMessageSort.js`) floats `mine` posts to the top of *every* sort order (New
  to you / Newest / Closest), newest-first, just below any paid pinned clearance. Without this,
  members lost their own posts among the reach-ordered feed and assumed they were not showing
  (Discourse 9933).

  The containment test itself is served through **sandwich bounds**
  (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md): the exact polygons are grid-fill
  isochrones averaging ~11k vertices / 178 KB, so the hot queries first consult two small
  derived polygons stored as SAME-ROW columns on `rippling_reach` — `outer_bound` (a
  verified superset, NOT NULL and spatially indexed: outside it = definitely out) and
  `inner_bound` (a verified subset, nullable: inside it = definitely in) — and only fetch
  the exact polygon for the thin band between them, always via a correlated `EXISTS`
  (MySQL's lazy BLOB fetch does not cross OR items). The browse feed and count DRIVE
  their R-tree from the small `outer_bound` index rather than the polygon's, so completed
  posts are pruned by the index itself. Same-row storage means every polygon write sets
  the bounds in the SAME statement (`ExpandService`, all four write paths) — no timing
  window can exist between polygon and bounds. One exception: MySQL error 1713 ("Undo
  log record is too big") is raised from the OLD values of the updated columns — both
  `polygon` and `outer_bound` are SPATIAL-indexed, so their previous geometries are
  undo-logged in full and can jointly overflow the 16KB undo page even when each alone
  fits. When that happens the advance is split into two statements — polygon+scalar
  fields first, bounds second (`ExpandService::advanceSplitForUndoLog`) — accepting a
  one-statement bounds lag for a post that otherwise never advances at all. A separate
  degree-scale `ST_Simplify` ladder (`storeWithUndoLogShrink`, with a `ST_Buffer(…,0)`
  repair) remains as a last-resort backstop; note the geometry columns hold lon/lat
  degrees despite their SRID 3857 tag, so simplify tolerances must be degree-scale.

  The `outer_bound` sentinel ladder every reader relies on: a real bound (cheap
  reject/accept work) > `ST_Envelope(polygon)` (derivation failed — the MBR still finds
  the row and the exact polygon decides) > a degenerate POINT (completed posts ONLY —
  pruned from the browse R-tree; consumers that still serve completed posts, i.e. the
  digest's "came and went" section and held replies, treat POINT as "no bounds info" and
  use the exact test). The bounds preferentially come from the routing server
  (`iznik-routing-go/bounds.go`): derived on the same rasterisation grid the exact
  polygon is traced from (morphological dilate/erode + simplification budgeted inside
  that margin, so superset/subset hold by construction), shipped as
  `catchment_outer`/`catchment_inner` on point-form `/v1/catchment`, and verified against
  the stored polygon on write (`Ripple\ReachBoundsService`); otherwise derived in SQL
  (`ST_Buffer(ST_Simplify(polygon, tol), ±tol)`, tol 0.002°). A provided inner is held to
  a **usefulness** bar as well as a correctness one
  (`ReachBoundsService::ensureUsefulInner`, `INNER_MIN_AREA_RATIO`): the routing grid's
  erosion disintegrates ribbon-shaped rural reaches and ships a town-core fragment
  covering 1–2% of the polygon, which verifies as a subset yet sends nearly every
  in-outer viewer to the full polygon test (the Aug 2026 db3 saturation — the band ran
  at ~58% against the designed 7–19%). An inner covering less than half the polygon's
  area — or missing altogether — is replaced by the SQL derivation from the stored
  polygon (~90% coverage); `ripple:backfill-inner-bounds` repairs rows written before
  this guard existed. A rejection clip that shrinks the polygon NULLs `inner_bound` in
  the same statement (`ClipReachForRejectedGroup`, `reapplyClips`), since a stale inner
  bound would keep showing the post in the just-rejected area; the sync that follows the
  clip then re-derives a safe inner from the clipped polygon.

  The single-point gates consult the same sandwich: `ReachQueryService::isWithinReach`
  (browse Nearby / reply-eligibility / held-reply release in batch), the message-list
  replyeligible probe and the chat reply hold (shared Go fragments in
  `rippling/reachbounds.go`). Being PK lookups they skip the MBR conjunct, and they
  treat POINT bounds as absent so completed posts still resolve against the exact
  polygon.

  Production migration: a plain ALTER cannot deliver this schema on the ~10 GB hot table
  (NOT NULL + SPATIAL index = tablespace rebuild under Galera TOI; verified on Percona
  8.0.43 that ALGORITHM=INSTANT refuses both), so `ripple:migrate-reach-bounds-schema`
  does a pt-online-schema-change-style shadow copy: full-schema shadow table, chunked
  resumable copy deriving bounds per row (completed posts get the POINT sentinel),
  `updated_at` delta catch-up, then an atomic RENAME swap. Dev/CI just run the Laravel
  migration (small tables).
- **Unified digest distance scoring:** the reach polygon feeds each post's closeness score.
- **Reach mail:** the join notification when a post ripples to within reach.
- **Held replies:** replies to rippled posts held for moderator Chat Review where applicable.
  One exception: a post's FIRST reply is not held when the replier is inside the reach the
  post will eventually have (`rippling_reach.max_polygon`). They were always going to be
  allowed to reply once the ripple got there, so holding them delays a poster who currently
  has nothing without protecting local-first ordering in any lasting way. See
  [first-reply.md](first-reply.md); gated by `freegle.firstreply.passthrough.enabled`, off by
  default.
- **Rippling Explorer (ModTools `/rippling`):** draws the exact polygon and tints groups from
  the per-tick `reachable_group_ids`.

### 7a. Relevance ranking (browse feed AND digest - same engine)

The browse/Nearby feed is **not** reverse-chronological. Both it and the unified digest order
posts by a **rippling relevance score**, computed by one shared function
(`isochrone/score.go` `Score()`; the Laravel `DigestPostScorer` mirrors it for mass mail and is
unit-tested against the Go reference values).

**Score** (per post, all terms in `[0,1]`):

    Total = W_close·close + W_fresh·fresh + W_budget·budget + W_anchor·anchor

- `close = 1 − distance/reachRadius` (clamped ≥0) - closeness. Uses the **blurred** great-circle
  distance, the *same* figure the card's distance badge and the unread-count distance filter use,
  so the viewer's slider and the server's ordering can't disagree. (Digest approximates drive-time
  as haversine÷reach because a per-recipient isochrone on mass mail is infeasible; the `/rippling`
  "Digest preview" uses real drive-time.)
- `fresh = 1 − ageHours/windowHours` (clamped ≥0).
- `budget = exp(−engagement / (budgetDecay/12))`, where `engagement = (views + 3·replies)/max(ageH,1)`
  - an **engagement-decay** term: the more a post has already been seen/replied to, the lower it
  ranks, spreading attention across posts.
- `anchor = 1` for a home-group post, else 0.

**Final browse order** (`isochrone/message.go`, `sort.SliceStable`): **pinned first** (a
`messages_pinned` row - paid bulk-offer clearances floated to the top), **then Score descending**,
**then arrival (newest)** as the tiebreak. The score is exposed as `MessageSummary.Score` and the
`nearby` store preserves that server order.

**Weights are per-consumer and env-tunable without a deploy** (defaults `close=1, fresh=0,
budget=1, anchor=0` for both today - closeness × engagement-decay):
- Browse: `RIPPLE_BROWSE_W_{CLOSE,FRESH,BUDGET,ANCHOR}`, `RIPPLE_BROWSE_WINDOW_HOURS`
  (`isochrone/score.go` `LoadScoreWeights`).
- Digest: `ripple.score.weights.*` / `RIPPLE_DIGEST_W_*`, `RIPPLE_DIGEST_WINDOW_HOURS`
  (`config/freegle.php`).

Design spec: `docs/superpowers/specs/2026-06-22-digest-rippling-score-ordering-design.md`.

## 8. Kill switches and key config (`config/freegle.php` `ripple.*`)

- `enabled` (`RIPPLE_ENABLED`) - master switch.
- `reachable_gate` - member-based reachability targeting (else polygon-only).
- `proximity_notes` - the "quicker to get to" moderator note (independent).
- `extent.*` (`target_users`, ...) - the audience governor.
- `reply_saturation_stop` (5), `hazard_hours`, `rippled_in_pending_hours` (0).

## 9. Data model

- `rippling_reach` - one row per active post: origin, current `polygon` (SRID 3857), the
  `outer_bound` / `inner_bound` sandwich columns the hot read queries consult before the
  exact polygon (see §7; `outer_bound` is NOT NULL + spatially indexed and drives the
  browse R-tree), cached slim `schedule` (per-tick drive-time / audience / reached-group
  ids, no geometry), `tick`, `status` (expanding / stopped / done / held),
  `reachable_group_ids` (the current tick's set, used by retraction). Bounds maintained
  in the same statements as the polygon writes; prod schema migrated via
  `ripple:migrate-reach-bounds-schema` (shadow copy + swap).
- `messages_groups.rippled_in = 1` - marks a rippled-in copy (vs the origin membership).
- `rippling_proximity` - cached "quicker to get to" P/Q points per (msgid, groupid).
- `logs` `text='Rippled'` - the ripple-join marker used for rejoin suppression.
- `memberships.rippled = 1` - marks a membership rippling created, when the member's own post
  rippled into that group and we auto-joined them (§5). Every statistic that asks "were they
  already a member?" must exclude these - see §10a.

## 10. The sysadmin analytics tab

One ModTools component (`modtools/components/ModSysAdminRipplingAnalytics.vue`) reads three
apiv2 surfaces, deliberately split so no single request can exceed the production gateway's
timeout:

- `/rippling/analytics` (`rippling/analytics.go`) - the KPIs, trends and "is rippling helping?"
  section. Every query anchors on `rippling_reach` (one row per rippled post, bounded by
  `created_at`), which is what keeps it selective.

  The trend series use **fixed accrual horizons** measured from `rippling_reach.created_at`:
  replies and mean-replies within 36 hours (`ReplyHorizonHours`), taken within 14 days
  (`TakenHorizonDays`). Counting replies/takes *ever* made recent days - which simply hadn't
  finished accruing - draw as a steep mechanical decline (taken outcomes are marked a mean of
  ~19 days after the post enters rippling, so the taken-ever line fell to near zero over the
  trailing fortnight regardless of reality). Each trend row also carries `replied_mature` /
  `taken_mature` flags - false until the day's whole horizon has elapsed - which the component
  renders through a Google Charts `certainty` role, so a still-provisional tail draws dashed
  rather than as a decline. The clock is reach creation, not `messages_groups.arrival`, because
  autorepost bumps `arrival` forward, silently granting older posts longer windows.
- `/rippling/metrics` (`rippling/metrics.go`) - reply attribution channels, geographic hotspots,
  held-reply friction. Small rippling-owned tables only, plus the live-capture boundary date,
  which is cached per process because its query ORs three unindexed nullable columns and so
  full-scans `rippling_reply_attribution`.
- `/rippling/analytics/drivetime[/score|/aggregate]` - the sampled routing pass, driven from the
  client one chunk at a time.

**A gateway timeout here does not look like a timeout.** The 504 carries no
`Access-Control-Allow-Origin` header, so the browser reports a CORS policy error and the real
cause (slow SQL) is invisible from the console. Two rules follow, both learned the hard way:

1. Never add a query that scans `messages_groups` or `chat_messages` over the dashboard's window
   to these endpoints. Rippling now writes 5-8k `rippled_in` rows a day, so ~75% of a 30-day
   `messages_groups` slice is rippled-in rows: per-day reply-rate / taken-rate / distance KPIs
   built that way measured 40-190s **each** on production. Anchor on `rippling_reach` instead, or
   drive the work from the client in chunks.
2. Bound the DB work with a deadline, not just the request context. fasthttp closes
   `RequestCtx.Done()` only when the **server** shuts down - never on a client disconnect - so a
   browser or gateway that gives up cancels nothing, and each retry stacks another full set of
   queries on top of the ones still running. `/rippling/metrics` therefore wraps the request
   context in a 20s `context.WithTimeout` and names any section that hits it in a `degraded`
   array, so "we gave up" reaches the dashboard instead of rendering as "no data".

The component loads the three surfaces independently: a failure or delay in one fills in its own
panels late (or reports its own error there) rather than blanking the tab.

### 10a. "Was this replier already a member?" - and why ripple-created joins don't count

Almost every effectiveness figure on the tab turns on one test: was the replier an **established
member of an origin group** of the post? If yes the reply is `home` - they'd have seen it anyway,
rippling gets no credit. If no, rippling reached them. That single test drives the rippled-reply
and rippled-taker shares, the reply→take comparison, and the **rescue floor** (posts taken with no
home-group reply at all - the takes that would otherwise have gone nowhere).

The test has three qualifiers, all load-bearing, and it lives in one place -
`rippling.EstablishedOriginMemberExists` in `rippling/attribution.go`:

- **origin groups only** (`messages_groups.rippled_in = 0`) - being in a group the post *rippled
  into* is not being local to it,
- **joined before the post arrived** - the reply flow joins people to groups in order to reply, so
  a join made seconds ago is not evidence of anything,
- **not a ripple-created join** (`memberships.rippled = 0`) - rippling auto-joins a poster to every
  group their post rippled into (§5), so a frequent poster accumulates memberships of distant
  groups purely as a side-effect of rippling. When one of those groups later hosts a post of its
  own, that member is only there to see it *because* of an earlier ripple.

The third qualifier was missing until August 2026, and it mattered: on production 92k memberships
carry `rippled = 1`, and ~7% of all replies scored `home` were backed by nothing else. Rippling's
own knock-on reach was being counted in the column that means "rippling had nothing to do with
this", so every effectiveness figure on the tab read low.

Those replies now have their own attribution channel, `ripple_join`, one rung below `ripple_group`
in the ladder (`rippling.DeriveAttribution`) - both are membership-level exposure that exists
because of a ripple. It carries no "did this post ripple?" guard, unlike `ripple_reach`: the ripple
that earns the credit already happened, to a different post, and left the membership behind as its
record. The evidence bit is frozen per reply in `rippling_reply_attribution.was_ripple_join`, and
`ripple:backfill-reply-attribution` reconstructs it for older rows - re-reading a frozen
`was_home_member` bit as `ripple_join` where the surviving membership shows that provenance, while
leaving rows whose membership has since decayed away on their original answer.
