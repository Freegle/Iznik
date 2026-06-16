<?php

namespace App\Services\Ripple;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Computes a post's rippled-out reach from the routing server
 * (iznik-routing-go) GET /v1/ripple-schedule, and provides the wall-clock
 * timing helpers the ripple:expand engine uses.
 *
 * No new container: this runs inside the existing batch container and calls the
 * routing server's internal (no-auth) port over HTTP — see
 * config('freegle.routing_server_url').
 *
 * One schedule "tick" is requested per entry in the hazard schedule, so the
 * number of ticks equals count(hazard_hours). Tick k's polygon is the reach the
 * post should have once it has been live for hazard_hours[k-1] hours.
 */
class ReachService
{
    private string $url;
    private string $curve;
    private string $mode;
    private float $maxMinutes;

    /** @var int[] hours-since-arrival thresholds, one per expansion tick */
    private array $hazardHours;

    public function __construct()
    {
        $this->url = rtrim(config('freegle.routing_server_url', 'http://spatial:8194'), '/');
        $this->curve = config('freegle.ripple.curve', 'step-70');
        $this->mode = config('freegle.ripple.mode', 'drive');
        $this->maxMinutes = (float) config('freegle.ripple.max_minutes', 30);
        $this->hazardHours = config('freegle.ripple.hazard_hours', [1, 3, 6, 12, 24, 48, 72, 120, 168]);
    }

    /** @return int[] */
    public function hazardHours(): array
    {
        return $this->hazardHours;
    }

    public function totalTicks(): int
    {
        return count($this->hazardHours);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Compute the full ripple schedule for a post origin (degrees). One tick per
     * hazard-hours entry. Returns null if the routing server is unreachable or
     * returns no usable schedule (e.g. origin off the road graph, or — in dev/CI
     * — the container has no UK graph loaded). Callers treat null as "leave the
     * reach unchanged this run".
     *
     * @return array{total_freeglers:int,max_drive_min:float,ticks:array<int,array{tick:int,drive_min:float,cumulative_users:int,wkt:string}>}|null
     */
    public function computeSchedule(float $lat, float $lng): ?array
    {
        try {
            $response = Http::timeout(20)->get("{$this->url}/v1/ripple-schedule", [
                'lat' => $lat,
                'lng' => $lng,
                'mode' => $this->mode,
                'ticks' => $this->totalTicks(),
                'max_minutes' => $this->maxMinutes,
                'curve' => $this->curve,
            ]);
        } catch (\Throwable $e) {
            Log::warning("ripple: schedule fetch failed: {$e->getMessage()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning("ripple: schedule HTTP {$response->status()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }

        $body = $response->json() ?? [];
        $schedule = $body['schedule'] ?? [];
        if (empty($schedule)) {
            return null;
        }

        $ticks = [];
        foreach ($schedule as $entry) {
            $wkt = $this->polygonToWkt($entry['polygon'] ?? null);
            if ($wkt === null) {
                continue;
            }
            $ticks[] = [
                'tick' => (int) ($entry['tick'] ?? (count($ticks) + 1)),
                'drive_min' => (float) ($entry['drive_min'] ?? 0),
                'cumulative_users' => (int) ($entry['cumulative_users'] ?? 0),
                'wkt' => $wkt,
            ];
        }
        if (empty($ticks)) {
            return null;
        }

        return [
            'total_freeglers' => (int) ($body['total_freeglers'] ?? 0),
            'max_drive_min' => (float) ($body['max_drive_min'] ?? $this->maxMinutes),
            'ticks' => $ticks,
        ];
    }

    /**
     * The tick index (1-based) a post should be at after $elapsedHours since
     * arrival, per the hazard schedule. Clamped to [1, totalTicks].
     */
    public function tickForElapsedHours(float $elapsedHours): int
    {
        $tick = 1;
        foreach ($this->hazardHours as $i => $h) {
            if ($elapsedHours >= $h) {
                $tick = $i + 1;
            }
        }
        return min(max($tick, 1), $this->totalTicks());
    }

    /**
     * When tick $tick should expand to the next one: arrival + hazardHours[$tick]
     * (hazardHours is 0-indexed and $tick is 1-based, so index $tick is the next
     * threshold). Returns null when $tick is already the final tick.
     */
    public function nextExpansionAfter(Carbon $arrival, int $tick): ?Carbon
    {
        if ($tick >= $this->totalTicks()) {
            return null;
        }
        return $arrival->copy()->addHours($this->hazardHours[$tick]);
    }

    /**
     * Convert a routing-server GeoJSON polygon Feature to a WKT POLYGON string.
     * Uses the outer ring only; coordinates are [lng, lat] (degrees), matching
     * how messages_spatial.point / isochrones.polygon store geometry.
     */
    private function polygonToWkt(?array $polygon): ?string
    {
        $ring = $polygon['geometry']['coordinates'][0] ?? null;
        if (!is_array($ring) || count($ring) < 4) {
            return null;
        }

        $pts = [];
        foreach ($ring as $pt) {
            if (!isset($pt[0], $pt[1])) {
                return null;
            }
            $pts[] = ((float) $pt[0]) . ' ' . ((float) $pt[1]);
        }

        // Ensure the ring is closed.
        if ($pts[0] !== $pts[count($pts) - 1]) {
            $pts[] = $pts[0];
        }

        return 'POLYGON((' . implode(', ', $pts) . '))';
    }
}
