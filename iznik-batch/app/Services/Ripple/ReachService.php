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
    /** groupProximity() status: definitive answer; body carries closest/furthest/quicker. */
    public const PROX_OK = 'ok';

    /** groupProximity() status: definitive answer - group not reachable within the budget. */
    public const PROX_UNREACHABLE = 'unreachable';

    /**
     * groupProximity() status: no usable answer (exception, timeout, non-2xx - e.g. the routing
     * server mid-restart). NOT definitive: callers must retry later and never memoize this,
     * otherwise a routing restart would permanently suppress notes for rows checked during it.
     */
    public const PROX_ERROR = 'error';

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
     * $maxMinutes overrides the configured flat cap for this one origin - that is
     * how the density-conditional cap (DensityService) shortens city posts and
     * lengthens country ones. Null keeps the flat cap.
     *
     * @return array{total_freeglers:int,max_drive_min:float,ticks:array<int,array{tick:int,drive_min:float,cumulative_users:int,wkt:string}>,reachable_group_ids:int[]}|null
     */
    public function computeSchedule(float $lat, float $lng, ?float $maxMinutes = null): ?array
    {
        try {
            $response = Http::timeout($this->requestTimeout)
                ->get("{$this->url}/v1/ripple-schedule", $this->scheduleParams($lat, $lng, $maxMinutes));
        } catch (\Throwable $e) {
            Log::warning("ripple: schedule fetch failed: {$e->getMessage()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning("ripple: schedule HTTP {$response->status()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }

        return $this->parseScheduleResponse($response->json() ?? [], $maxMinutes);
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
     * Each origin may carry its own `max_minutes`; absent means the configured flat cap.
     * ExpandService passes DensityService::ceiling() - the widest budget any band earns -
     * because the cap belongs to the recipient rather than the post, and each member is held
     * to their own band on the way out (see DensityService's docblock).
     *
     * @param array<int,array{lat:float,lng:float,max_minutes?:float}> $origins
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
                    ->get($url, $this->scheduleParams(
                        (float) $o['lat'],
                        (float) $o['lng'],
                        isset($o['max_minutes']) ? (float) $o['max_minutes'] : null
                    )),
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
            $out[$i] = $this->parseScheduleResponse(
                $resp->json() ?? [],
                isset($o['max_minutes']) ? (float) $o['max_minutes'] : null
            );
        }

        return $out;
    }

    /**
     * P/Q proximity for a post rippling into a group: P = nearest in-group point to the offer,
     * Q = the in-group point furthest FROM P, each with road drive-time. Backs the moderator
     * "quicker to get to" line. Never throws.
     *
     * Tri-state so callers can tell a definitive "no" (safe to memoize checked-once-forever)
     * from a failed call (must retry): PROX_OK carries the routing body, PROX_UNREACHABLE means
     * the routing server answered that the group is beyond the budget, PROX_ERROR means no
     * usable answer was obtained (timeout/non-2xx/exception) and nothing may be memoized.
     *
     * @return array{status:string, body:?array{closest:array{lat:float,lng:float,drive_min:float},furthest:array{lat:float,lng:float,drive_min:float},quicker:bool}}
     */
    public function groupProximity(float $lat, float $lng, int $groupid, ?float $maxMinutes = null): array
    {
        // Best-effort moderator note, computed OUT of the hot ripple:expand cron (by the
        // ripple:proximity-notes command), so a slacker timeout is fine here. Slow or failed calls
        // are surfaced to Sentry for visibility rather than silently swallowed. Never throws.
        $timeout = (int) config('freegle.ripple.proximity_timeout', 15);
        $started = microtime(true);
        $query = [
            'groupid' => $groupid,
            'lat' => $lat,
            'lng' => $lng,
            'mode' => $this->mode,
        ];
        // Scope the isochrone exploration to the post's own reach budget rather than the
        // routing server default (120 min). Over-exploring made every note call ~4x slower
        // and, on dense-urban high-ripple groups, tripped the 3s slow-warning en masse
        // (Sentry storm 2026-07-06, groupid=21521). A post only rippled into groups within
        // its reach, so the note never needs to look further than that.
        if ($maxMinutes !== null && $maxMinutes > 0) {
            $query['max_minutes'] = (int) ceil($maxMinutes);
        }
        try {
            $response = Http::timeout($timeout)
                ->get("{$this->url}/v1/group-proximity", $query);
        } catch (\Throwable $e) {
            $this->reportProximityTiming($groupid, (microtime(true) - $started) * 1000, $e->getMessage());
            return ['status' => self::PROX_ERROR, 'body' => null];
        }

        $elapsedMs = (microtime(true) - $started) * 1000;
        if (!$response->successful()) {
            $this->reportProximityTiming($groupid, $elapsedMs, "HTTP {$response->status()}");
            return ['status' => self::PROX_ERROR, 'body' => null];
        }
        $this->reportProximityTiming($groupid, $elapsedMs, null);

        $body = $response->json() ?? [];
        if (!($body['reachable'] ?? false)) {
            return ['status' => self::PROX_UNREACHABLE, 'body' => null];
        }

        return ['status' => self::PROX_OK, 'body' => $body];
    }

    /**
     * Report a slow or failed group-proximity call to Sentry (and the log) for monitoring. The note
     * is best-effort so we never fail on it, but a routing server that is slow/erroring for proximity
     * is worth surfacing. Fast, successful calls are silent.
     */
    private function reportProximityTiming(int $groupid, float $ms, ?string $error): void
    {
        $slowMs = (float) config('freegle.ripple.proximity_slow_ms', 3000);
        if ($error === null && $ms < $slowMs) {
            return;
        }
        $msg = $error !== null
            ? 'ripple: group-proximity failed after ' . round($ms) . 'ms (groupid=' . $groupid . '): ' . $error
            : 'ripple: slow group-proximity ' . round($ms) . 'ms (groupid=' . $groupid . ')';
        Log::warning($msg);
        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\captureMessage($msg);
        }
    }

    /** Query parameters for a /v1/ripple-schedule request at the given origin. */
    private function scheduleParams(float $lat, float $lng, ?float $maxMinutes = null): array
    {
        $params = [
            'lat' => $lat,
            'lng' => $lng,
            'mode' => $this->mode,
            'ticks' => $this->totalTicks(),
            'max_minutes' => $maxMinutes !== null && $maxMinutes > 0 ? $maxMinutes : $this->maxMinutes,
            'curve' => $this->curve,
            // Slim form: the batch needs per-tick drive_min / cumulative_users /
            // reachable_group_ids, not a ~20k-vertex polygon per tick (which made a
            // London schedule call ~24MB and dominated the stored schedule size).
            // Tick polygons are fetched one at a time as ticks are actually reached
            // (see catchmentWkt). Old servers ignore the parameter and return
            // polygons, which parseScheduleResponse still accepts.
            'polygons' => '0',
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
     * @return array{total_freeglers:int,max_drive_min:float,ticks:array<int,array{tick:int,drive_min:float,cumulative_users:int,wkt:string}>,reachable_group_ids:int[]}|null
     */
    public function parseScheduleResponse(array $body, ?float $maxMinutes = null): ?array
    {
        $schedule = $body['schedule'] ?? [];
        if (empty($schedule)) {
            return null;
        }

        $ticks = [];
        foreach ($schedule as $entry) {
            $tick = [
                'tick' => (int) ($entry['tick'] ?? (count($ticks) + 1)),
                'drive_min' => (float) ($entry['drive_min'] ?? 0),
                'cumulative_users' => (int) ($entry['cumulative_users'] ?? 0),
            ];
            // Slim responses (polygons=0) carry no per-tick polygon - the tick's
            // polygon is fetched when the tick is reached (catchmentWkt). A full
            // response's polygon is kept as WKT exactly as before.
            $wkt = $this->polygonToWkt($entry['polygon'] ?? null);
            if ($wkt !== null) {
                $tick['wkt'] = $wkt;
            }
            // Per-tick targeting decision: groups with >=1 active in-polygon member
            // road-reachable within THIS tick's drive-time. Absent on older servers.
            if (isset($entry['reachable_group_ids']) && is_array($entry['reachable_group_ids'])) {
                $tick['reachable_group_ids'] = array_map('intval', $entry['reachable_group_ids']);
            }
            $ticks[] = $tick;
        }
        if (empty($ticks)) {
            return null;
        }

        return [
            'total_freeglers' => (int) ($body['total_freeglers'] ?? 0),
            'max_drive_min' => (float) ($body['max_drive_min'] ?? (
                $maxMinutes !== null && $maxMinutes > 0 ? $maxMinutes : $this->maxMinutes
            )),
            'ticks' => $ticks,
            // Groups containing a road node reachable from the origin - the
            // water/toll-correct ripple-targeting signal. Empty when the server
            // omits it (older build); the gate treats [] as "not available".
            'reachable_group_ids' => array_map('intval', $body['reachable_group_ids'] ?? []),
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
    /**
     * Fetch the reach polygon for a single drive-time budget as WKT, via the routing
     * server's point-form /v1/catchment (one Dijkstra, one polygon - unlike
     * /v1/isochrone it computes only the requested mode). Used to materialise a
     * tick's polygon when the slim schedule (polygons=0) is in use, and by the
     * reach backfill. Returns null when the routing server is unreachable or the
     * origin is off the road graph; callers treat that as "skip this run".
     */
    public function catchmentWkt(float $lat, float $lng, float $minutes): ?string
    {
        return $this->catchmentGeometry($lat, $lng, $minutes)['wkt'] ?? null;
    }

    /**
     * As catchmentWkt, but also returns the routing server's sandwich bounds when it
     * ships them (catchment_outer / catchment_inner — derived on the routing server's
     * own rasterisation grid, see iznik-routing-go bounds.go):
     * ['wkt' => string, 'outer' => ?string, 'inner' => ?string], or null when the
     * routing server is unreachable / the origin is off-graph. The bounds are null on
     * older servers or when a small reach eroded to no inner — callers fall back to
     * SQL derivation (ReachBoundsService).
     */
    public function catchmentGeometry(float $lat, float $lng, float $minutes): ?array
    {
        try {
            $response = Http::timeout($this->requestTimeout)
                ->get("{$this->url}/v1/catchment", [
                    'lat' => $lat,
                    'lng' => $lng,
                    'minutes' => $minutes,
                    'mode' => $this->mode,
                ]);
        } catch (\Throwable $e) {
            Log::warning("ripple: catchment fetch failed: {$e->getMessage()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }
        if (!$response->successful()) {
            Log::warning("ripple: catchment HTTP {$response->status()}", ['lat' => $lat, 'lng' => $lng]);
            return null;
        }
        $body = $response->json() ?? [];
        $wkt = $this->polygonToWkt($body['catchment'] ?? null);
        if ($wkt === null) {
            return null;
        }

        return [
            'wkt' => $wkt,
            'outer' => $this->polygonToWkt($body['catchment_outer'] ?? null),
            'inner' => $this->polygonToWkt($body['catchment_inner'] ?? null),
        ];
    }

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
