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

    /**
     * Rural-access overflow: ask the routing server for one ring per density-band ceiling
     * alongside the capped reach, so a member the HEADCOUNT shut out of a post they are
     * within their own travel budget of can still find it. Off unless configured, and the
     * routing server omits the field entirely when it is not asked for, so the stored
     * schedule is unchanged until this is turned on.
     */
    private bool $ruralAccess;

    /**
     * Demographic-fairness overflow: stretch the travel-time budget for deprived recipients
     * on the reaches the cap never bound, which is where the measured shortfall is. Weight 0
     * (the default) is a complete no-op end to end.
     */
    private float $fairnessWeight;

    /**
     * How far down the deprivation scale the stretch reaches. 1 = the most deprived fifth
     * only, which is both what the measurement supports (the shortfall is a knee at the most
     * deprived fifth, with the other four within about 7% of each other) and far cheaper,
     * needing one traced ring rather than four.
     */
    private int $fairnessMaxQuintile;

    /**
     * Cluster-anchor overflow: the opposite miss from rural-access/fairness above. Those two
     * fire when the audience cap BOUND (the reach stopped short of the travel-time budget);
     * this fires when it did NOT (the reach ran its full budget and still left a dense pocket
     * of freeglers stranded just past the isochrone edge). Off unless configured, same as the
     * other two lanes.
     */
    private bool $clusterAnchor;

    /** Audience floor: the cluster pass only runs when the post's own pool is below this. */
    private int $clusterFloor;

    /** Cluster-cell density threshold (cell + 8 neighbours) a candidate cell must clear. */
    private int $clusterK;

    /** Hard cap (contract: 3) on how many wedge polygons a post can carry. */
    private int $clusterMaxWedges;

    /** Absolute drive-time bound for a cluster wedge, independent of the post's own budget. */
    private float $clusterMaxMinutes;

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
        // Rural-access and fairness overflow (cluster-anchor follows below): read once here,
        // mirroring targetUsers above, and sent only when enabled so an unconfigured
        // deployment gets a byte-identical schedule.
        $this->ruralAccess = (bool) config('freegle.ripple.rural_access.enabled', false);
        $this->fairnessWeight = config('freegle.ripple.fairness.enabled', false)
            ? max(0.0, min(1.0, (float) config('freegle.ripple.fairness.weight', 0.0)))
            : 0.0;
        $this->fairnessMaxQuintile = max(1, min(4, (int) config('freegle.ripple.fairness.max_quintile', 1)));
        $this->clusterAnchor = (bool) config('freegle.ripple.cluster.enabled', false);
        // Falls back to the audience cap, not to a number of its own: an independent
        // default is what left posts between the two lanes with neither.
        $this->clusterFloor = max(0, (int) config('freegle.ripple.cluster.floor',
            (int) config('freegle.ripple.extent.target_users', 4000)));
        $this->clusterK = max(0, (int) config('freegle.ripple.cluster.cell_k', 150));
        // Hard cap 3 per the interface contract - clamped here too rather than trusted
        // to the routing server, same posture as fairnessMaxQuintile above.
        $this->clusterMaxWedges = max(1, min(3, (int) config('freegle.ripple.cluster.max_wedges', 3)));
        $this->clusterMaxMinutes = max(0.0, (float) config('freegle.ripple.cluster.max_minutes', 60));
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

        // Rural-access and fairness are mutually exclusive PER POST, but which one applies
        // depends on whether the audience cap actually bound for that post, which only the
        // routing server knows once it has counted. So both are offered here and it picks;
        // asking for either costs nothing when it does not apply.
        if ($this->ruralAccess) {
            $params['rural_access'] = 1;
        }
        if ($this->fairnessWeight > 0) {
            $params['fairness_weight'] = $this->fairnessWeight;
            $params['fairness_max_quintile'] = $this->fairnessMaxQuintile;
        }

        // Cluster-anchor is independent of the two above: it fires precisely when the audience
        // cap did NOT bind (see parseOverflow), the opposite condition from rural/fairness, so
        // a post can carry a cluster ring alongside a rural or fairness one. Offered every time
        // cluster.enabled is on; the routing server decides per-post whether a qualifying cell
        // exists.
        if ($this->clusterAnchor) {
            $params['cluster_anchor'] = 1;
            $params['cluster_floor'] = $this->clusterFloor;
            $params['cluster_k'] = $this->clusterK;
            $params['cluster_max_wedges'] = $this->clusterMaxWedges;
            $params['cluster_max_minutes'] = $this->clusterMaxMinutes;
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
            // The overflow lanes' rings, when a lane was asked for and applied. Absent on
            // older servers and whenever every lane is off, so null means "no lane", never
            // "a lane with nothing in it".
            'overflow_bounds' => $this->parseOverflow($body),
        ];
    }

    /**
     * Turn the routing server's overflow rings into the JSON stored on rippling_reach.
     *
     * Three lanes, keyed by whether the audience cap bound for THIS post: rural and fairness
     * fire when it DID (the reach stopped at the nearest-N ceiling short of the travel-time
     * budget); cluster fires when it did NOT (the reach ran its full budget and still left a
     * dense pocket of freeglers stranded just past the edge). Rural and fairness remain
     * mutually exclusive with each other, but cluster is decided independently, so a post CAN
     * carry a cluster ring alongside a rural or fairness one - every lane present in the body
     * is kept here, not just the first one found. Geometry is converted to WKT here for the
     * same reason the tick polygons are: it is what MySQL's ST_GeomFromText wants and what the
     * fallback containment test reads back.
     *
     * Returns null rather than an empty array when there is nothing, so a row's NULL is
     * unambiguous: no lane applied, as against a lane that produced no drawable ring.
     */
    private function parseOverflow(array $body): ?array
    {
        $out = [];

        foreach ([
            'overflow_rural' => 'rural',
            'overflow_fairness' => 'fairness',
            'overflow_cluster' => 'cluster',
        ] as $key => $name) {
            $rings = $body[$key] ?? null;
            if (! is_array($rings) || empty($rings)) {
                continue;
            }
            $converted = [];
            foreach ($rings as $band => $geom) {
                $wkt = $this->polygonToWkt($geom);
                if ($wkt !== null) {
                    $converted[(string) $band] = $wkt;
                }
            }
            if (! empty($converted)) {
                $out[$name] = $converted;
            }
        }

        if (empty($out)) {
            return null;
        }

        // Record the weight actually applied, not the weight configured at read time: a row
        // written under one weight must not be read as though it had another, and the reuse
        // guard compares this to decide whether a stored schedule is still valid.
        if (isset($out['fairness']) && isset($body['fairness_budget_min'])) {
            $out['fairness_budget_min'] = (float) $body['fairness_budget_min'];
        }

        // The bounding box of every ring, as [minLng, minLat, maxLng, maxLat].
        //
        // This is what makes the rings readable on the BROWSE feed. There, unlike the mail,
        // every candidate row is a different post with a different ring, so the exact test has
        // to parse a polygon per row - on the path every request goes through. Four numeric
        // comparisons against a box reject almost every row before that happens, which is the
        // same shape as the cheap outer/inner bounds check already sitting in front of the
        // exact reach polygon.
        //
        // Stored beside the rings rather than in a column of its own precisely because it is
        // only ever a prefilter: it is never consulted without the exact test behind it, so it
        // needs no spatial index, and keeping it here costs no migration and cannot drift out
        // of step with the rings it describes.
        $bbox = $this->ringsBbox($out);
        if ($bbox !== null) {
            $out['bbox'] = $bbox;
        }

        return $out;
    }

    /**
     * The widest stretched budget the fairness lane would route to for a given ceiling, or
     * null when the lane is off. Used by the reuse guard: a stored ring computed under a
     * different weight is not this post's ring, so it must be recomputed rather than
     * inherited - the same argument as the reach-budget guard beside it.
     *
     * Mirrors the routing server's own arithmetic (fairnessoverflow.go): the widest budget is
     * the most deprived fifth's, ceiling x (1 + W).
     */
    public function fairnessBudgetMinutes(float $ceilingMinutes): ?float
    {
        if ($this->fairnessWeight <= 0) {
            return null;
        }

        return $ceilingMinutes * (1.0 + $this->fairnessWeight);
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

    /**
     * The box enclosing every ring in a parsed overflow set, as [minLng, minLat, maxLng, maxLat].
     *
     * Taken from the WKT actually stored rather than from the source geometry, so the box can
     * never describe a ring different from the one it ships with. Returns null if no ring
     * yielded a usable coordinate, which keeps "no rings" and "a box covering nothing" distinct.
     *
     * This lane list must be kept in step with parseOverflow()'s key map above: a lane added
     * there but not here ships rings whose bbox silently excludes them, and the reach mail's
     * bbox widening (UnifiedDigestService::overflowBboxBranch) then never even offers those
     * members to the ring index as candidates.
     *
     * @param  array<string, mixed>  $out
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function ringsBbox(array $out): ?array
    {
        $minLng = $minLat = INF;
        $maxLng = $maxLat = -INF;
        $seen = false;

        foreach (['rural', 'fairness', 'cluster'] as $lane) {
            foreach (($out[$lane] ?? []) as $wkt) {
                if (!is_string($wkt) || !preg_match('/^POLYGON\(\((.*)\)\)$/', $wkt, $m)) {
                    continue;
                }
                foreach (explode(',', $m[1]) as $pair) {
                    $parts = preg_split('/\s+/', trim($pair));
                    if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                        continue;
                    }
                    $lng = (float) $parts[0];
                    $lat = (float) $parts[1];
                    $minLng = min($minLng, $lng);
                    $maxLng = max($maxLng, $lng);
                    $minLat = min($minLat, $lat);
                    $maxLat = max($maxLat, $lat);
                    $seen = true;
                }
            }
        }

        return $seen ? [$minLng, $minLat, $maxLng, $maxLat] : null;
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
