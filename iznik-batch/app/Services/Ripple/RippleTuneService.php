<?php

namespace App\Services\Ripple;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * §16 self-tuning loop (advisory mode).
 *
 * Three jobs, run weekly by `ripple:tune`:
 *   1. rollup()         - aggregate the period's rippling metrics into rippling_live_metrics
 *                         (overall + per deprivation fifth), so trends are cheap to read.
 *   2. detectHotspots() - flag geographically UNUSUAL areas: deprivation fifths whose metric
 *                         deviates far from the population, using a robust median + MAD modified
 *                         z-score. This catches a LOCAL problem the aggregate average hides - e.g.
 *                         one fifth's reach running far shorter than the rest while the network
 *                         looks fine.
 *   3. proposeParams()  - write PROPOSED per-category parameter changes (rippling_params), within
 *                         the volume guard-rail band. Advisory only: a human promotes to active.
 *
 * Stratifies by deprivation fifth (1 = most deprived, 5 = least deprived), asked per point from
 * the spatial server the same way App\Services\Ripple\ReachQueryService's fairness lane does.
 * With one national moderation pool and one spread mechanism (the reach polygon), a geographic
 * fifth is what "an area doing worse than the rest" now means.
 *
 * Source tables: messages_spatial (approved OFFER/WANTED posts with locations) and rippling_reach
 * (per-message reach state), read directly - both already carry their own lat/lng.
 */
class RippleTuneService
{
    /** Modified z-score thresholds (Iglewicz-Hoaglin: 3.5 is the standard outlier cut). */
    public const HOTSPOT_WATCH = 3.0;

    public const HOTSPOT_ALERT = 3.5;

    /** Need at least this many areas before "unusual" is meaningful. */
    public const MIN_AREAS = 5;

    /** Volume guard-rail band (ripple-thresholds): never propose cutting a cohort > 10%, allow +50%. */
    public const BAND_LOW = -0.10;

    public const BAND_HIGH = 0.50;

    /** In-request cache for quintileFor(), keyed by a coarse rounding of (lat,lng). */
    private array $quintileCache = [];

    /**
     * Run the full weekly tune: rollup, hotspot detection, advisory param proposals.
     *
     * @return array{period_start:string,metrics:int,hotspots:int,proposals:int}
     */
    public function tune(?string $periodStart = null, ?string $periodEnd = null): array
    {
        $end = $periodEnd ? Carbon::parse($periodEnd) : now();
        $start = $periodStart ? Carbon::parse($periodStart) : $end->copy()->subDays(7);
        $startStr = $start->toDateString();

        $metrics = $this->rollup($start, $end);
        $hotspots = $this->detectAllHotspots($start, $end);
        $proposals = $this->proposeParams($startStr);

        Log::info('ripple:tune complete', [
            'period_start' => $startStr,
            'metrics' => $metrics,
            'hotspots' => $hotspots,
            'proposals' => $proposals,
        ]);

        return [
            'period_start' => $startStr,
            'metrics' => $metrics,
            'hotspots' => $hotspots,
            'proposals' => $proposals,
        ];
    }

    /**
     * Aggregate the period into rippling_live_metrics: per deprivation fifth volume + the overall
     * p50/p90. Returns the number of metric rows written.
     */
    public function rollup(Carbon $start, Carbon $end, string $periodType = 'weekly'): int
    {
        $periodStart = $start->toDateString();
        $written = 0;

        $volumes = $this->quintilePostVolumes($start, $end); // [quintile => count]
        if (! empty($volumes)) {
            foreach ($volumes as $quintile => $count) {
                $written += $this->writeMetric($periodStart, $periodType, 'imd_quintile', (string) $quintile, 'volume_posts', (float) $count, 1);
            }
            $vals = array_values($volumes);
            $written += $this->writeMetric($periodStart, $periodType, 'overall', 'all', 'volume_posts_p50', $this->percentile($vals, 0.50), count($vals));
            $written += $this->writeMetric($periodStart, $periodType, 'overall', 'all', 'volume_posts_p90', $this->percentile($vals, 0.90), count($vals));
        }

        $reach = $this->quintileReachDriveMin($start, $end);
        foreach ($reach as $quintile => $driveMin) {
            $written += $this->writeMetric($periodStart, $periodType, 'imd_quintile', (string) $quintile, 'reach_drive_min', (float) $driveMin, 1);
        }

        return $written;
    }

    /**
     * Detect hotspots across every per deprivation fifth metric we have for the period.
     * Returns the number of hotspot rows written.
     */
    public function detectAllHotspots(Carbon $start, Carbon $end): int
    {
        $periodStart = $start->toDateString();
        $names = $this->quintileLabels();
        $found = 0;

        $found += $this->detectHotspots($this->quintilePostVolumes($start, $end), 'volume_posts', $periodStart, 'imd_quintile', $names);
        $found += $this->detectHotspots($this->quintileReachDriveMin($start, $end), 'reach_drive_min', $periodStart, 'imd_quintile', $names);

        return $found;
    }

    /**
     * Flag areas whose value is a robust outlier (modified z-score |M| >= HOTSPOT_WATCH), using
     * median + MAD (falling back to mean absolute deviation when MAD is 0). Writes rippling_hotspots.
     *
     * This is the heart of "spot geographically unusual areas, not just the overall picture": the
     * aggregate mean can sit comfortably in band while one area is wildly off - the median+MAD score
     * surfaces exactly those, and is resistant to the very outliers it is looking for.
     *
     * @param  array<int|string,float>  $areaValues  areaId => metric value
     * @param  array<int|string,string>  $areaNames  areaId => display name (optional)
     */
    public function detectHotspots(array $areaValues, string $metric, string $periodStart, string $areaType = 'imd_quintile', array $areaNames = []): int
    {
        if (count($areaValues) < self::MIN_AREAS) {
            return 0; // too few areas for "unusual" to mean anything
        }

        $values = array_values($areaValues);
        $median = $this->median($values);
        $absDevs = array_map(fn ($v) => abs($v - $median), $values);
        $mad = $this->median($absDevs);
        $meanAd = array_sum($absDevs) / count($absDevs);

        if ($mad <= 0 && $meanAd <= 0) {
            return 0; // no spread at all → nothing is unusual
        }

        $written = 0;
        foreach ($areaValues as $areaId => $value) {
            $z = $mad > 0
                ? 0.6745 * ($value - $median) / $mad
                : ($value - $median) / (1.253314 * $meanAd);
            $absZ = abs($z);
            if ($absZ < self::HOTSPOT_WATCH) {
                continue;
            }

            DB::table('rippling_hotspots')->insert([
                'period_start' => $periodStart,
                'area_type' => $areaType,
                'area_id' => is_numeric($areaId) ? (int) $areaId : null,
                'area_name' => $areaNames[$areaId] ?? null,
                'metric' => $metric,
                'value' => round($value, 4),
                'baseline' => round($median, 4),
                'deviation' => round($z, 3),
                'direction' => $value >= $median ? 'high' : 'low',
                'severity' => $absZ >= self::HOTSPOT_ALERT ? 'alert' : 'watch',
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * Advisory parameter proposals per ONS category. Conservative + guard-railed: only proposes a
     * change when a category's volume sits outside the comfort band, and never proposes a cut beyond
     * the band floor. Written as status='proposed' for a human to accept.
     */
    public function proposeParams(string $periodStart): int
    {
        // Per-category volume delta vs the category's own prior-period baseline.
        $deltas = $this->categoryVolumeDeltas($periodStart);
        $proposals = 0;

        foreach ($deltas as $category => $delta) {
            if ($delta >= self::BAND_LOW && $delta <= self::BAND_HIGH) {
                continue; // within band - leave the category alone
            }

            // Too much volume → tighten reach (less max_minutes); too little → widen it. Bounded.
            $direction = $delta > self::BAND_HIGH ? 'tighten' : 'widen';
            $rationale = sprintf(
                'volume delta %+.0f%% outside band; propose to %s reach',
                $delta * 100,
                $direction
            );

            DB::table('rippling_params')->updateOrInsert(
                ['ons_category' => $category, 'status' => 'proposed'],
                [
                    'rationale' => $rationale,
                    'max_minutes' => $direction === 'tighten' ? 25 : 35,
                    'proposed_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $proposals++;
        }

        return $proposals;
    }

    // ---- source data ---------------------------------------------------------------------------

    /** @return array<int,int> deprivation fifth (1-5) => approved OFFER/WANTED post count in the window */
    private function quintilePostVolumes(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('messages_spatial')
            ->whereIn('msgtype', ['Offer', 'Wanted'])
            ->whereBetween('arrival', [$start, $end])
            ->select(DB::raw('ST_Y(point) as lat'), DB::raw('ST_X(point) as lng'))
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $quintile = $this->quintileFor((float) $row->lat, (float) $row->lng);
            if ($quintile === null) {
                continue;
            }
            $counts[$quintile] = ($counts[$quintile] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<int,float> deprivation fifth (1-5) => mean reach drive-minutes */
    private function quintileReachDriveMin(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('rippling_reach')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('max_drive_min')
            ->select('lat', 'lng', 'max_drive_min')
            ->get();

        $sums = [];
        $counts = [];
        foreach ($rows as $row) {
            $quintile = $this->quintileFor((float) $row->lat, (float) $row->lng);
            if ($quintile === null) {
                continue;
            }
            $sums[$quintile] = ($sums[$quintile] ?? 0.0) + (float) $row->max_drive_min;
            $counts[$quintile] = ($counts[$quintile] ?? 0) + 1;
        }

        $means = [];
        foreach ($sums as $quintile => $sum) {
            $means[$quintile] = $sum / $counts[$quintile];
        }

        return $means;
    }

    /**
     * A point's deprivation fifth (1-5), or null if the spatial server cannot say. Mirrors
     * App\Services\Ripple\ReachQueryService::quintileFor(), with a cache added: this runs once per
     * row of a weekly batch rather than once per reply, and the fifth is an LSOA-sized statistic, so
     * nearby points share an answer and a coarse rounding of the coordinates avoids repeating the
     * same lookup for every post in a neighbourhood.
     */
    private function quintileFor(float $lat, float $lng): ?int
    {
        $key = round($lat, 3).','.round($lng, 3);
        if (array_key_exists($key, $this->quintileCache)) {
            return $this->quintileCache[$key];
        }

        try {
            $base = rtrim((string) config('freegle.routing_server_url'), '/');
            $r = Http::timeout(5)->get($base.'/v1/quintile', ['lat' => $lat, 'lng' => $lng]);
            if (! $r->successful() || ! $r->json('available')) {
                return $this->quintileCache[$key] = null;
            }
            $q = (int) $r->json('quintile');

            return $this->quintileCache[$key] = ($q >= 1 && $q <= 5 ? $q : null);
        } catch (\Throwable $e) {
            return $this->quintileCache[$key] = null;
        }
    }

    /**
     * @return array<string,float> category => volume delta vs prior period
     *
     * Advisory stub: always empty, so proposeParams() proposes nothing. The baseline wiring
     * lands once live-vs-baseline data accrues in rippling_algorithm_metrics. Tests override
     * this to exercise the proposal logic.
     */
    protected function categoryVolumeDeltas(string $periodStart): array
    {
        return [];
    }

    /**
     * Static labels for the five deprivation fifths (1 = most deprived, 5 = least deprived - the
     * same ordering as config('freegle.ripple.fairness') and ReachQueryService's fairness lane).
     * The fifth is a fixed axis, not a queried set of areas, so this needs no table.
     */
    private function quintileLabels(): array
    {
        return [
            1 => 'Deprivation fifth 1 (most deprived)',
            2 => 'Deprivation fifth 2',
            3 => 'Deprivation fifth 3',
            4 => 'Deprivation fifth 4',
            5 => 'Deprivation fifth 5 (least deprived)',
        ];
    }

    private function writeMetric(string $periodStart, string $periodType, string $stratumType, string $stratumKey, string $metric, float $value, int $sampleSize): int
    {
        DB::table('rippling_live_metrics')->updateOrInsert(
            [
                'period_start' => $periodStart,
                'period_type' => $periodType,
                'stratum_type' => $stratumType,
                'stratum_key' => $stratumKey,
                'metric' => $metric,
            ],
            ['value' => round($value, 4), 'sample_size' => $sampleSize]
        );

        return 1;
    }

    // ---- robust statistics --------------------------------------------------------------------

    /** @param array<int,float> $values */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;
    }

    /** @param array<int,float> $values */
    private function percentile(array $values, float $p): float
    {
        if (empty($values)) {
            return 0.0;
        }
        $sorted = $values;
        sort($sorted);
        $rank = $p * (count($sorted) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $sorted[$low];
        }

        return $sorted[$low] + ($rank - $low) * ($sorted[$high] - $sorted[$low]);
    }
}
