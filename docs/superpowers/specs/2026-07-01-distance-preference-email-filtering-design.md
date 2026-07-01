# Distance preference email filtering (immediate + digest)

Date: 2026-07-01
Branch: `fix/popular-posts-ripple-aware`
Status: design for review — **no code changes in this pass**

## Problem / goal

The Nearby browse feature (see the companion design,
`docs/superpowers/specs/2026-07-01-nearby-browse-relevance-ordering-and-distance-slider-design.md`)
gives members a distance slider, stored as `settings.browseMaxDistance` (miles; absent or the
sentinel `Number.MAX_SAFE_INTEGER` = "no limit"). That spec explicitly deferred applying the
same preference to **emails**: "Applying the distance preference to digest emails... is
persisted so a later phase can read it server-side... no email change is made now" (its
Non-goals section) and lists it again under Deferred/future.

This document designs that later phase: a member who has pulled their distance slider in
(e.g. to 2 miles) should not be **emailed** about posts further away than that, whether the
email is an immediate per-post notification or the rolled-up digest. This is a pure
**narrowing** filter layered on top of the existing group-membership / rippling-reach
selection — it can only remove candidates, never add ones the existing logic wouldn't
already have selected.

## Investigation: the three member-notification pipelines

All member-facing post emails run through `iznik-batch`'s `UnifiedDigestService`
(`iznik-batch/app/Services/UnifiedDigestService.php`, 1731 lines) and its mailable
`App\Mail\Digest\UnifiedDigest`. There are, in practice, **three** distinct pipelines, not two
— the "immediate" pipeline described in the task actually splits into a cursor path (plain,
non-rippled group posts) and a reach-mail path (rippled-in posts). All three ultimately spool
a `UnifiedDigest` mailable via `EmailSpoolerService`.

### 1. Daily digest (scored) — `MODE_DAILY`

Entry: `sendDigests('daily')` → the generic per-user loop (`UnifiedDigestService.php:74-115`)
→ `sendDigestToUser($user, 'daily', ...)` (`:1020-1137`).

- `getPostsForUser($user, $tracker, 'daily')` (`:1204-1268`) pulls every new post since the
  user's daily cursor across all the user's periodic-cadence groups, with two important
  existing gates:
  - a **reach gate** (`:1252-1265`): if the member's location resolves, any post that has a
    `rippling_reach` row is excluded unless `ST_Contains(rr.polygon, viewer point) = 1` — i.e.
    daily-mode members are not told about a post that hasn't rippled to them yet.
  - member location is resolved by `resolveUserLatLng($user)` (`:1277-1296`):
    `settings.mylocation` (both coords) if present, else `locations` row for
    `users.lastlocation`. This is the **canonical** "where is this member" resolver already
    shared by the reach gate and the scorer.
- Back in `sendDigestToUser` (`:1043-1051`), live (no-outcome) posts are, **daily mode only**,
  passed to `scoreAndSortAvailable($posts, $latlng)` (`:1371-1417`), which for each post:
  - computes `reachRadiusMetres($msgid)` (`:1314-1361`, cached per run) — the post's reach
    polygon extent, or a configured default (`freegle.ripple.score.default_reach_metres`,
    `config/freegle.php:378`) when there's no reach row.
  - computes `$dist = haversineMetres($latlng[0], $latlng[1], $post->lat, $post->lng)`
    (`:1389-1394`, `messages.lat/lng` — **real, unblurred** coordinates) — the recipient→post
    distance, **in metres**, stashed as `$post->_dist` on the model.
  - feeds `$dist`, `$reachRadiusMetres`, age, views/replies into `DigestPostScorer::score()`
    (`app/Services/Ripple/DigestPostScorer.php`, mirrors `iznik-routing-go/digest_simulator.go`)
    to get a relevance score, then sorts by score desc and pins the two nearest
    (`pinClosestTwo`, `:1424-1433`).
- Posts are then deduplicated (`deduplicatePosts`, `:1464-1508`) and mailed as one rolled-up
  `UnifiedDigest` (`:1126-1134`).

**Important shared-code hazard**: `getPostsForUser(MODE_DAILY)` is **also** called directly by
the daily push-notification command,
`app/Console/Commands/Push/SendDailyPostsPushCommand.php:238`
(`$digestService->getPostsForUser($user, $tracker, UnifiedDigestService::MODE_DAILY)`), to
build the push notification's candidate list. **Any distance filter added inside
`getPostsForUser` would silently also filter push notifications**, which this task explicitly
scopes to email only. `scoreAndSortAvailable`, by contrast, is called **only** from
`sendDigestToUser` (the email path) — it is the safe insertion point for the daily digest (see
"Insertion points" below).

### 2. Immediate — cursor path (plain, non-rippled posts) — `MODE_IMMEDIATE`

Entry: `sendDigests('immediate')` → `sendImmediateDigests(...)` (`:141-224`) → per group,
`processGroupImmediate($cursorRow, ...)` (`:231-428`).

- `getGroupMessagesSinceCursor($groupid, ...)` (`:665-712`) fetches new messages for the group
  since its `groups_digests` cursor, **explicitly excluding any message that has a
  `rippling_reach` row** (`:678-685`, `whereNotExists(... from rippling_reach ...)`) — those are
  mailed by the reach-mail path instead, so the two paths can't double-mail.
- Recipients are the group's `emailfrequency = -1` members (`:257-266`), loaded once
  (`:292-295`).
- For each message, for each recipient (`:302-397`), the mailable is spooled directly — **there
  is no location or distance concept anywhere in this path today**. It doesn't need one
  currently because these are always non-rippled posts (typically recent posts, or posts on
  groups where rippling hasn't run yet); the reach gate doesn't apply because there's no reach
  row. Adding a distance filter here requires **new** plumbing: resolving each recipient's
  latlng (reuse `resolveUserLatLng`) and computing distance to `$message->lat/lng` — real
  columns already on the row (`messages.*` selected at `:667-671`).
- Test coverage confirms today's behaviour: a poster **does** get immediately mailed about
  their own post (`UnifiedDigestServiceTest::test_immediate_includes_poster_own_post`, `:1268`)
  — any new filter must preserve this.

### 3. Immediate — reach-mail path (rippled-in posts) — `MODE_REACH`

Entry: `sendDigests('reach')` → `sendReachDigests(...)` (`:497-541`), a decoupled, sharded pass
over `rippling_reach` rows whose reach changed recently, calling
`mailNewlyReachedForPost($msgid, ...)` (`:554-663`) once per post.

- The recipient query (`:567-595`) is a single raw SQL statement: members of a group the post
  is Approved on, at `emailfrequency = -1`, whose resolved point (again mylocation-else-
  lastlocation, expressed inline via `JSON_EXTRACT`/`CASE`, `:582-589`) is
  `ST_Contains`-covered by the post's reach polygon, who haven't already been notified
  (`rippling_reach_notified` ledger, `:591-593`).
- **No distance computation exists here at all** — only polygon containment. This is the
  pipeline the task description ("a member being emailed about a newly-arrived/rippled-in
  nearby post") is most directly about. The candidate recipient set is already narrow (bounded
  by "inside this one post's reach polygon", not the whole userbase), so adding a per-recipient
  distance check here is cheap regardless of whether it's done in SQL or after fetching.
- The ledger write (`:636-640`) marks a user "notified" for this msgid so they're never
  re-considered even on a later tick. This matters for the design decision below (§ Edge
  cases → ledger semantics).

### Distance precedent already in the codebase

Two existing facts materially shape the "real vs blurred" decision:

1. **The digest email already shows a real, unblurred distance to the recipient in its own
   body.** `UnifiedDigest::preparePosts()` (`app/Mail/Digest/UnifiedDigest.php:808-844`)
   resolves the user's point from `users.lastlocation` only (**not** `settings.mylocation` —
   a pre-existing minor inconsistency with `resolveUserLatLng`, noted but out of scope here),
   and `prepareCard()` (`:1001-1006`) computes
   `haversineDistance($userLat, $userLng, $message->lat, $message->lng)` — **real**
   coordinates — rendering `"< 1 mile"` or `"N miles"` directly in the card. This same
   `haversineDistance` (`:1210-1219`, miles) is a near-duplicate of
   `UnifiedDigestService::haversineMetres` (`:1440-1449`, metres) — two independent
   implementations of the same formula, worth consolidating into the shared helper this design
   proposes (see § Shared helper).
2. **A PHP mirror of the Go blur function already exists**, but it's private and scoped to a
   different purpose. `ExpandService::blurOrigin()`
   (`app/Services/Ripple/ExpandService.php:1594-1606`) blurs a post's origin by `BLUR_USER =
   400` metres (`:27-28`) before it drives reach-polygon growth, using the same deterministic
   direction formula and fallback (`dir = (lat*1000 + lng*1000) % 360`, Dunsop Bridge for
   invalid coordinates) as `iznik-server-go/utils/utils.go:249-264` (`func Blur`). Two small,
   pre-existing divergences from the Go version, neither a blocker at mile-scale but worth
   flagging if this path is chosen: (a) PHP rounds to 4 dp, Go to 3 dp
   (`utils.go:263: math.Round(dlat*1000)/1000`); (b) PHP's `App\Support\GreatCircle` uses a
   fixed-metres-per-degree spherical approximation, whereas Go's `geodesic.WGS84.Direct` is a
   full WGS84 ellipsoid geodesic — sub-metre to low-single-digit-metre differences, invisible
   at a 0.5-mile slider granularity.

The on-site feature itself is **already fully implemented** in Go
(`iznik-server-go/isochrone/message.go`): `BrowseDistanceUnlimited = 9007199254740991` (`:22`,
matching `Number.MAX_SAFE_INTEGER` and the client's `BROWSE_DISTANCE_UNLIMITED` in
`iznik-nuxt3/constants.js:68`), `resolveMaxDistance` (`:366-384`, query param, else
`settings.browseMaxDistance`, else sentinel), and `blurredDistanceMiles` (`:60-64`, the single
source of "how far away is this post" for both the feed's exposed `distance` field and the
count endpoint's filter). This is the reference implementation to stay consistent with if the
blurred option is chosen.

## Distance basis decision: real vs blurred

**Recommendation: use real (unblurred) distance for the email filter.** Reasoning:

- **It's already what the email shows.** The digest card already prints a real-distance
  "N miles away" line (`UnifiedDigest.php:1004-1005`). If the *filter* used blurred distance
  while the *displayed text in the same email* used real distance, a member could receive an
  email whose own body says "3 miles away" despite them having set a 2-mile limit (blurred
  distance happened to be ≤ 2 while real was > 2, or vice versa near the boundary) — a visible,
  self-contradicting inconsistency inside one email. Using real distance for both the filter
  and the display text they already see makes the email internally consistent by
  construction.
- **No privacy exposure is created.** Blur exists to stop a *displayed number* being precise
  enough to triangulate a poster's home. A filter is a binary "did I get mailed or not"
  signal — it leaks far less positional information than a displayed figure, so the blur
  rationale doesn't really transfer to this use case.
- **It's cheaper and simpler.** The daily digest already computes a real haversine distance per
  post for scoring (`scoreAndSortAvailable`'s `$dist`, metres) — reusing that (converted to
  miles) costs nothing extra. Going blurred would require calling (or newly extracting) the
  blur transform for every candidate, for no behavioural benefit here.
- **Trade-off, honestly stated**: it means the email's "am I close enough" decision won't be
  byte-for-byte identical to the on-site slider's blurred-distance decision at the margin (the
  blur offset is ~400 m ≈ 0.25 mile). A post could very rarely appear in the browse feed
  (blurred distance ≤ limit) but not generate/continue an email (real distance > limit), or
  the reverse. Given the slider's own granularity is 0.5 mile and its purpose is a coarse
  "how far is too far" preference rather than a precise contract, this seems an acceptable,
  minor inconsistency — but it is a genuine judgment call, not a slam dunk, so it's listed
  again under Open questions.

If the user prefers strict on-site parity instead, the **blurred** alternative is fully
speced: extract `ExpandService::blurOrigin`'s logic (or better, the Go `utils.Blur` values) into
a small shared, unit-tested PHP function (mirroring the existing `DigestPostScorer` pattern of
"pure function unit-tested against Go reference values"), and use it — plus the same
`blurredDistanceMiles`-style haversine — everywhere this design otherwise says "real distance."
The insertion points below are identical either way; only the distance computation swaps.

## Setting read / sentinel handling

`settings.browseMaxDistance` lives in `users.settings` (JSON). The Laravel `User` model already
casts `settings` to `array` (`app/Models/User.php:162`), so it's `$user->settings['browseMaxDistance']
?? null` — no JSON decoding needed (contrast with the Go side, which parses a raw string via
`JSON_EXTRACT` because it runs outside the ORM's cast).

Rule (identical across all three pipelines and matching the Go/client sentinel exactly):

- Absent, `null`, non-numeric, `<= 0`, or `>=` the sentinel → **no limit**: skip the filter
  entirely (fastest path, matches every member who has never touched the slider — the default,
  overwhelming majority).
- A finite value `> 0` and `<` the sentinel → filter candidates to `distanceMiles <=
  browseMaxDistance` (subject to the exceptions below).

The sentinel constant itself (`9007199254740991` / `Number.MAX_SAFE_INTEGER`) must be kept
identical across three codebases: `iznik-nuxt3/constants.js:68`
(`BROWSE_DISTANCE_UNLIMITED`), `iznik-server-go/isochrone/message.go:22`
(`BrowseDistanceUnlimited`), and the new PHP constant this design adds. Recommend a PHP class
constant on the new shared helper (see below), named the same for grep-ability, e.g.
`BROWSE_DISTANCE_UNLIMITED`.

## Insertion points (per pipeline)

All three insertion points apply the filter as a pure **narrowing** step layered strictly
*after* the existing group-membership / reach-gate / polygon-containment selection has already
run — never as an alternative selection mechanism. When the sentinel is in effect, every
pipeline's behaviour is byte-for-byte unchanged from today.

### A. Daily digest — filter in `sendDigestToUser`, not `getPostsForUser`

Do **not** touch `getPostsForUser` (shared with the push command, § Investigation #1). Instead,
add the filter as a new step in `sendDigestToUser` (`:1020-1137`), applied to the `$posts`
collection **after** `scoreAndSortAvailable` (`:1049-1051`) and **before** `deduplicatePosts`
(`:1068`) — i.e. filtering happens on individual candidate rows at the same granularity the
scorer already operates on, consistent with the existing comment that "dedup runs after, so the
kept cross-post representative is the top-scoring one" (`:1046-1048`); the same reasoning
extends to "the kept representative is also the one within range."

Do not rely on `scoreAndSortAvailable`'s internal `$post->_dist` side value — it's only set
when the method doesn't early-return (it returns early, with no scoring/distance done at all,
when there's no resolvable location or fewer than two posts, `:1373-1375`). The new filter step
should independently resolve `resolveUserLatLng($user)` (already-cached-per-user cost) and
compute its own real haversine distance per post so it behaves consistently regardless of the
scorer's short-circuit — a small, self-contained step rather than a fragile dependency on a
scoring side-effect.

Exceptions (bypass the filter, post always kept): `$post->fromuser === $user->id` (own post);
no resolvable member location (fail-open, matches the existing reach-gate and scorer
precedent of "skip when we can't resolve — no regression for locationless members",
`:1256-1257` and `:1368-1369`).

### B. Immediate — cursor path — filter in `processGroupImmediate`

Add the filter inside `processGroupImmediate` (`:231-428`), in the existing nested loop
`foreach ($messages as $message) { foreach ($users as $uid => $user) { ... } }` (`:302-397`),
immediately before the `spool()` call (`:333-364`) — skip spooling (but still count the message
as processed for cursor-advance purposes; see below) when the filter says no.

This path currently has **no** location resolution at all, so it needs new plumbing:
- Resolve each recipient's latlng **once**, before the message loop (cache per `$uid`, reusing
  `resolveUserLatLng`), not once per message — the `$users` collection is already built once
  per group (`:292-295`), so this is a single extra pass over a small collection, not a
  per-message cost.
- `messages.lat/lng` are already on each `$message` row (`messages.*` selected at
  `getGroupMessagesSinceCursor:667`).

Cursor semantics must not change: `advanceGroupCursor` (`:754-767`) is called once per group,
based on the **last message considered**, independent of which individual recipients actually
received it (exactly as the existing allowlist gate already behaves — filtering a recipient
out doesn't stall the cursor). No change needed there; just don't spool for a filtered-out
(message, recipient) pair.

Exception: preserve `test_immediate_includes_poster_own_post` (`:1268`) — bypass the filter
when `$message->fromuser === $uid`.

### C. Immediate — reach-mail path — filter in `mailNewlyReachedForPost`

Add the filter in `mailNewlyReachedForPost` (`:554-663`). Recommended shape: keep the existing
raw-SQL recipient query's `ST_Contains` reach-membership selection exactly as is (it's the
correctness-critical "is this post even reachable" gate — never touch that), but extend the
`SELECT` to also return each candidate's resolved point (it's already computed inline per row
for the `ST_Contains` argument, `:581-589` — just also project it as columns) alongside `u.id`.
Then, in the existing `foreach ($users as $user)` loop (`:621-647`), before spooling: compute
real haversine distance from the returned point to `$msg->lat/$msg->lng` (`$msg` is already
loaded, `:561`), resolve `$user->settings['browseMaxDistance']`, and skip the spool (**and skip
the `rippling_reach_notified` ledger write**, see Edge cases below) when out of range.

Doing the comparison in PHP (reusing the shared helper, § below) rather than folding a second
distance formula into the SQL keeps exactly one implementation of "is this post within this
member's preference", avoiding the exact class of drift bug `isochrone/message.go`'s own
comments call out ("two independently-written distance calcs drifting at the boundary",
`message.go:58-59`). This is affordable because the candidate set here is already bounded by
reach-polygon containment (typically low hundreds of members per post at most), not the whole
userbase — unlike the browse count endpoint, which had to worry about scanning many more
candidates and so cares more about doing it in one SQL pass.

Exception: bypass the filter when `$user->id === $msg->fromuser` (own post) — mirrors the
cursor path's existing own-post behaviour, even though a poster is usually inside their own
post's reach trivially (their own `settings.mylocation`/`lastlocation` may still differ from
the post's origin, e.g. they posted from work), so an explicit bypass, not an assumption of
~0 distance, is required — exactly the same reasoning the parent browse design uses for "own
posts always included regardless of distance" (parent spec, Edge cases).

## Shared distance-filter helper

**Yes — one shared helper**, used by all three insertion points, analogous to the existing
`App\Services\Ripple\DigestPostScorer` (small, dependency-free, container-instantiated,
unit-testable in isolation). Proposed shape (described, not coded):

- A new class, e.g. `App\Services\Ripple\DistancePreferenceFilter`, alongside
  `DigestPostScorer` in the same `Ripple` namespace.
- `const DISTANCE_UNLIMITED = 9007199254740991;` — identical value to the Go/JS sentinel.
- `maxDistanceMiles(User $user): float` — reads `$user->settings['browseMaxDistance'] ?? null`,
  returns `self::DISTANCE_UNLIMITED` for absent/invalid/`>=` sentinel, else the numeric value.
  This centralises the sentinel logic so a future change to it (or to where the setting lives)
  only touches one place.
- `passes(float $distanceMiles, float $maxDistanceMiles, bool $isOwnPost): bool` — `$isOwnPost
  || $maxDistanceMiles >= self::DISTANCE_UNLIMITED || $distanceMiles <= $maxDistanceMiles`.
- Optionally absorb the two near-duplicate haversine implementations noted in § Distance
  precedent (`UnifiedDigestService::haversineMetres` and `UnifiedDigest::haversineDistance`)
  into one canonical `distanceMiles(lat1,lng1,lat2,lng2)` on this class, called by both the
  filter and (as a secondary, optional cleanup) the digest card's displayed-distance text —
  not required for this feature to work, but removes a pre-existing duplication while touching
  the same code.

All three call sites (`sendDigestToUser`'s new post-score filter step, `processGroupImmediate`,
`mailNewlyReachedForPost`) call `maxDistanceMiles($user)` once per user and `passes(...)` once
per candidate post. This keeps the "is this within range" decision in exactly one place, unit
tested once, rather than three bespoke inline comparisons that could drift.

## Edge cases

- **Member with no known location**: every insertion point must fail open (matches existing
  precedent at `getPostsForUser`, `scoreAndSortAvailable`, and the reach-mail SQL's `LEFT JOIN
  locations`/`lastlocation` fallback) — if neither `settings.mylocation` nor
  `lastlocation` resolves, skip the filter for that member entirely (send as today); do not
  suppress mail just because distance can't be computed.
- **Own posts**: always bypass the filter (`fromuser === recipient id`), matching the parent
  browse design's "own posts always included regardless of distance" and the existing tested
  behaviour that a poster gets immediately mailed their own post
  (`UnifiedDigestServiceTest::test_immediate_includes_poster_own_post:1268`,
  `test_digest_includes_users_own_posts:398`). Do not rely on "distance is trivially ~0" — a
  poster's resolved location can differ from the post's origin (e.g. posted from a different
  address than home), so this must be an explicit id comparison, not an emergent property of
  small distance.
- **Interaction with the reach gate**: strictly additive/narrowing. The reach gate (does this
  post's polygon cover the member at all?) and group-membership checks always run first and
  are unchanged; the distance filter only removes candidates that already passed those checks.
  Never widen — a post outside the member's groups or outside rippling reach must never become
  visible via email merely because it happens to be geographically close.
  Members who never touch the slider (the overwhelming majority — `browseMaxDistance` absent)
  see **zero** behavioural change; this is guaranteed structurally by the sentinel short-circuit
  in `maxDistanceMiles`/`passes`, not by convention.
- **Reach-mail ledger semantics (a genuine open decision)**: when a candidate is filtered out
  by distance in `mailNewlyReachedForPost`, should the `rippling_reach_notified` row still be
  written? Recommendation: **no** — only write the ledger when mail is actually sent, so that if
  the member widens their slider (or their resolved location changes) while the post is still
  inside the reach-mail recency window (`freegle.ripple.reach_mail_window_minutes`, default 60
  min, `UnifiedDigestService.php:509`), a later tick can still notify them. Once the window
  closes the post drops out of `sendReachDigests`'s candidate query regardless of ledger state
  (`:511-513`), so the cost of not writing the ledger for a filtered skip is bounded to that
  window — cheap, and avoids ever needing "was this skip a real send or a filter skip" ledger
  semantics. Flagged again under Open questions since a reasonable person could prefer the
  simpler "always write the ledger" rule to avoid re-checking the same (post, user) pair every
  tick within the window.
- **Performance**:
  - Daily digest: effectively free — distance is (or, per this design, an equivalent
    independent computation is) already computed per candidate post for scoring; adding a
    comparison is O(1) extra per post, no new query.
  - Reach-mail: bounded by the reach-polygon-contained recipient count for one post at a time
    (already small by construction), one extra haversine + comparison per recipient — no new
    query round-trips if the recipient's point is projected in the existing SQL (§ insertion
    point C).
  - Cursor immediate: one extra latlng resolution per recipient **once per group per tick**
    (not per message), plus one haversine per (message, recipient) pair within that group's
    batch (capped at 500 messages, `getGroupMessagesSinceCursor:707-709`, and typically far
    fewer) — negligible.
  - None of the three pipelines needs a new index or schema change; all filtering happens in
    PHP over already-fetched/bounded candidate sets.

## Testing strategy (for the future implementation — not run now)

Follow the existing repo pattern of pure, dependency-free unit tests for scoring/filter logic
(`tests/Unit/Services/Ripple/DigestPostScorerTest.php` is the template: bare `new
DigestPostScorer()`, table-driven assertions against known inputs, no DB).

- **`DistancePreferenceFilterTest`** (new, mirrors `DigestPostScorerTest`'s style):
  `maxDistanceMiles` returns the sentinel for null/absent/zero/negative/`>=`sentinel settings,
  and the real value otherwise; `passes` — within range, at the exact boundary (`<=`, not `<`),
  beyond range, sentinel always passes, `isOwnPost` always passes regardless of distance.
- **`UnifiedDigestServiceTest`** (extend the existing file): 
  - Daily: a member with `browseMaxDistance` set receives only in-range live posts in the
    rolled-up digest; a post beyond range is silently dropped (not just re-ordered); the
    member's own distant post still appears; a member with no location gets the unfiltered
    set; a member with the sentinel/absent setting is unaffected (regression check against
    today's behaviour — the majority case).
  - Cursor immediate (`processGroupImmediate`): a distance-limited member in a large/rural
    group does not get mailed a far post that an unlimited member in the same group does; the
    group cursor still advances past the message even when every recipient in this test is
    filtered out (cursor correctness must not regress); the poster still gets their own far
    post.
  - Reach-mail (`mailNewlyReachedForPost`): mirror
    `test_mail_newly_reached_reach_gates_then_picks_up_later_reached_on_rerun` (`:932`) with an
    added distance-limited member who is inside the reach polygon but beyond their personal
    limit — confirm no mail and no ledger write, then (per the recommended ledger decision)
    confirm a re-run **after** widening their `browseMaxDistance` does mail them, while still
    inside the reach-mail window.
  - A **regression test that nothing changes when `browseMaxDistance` is absent/sentinel**
    across all three pipelines — this is the majority-case safety net given the setting
    defaults to unlimited for every existing member.
- **Cross-pipeline consistency test**: same post, same member, same real distance — assert the
  three pipelines' filter decisions agree (they should, since all three call the one shared
  helper) — guards against a future edit to one call site silently diverging from the others.
- **Explicit non-regression check for the push command**: a test asserting
  `SendDailyPostsPushCommand`'s candidate list (via `getPostsForUser`) is **unaffected** by a
  member's `browseMaxDistance` — proves the insertion point choice (§ A) actually kept push
  notifications out of scope, not just documentation asserting it.
- If the **blurred** alternative is chosen instead of real distance (§ Distance basis
  decision), add a scenario at the real/blurred boundary (a post whose real distance and
  blurred distance straddle the configured limit) and assert the filter's decision is driven
  by whichever basis was chosen, matching what the on-site slider would show for the same
  post/member if that's the parity goal.
- Manual/integration sanity, once implemented: run `mail:digest:unified --mode=immediate
  --dry-run --user=<id>` and `--mode=daily --dry-run --user=<id>` for a member with a tight
  `browseMaxDistance` against a realistic local dataset and eyeball the candidate list before
  wiring real sends.

## Open questions / decisions for the user

1. **Real vs blurred distance** (§ Distance basis decision): this design recommends **real**
   distance, primarily because the digest already displays a real distance in the same email
   and using the same basis for filtering keeps that email internally consistent. Confirm, or
   ask for the blurred/on-site-parity alternative (fully speced above as a drop-in
   replacement for the distance computation only).
2. **Reach-mail ledger semantics on a filtered skip** (§ Edge cases): recommended not to write
   `rippling_reach_notified` on a distance-filtered skip (so a later slider change can still
   catch it within the recency window). Confirm, or prefer the simpler "always write the
   ledger" rule.
3. **Scope of the cursor-path (non-rippled) filter**: given rippling is now live for the large
   majority of groups/posts (per the rippling rollout status), the cursor path increasingly
   only covers backlog/edge-case posts. Is it worth the new plumbing (§ insertion point B) for
   that shrinking case, or should this phase ship reach-mail + daily only, with the cursor path
   picked up later if it turns out to matter in practice? This design includes it for
   completeness/consistency, but it's the lowest-value, highest-relative-effort of the three.
  4. **Should the daily *push* notification (`SendDailyPostsPushCommand`) eventually honour the
     same preference?** Explicitly out of scope per the task ("for EMAILS"), and this design
     deliberately chooses an insertion point that avoids touching it as a side effect (§ A). If
     the member's expectation is "don't tell me about far-away stuff" full stop, a symmetrical
     push change would likely be wanted eventually — flagging so it isn't forgotten, not
     recommending it now.
5. **Rollout safety gate**: unlike the immediate/daily digest features themselves (which shipped
   behind `FREEGLE_DIGEST_IMMEDIATE_ALLOWLIST` / `FREEGLE_DIGEST_DAILY_ALLOWLIST`,
   `config/freegle.php:154-174`), this feature is inherently member-opt-in (sentinel default =
   unlimited = no behavioural change for anyone who hasn't touched the slider), so a separate
   global allowlist/kill-switch may be unnecessary. Worth confirming whether an env-level kill
   switch is still wanted for extra safety (cheap to add given the shared helper is a single
   choke point), or whether the sentinel default is judged sufficient protection on its own.
