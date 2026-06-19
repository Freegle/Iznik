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
}
