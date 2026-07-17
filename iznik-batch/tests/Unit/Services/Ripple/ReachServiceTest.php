<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\ReachService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
}
