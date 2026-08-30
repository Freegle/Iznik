<?php

namespace App\Services\Ripple;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    /**
     * Fetch and store the reach-engine LABELS for a post: the compact per-region
     * record from which membership is answered exactly (routing /v1/reach-labels),
     * plus the reached region ids for the feed prefilter. Labels are computed ONCE
     * at the post's maximum budget; every later tick just raises the effective
     * budget when evaluating them, so nothing is ever recomputed as reach grows.
     *
     * Best-effort and additive: on any failure the post simply has no labels yet
     * (readers fall back to the stored cells) and the backfill command
     * (ripple:backfill-reach-labels) or the next init retries. Never throws.
     */
    public function storeReachLabels(int $msgid, float $lat, float $lng, float $maxMinutes): bool
    {
        if ($maxMinutes <= 0) {
            return false;
        }
        try {
            $response = Http::timeout($this->requestTimeout)
                ->get("{$this->url}/v1/reach-labels", [
                    'lat' => $lat,
                    'lng' => $lng,
                    'minutes' => $maxMinutes,
                    // With the msgid the routing server also answers the
                    // road-native origin-group union (origin_union_secs +
                    // the group area's regions), stored alongside.
                    'msgid' => $msgid,
                ]);
        } catch (\Throwable $e) {
            Log::warning("ripple: reach-labels fetch failed: {$e->getMessage()}", ['msgid' => $msgid]);
            return false;
        }
        if (!$response->successful()) {
            // 503 = reach engine not configured; 404 = routing server predates the
            // endpoint. Both are expected until the artifacts are deployed, so stay
            // quiet about them.
            if (!in_array($response->status(), [503, 404], true)) {
                Log::warning("ripple: reach-labels HTTP {$response->status()}", ['msgid' => $msgid]);
            }
            return false;
        }
        $body = $response->json() ?? [];
        $labels = base64_decode((string) ($body['labels'] ?? ''), true);
        $leaves = $body['leaves'] ?? null;
        if ($labels === false || $labels === '' || !is_array($leaves)) {
            Log::warning('ripple: reach-labels response malformed', ['msgid' => $msgid]);
            return false;
        }
        // Union-admitted regions ride along with the reached ones, so members
        // the union admits DISCOVER the post; dedupe against the label's own.
        foreach ($body['union_leaves'] ?? [] as $leaf) {
            if (!in_array((int) $leaf, $leaves, false)) {
                $leaves[] = (int) $leaf;
            }
        }
        $update = ['reach_labels' => $labels];
        if (array_key_exists('origin_union_secs', $body)) {
            $update['origin_union_secs'] = (float) $body['origin_union_secs'];
        }
        $fp = !empty($body['fp']) ? (string) $body['fp'] : null;
        try {
            // One transaction: the blob and its leaves commit together. A blob
            // without its leaves would permanently hide the post from the leaf
            // prefilter, because every retry path keys off reach_labels IS NULL.
            DB::transaction(function () use ($msgid, $update, $leaves, $fp) {
                DB::table('rippling_reach')->where('msgid', $msgid)->update($update);
                DB::table('rippling_reach_leaves')->where('msgid', $msgid)->delete();
                foreach (array_chunk($leaves, 500) as $chunk) {
                    DB::table('rippling_reach_leaves')->insertOrIgnore(
                        collect($chunk)->map(function ($leaf) use ($msgid, $fp) {
                            $row = ['msgid' => $msgid, 'leaf' => (int) $leaf];
                            if ($fp !== null) {
                                $row['fp'] = $fp;
                            }

                            return $row;
                        })->all()
                    );
                }
            });
        } catch (\Throwable $e) {
            Log::warning("ripple: reach-labels store failed: {$e->getMessage()}", ['msgid' => $msgid]);
            return false;
        }
        return true;
    }

    /**
     * The backfill face of the union computation, for a post whose labels are
     * ALREADY stored: one POST /v1/reach-union with the stored blob computes
     * origin_union_secs + the group area's regions; the row is updated, the
     * union regions merged into its leaves, and its existing leaves stamped
     * with the build fingerprint the blob decoded on. False on any failure -
     * the row keeps origin_union_secs NULL and the transitional behaviour.
     */
    public function storeUnionSecs(int $msgid): bool
    {
        $row = DB::table('rippling_reach')->select('reach_labels')->where('msgid', $msgid)->first();
        if ($row === null || $row->reach_labels === null) {
            return false;
        }
        try {
            $response = Http::timeout($this->requestTimeout)
                ->post("{$this->url}/v1/reach-union", [
                    'labels' => base64_encode((string) $row->reach_labels),
                    'msgid' => $msgid,
                ]);
        } catch (\Throwable $e) {
            Log::warning("ripple: reach-union fetch failed: {$e->getMessage()}", ['msgid' => $msgid]);

            return false;
        }
        if (!$response->successful()) {
            // 503/404 = not deployed yet; 422 = the blob belongs to a build
            // the routing server no longer holds (re-run the label backfill).
            if (!in_array($response->status(), [503, 404, 422], true)) {
                Log::warning("ripple: reach-union HTTP {$response->status()}", ['msgid' => $msgid]);
            }

            return false;
        }
        $body = $response->json() ?? [];
        if (!array_key_exists('origin_union_secs', $body)) {
            return false;
        }
        $secs = (float) $body['origin_union_secs'];
        $unionLeaves = array_map('intval', $body['union_leaves'] ?? []);
        $fp = !empty($body['fp']) ? (string) $body['fp'] : null;
        try {
            DB::transaction(function () use ($msgid, $secs, $unionLeaves, $fp) {
                DB::table('rippling_reach')->where('msgid', $msgid)->update(['origin_union_secs' => $secs]);
                if ($fp !== null) {
                    DB::table('rippling_reach_leaves')->where('msgid', $msgid)->whereNull('fp')->update(['fp' => $fp]);
                }
                foreach (array_chunk($unionLeaves, 500) as $chunk) {
                    DB::table('rippling_reach_leaves')->insertOrIgnore(
                        collect($chunk)->map(function ($leaf) use ($msgid, $fp) {
                            $row = ['msgid' => $msgid, 'leaf' => (int) $leaf];
                            if ($fp !== null) {
                                $row['fp'] = $fp;
                            }

                            return $row;
                        })->all()
                    );
                }
            });
        } catch (\Throwable $e) {
            Log::warning("ripple: reach-union store failed: {$e->getMessage()}", ['msgid' => $msgid]);

            return false;
        }

        return true;
    }

    /**
     * Road drive miles from one origin to a set of points, via the routing
     * server's reach engine (POST /v1/drive-metrics). $targets is
     * [id => [lat, lng]]; returns [id => miles] for the points the engine
     * answered. Empty array on any failure (503 = engine not deployed, quiet):
     * callers fall back to crow-flies. Used by the digest and matched-posts
     * emails so the distances members read match the road miles the site shows.
     *
     * @param  array<int|string, array{0: float, 1: float}>  $targets
     * @return array<int|string, float>
     */
    /** After a failed drive-metrics call, skip further ones until this time -
     *  a digest run sends thousands of emails, and without a breaker a down
     *  routing server would cost the full HTTP timeout on every one. */
    private static float $driveMetricsDownUntil = 0.0;

    /**
     * Reach-eval circuit breaker, same shape as the drive-metrics one below:
     * the digest/push loops call labelEval once per RECIPIENT, so without a
     * breaker a down or browning-out routing server costs the full HTTP
     * timeout on every one of thousands of sequential mails.
     */
    private static float $labelEvalDownUntil = 0.0;

    public static function resetLabelEvalBreaker(): void
    {
        self::$labelEvalDownUntil = 0.0;
    }

    /** Tests only: a tripped breaker must not leak into later tests. */
    public static function resetDriveMetricsBreaker(): void
    {
        self::$driveMetricsDownUntil = 0.0;
    }

    public function driveMetrics(float $lat, float $lng, array $targets): array
    {
        if ($targets === [] || microtime(true) < self::$driveMetricsDownUntil) {
            return [];
        }
        $body = [];
        $keys = [];
        $i = 0;
        foreach ($targets as $key => $t) {
            $body[] = ['id' => $i, 'lat' => (float) $t[0], 'lng' => (float) $t[1]];
            $keys[$i] = $key;
            $i++;
        }
        try {
            $response = Http::timeout(3)->post("{$this->url}/v1/drive-metrics", [
                'lat' => $lat,
                'lng' => $lng,
                'targets' => $body,
            ]);
        } catch (\Throwable $e) {
            self::$driveMetricsDownUntil = microtime(true) + 300;
            Log::warning("ripple: drive-metrics fetch failed: {$e->getMessage()}");

            return [];
        }
        if (!$response->successful()) {
            self::$driveMetricsDownUntil = microtime(true) + 300;
            if (!in_array($response->status(), [503, 404], true)) {
                Log::warning("ripple: drive-metrics HTTP {$response->status()}");
            }

            return [];
        }
        $out = [];
        foreach ($response->json('results') ?? [] as $r) {
            if (isset($r['id'], $keys[$r['id']]) && isset($r['miles']) && $r['miles'] !== null) {
                $out[$keys[$r['id']]] = (float) $r['miles'];
            }
        }

        return $out;
    }

    /**
     * Stored-label membership verdicts from the routing server: msgid =>
     * 'in'|'out' for every candidate whose stored label decided it exactly
     * (at the post's CURRENT tick budget); candidates without labels are
     * absent and keep their cell-grid verdict. Empty array on any failure -
     * callers change nothing.
     *
     * @param  array<int, int|string>  $msgids
     * @return array<int, string>
     */
    public function labelVerdicts(float $lat, float $lng, array $msgids, string $budget = ''): array
    {
        return $this->labelEval($lat, $lng, $msgids, $budget, false)['verdicts'];
    }

    /**
     * As labelVerdicts, but also returns 'discovered': labelled posts NOT in
     * $msgids whose stored labels admit this member - the band where the grid
     * prefilter under-covers the true road reach. Both empty on any failure.
     *
     * @param  array<int, int|string>  $msgids
     * @return array{verdicts: array<int, string>, discovered: array<int, int>}
     */
    public function labelVerdictsWithDiscover(float $lat, float $lng, array $msgids): array
    {
        return $this->labelEval($lat, $lng, $msgids, '', true);
    }

    /**
     * @param  array<int, int|string>  $msgids
     * @return array{verdicts: array<int, string>, discovered: array<int, int>}
     */
    private function labelEval(float $lat, float $lng, array $msgids, string $budget, bool $discover): array
    {
        $none = ['verdicts' => [], 'discovered' => []];
        // An empty candidate list still discovers: a member covered by NO
        // grid can still be admitted by a stored label.
        if (($msgids === [] && !$discover) || ($lat === 0.0 && $lng === 0.0)) {
            return $none;
        }
        if (microtime(true) < self::$labelEvalDownUntil) {
            return $none;
        }
        $out = [];
        $discovered = [];
        $chunks = array_chunk(array_values($msgids), 1000) ?: [[]];
        foreach ($chunks as $i => $chunk) {
            try {
                $response = Http::timeout(3)->post("{$this->url}/v1/reach-eval", [
                    'lat' => $lat,
                    'lng' => $lng,
                    'msgids' => array_map('intval', $chunk),
                    'budget' => $budget,
                    // Only the first chunk discovers: the discovery set is a
                    // property of the member, not of the candidate chunking.
                    'discover' => $discover && $i === 0,
                ]);
            } catch (\Throwable $e) {
                self::$labelEvalDownUntil = microtime(true) + 300;
                Log::warning("ripple: reach-eval fetch failed: {$e->getMessage()}");

                return $none;
            }
            if (!$response->successful()) {
                // 503 (engine not configured yet) and 404 (routing server
                // predates the endpoint) are expected states, not outages -
                // they answer instantly, so no breaker for them either.
                if (!in_array($response->status(), [503, 404], true)) {
                    self::$labelEvalDownUntil = microtime(true) + 300;
                    Log::warning("ripple: reach-eval HTTP {$response->status()}");
                }

                return $none;
            }
            foreach ($response->json('results') ?? [] as $r) {
                if (!isset($r['msgid'], $r['verdict']) || !in_array($r['verdict'], ['in', 'out'], true)) {
                    continue;
                }
                // out+origin_area = the member stands in the post's origin
                // group's area, which the stored reach deliberately unions in
                // (ExpandService::unionWithOriginGroupArea): treat as NO
                // verdict, so the cell grid - which holds that union - decides.
                if ($r['verdict'] === 'out' && !empty($r['origin_area'])) {
                    continue;
                }
                $out[(int) $r['msgid']] = $r['verdict'];
            }
            foreach ($response->json('discovered') ?? [] as $r) {
                if (isset($r['msgid'])) {
                    $discovered[] = (int) $r['msgid'];
                }
            }
        }

        // A discovered id can also ride in a LATER chunk of the candidate
        // list, where its own verdict may be 'out' (discover only sees the
        // first chunk's asked set). The verdict wins: never re-admit what
        // the labels narrowed away.
        $discovered = array_values(array_filter(
            $discovered,
            fn ($id) => ($out[$id] ?? '') !== 'out'
        ));

        return ['verdicts' => $out, 'discovered' => $discovered];
    }

    /**
     * Evaluate a stored label blob at many member points in one routing call
     * (POST /v1/reach-arrival): returns per-point ['arrival' => ?float,
     * 'in' => bool] at budget $tSecs (0 < t <= the label's own budget).
     * Null on any failure - callers fall back to their cell tests.
     *
     * @param  array<int, array{0: float, 1: float}>  $points  [lat, lng]
     * @return ?array<int, array{arrival: ?float, in: bool}>
     */
    public function reachArrivalBatch(string $labelBytes, float $tSecs, array $points): ?array
    {
        if ($labelBytes === '' || $points === []) {
            return null;
        }
        $out = [];
        foreach (array_chunk($points, 1000, true) as $chunk) {
            try {
                $response = Http::timeout(5)->post("{$this->url}/v1/reach-arrival", [
                    'labels' => base64_encode($labelBytes),
                    't' => $tSecs,
                    'points' => array_map(
                        fn ($p) => ['lat' => (float) $p[0], 'lng' => (float) $p[1]],
                        array_values($chunk)
                    ),
                ]);
            } catch (\Throwable $e) {
                Log::warning("ripple: reach-arrival fetch failed: {$e->getMessage()}");

                return null;
            }
            if (!$response->successful()) {
                if (!in_array($response->status(), [503, 404], true)) {
                    Log::warning("ripple: reach-arrival HTTP {$response->status()}");
                }

                return null;
            }
            $results = $response->json('results');
            if (!is_array($results) || count($results) !== count($chunk)) {
                return null;
            }
            $keys = array_keys($chunk);
            foreach ($results as $i => $r) {
                $out[$keys[$i]] = [
                    'arrival' => isset($r['arrival']) ? (float) $r['arrival'] : null,
                    'in' => (bool) ($r['in'] ?? false),
                ];
            }
        }

        return $out;
    }

    /**
     * The post's CURRENT tick drive-time budget in seconds, from its stored
     * schedule (falling back to the maximum when unparseable - a too-wide
     * budget only re-admits what the maximum already contains).
     */
    public function currentBudgetSecs(int $tick, float $maxDriveMin, ?string $schedule): float
    {
        if ($schedule) {
            $entries = json_decode($schedule, true);
            if (is_array($entries)) {
                foreach ($entries as $en) {
                    if ((int) ($en['tick'] ?? 0) === $tick && (float) ($en['drive_min'] ?? 0) > 0) {
                        return ((float) $en['drive_min']) * 60.0;
                    }
                }
            }
        }

        return $maxDriveMin * 60.0;
    }

    /**
     * The catchment for a point, as WKT plus its sandwich bounds.
     *
     * $coarse asks the routing server for the region-scale form: the same reach drawn
     * on a grid sized to a fixed cell budget instead of to the road network, so the
     * call stops costing more as the drive-time budget grows (a 45-minute catchment is
     * 2.5MB and several seconds at full resolution, and the routing server only has
     * eight compute slots to serve them from). Ask for it only where the answer is used
     * at region scale - see ExpandService::resolveTickGeometry, which is careful about
     * when that is true. An older routing server ignores the parameter and returns the
     * exact form, so a half-deployed fleet is slow rather than wrong.
     */
    public function catchmentGeometry(float $lat, float $lng, float $minutes, bool $coarse = false): ?array
    {
        try {
            $response = Http::timeout($this->requestTimeout)
                ->get("{$this->url}/v1/catchment", array_filter([
                    'lat' => $lat,
                    'lng' => $lng,
                    'minutes' => $minutes,
                    'mode' => $this->mode,
                    'coarse' => $coarse ? '1' : null,
                ], fn ($v) => $v !== null));
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


    /**
     * Decimal places kept when a coordinate is written into WKT.
     *
     * PHP renders a float with `precision` significant digits - 14 by default - so
     * `(float) -2.012234405899` came out as `-2.012234405899`: fifteen characters
     * describing a position to about a tenth of a MICROMETRE. The data does not have
     * that. The overflow rings are traced from a raster and every vertex sits on an
     * exact 0.0003 degree lattice (~33 m); the reach polygons come from the same
     * routing grid. Nine orders of magnitude of the digits we were storing were noise.
     *
     * Four places is ~11 m of resolution and moves a vertex by at most 5.3 m (5e-5
     * degrees of latitude; ~3.4 m in longitude at UK latitudes). That sounds coarse until
     * you notice it is 16% of the 33 m cell the vertex already sits on, and that the rings
     * are consumed by rasterising them at 192 cells across the envelope - roughly 130 m a
     * cell - so the shift is a few percent of one raster cell. It is inside the noise the
     * data already carries.
     *
     * It cannot merge two neighbours either: lattice points are 0.0003 apart, which is
     * three whole units at 0.0001 resolution, and rounding moves each by at most half a
     * unit. Checked over 632,152 consecutive vertex pairs in production rings - none
     * collapsed.
     *
     * Measured over 12 production rows: 1.70x on its own, and 12.68x once the column is
     * compressed (against 10.60x for compressing without rounding), on what is half of
     * rippling_reach (~24GB of the table's 47.7GB on 2026-08-23).
     *
     * This matters for `overflow_bounds` specifically, because that column stores WKT
     * as TEXT inside JSON. The geometry columns (polygon, max_polygon, outer_bound)
     * are held as binary by MySQL at 16 bytes a vertex whatever we send, so there the
     * only gain is a smaller statement to parse - real but small.
     *
     * NOT a geometry change: this does not drop vertices or move one beyond that 5.3 m, and
     * deliberately stops short of simplifying the staircase, which would shift the
     * boundary by up to half a cell and is a decision about reach, not encoding.
     */
    private const WKT_DECIMALS = 4;

    /**
     * Format one coordinate for WKT at a sane precision.
     *
     * Trailing zeros are trimmed because they cost bytes and say nothing, and a value
     * that rounds to a whole number must still be a bare integer rather than `52.`,
     * which is not valid WKT.
     */
    private static function coord(mixed $v): string
    {
        $s = number_format((float) $v, self::WKT_DECIMALS, '.', '');

        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        // number_format can produce "-0" for a tiny negative; WKT is happier with 0.
        return $s === '-0' ? '0' : $s;
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
            $pts[] = self::coord($pt[0]) . ' ' . self::coord($pt[1]);
        }

        // Ensure the ring is closed.
        if ($pts[0] !== $pts[count($pts) - 1]) {
            $pts[] = $pts[0];
        }

        return 'POLYGON((' . implode(', ', $pts) . '))';
    }
}
