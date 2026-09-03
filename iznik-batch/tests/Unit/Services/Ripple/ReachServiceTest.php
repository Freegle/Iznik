<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\ReachService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReachServiceTest extends TestCase
{
    private function service(): ReachService
    {
        // Pin the hazard schedule so the assertions are deterministic.
        config(['freegle.ripple.hazard_hours' => [1, 3, 6, 12, 24, 48, 72, 120, 168]]);
        config(['freegle.ripple.curve' => 'step-70']);
        config(['freegle.ripple.mode' => 'drive']);
        config(['freegle.ripple.max_minutes' => 30]);

        return new ReachService();
    }

    public function test_tick_for_elapsed_hours_maps_to_hazard_schedule(): void
    {
        $s = $this->service();

        $this->assertSame(1, $s->tickForElapsedHours(0.0));   // before h1
        $this->assertSame(1, $s->tickForElapsedHours(1.0));   // exactly h1
        $this->assertSame(2, $s->tickForElapsedHours(4.0));   // past h3
        $this->assertSame(3, $s->tickForElapsedHours(7.0));   // past h6
        $this->assertSame(9, $s->tickForElapsedHours(1000.0)); // clamped to last tick
        $this->assertSame(9, $s->totalTicks());
    }

    public function test_next_expansion_after_uses_next_hazard_threshold(): void
    {
        $s = $this->service();
        $arrival = Carbon::parse('2026-06-16 09:00:00');

        // tick 1 → next at arrival + hazard[1] = +3h
        $this->assertEquals(
            $arrival->copy()->addHours(3)->toDateTimeString(),
            $s->nextExpansionAfter($arrival, 1)->toDateTimeString()
        );
        // tick 2 → next at arrival + hazard[2] = +6h
        $this->assertEquals(
            $arrival->copy()->addHours(6)->toDateTimeString(),
            $s->nextExpansionAfter($arrival, 2)->toDateTimeString()
        );
        // final tick → null (no further expansion)
        $this->assertNull($s->nextExpansionAfter($arrival, 9));
    }

    public function test_compute_schedule_parses_routing_response_to_wkt(): void
    {
        $s = $this->service();

        $polygon = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
                ]],
            ],
        ];

        Http::fake(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 120,
            'max_drive_min' => 30,
            'schedule' => [
                ['tick' => 1, 'drive_min' => 5.0, 'cumulative_users' => 84, 'polygon' => $polygon],
                ['tick' => 2, 'drive_min' => 9.0, 'cumulative_users' => 108, 'polygon' => $polygon],
            ],
        ], 200)]);

        $result = $s->computeSchedule(51.5, -0.1);

        $this->assertNotNull($result);
        $this->assertSame(120, $result['total_freeglers']);
        $this->assertCount(2, $result['ticks']);
        $this->assertSame(1, $result['ticks'][0]['tick']);
        $this->assertSame(84, $result['ticks'][0]['cumulative_users']);
        $this->assertStringStartsWith('POLYGON((-0.1 51.5', $result['ticks'][0]['wkt']);
        $this->assertStringEndsWith('-0.1 51.5))', $result['ticks'][0]['wkt']);
    }

    public function test_parseScheduleResponse_includes_reachable_group_ids(): void
    {
        $s = $this->service();
        $polygon = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
                ]],
            ],
        ];
        $result = $s->parseScheduleResponse([
            'total_freeglers' => 5,
            'max_drive_min' => 30,
            'schedule' => [
                ['tick' => 1, 'drive_min' => 5.0, 'cumulative_users' => 2, 'polygon' => $polygon],
            ],
            // The routing server may send them as JSON numbers; keep them ints.
            'reachable_group_ids' => [21439, 21656],
        ]);

        $this->assertNotNull($result);
        $this->assertSame([21439, 21656], $result['reachable_group_ids']);
    }

    public function test_parseScheduleResponse_defaults_reachable_group_ids_to_empty(): void
    {
        // An older routing server omits the field entirely - the batch must see []
        // (not null), which the gate treats as "not available" and leaves targeting
        // unchanged.
        $s = $this->service();
        $polygon = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
                ]],
            ],
        ];
        $result = $s->parseScheduleResponse([
            'schedule' => [
                ['tick' => 1, 'drive_min' => 5.0, 'cumulative_users' => 2, 'polygon' => $polygon],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertSame([], $result['reachable_group_ids']);
    }

    public function test_schedule_omits_target_users_when_extent_disabled(): void
    {
        // Default / dark: the audience cap must not touch the request at all,
        // so the routing schedule is identical to the pre-feature behaviour.
        config(['freegle.ripple.extent.enabled' => false]);
        config(['freegle.ripple.extent.target_users' => 4000]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => !str_contains($request->url(), 'target_users'));
    }

    public function test_schedule_sends_target_users_when_extent_enabled(): void
    {
        config(['freegle.ripple.extent.enabled' => true]);
        config(['freegle.ripple.extent.target_users' => 4000]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'target_users=4000'));
    }

    public function test_schedule_omits_target_users_when_enabled_but_zero(): void
    {
        // enabled but target 0 = no cap configured -> still nothing sent.
        config(['freegle.ripple.extent.enabled' => true]);
        config(['freegle.ripple.extent.target_users' => 0]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => !str_contains($request->url(), 'target_users'));
    }

    public function test_compute_schedule_returns_null_on_empty_schedule(): void
    {
        $s = $this->service();
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);
        $this->assertNull($s->computeSchedule(51.5, -0.1));
    }

    public function test_compute_schedule_returns_null_on_http_error(): void
    {
        $s = $this->service();
        Http::fake(['*ripple-schedule*' => Http::response('boom', 500)]);
        $this->assertNull($s->computeSchedule(51.5, -0.1));
    }

    public function test_compute_schedules_batch_returns_one_result_per_origin(): void
    {
        $s = $this->service();

        $polygon = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
                ]],
            ],
        ];
        Http::fake(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 120,
            'max_drive_min' => 30,
            'schedule' => [
                ['tick' => 1, 'drive_min' => 5.0, 'cumulative_users' => 84, 'polygon' => $polygon],
            ],
        ], 200)]);

        $results = $s->computeSchedulesBatch([
            ['lat' => 51.5, 'lng' => -0.1],
            ['lat' => 52.2, 'lng' => -1.5],
        ]);

        $this->assertCount(2, $results, 'one result per input origin, index-aligned');
        $this->assertNotNull($results[0]);
        $this->assertNotNull($results[1]);
        $this->assertSame(120, $results[0]['total_freeglers']);
        $this->assertStringStartsWith('POLYGON((', $results[0]['ticks'][0]['wkt']);
        // One HTTP request per origin was fired (the pool fans them out concurrently).
        $this->assertCount(2, Http::recorded());
    }

    public function test_compute_schedules_batch_is_empty_for_no_origins(): void
    {
        Http::fake();
        $this->assertSame([], $this->service()->computeSchedulesBatch([]));
        $this->assertCount(0, Http::recorded());
    }

    // --- Slim schedule contract (polygons=0) --------------------------------------

    public function test_schedule_request_asks_for_the_slim_form(): void
    {
        Http::fake(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 1, 'max_drive_min' => 30,
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 1, 'reachable_group_ids' => []]],
            'reachable_group_ids' => [],
        ], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/v1/ripple-schedule')
                && ($req['polygons'] ?? null) === '0';
        });
    }

    public function test_parse_keeps_slim_ticks_and_per_tick_ids(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'total_freeglers' => 42,
            'max_drive_min' => 30,
            'schedule' => [
                ['tick' => 1, 'drive_min' => 5.5, 'cumulative_users' => 10, 'reachable_group_ids' => ['21656']],
                ['tick' => 2, 'drive_min' => 12.0, 'cumulative_users' => 30, 'reachable_group_ids' => [21656, 21458]],
            ],
            'reachable_group_ids' => [21656, 21458],
        ]);

        $this->assertNotNull($parsed, 'slim ticks (no polygon) are usable');
        $this->assertCount(2, $parsed['ticks']);
        $this->assertArrayNotHasKey('wkt', $parsed['ticks'][0]);
        $this->assertSame([21656], $parsed['ticks'][0]['reachable_group_ids'], 'per-tick ids are cast to ints');
        $this->assertSame([21656, 21458], $parsed['ticks'][1]['reachable_group_ids']);
        $this->assertSame([21656, 21458], $parsed['reachable_group_ids']);
    }

    public function test_catchment_wkt_parses_the_polygon(): void
    {
        Http::fake(['*catchment*' => Http::response(['catchment' => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.1, 51.5], [-0.2, 51.5], [-0.2, 51.6], [-0.1, 51.5],
            ]]],
        ]], 200)]);

        $wkt = $this->service()->catchmentWkt(51.5, -0.1, 12.5);
        $this->assertNotNull($wkt);
        $this->assertStringStartsWith('POLYGON((', $wkt);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/catchment') && (float) $req['minutes'] === 12.5);
    }

    public function test_catchment_wkt_returns_null_on_server_error(): void
    {
        Http::fake(['*catchment*' => Http::response('', 500)]);
        $this->assertNull($this->service()->catchmentWkt(51.5, -0.1, 12.5));
    }

    /** A GeoJSON polygon Feature around the given square, as the routing server ships it. */
    private function geoSquare(float $w, float $s, float $e, float $n): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [$w, $s], [$e, $s], [$e, $n], [$w, $n], [$w, $s],
            ]]],
        ];
    }

    public function test_catchment_geometry_parses_polygon_and_sandwich_bounds(): void
    {
        // The routing server ships sandwich bounds alongside the exact catchment
        // (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md); catchmentGeometry exposes
        // all three as WKT for the reach writers.
        Http::fake(['*catchment*' => Http::response([
            'catchment' => $this->geoSquare(-0.2, 51.4, 0.0, 51.6),
            'catchment_outer' => $this->geoSquare(-0.21, 51.39, 0.01, 51.61),
            'catchment_inner' => $this->geoSquare(-0.19, 51.41, -0.01, 51.59),
        ], 200)]);

        $geom = $this->service()->catchmentGeometry(51.5, -0.1, 12.5);

        $this->assertNotNull($geom);
        $this->assertStringStartsWith('POLYGON((', $geom['wkt']);
        $this->assertStringStartsWith('POLYGON((', $geom['outer']);
        $this->assertStringStartsWith('POLYGON((', $geom['inner']);
        $this->assertStringContainsString('-0.21 51.39', $geom['outer']);
        $this->assertStringContainsString('-0.19 51.41', $geom['inner']);
    }

    public function test_catchment_geometry_asks_for_the_coarse_form_only_when_told(): void
    {
        // The region-scale form is what stops a late tick costing seconds and megabytes
        // on a shared compute slot, but it is opt-in per call: the ModTools reach map and
        // the explorer's catchment tab want the real outline.
        Http::fake(['*catchment*' => Http::response([
            'catchment' => $this->geoSquare(-0.2, 51.4, 0.0, 51.6),
        ], 200)]);

        $this->service()->catchmentGeometry(51.5, -0.1, 12.5, true);
        Http::assertSent(fn ($request) => ($request->data()['coarse'] ?? null) === '1');
    }

    public function test_catchment_geometry_omits_the_coarse_parameter_by_default(): void
    {
        // Omitted rather than sent as 0, so the request an old routing server sees is
        // byte-for-byte the one it saw before this existed.
        Http::fake(['*catchment*' => Http::response([
            'catchment' => $this->geoSquare(-0.2, 51.4, 0.0, 51.6),
        ], 200)]);

        $this->service()->catchmentGeometry(51.5, -0.1, 12.5);
        Http::assertSent(fn ($request) => !array_key_exists('coarse', $request->data()));
    }

    public function test_catchment_geometry_tolerates_absent_bounds(): void
    {
        // Old routing servers (or an eroded-to-nothing inner) simply omit the bounds:
        // the polygon still comes through and the bounds are null.
        Http::fake(['*catchment*' => Http::response([
            'catchment' => $this->geoSquare(-0.2, 51.4, 0.0, 51.6),
        ], 200)]);

        $geom = $this->service()->catchmentGeometry(51.5, -0.1, 12.5);

        $this->assertNotNull($geom);
        $this->assertStringStartsWith('POLYGON((', $geom['wkt']);
        $this->assertNull($geom['outer']);
        $this->assertNull($geom['inner']);
    }

    public function test_schedule_omits_both_overflow_lanes_by_default(): void
    {
        // Dark by default: neither lane touches the request, so an unconfigured deployment
        // gets a byte-identical schedule and the stored reach is unchanged.
        config(['freegle.ripple.rural_access.enabled' => false]);
        config(['freegle.ripple.fairness.enabled' => false]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => !str_contains($request->url(), 'rural_access')
            && !str_contains($request->url(), 'fairness_weight'));
    }

    public function test_schedule_asks_for_rural_rings_when_enabled(): void
    {
        config(['freegle.ripple.rural_access.enabled' => true]);
        config(['freegle.ripple.fairness.enabled' => false]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'rural_access=1')
            && !str_contains($request->url(), 'fairness_weight'));
    }

    public function test_schedule_sends_fairness_weight_and_quintile_when_enabled(): void
    {
        config(['freegle.ripple.fairness.enabled' => true]);
        config(['freegle.ripple.fairness.weight' => 0.5]);
        config(['freegle.ripple.fairness.max_quintile' => 1]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fairness_weight=0.5')
            && str_contains($request->url(), 'fairness_max_quintile=1'));
    }

    /**
     * Weight zero is the deliberate first rollout step: the flag on, the plumbing exercised
     * end to end, and no behaviour change. It must therefore send nothing, or the routing
     * server would run the extra road-network search for a stretch of exactly zero.
     */
    public function test_schedule_sends_no_fairness_at_zero_weight(): void
    {
        config(['freegle.ripple.fairness.enabled' => true]);
        config(['freegle.ripple.fairness.weight' => 0.0]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => !str_contains($request->url(), 'fairness_weight'));
    }

    /**
     * A misconfigured weight must be clamped here rather than trusted. The routing server
     * clamps too, but a value of 5 silently becoming 1 at the far end is harder to explain
     * than one that never leaves.
     */
    public function test_schedule_clamps_a_fairness_weight_above_one(): void
    {
        config(['freegle.ripple.fairness.enabled' => true]);
        config(['freegle.ripple.fairness.weight' => 5.0]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fairness_weight=1'));
    }

    /**
     * The most deprived fifth only, by default. The measured shortfall is a knee there rather
     * than a gradient across all five, and one fifth needs one traced ring rather than four.
     */
    public function test_fairness_defaults_to_the_most_deprived_fifth_only(): void
    {
        config(['freegle.ripple.fairness.enabled' => true]);
        config(['freegle.ripple.fairness.weight' => 0.5]);
        config(['freegle.ripple.fairness.max_quintile' => null]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fairness_max_quintile=1'));
    }

    public function test_parseScheduleResponse_carries_the_rural_rings(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
            'overflow_rural' => [
                'dense' => ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]]],
                'sparse' => ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [3, 0], [3, 3], [0, 0]]]]],
            ],
        ]);

        $this->assertNotNull($parsed['overflow_bounds']);
        $this->assertArrayHasKey('rural', $parsed['overflow_bounds']);
        $this->assertStringStartsWith('POLYGON((', $parsed['overflow_bounds']['rural']['dense']);
        $this->assertStringStartsWith('POLYGON((', $parsed['overflow_bounds']['rural']['sparse']);
        $this->assertArrayNotHasKey('fairness', $parsed['overflow_bounds']);
    }

    public function test_parseScheduleResponse_carries_the_fairness_rings_and_the_budget_applied(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
            'overflow_fairness' => [
                '1' => ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [4, 0], [4, 4], [0, 0]]]]],
            ],
            'fairness_budget_min' => 67.5,
        ]);

        $this->assertArrayHasKey('fairness', $parsed['overflow_bounds']);
        $this->assertStringStartsWith('POLYGON((', $parsed['overflow_bounds']['fairness']['1']);
        // The budget actually routed, not whatever is configured when the row is later read.
        $this->assertSame(67.5, $parsed['overflow_bounds']['fairness_budget_min']);
    }

    /**
     * NULL must mean "no lane applied", never "a lane that produced nothing". A row read back
     * as an empty array would look like an admissible-to-nobody lane rather than no lane.
     */
    public function test_parseScheduleResponse_gives_null_overflow_when_no_lane_applied(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
        ]);
        $this->assertNull($parsed['overflow_bounds']);

        // An empty lane object from the server is the same thing as no lane.
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
            'overflow_rural' => [],
        ]);
        $this->assertNull($parsed['overflow_bounds']);
    }

    // --- Cluster-anchor overflow (contract: cluster_anchor / cluster_floor / cluster_k /
    // cluster_max_wedges / cluster_max_minutes; overflow_cluster keyed w1..w3) -------------

    public function test_parseScheduleResponse_carries_the_cluster_wedges(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
            'overflow_cluster' => [
                'w1' => $this->geoSquare(-0.2, 51.4, 0.0, 51.6),
                'w2' => $this->geoSquare(0.0, 51.4, 0.2, 51.6),
            ],
        ]);

        $this->assertNotNull($parsed['overflow_bounds']);
        $this->assertArrayHasKey('cluster', $parsed['overflow_bounds']);
        $this->assertStringStartsWith('POLYGON((', $parsed['overflow_bounds']['cluster']['w1']);
        $this->assertStringStartsWith('POLYGON((', $parsed['overflow_bounds']['cluster']['w2']);
        $this->assertArrayNotHasKey('rural', $parsed['overflow_bounds']);
        $this->assertArrayNotHasKey('fairness', $parsed['overflow_bounds']);
    }

    /**
     * Rural fires when the audience cap bound; cluster fires when it did not - opposite
     * conditions, so the two are independent rather than mutually exclusive. Both keys must
     * survive parsing at once, not just whichever the loop happens to see first.
     */
    public function test_parseScheduleResponse_rural_and_cluster_coexist(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
            'overflow_rural' => [
                'sparse' => $this->geoSquare(-0.3, 51.3, -0.1, 51.5),
            ],
            'overflow_cluster' => [
                'w1' => $this->geoSquare(0.2, 51.4, 0.4, 51.6),
            ],
        ]);

        $this->assertArrayHasKey('rural', $parsed['overflow_bounds'], 'rural lane must survive alongside cluster');
        $this->assertArrayHasKey('cluster', $parsed['overflow_bounds'], 'cluster lane must survive alongside rural');
        $this->assertStringStartsWith('POLYGON((', $parsed['overflow_bounds']['rural']['sparse']);
        $this->assertStringStartsWith('POLYGON((', $parsed['overflow_bounds']['cluster']['w1']);
    }

    /**
     * Regression for the bug where a new lane ships with a bbox that silently excludes it:
     * ringsBbox() has its own lane list and must be told about every lane parseOverflow can
     * produce, or the read-side bbox prefilter rejects every candidate for that lane before
     * the exact ring test ever runs. Cluster-only (no rural/fairness) isolates the bug: before
     * the fix ringsBbox() never even looks at 'cluster', so 'bbox' is absent entirely.
     */
    public function test_bbox_covers_cluster_wedges_when_no_other_lane_present(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
            'overflow_cluster' => [
                'w1' => $this->geoSquare(0.2, 51.4, 0.4, 51.6),
            ],
        ]);

        $this->assertArrayHasKey('bbox', $parsed['overflow_bounds'], 'cluster-only rings must still get a bbox');
        [$minLng, $minLat, $maxLng, $maxLat] = $parsed['overflow_bounds']['bbox'];
        $this->assertEqualsWithDelta(0.2, $minLng, 0.0001);
        $this->assertEqualsWithDelta(51.4, $minLat, 0.0001);
        $this->assertEqualsWithDelta(0.4, $maxLng, 0.0001);
        $this->assertEqualsWithDelta(51.6, $maxLat, 0.0001);
    }

    /** bbox must cover cluster wedges TOO when rural is also present, not just rural's box. */
    public function test_bbox_covers_cluster_wedges_alongside_rural(): void
    {
        $parsed = $this->service()->parseScheduleResponse([
            'schedule' => [['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 10]],
            'overflow_rural' => [
                'sparse' => $this->geoSquare(-0.3, 51.3, -0.1, 51.5),
            ],
            'overflow_cluster' => [
                'w1' => $this->geoSquare(0.2, 51.4, 0.4, 51.6),
            ],
        ]);

        [$minLng, $minLat, $maxLng, $maxLat] = $parsed['overflow_bounds']['bbox'];
        // The union of the rural box (-0.3..-0.1, 51.3..51.5) and the cluster box
        // (0.2..0.4, 51.4..51.6): the far corners on each axis.
        $this->assertEqualsWithDelta(-0.3, $minLng, 0.0001);
        $this->assertEqualsWithDelta(51.3, $minLat, 0.0001);
        $this->assertEqualsWithDelta(0.4, $maxLng, 0.0001);
        $this->assertEqualsWithDelta(51.6, $maxLat, 0.0001);
    }

    public function test_schedule_omits_cluster_params_when_disabled(): void
    {
        config(['freegle.ripple.cluster.enabled' => false]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => !str_contains($request->url(), 'cluster_anchor')
            && !str_contains($request->url(), 'cluster_floor')
            && !str_contains($request->url(), 'cluster_k')
            && !str_contains($request->url(), 'cluster_max_wedges')
            && !str_contains($request->url(), 'cluster_max_minutes'));
    }

    public function test_schedule_sends_cluster_params_when_enabled(): void
    {
        config([
            'freegle.ripple.cluster.enabled' => true,
            'freegle.ripple.cluster.floor' => 1000,
            'freegle.ripple.cluster.cell_k' => 150,
            'freegle.ripple.cluster.max_wedges' => 3,
            'freegle.ripple.cluster.max_minutes' => 60,
        ]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'cluster_anchor=1')
            && str_contains($request->url(), 'cluster_floor=1000')
            && str_contains($request->url(), 'cluster_k=150')
            && str_contains($request->url(), 'cluster_max_wedges=3')
            && str_contains($request->url(), 'cluster_max_minutes=60'));
    }

    /** Hard cap 3 (contract) - a misconfigured value must be clamped here, not trusted. */
    public function test_schedule_clamps_cluster_max_wedges_above_the_hard_cap(): void
    {
        config(['freegle.ripple.cluster.enabled' => true, 'freegle.ripple.cluster.max_wedges' => 9]);
        Http::fake(['*ripple-schedule*' => Http::response(['schedule' => []], 200)]);

        $this->service()->computeSchedule(51.5, -0.1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'cluster_max_wedges=3'));
    }


    public function test_label_verdicts_with_discover_returns_both_lists(): void
    {
        Http::fake(['*reach-eval*' => Http::response([
            'results' => [['msgid' => 1, 'verdict' => 'out'], ['msgid' => 2, 'verdict' => 'nolabels']],
            'discovered' => [['msgid' => 7, 'verdict' => 'in'], ['msgid' => 8, 'verdict' => 'in']],
        ])]);

        $eval = app(ReachService::class)->labelVerdictsWithDiscover(51.5, -0.1, [1, 2]);

        // 'nolabels' is absence (the caller keeps its grid verdict), 'out' is a verdict.
        $this->assertSame([1 => 'out'], $eval['verdicts']);
        $this->assertSame([7, 8], $eval['discovered']);
        Http::assertSent(fn ($req) => ($req['discover'] ?? false) === true);
    }

    public function test_label_verdicts_with_discover_calls_even_with_no_candidates(): void
    {
        // A member covered by NO grid can still be admitted by a stored label,
        // so an empty candidate list must still ask the routing server.
        Http::fake(['*reach-eval*' => Http::response([
            'results' => [],
            'discovered' => [['msgid' => 7, 'verdict' => 'in']],
        ])]);

        $eval = app(ReachService::class)->labelVerdictsWithDiscover(51.5, -0.1, []);

        $this->assertSame([7], $eval['discovered']);
    }



    /**
     * An outage has to be visible from outside the site.
     *
     * Every gate in front of these verdicts now fails open on purpose - a
     * reply goes through, no "hasn't reached you yet" notice is shown - so
     * nothing a member sees says anything is wrong. On 2026-09-02 the engine
     * was down sixteen hours and the way we found out was a member asking why
     * a post three miles away had not reached her.
     */
    public function test_an_unanswerable_reach_call_is_reported(): void
    {
        Log::spy();
        Http::fake(['*reach-eval*' => Http::response(null, 503)]);

        app(ReachService::class)->labelVerdicts(51.5, -0.1, [1]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'reach evaluation unavailable'))
            ->once();
    }

    /**
     * One report a minute per process, no matter how many calls fail. These
     * calls sit on the feed's hot path, so an outage would otherwise post
     * thousands of identical alerts a minute and bury everything else.
     */
    public function test_repeated_failures_report_once_a_minute(): void
    {
        Log::spy();
        Http::fake(['*reach-eval*' => Http::response(null, 503)]);

        $svc = app(ReachService::class);
        $svc->labelVerdicts(51.5, -0.1, [1]);
        $svc->labelVerdicts(51.5, -0.1, [2]);
        $svc->labelVerdicts(51.5, -0.1, [3]);

        Http::assertSentCount(3);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'reach evaluation unavailable'))
            ->once();
    }

    /** A working routing server reports nothing. */
    public function test_a_successful_reach_call_reports_nothing(): void
    {
        Log::spy();
        Http::fake(['*reach-eval*' => Http::response(['results' => [['msgid' => 1, 'verdict' => 'in']]])]);

        app(ReachService::class)->labelVerdicts(51.5, -0.1, [1]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_label_eval_breaker_stops_calls_after_a_server_fault(): void
    {
        Http::fake(['*reach-eval*' => Http::response(null, 500)]);

        $svc = app(ReachService::class);
        $this->assertSame([], $svc->labelVerdicts(51.5, -0.1, [1]));
        // The 500 opened the breaker: the next call must not touch HTTP at
        // all - a digest run asks once per RECIPIENT, and thousands of 3s
        // timeouts against a browning-out routing server would stall mail.
        $this->assertSame([], $svc->labelVerdicts(51.5, -0.1, [2]));
        Http::assertSentCount(1);

        ReachService::resetLabelEvalBreaker();
    }

    public function test_label_eval_404_and_503_do_not_trip_the_breaker(): void
    {
        Http::fake(['*reach-eval*' => Http::response(null, 404)]);

        $svc = app(ReachService::class);
        $this->assertSame([], $svc->labelVerdicts(51.5, -0.1, [1]));
        $this->assertSame([], $svc->labelVerdicts(51.5, -0.1, [2]));
        // Expected states (endpoint not deployed yet) answer instantly, so
        // every call still goes out.
        Http::assertSentCount(2);
    }

    public function test_label_verdicts_skips_out_in_origin_group_area(): void
    {
        // out+origin_area = the member stands in the post's origin group's
        // area, which the stored reach deliberately unions in: no verdict,
        // the cell grid decides.
        Http::fake(['*reach-eval*' => Http::response([
            'results' => [
                ['msgid' => 1, 'verdict' => 'out'],
                ['msgid' => 2, 'verdict' => 'out', 'origin_area' => true],
            ],
        ])]);

        $this->assertSame([1 => 'out'], app(ReachService::class)->labelVerdicts(51.5, -0.1, [1, 2]));
    }

    public function test_discovered_ids_narrowed_by_a_later_chunk_are_dropped(): void
    {
        // 1001 candidates = two chunks. Chunk 0 discovers id 1001 (it was
        // not in chunk 0's asked set); chunk 1 then verdicts 1001 'out'.
        // The verdict wins: never re-admit what the labels narrowed away.
        Http::fake(['*reach-eval*' => Http::sequence()
            ->push(['results' => [], 'discovered' => [['msgid' => 1001, 'verdict' => 'in']]])
            ->push(['results' => [['msgid' => 1001, 'verdict' => 'out']], 'discovered' => []]),
        ]);

        $eval = app(ReachService::class)->labelVerdictsWithDiscover(51.5, -0.1, range(1, 1001));

        $this->assertSame(['verdicts' => [1001 => 'out'], 'discovered' => []], $eval);
    }
}
