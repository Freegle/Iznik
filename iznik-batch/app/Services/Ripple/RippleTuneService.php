<?php

namespace App\Services\Ripple;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * §16 self-tuning loop (advisory mode).
 *
 * Three jobs, run weekly by `ripple:tune`:
 *   1. rollup()         - aggregate the period's rippling metrics into rippling_live_metrics
 *                         (overall + per-group), so trends are cheap to read.
 *   2. detectHotspots() - flag geographically UNUSUAL areas: groups whose metric deviates far
 *                         from the population, using a robust median + MAD modified z-score. This
 *                         catches a LOCAL problem the aggregate average hides - e.g. one area
 *                         rejecting nearly every rippled-in post while the network looks fine.
 *   3. proposeParams()  - write PROPOSED per-category parameter changes (rippling_params), within
 *                         the volume guard-rail band. Advisory only: a human promotes to active.
 *
 * Source tables that belong to earlier PRs (rippling_reach, messages_groups.rippled_in) are read
 * defensively so this is inert until they exist.
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
     * Aggregate the period into rippling_live_metrics: per-group volume + the overall p50/p90.
     * Returns the number of metric rows written.
     */
    public function rollup(Carbon $start, Carbon $end, string $periodType = 'weekly'): int
    {
        $periodStart = $start->toDateString();
        $written = 0;

        $volumes = $this->groupPostVolumes($start, $end); // [groupid => count]
        if (!empty($volumes)) {
            foreach ($volumes as $groupid => $count) {
                $written += $this->writeMetric($periodStart, $periodType, 'group', (string) $groupid, 'volume_posts', (float) $count, 1);
            }
            $vals = array_values($volumes);
            $written += $this->writeMetric($periodStart, $periodType, 'overall', 'all', 'volume_posts_p50', $this->percentile($vals, 0.50), count($vals));
            $written += $this->writeMetric($periodStart, $periodType, 'overall', 'all', 'volume_posts_p90', $this->percentile($vals, 0.90), count($vals));
        }

        // Reach size per group, only if the reach engine (PR A) is live.
        $reach = $this->groupReachDriveMin($start, $end);
        foreach ($reach as $groupid => $driveMin) {
            $written += $this->writeMetric($periodStart, $periodType, 'group', (string) $groupid, 'reach_drive_min', (float) $driveMin, 1);
        }

        return $written;
    }

    /**
     * Detect hotspots across every per-group metric we have for the period.
     * Returns the number of hotspot rows written.
     */
    public function detectAllHotspots(Carbon $start, Carbon $end): int
    {
        $periodStart = $start->toDateString();
        $names = $this->groupNames();
        $found = 0;

        $found += $this->detectHotspots($this->groupPostVolumes($start, $end), 'volume_posts', $periodStart, 'group', $names);
        $found += $this->detectHotspots($this->groupReachDriveMin($start, $end), 'reach_drive_min', $periodStart, 'group', $names);
        $found += $this->detectHotspots($this->groupSecondaryRejectRates($start, $end), 'secondary_reject_rate', $periodStart, 'group', $names);

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
     * @param array<int|string,float> $areaValues   areaId => metric value
     * @param array<int|string,string> $areaNames   areaId => display name (optional)
     */
    public function detectHotspots(array $areaValues, string $metric, string $periodStart, string $areaType = 'group', array $areaNames = []): int
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

    // ---- source data (defensive: earlier-PR tables may not exist yet) -------------------------

    /** @return array<int,int> groupid => approved OFFER/WANTED post count in the window */
    private function groupPostVolumes(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->where('mg.collection', 'Approved')
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->whereIn('m.type', ['Offer', 'Wanted'])
            ->whereBetween('mg.arrival', [$start, $end])
            ->groupBy('mg.groupid')
            ->select('mg.groupid', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'mg.groupid')
            ->toArray();

        return array_map('intval', $rows);
    }

    /** @return array<int,float> groupid => mean reach drive-minutes (empty unless rippling_reach exists) */
    private function groupReachDriveMin(Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('rippling_reach')) {
            return [];
        }
        $rows = DB::table('rippling_reach as rr')
            ->join('messages_groups as mg', 'mg.msgid', '=', 'rr.msgid')
            ->whereBetween('rr.created_at', [$start, $end])
            ->groupBy('mg.groupid')
            ->select('mg.groupid', DB::raw('AVG(rr.max_drive_min) as d'))
            ->pluck('d', 'mg.groupid')
            ->toArray();

        return array_map('floatval', $rows);
    }

    /** @return array<int,float> groupid => secondary-reject rate (rejected rippled-in / rippled-in) */
    private function groupSecondaryRejectRates(Carbon $start, Carbon $end): array
    {
        if (!Schema::hasColumn('messages_groups', 'rippled_in')) {
            return [];
        }
        $rows = DB::table('messages_groups as mg')
            ->where('mg.rippled_in', 1)
            ->whereBetween('mg.arrival', [$start, $end])
            ->groupBy('mg.groupid')
            ->select(
                'mg.groupid',
                DB::raw("SUM(CASE WHEN mg.collection = 'Rejected' THEN 1 ELSE 0 END) / COUNT(*) as rate")
            )
            ->pluck('rate', 'mg.groupid')
            ->toArray();

        return array_map('floatval', $rows);
    }

    /** @return array<string,float> category => volume delta vs prior period (empty if no baseline) */
    private function categoryVolumeDeltas(string $periodStart): array
    {
        // Uses the offline simulator's per-category figures if present; otherwise no proposals.
        if (!Schema::hasTable('ripple_algorithm_metrics')) {
            return [];
        }
        return []; // baseline wiring lands once live-vs-baseline data accrues (advisory stub)
    }

    private function groupNames(): array
    {
        return DB::table('groups')->pluck('nameshort', 'id')->toArray();
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
