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
    private int $requestTimeout;

    /** Stage-A audience cap: nearest-freegler ceiling per post, 0 = off. */
    private int $targetUsers;

    /** @var int[] hours-since-arrival thresholds, one per expansion tick */
    private array $hazardHours;

    public function __construct()
    {
        $this->url = rtrim(config('freegle.routing_server_url', 'http://spatial:8194'), '/');
        $this->curve = config('freegle.ripple.curve', 'step-70');
        $this->mode = config('freegle.ripple.mode', 'drive');
        $this->maxMinutes = (float) config('freegle.ripple.max_minutes', 30);
        $this->requestTimeout = (int) config('freegle.ripple.request_timeout', 60);
        // Audience-budget extent cap (Stage A). Only sent to the routing server
        // when the feature is enabled AND a positive target is set, so the
        // schedule (and thus reach) is unchanged until both are configured.
        $this->targetUsers = config('freegle.ripple.extent.enabled')
            ? max(0, (int) config('freegle.ripple.extent.target_users', 0))
            : 0;
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
            $response = Http::timeout($this->requestTimeout)
                ->get("{$this->url}/v1/ripple-schedule", $this->scheduleParams($lat, $lng));
        } catch (\Throwable $e) {
            Log::warning("ripple: schedule fetch failed: {$e->getMessage()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning("ripple: schedule HTTP {$response->status()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }

        return $this->parseScheduleResponse($response->json() ?? []);
    }

    /**
     * Compute schedules for several origins CONCURRENTLY (one HTTP request per origin,
     * fanned out via Http::pool / curl_multi). Read-only: callers apply the results to
     * the DB serially afterwards. Returns one entry per input origin, index-aligned,
     * each a parsed schedule or null (unreachable / off-graph / empty).
     *
     * The caller is expected to pass DISTINCT (already-blurred) origins — computeSchedule
     * is deterministic per origin, so de-duplicating origins before calling this turns
     * O(posts) routing calls into O(distinct origins).
     *
     * @param array<int,array{lat:float,lng:float}> $origins
     * @return array<int,?array>
     */
    public function computeSchedulesBatch(array $origins): array
    {
        if (empty($origins)) {
            return [];
        }

        $url = "{$this->url}/v1/ripple-schedule";
        try {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($o) => $pool->timeout($this->requestTimeout)
                    ->get($url, $this->scheduleParams((float) $o['lat'], (float) $o['lng'])),
                array_values($origins)
            ));
        } catch (\Throwable $e) {
            Log::warning("ripple: schedule pool failed: {$e->getMessage()}");
            return array_fill(0, count($origins), null);
        }

        $out = [];
        foreach (array_values($origins) as $i => $o) {
            $resp = $responses[$i] ?? null;
            if ($resp instanceof \Throwable) {
                Log::warning("ripple: schedule fetch failed: {$resp->getMessage()}", $o);
                $out[$i] = null;
                continue;
            }
            if ($resp === null || !$resp->successful()) {
                Log::warning('ripple: schedule HTTP ' . ($resp ? $resp->status() : 'no-response'), $o);
                $out[$i] = null;
                continue;
            }
            $out[$i] = $this->parseScheduleResponse($resp->json() ?? []);
        }

        return $out;
    }

    /** Query parameters for a /v1/ripple-schedule request at the given origin. */
    private function scheduleParams(float $lat, float $lng): array
    {
        $params = [
            'lat' => $lat,
            'lng' => $lng,
            'mode' => $this->mode,
            'ticks' => $this->totalTicks(),
            'max_minutes' => $this->maxMinutes,
            'curve' => $this->curve,
        ];
        // Only included when the audience cap is on, so the routing server's
        // schedule is byte-identical to the old behaviour otherwise.
        if ($this->targetUsers > 0) {
            $params['target_users'] = $this->targetUsers;
        }
        return $params;
    }

    /**
     * Parse a /v1/ripple-schedule JSON body into the schedule structure, or null if it
     * carries no usable ticks. Shared by the single and batch paths.
     *
     * @return array{total_freeglers:int,max_drive_min:float,ticks:array<int,array{tick:int,drive_min:float,cumulative_users:int,wkt:string}>}|null
     */
    public function parseScheduleResponse(array $body): ?array
    {
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
     * threshold). Returns null when $tick is already the post's final tick.
     *
     * $totalTicks is the post's own tick count (stored at init). Passing it makes the
     * 'done' transition robust to the config hazard schedule changing while a post is
     * mid-flight, and to routing ticks having been filtered out. Defaults to the
     * current config length.
     */
    public function nextExpansionAfter(Carbon $arrival, int $tick, ?int $totalTicks = null): ?Carbon
    {
        $total = $totalTicks ?? $this->totalTicks();
        if ($tick >= $total || !isset($this->hazardHours[$tick])) {
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
