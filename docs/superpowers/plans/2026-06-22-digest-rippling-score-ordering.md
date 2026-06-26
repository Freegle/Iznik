# Rippling score-ordering in the unified digest — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Order the unified daily digest's live posts by the `/rippling` digest-preview score (closeness + freshness + underexposure + home-group anchor) instead of strictly by arrival time.

**Architecture:** A new pure `DigestPostScorer` class mirrors `iznik-routing-go/digest_simulator.go::scoreDigestPost`. `UnifiedDigestService` fetches engagement counts + each post's reach radius (cached per run), scores every available post against the recipient's location using a haversine **approximation** of drive-time, then sorts available posts by score descending before dedup. Completed posts and immediate mode stay chronological. Weights/window/decay are config-tunable under `freegle.ripple.score`.

**Tech Stack:** Laravel 12 / PHP 8.3, PHPUnit (`iznik_batch_test` DB), MySQL spatial (`rippling_reach.polygon`, SRID 3857). Tests run via `docker exec freegle-batch php artisan test`.

**Reference (source of truth):** `iznik-routing-go/digest_simulator.go` lines 126-243 (query + sort) and 407-436 (`scoreDigestPost`).

---

## File Structure

- **Create** `iznik-batch/app/Services/Ripple/DigestPostScorer.php` — pure scoring class (no DB/I/O). One responsibility: turn post features + weights into component + total scores.
- **Create** `iznik-batch/tests/Unit/Services/Ripple/DigestPostScorerTest.php` — unit tests pinned to the Go reference values.
- **Modify** `iznik-batch/config/freegle.php` — add `score` sub-block inside the existing `ripple` array.
- **Modify** `iznik-batch/app/Services/UnifiedDigestService.php`:
  - `getPostsForUser()` (~1125-1159): add `views`/`replies` select subqueries.
  - new private `reachRadiusMetres(int $msgid): float` + a per-run cache property.
  - new private `scoreAndSortAvailable(Collection $posts, array $latlng): Collection`.
  - `sendDigestToUser()` (~949): score+sort the `available` collection (daily mode only) before dedup.
- **Modify** `iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php` — ordering tests.

---

## Task 1: `DigestPostScorer` pure scoring class

**Files:**
- Create: `iznik-batch/app/Services/Ripple/DigestPostScorer.php`
- Test: `iznik-batch/tests/Unit/Services/Ripple/DigestPostScorerTest.php`

The Go reference (`scoreDigestPost`) computes, with `maxMinutes=30, windowH=24, budgetDecay=25, weights {close:1,fresh:0,budget:1,anchor:0}`:
```
close  = clamp(1 - driveMin/maxMinutes, 0, 1)
fresh  = clamp(1 - ageH/windowH, 0, 1)
budget = exp( -(views+3*replies)/max(ageH,1) / (budgetDecay/12) )
anchor = homeGroup ? 1 : 0
total  = wClose*close + wFresh*fresh + wBudget*budget + wAnchor*anchor
```
Here `close` substitutes `distanceMetres/reachRadius` for `driveMin/maxMinutes` (the haversine perf approximation).

- [ ] **Step 1: Write the failing test**

Create `iznik-batch/tests/Unit/Services/Ripple/DigestPostScorerTest.php`:

```php
<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\DigestPostScorer;
use Tests\TestCase;

class DigestPostScorerTest extends TestCase
{
    private array $weights = ['close' => 1.0, 'fresh' => 0.0, 'budget' => 1.0, 'anchor' => 0.0];
    private array $env = ['window_hours' => 24.0, 'budget_decay' => 25.0];

    private function scorer(): DigestPostScorer
    {
        return new DigestPostScorer();
    }

    public function test_close_term_is_one_at_origin_and_clamps_to_zero_beyond_reach(): void
    {
        $s = $this->scorer();

        // At the post origin: distance 0 => close 1.0
        $atOrigin = $s->score(0.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(1.0, $atOrigin['close'], 1e-9);

        // Beyond the reach radius: close clamps to 0 (never negative).
        $beyond = $s->score(2000.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $beyond['close']);
    }

    public function test_fresh_term_decays_linearly_and_clamps(): void
    {
        $s = $this->scorer();
        // ageH = windowH/2 => fresh 0.5
        $half = $s->score(0.0, 1000.0, 12.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(0.5, $half['fresh'], 1e-9);
        // ageH > windowH => fresh clamps to 0
        $old = $s->score(0.0, 1000.0, 48.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $old['fresh']);
    }

    public function test_budget_term_matches_go_reference_with_age_clamp(): void
    {
        $s = $this->scorer();
        // views=0, replies=0 => engagement 0 => budget exp(0) = 1.0
        $unseen = $s->score(0.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(1.0, $unseen['budget'], 1e-9);

        // ageH < 1 is clamped to 1 in the rate denominator.
        // views=10, replies=2 => (10 + 3*2)/max(0.25,1)=16; decay/12=25/12.
        $expected = exp(-16.0 / (25.0 / 12.0));
        $busy = $s->score(0.0, 1000.0, 0.25, 10, 2, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta($expected, $busy['budget'], 1e-9);
    }

    public function test_anchor_term_is_one_only_for_home_group(): void
    {
        $s = $this->scorer();
        $home = $s->score(0.0, 1000.0, 5.0, 0, 0, true, $this->weights, $this->env);
        $this->assertSame(1.0, $home['anchor']);
        $away = $s->score(0.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $away['anchor']);
    }

    public function test_total_is_weighted_sum_with_explorer_default_weights(): void
    {
        $s = $this->scorer();
        // near (close ~0.8), fresh weight 0, unseen (budget 1), away (anchor 0)
        // close = 1 - 200/1000 = 0.8 ; total = 1*0.8 + 0 + 1*1 + 0 = 1.8
        $r = $s->score(200.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(0.8, $r['close'], 1e-9);
        $this->assertEqualsWithDelta(1.8, $r['total'], 1e-9);
    }

    public function test_zero_reach_radius_does_not_divide_by_zero(): void
    {
        $s = $this->scorer();
        // Degenerate reach radius: treat as no closeness signal (close 0), not NaN/inf.
        $r = $s->score(50.0, 0.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $r['close']);
        $this->assertFalse(is_nan($r['total']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-batch php artisan test --filter=DigestPostScorerTest`
Expected: FAIL — `Class "App\Services\Ripple\DigestPostScorer" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `iznik-batch/app/Services/Ripple/DigestPostScorer.php`:

```php
<?php

namespace App\Services\Ripple;

/**
 * Mirrors iznik-routing-go/digest_simulator.go::scoreDigestPost — the scoring
 * used by the /rippling page's "Digest preview" (inbound) mode — so the unified
 * digest email orders posts the same way moderators see on that page.
 *
 * PERFORMANCE APPROXIMATION: the reference `close` term is 1 - driveMin/maxMinutes,
 * where driveMin comes from a full Dijkstra drive-time isochrone computed per
 * recipient in the routing server. The unified digest is mass mail (potentially
 * millions of recipient-sends per run), so running an isochrone per recipient is
 * infeasible inline. Instead we approximate drive-time with the straight-line
 * (haversine) distance from the recipient to the post origin, normalised by the
 * post's reach radius (or a fixed default for posts with no reach row). This is a
 * deliberate trade of fidelity for throughput; see the design spec
 * docs/superpowers/specs/2026-06-22-digest-rippling-score-ordering-design.md.
 *
 * This class is intentionally pure (no DB / no I/O) so it is unit-testable in
 * isolation against the Go reference values.
 */
class DigestPostScorer
{
    /**
     * @param float $distanceMetres Haversine distance recipient -> post origin (drive-time proxy).
     * @param float $reachRadius    Post reach extent in metres (closeness denominator).
     * @param float $ageH           Post age in hours.
     * @param int   $views          messages_likes 'View' count (SUM of count).
     * @param int   $replies        'Interested' chat replies.
     * @param bool  $homeGroup      Post is from the recipient's home group.
     * @param array{close:float,fresh:float,budget:float,anchor:float} $weights
     * @param array{window_hours:float,budget_decay:float} $env
     * @return array{close:float,fresh:float,budget:float,anchor:float,total:float}
     */
    public function score(
        float $distanceMetres,
        float $reachRadius,
        float $ageH,
        int $views,
        int $replies,
        bool $homeGroup,
        array $weights,
        array $env
    ): array {
        // close = 1 - dist/reach, clamped to [0,1]. reach<=0 => no closeness signal.
        $close = 0.0;
        if ($reachRadius > 0) {
            $close = 1.0 - $distanceMetres / $reachRadius;
            if ($close < 0) {
                $close = 0.0;
            }
        }

        $fresh = 1.0 - $ageH / $env['window_hours'];
        if ($fresh < 0) {
            $fresh = 0.0;
        }

        // engagement_rate = (views + 3*replies) / max(ageH, 1); budgetDecay/12
        // converts the minute-equivalent knob to the rate-scale exp() expects.
        $rateAgeH = $ageH < 1 ? 1.0 : $ageH;
        $engagement = ($views + 3 * $replies) / $rateAgeH;
        $budget = exp(-$engagement / ($env['budget_decay'] / 12.0));

        $anchor = $homeGroup ? 1.0 : 0.0;

        $total = $weights['close'] * $close
            + $weights['fresh'] * $fresh
            + $weights['budget'] * $budget
            + $weights['anchor'] * $anchor;

        return [
            'close' => $close,
            'fresh' => $fresh,
            'budget' => $budget,
            'anchor' => $anchor,
            'total' => $total,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-batch php artisan test --filter=DigestPostScorerTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add iznik-batch/app/Services/Ripple/DigestPostScorer.php iznik-batch/tests/Unit/Services/Ripple/DigestPostScorerTest.php
git commit -m "feat(digest): pure DigestPostScorer mirroring rippling digest-preview score"
```

---

## Task 2: Config block for digest scoring

**Files:**
- Modify: `iznik-batch/config/freegle.php` (inside the existing `'ripple' => [ ... ]` array, after `'hazard_hours'`)

- [ ] **Step 1: Add the config sub-block**

Add inside the `ripple` array in `iznik-batch/config/freegle.php`:

```php
        // Unified-digest score-ordering (see App\Services\Ripple\DigestPostScorer).
        // Mirrors the /rippling "Digest preview" weights. Tunable via env without a deploy.
        'score' => [
            'weights' => [
                'close'  => (float) env('RIPPLE_DIGEST_W_CLOSE', 1.0),
                'fresh'  => (float) env('RIPPLE_DIGEST_W_FRESH', 0.0),
                'budget' => (float) env('RIPPLE_DIGEST_W_BUDGET', 1.0),
                'anchor' => (float) env('RIPPLE_DIGEST_W_ANCHOR', 0.0),
            ],
            'window_hours' => (float) env('RIPPLE_DIGEST_WINDOW_HOURS', 24),
            'budget_decay' => (float) env('RIPPLE_DIGEST_BUDGET_DECAY', 25),
            // ~30km, the 30-min drive-isochrone analogue. Used for posts with no
            // rippling_reach row (the dominant case while rippling is dark, and for
            // all backlog posts after go-live).
            'default_reach_metres' => (float) env('RIPPLE_DIGEST_DEFAULT_REACH_M', 30000),
        ],
```

- [ ] **Step 2: Verify config loads**

Run: `docker exec freegle-batch php artisan tinker --execute="echo json_encode(config('freegle.ripple.score'));"`
Expected: JSON with `weights`, `window_hours`, `budget_decay`, `default_reach_metres`.

- [ ] **Step 3: Commit**

```bash
git add iznik-batch/config/freegle.php
git commit -m "feat(digest): config block for rippling digest score weights"
```

---

## Task 3: Engagement counts in the post query

Add `views` and `replies` to `getPostsForUser()` matching the Go query verbatim
(`digest_simulator.go` lines 138-147).

**Files:**
- Modify: `iznik-batch/app/Services/UnifiedDigestService.php` (`getPostsForUser`, the `Message::select(...)` builder ~1125-1134)
- Test: `iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add to `iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php`. Use the
real base-class fixture helpers (`createTestUser`, `createTestGroup`,
`createMembership`, `createTestMessage` — all in `tests/TestCase.php`). The test
needs a `UserDigest` tracker; build one with `lastmsgdate => null` so the 24h
first-digest window applies (the message arrives "now"):

```php
public function test_get_posts_for_user_exposes_engagement_counts(): void
{
    $recipient = $this->createTestUser();
    $poster = $this->createTestUser();
    $group = $this->createTestGroup();
    $this->createMembership($recipient, $group, [
        'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
    ]);

    $msg = $this->createTestMessage($poster, $group, [
        'subject' => 'OFFER: Counted (TestLocation)',
        'arrival' => now()->subHours(2),
    ]);

    // 3 'View' likes (the count column is SUMmed) and 1 approved 'Interested' reply.
    DB::table('messages_likes')->insert([
        'msgid' => $msg->id, 'userid' => $recipient->id, 'type' => 'View', 'count' => 3,
        'timestamp' => now(),
    ]);
    DB::table('chat_messages')->insert([
        'refmsgid' => $msg->id, 'userid' => $poster->id, 'chatid' => 0,
        'type' => 'Interested', 'message' => 'Interested',
        'reviewrejected' => 0, 'reviewrequired' => 0, 'date' => now(),
    ]);

    $tracker = UserDigest::create([
        'userid' => $recipient->id,
        'mode' => UnifiedDigestService::MODE_DAILY,
        'lastmsgdate' => null,
    ]);

    $posts = $this->service->getPostsForUser(
        $recipient, $tracker, UnifiedDigestService::MODE_DAILY
    );

    $row = $posts->firstWhere('id', $msg->id);
    $this->assertNotNull($row);
    $this->assertSame(3, (int) $row->views);
    $this->assertSame(1, (int) $row->replies);
}
```

> NOTE for the implementer: confirm the `chat_messages` insert satisfies that
> table's NOT NULL columns in `iznik_batch_test` (mirror `createTestChatMessage`
> in `tests/TestCase.php` for the full column set if the bare insert above is
> rejected — add `processingrequired`/`processingsuccessful`/etc.). Confirm
> `UserDigest`'s fillable columns (`userid`, `mode`, `lastmsgdate`) match the model;
> adjust to the real column names if needed. Do NOT weaken the `views`/`replies`
> assertions.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-batch php artisan test --filter=test_get_posts_for_user_exposes_engagement_counts`
Expected: FAIL — `views`/`replies` are undefined on the row.

- [ ] **Step 3: Add the select subqueries**

In `getPostsForUser()`, extend the `Message::select(...)` chain. Change:

```php
        $query = Message::select('messages.*', 'messages_groups.groupid', 'messages_groups.arrival')
            ->selectRaw('EXISTS(SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = messages.id) AS has_outcome')
            ->selectRaw("EXISTS(SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = messages.id AND mo.outcome IN ($successList)) AS has_success")
```

to additionally select engagement counts (matching iznik-routing-go/digest_simulator.go):

```php
        $query = Message::select('messages.*', 'messages_groups.groupid', 'messages_groups.arrival')
            ->selectRaw('EXISTS(SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = messages.id) AS has_outcome')
            ->selectRaw("EXISTS(SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = messages.id AND mo.outcome IN ($successList)) AS has_success")
            // Engagement signal for the rippling 'budget' (underexposure) score term;
            // mirrors iznik-routing-go/digest_simulator.go (views = SUM of 'View'
            // like counts; replies = approved 'Interested' chat replies).
            ->selectRaw("(SELECT COALESCE(SUM(ml.count),0) FROM messages_likes ml WHERE ml.msgid = messages.id AND ml.type = 'View') AS views")
            ->selectRaw("(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = messages.id AND cm.type = 'Interested' AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies")
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-batch php artisan test --filter=test_get_posts_for_user_exposes_engagement_counts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add iznik-batch/app/Services/UnifiedDigestService.php iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php
git commit -m "feat(digest): fetch view/reply engagement counts for score-ordering"
```

---

## Task 4: Per-run reach-radius lookup

Add `reachRadiusMetres(int $msgid): float` returning the post's reach extent in
metres — the maximum great-circle distance from the post origin (`rippling_reach.lat/lng`)
to its polygon boundary vertices — cached per run. Falls back to the config
default for posts with no reach row.

The polygon is stored in SRID 3857; rather than parse WKT in PHP, compute the
radius in MySQL using `ST_Distance_Sphere` against each ring point is awkward, so
use the simpler, sufficient approach: derive the radius from the polygon's
bounding envelope corners in true metres via `ST_Distance_Sphere`. MySQL stores
the polygon in 3857 (planar metres-ish); transform points back to lon/lat for the
sphere distance is unavailable without SRID transforms. Instead, compute the
radius directly with the planar polygon: the reach polygon and the origin are both
in 3857, and 3857 units are metres scaled by sec(latitude). For the digest's
ranking purpose the per-post scale factor is constant across that post's own
distance/radius ratio, so using **3857 planar distance for BOTH** the numerator
(recipient→origin) and denominator (origin→boundary) keeps `close` scale-free.

**Therefore:** compute BOTH the recipient distance (Task 5) AND the reach radius
in **SRID-3857 planar units** so the ratio is unaffected by mercator scaling.

**Files:**
- Modify: `iznik-batch/app/Services/UnifiedDigestService.php` (new private method + cache property)
- Test: `iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add to `UnifiedDigestServiceTest.php`:

```php
public function test_reach_radius_falls_back_to_config_default_without_reach_row(): void
{
    config(['freegle.ripple.score.default_reach_metres' => 12345.0]);
    $svc = app(\App\Services\UnifiedDigestService::class);

    // No rippling_reach row for this msgid => default.
    $r = $this->callPrivate($svc, 'reachRadiusMetres', [999999999]);
    $this->assertEqualsWithDelta(12345.0, $r, 1e-6);
}

public function test_reach_radius_is_distance_origin_to_polygon_boundary(): void
{
    $svc = app(\App\Services\UnifiedDigestService::class);

    // Origin at 3857 (0,0); square polygon 1000 units half-width => corner dist sqrt(2)*1000.
    \DB::table('rippling_reach')->insert([
        'msgid' => 42, 'lat' => 0, 'lng' => 0,
        'polygon' => \DB::raw("ST_GeomFromText('POLYGON((-1000 -1000,1000 -1000,1000 1000,-1000 1000,-1000 -1000))', 3857)"),
        'arrival' => now(), 'mode' => 'drive', 'tick' => 1, 'total_ticks' => 9,
        'status' => 'expanding', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = $this->callPrivate($svc, 'reachRadiusMetres', [42]);
    // Farthest boundary point is a corner at distance sqrt(2)*1000 ~= 1414.2 (3857 planar units).
    $this->assertEqualsWithDelta(1414.21356, $r, 0.5);
}
```

If the test file has no `callPrivate` helper, add this once near the top of the class:

```php
private function callPrivate(object $obj, string $method, array $args = [])
{
    $ref = new \ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-batch php artisan test --filter="reach_radius"`
Expected: FAIL — `reachRadiusMetres` does not exist.

- [ ] **Step 3: Implement the method + cache**

Add a cache property near the other private properties of `UnifiedDigestService`:

```php
    /** Per-run cache of post reach radius (SRID-3857 planar units) keyed by msgid. */
    private array $reachRadiusCache = [];
```

Add the method (place it near `resolveUserLatLng`):

```php
    /**
     * The post's reach extent in SRID-3857 planar units: the greatest distance
     * from the post origin (rippling_reach.lat/lng) to any vertex of its reach
     * polygon. Used as the closeness denominator in the digest score.
     *
     * Computed in 3857 planar units (NOT true metres) on purpose: the recipient
     * distance (see scoreAndSortAvailable) is measured the same way, so the
     * mercator scale factor cancels in the close = 1 - dist/reach ratio.
     *
     * Posts with no rippling_reach row (rippling dark, or backlog posts arriving
     * before the go-live cutoff) fall back to the configured default. Cached per
     * run because many recipients share the same posts.
     */
    private function reachRadiusMetres(int $msgid): float
    {
        if (array_key_exists($msgid, $this->reachRadiusCache)) {
            return $this->reachRadiusCache[$msgid];
        }

        $default = (float) config('freegle.ripple.score.default_reach_metres', 30000);

        // ST_Length of the line from origin to each exterior-ring vertex is awkward;
        // instead take the max distance origin->vertex via the polygon's points.
        // ST_X/ST_Y on the origin point, and the polygon exterior ring vertices.
        $row = DB::selectOne(
            'SELECT rr.lng AS ox, rr.lat AS oy, rr.polygon AS poly
               FROM rippling_reach rr WHERE rr.msgid = ?',
            [$msgid]
        );

        if (!$row || $row->poly === null) {
            return $this->reachRadiusCache[$msgid] = $default;
        }

        // Compute the farthest exterior-ring vertex distance in 3857 planar units.
        // ST_Distance between the origin point (built in 3857) and each ring point.
        // Done in SQL by exploding the exterior ring; MySQL lacks a vertex unnest,
        // so use the polygon envelope corners which bound the true radius closely
        // for the near-circular reach polygons the engine produces.
        $radius = DB::selectOne(
            "SELECT GREATEST(
                ST_Distance(ST_SRID(POINT(?, ?),3857), ST_PointN(ST_ExteriorRing(ST_Envelope(p.poly)),1)),
                ST_Distance(ST_SRID(POINT(?, ?),3857), ST_PointN(ST_ExteriorRing(ST_Envelope(p.poly)),2)),
                ST_Distance(ST_SRID(POINT(?, ?),3857), ST_PointN(ST_ExteriorRing(ST_Envelope(p.poly)),3)),
                ST_Distance(ST_SRID(POINT(?, ?),3857), ST_PointN(ST_ExteriorRing(ST_Envelope(p.poly)),4))
             ) AS r
             FROM (SELECT ? AS poly) AS dummy
             CROSS JOIN (SELECT ST_GeomFromText(ST_AsText(?), 3857) AS poly) AS p",
            [$row->ox, $row->oy, $row->ox, $row->oy, $row->ox, $row->oy, $row->ox, $row->oy, $row->poly, $row->poly]
        );

        $r = $radius && $radius->r !== null ? (float) $radius->r : $default;
        if ($r <= 0) {
            $r = $default;
        }
        return $this->reachRadiusCache[$msgid] = $r;
    }
```

> IMPLEMENTER NOTE: The exact SQL for reading a geometry column and measuring
> origin→boundary distance can vary with the MySQL version in `freegle-batch`. The
> REQUIRED behaviour is: return the farthest distance (3857 units) from
> `(rr.lng, rr.lat)` to the reach polygon boundary, default on missing row/zero.
> The `test_reach_radius_is_distance_origin_to_polygon_boundary` test pins the
> expected value (corner of a square = sqrt(2)*halfwidth). If `ST_Envelope` corners
> under-approximate for the test's axis-aligned square (they don't — the envelope of
> a square is the square itself), keep the envelope approach; if a later real-polygon
> test needs true vertices, switch to parsing `ST_AsText` ring points in PHP. Make
> the pinned test pass; do not weaken it.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-batch php artisan test --filter="reach_radius"`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add iznik-batch/app/Services/UnifiedDigestService.php iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php
git commit -m "feat(digest): per-run reach-radius lookup with config-default fallback"
```

---

## Task 5: Score + sort the available posts

Add `scoreAndSortAvailable(Collection $posts, ?array $latlng): Collection` that
attaches a `_score` to each post and returns them sorted by `_score` descending.
Wire it into `sendDigestToUser()` for daily mode, before dedup. Post origin coords
come from `messages.lat`/`messages.lng` (already selected via `messages.*` in
`getPostsForUser` — no extra join needed). Recipient and post are both projected to
SRID-3857 planar units (matching the reach radius) so the mercator scale cancels in
the `close` ratio.

**Files:**
- Modify: `iznik-batch/app/Services/UnifiedDigestService.php`
- Test: `iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add to `UnifiedDigestServiceTest.php`. Build three available posts as plain
`stdClass` rows exposing exactly the fields the scorer reads (`id`, `lat`, `lng`,
`arrival`, `views`, `replies`) — no DB messages needed (none have a `rippling_reach`
row, so each uses the config default radius). With default weights (close + budget,
fresh weight 0): a near+unseen post outranks a far+unseen post, which outranks a
near+heavily-viewed post. Recipient at lat/lng (0,0):

```php
public function test_available_posts_sorted_by_score_descending_not_arrival(): void
{
    config(['freegle.ripple.score.default_reach_metres' => 40000.0]);
    $latlng = [0.0, 0.0]; // recipient [lat, lng]

    $mk = function (int $id, float $lat, float $lng, int $ageH, int $views) {
        $p = new \stdClass();
        $p->id = $id;
        $p->lat = $lat;
        $p->lng = $lng;
        $p->arrival = now()->subHours($ageH);
        $p->views = $views;
        $p->replies = 0;
        return $p;
    };

    // ~0.0009deg ~= 100m; ~0.0027deg ~= 300m near origin. Distances are well within
    // the 40km default radius so closeness differences are small but ordered; the
    // dominating differentiator is the budget term (views).
    $near = $mk(1, 0.0009, 0.0, 20, 0);   // nearest, unseen (oldest arrival)
    $far  = $mk(2, 0.0027, 0.0, 1,  0);   // farther, unseen, newest
    $busy = $mk(3, 0.0009, 0.0, 1,  500); // nearest but heavily viewed -> low budget

    $sorted = $this->callPrivate(
        $this->service, 'scoreAndSortAvailable', [collect([$busy, $far, $near]), $latlng]
    );

    $this->assertSame([1, 2, 3], $sorted->pluck('id')->all());
}
```

> IMPLEMENTER NOTE: with fresh weight 0, near(1) and far(2) both have budget≈1, so
> their order is decided by closeness — near(1) first. busy(3) has the same
> closeness as near(1) but its 500 views crush the budget term, so it scores
> lowest. If the chosen coordinates make the closeness gap too small to separate
> 1 from 2 reliably, widen `far`'s offset (e.g. 0.05deg) — keep the assertion, do
> not weaken it.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-batch php artisan test --filter=test_available_posts_sorted_by_score`
Expected: FAIL — `scoreAndSortAvailable` not defined.

- [ ] **Step 3: Implement scoring + sort**

Add (near `getPostsForUser`):

```php
    /**
     * Score the available (live) posts with the rippling digest-preview algorithm
     * and return them ordered by score descending. See DigestPostScorer for the
     * formula and the haversine/drive-time performance approximation.
     *
     * When the recipient's location is unknown we cannot compute closeness, so we
     * leave the posts in their incoming (arrival) order — fail open, no regression.
     */
    private function scoreAndSortAvailable(Collection $posts, ?array $latlng): Collection
    {
        if ($latlng === null || $posts->count() < 2) {
            return $posts->values();
        }

        $scorer = app(DigestPostScorer::class);
        $weights = (array) config('freegle.ripple.score.weights');
        $env = [
            'window_hours' => (float) config('freegle.ripple.score.window_hours', 24),
            'budget_decay' => (float) config('freegle.ripple.score.budget_decay', 25),
        ];

        // Recipient point in SRID-3857 planar units (same space as the reach radius).
        [$recX, $recY] = $this->toMercator($latlng[0], $latlng[1]); // (lat,lng) -> (x,y)

        $now = now();
        foreach ($posts as $post) {
            $reach = $this->reachRadiusMetres((int) $post->id);
            // Post origin: messages.lat/lng (already on the row via messages.* select).
            [$px, $py] = $this->toMercator((float) $post->lat, (float) $post->lng);
            $dist = sqrt(($px - $recX) ** 2 + ($py - $recY) ** 2);
            $ageH = $now->floatDiffInHours($post->arrival instanceof \DateTimeInterface
                ? $post->arrival
                : \Illuminate\Support\Carbon::parse($post->arrival));
            $s = $scorer->score(
                $dist,
                $reach,
                max(0.0, $ageH),
                (int) ($post->views ?? 0),
                (int) ($post->replies ?? 0),
                false, // anchor/home-group not yet implemented; see /rippling (digest_simulator.go homeGroups). Default weight 0.
                $weights,
                $env
            );
            $post->_score = $s['total'];
        }

        return $posts->sortByDesc('_score')->values();
    }

    /** Forward Web-Mercator (EPSG:3857) projection of (lat,lng) degrees -> (x,y) metres. */
    private function toMercator(float $lat, float $lng): array
    {
        $r = 6378137.0;
        $x = $r * deg2rad($lng);
        $lat = max(-85.05112878, min(85.05112878, $lat));
        $y = $r * log(tan(M_PI / 4 + deg2rad($lat) / 2));
        return [$x, $y];
    }
```

Ensure `use App\Services\Ripple\DigestPostScorer;` and `use Illuminate\Support\Collection;` are present at the top of the file (Collection almost certainly already imported).

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-batch php artisan test --filter=test_available_posts_sorted_by_score`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add iznik-batch/app/Services/UnifiedDigestService.php iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php
git commit -m "feat(digest): score available posts and sort by rippling score"
```

---

## Task 6: Wire score-sort into the daily digest flow

Call `scoreAndSortAvailable` in `sendDigestToUser()` for daily mode, before dedup.
Post origin coords (`messages.lat`/`messages.lng`) are already on each row via the
`messages.*` select from Task 3 — no extra join is required.

**Files:**
- Modify: `iznik-batch/app/Services/UnifiedDigestService.php` (`sendDigestToUser` ~949-965)
- Test: `iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php`

- [ ] **Step 1: Write the failing test**

Daily mode spools via `EmailSpoolerService->spool(new UnifiedDigest(...))` (not
`Mail::send`), so capture by spying the spooler and reading the mailable's
`getPosts()` (a `Collection` of arrays each with a `message` key). Add to
`UnifiedDigestServiceTest.php`:

```php
public function test_daily_digest_orders_live_posts_by_score(): void
{
    config(['freegle.digest.daily_allowlist' => '*']);

    $recipient = $this->createTestUser();
    $recipient->settings = [
        'simplemail' => User::SIMPLE_MAIL_BASIC,
        'mylocation' => ['lat' => 51.5, 'lng' => -0.12],
    ];
    $recipient->lastaccess = now();
    $recipient->save();
    $recipient->refresh();

    $poster = $this->createTestUser();
    $group = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.12]);
    $this->createMembership($recipient, $group, [
        'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
    ]);

    // Near post has the OLDER arrival; far post is newer. Arrival order would put
    // far... no: arrival ASC would put near (older) first by luck, so make the FAR
    // post older to prove score (not arrival) drives order: near must still win.
    $near = $this->createTestMessage($poster, $group, [
        'subject' => 'OFFER: Near (TestLocation)',
        'lat' => 51.5, 'lng' => -0.12, 'arrival' => now()->subHours(2),
    ]);
    $far = $this->createTestMessage($poster, $group, [
        'subject' => 'OFFER: Far (TestLocation)',
        'lat' => 53.0, 'lng' => -0.12, 'arrival' => now()->subHours(10),
    ]);

    // Spy the spooler so we can read the posts handed to the daily UnifiedDigest.
    $captured = null;
    $spy = \Mockery::mock(\App\Services\EmailSpoolerService::class);
    $spy->shouldReceive('spool')->andReturnUsing(function ($mailable) use (&$captured) {
        $captured = $mailable;
        return 'spooled';
    });
    $this->app->instance(\App\Services\EmailSpoolerService::class, $spy);

    $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

    $this->assertNotNull($captured, 'daily digest should have been spooled');
    $ids = $captured->getPosts()->map(fn ($p) => $p['message']->id)->all();
    $this->assertSame([$near->id, $far->id], $ids); // near outranks far on closeness
}
```

> IMPLEMENTER NOTE: arrival ASC and score order would coincide if the near post
> were also the newest, so the test deliberately makes the FAR post the OLDER one —
> arrival ASC would emit `[far, near]`, score emits `[near, far]`. If both posts
> share `messages_groups.arrival` ties or the daily allowlist key differs, check the
> existing daily tests (`test_daily_mode_*`) for the exact config keys. Keep the
> `[near, far]` assertion; do not weaken it.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-batch php artisan test --filter=test_daily_digest_orders_live_posts_by_score`
Expected: FAIL — posts come out arrival-ordered `[far, near]` (far is older), not score-ordered.

- [ ] **Step 3: Wire the sort into `sendDigestToUser`**

In `sendDigestToUser()`, after the `available` partition (line ~949) and before
`deduplicatePosts` (line ~965), for daily mode score+sort:

```php
        $posts = $allPosts->filter(fn ($p) => !$p->has_outcome)->values();

        // Order the live posts by the rippling digest-preview score (nearer +
        // newer + less-seen float up), matching the /rippling "Digest preview".
        // Daily only — immediate mode stays chronological (single-group, real-time).
        // Dedup runs after, so the kept cross-post representative is the top-scoring one.
        if ($mode === self::MODE_DAILY) {
            $posts = $this->scoreAndSortAvailable($posts, $this->resolveUserLatLng($user));
        }
```

(`$completedPosts` partition is unchanged and stays after, chronological.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-batch php artisan test --filter=test_daily_digest_orders_live_posts_by_score`
Expected: PASS.

- [ ] **Step 5: Run the whole digest test suite to check for regressions**

Run: `docker exec freegle-batch php artisan test --filter=UnifiedDigest`
Expected: PASS (all existing digest tests still green; arrival-order assumptions in
old tests may need updating ONLY if they asserted order for located multi-post
daily cases — if so, update the assertion to the score order, never weaken it, and
note why in the commit).

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/app/Services/UnifiedDigestService.php iznik-batch/tests/Unit/Services/UnifiedDigestServiceTest.php
git commit -m "feat(digest): order daily digest live posts by rippling score"
```

---

## Task 7: Full suite + spec-parity self-check

- [ ] **Step 1: Run the full batch unit+feature suite**

Run: `docker exec freegle-batch php artisan test --testsuite=Unit,Feature`
Expected: PASS. Investigate and fix any failure (never dismiss as pre-existing).

- [ ] **Step 2: Cross-check the scorer against the Go reference**

Open `iznik-routing-go/digest_simulator.go` `scoreDigestPost` (lines 407-436) beside
`DigestPostScorer::score`. Confirm term-by-term parity: close clamp, fresh clamp,
`max(ageH,1)` age clamp, `budgetDecay/12`, weighted sum. The only intended
divergence is `close` using `distance/reachRadius` instead of `driveMin/maxMinutes`
(the documented perf approximation) — confirm that comment is present.

- [ ] **Step 3: Confirm comments call out the approximation**

Grep: `grep -n "APPROXIMATION\|approximation\|drive-time\|haversine\|mercator" iznik-batch/app/Services/Ripple/DigestPostScorer.php iznik-batch/app/Services/UnifiedDigestService.php`
Expected: the perf-approximation rationale is present in `DigestPostScorer` and the
3857-units rationale in `reachRadiusMetres`/`scoreAndSortAvailable`.

- [ ] **Step 4: Final commit if any cleanup was needed**

```bash
git add -A && git commit -m "test(digest): full-suite green + rippling score parity check"
```

---

## Notes / Decisions captured from brainstorming

- **Closeness uses haversine/3857-planar distance, NOT Dijkstra drive-time** — deliberate perf approximation for mass mail. Commented in code. (User: "make sure we comment that as an approximation for perf reasons".)
- **Weights = Explorer defaults** (close 1, fresh 0, budget 1, anchor 0), config-tunable.
- **Distance denominator = post reach-polygon radius**, config-default (30km) fallback for posts with no reach row (the dominant case while rippling is dark + all backlog posts after go-live).
- **Anchor/home-group term: NOT implemented** — passed as `false` with a comment pointing to `/rippling` (`digest_simulator.go` homeGroups). Default weight 0, so no ordering effect.
- **Immediate mode unchanged** (chronological). Completed/"came and went" section unchanged (chronological, at end).
