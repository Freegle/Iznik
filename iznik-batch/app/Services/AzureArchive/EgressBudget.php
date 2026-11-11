<?php

namespace App\Services\AzureArchive;

/**
 * Decides how much the copier may fetch from Azure right now without the account ever paying for
 * egress. Pure: it reads only the figures it is given.
 *
 * Azure gives the first 100 GB of internet egress each month free. Billing months may start on
 * any day of the month, so the rule is about ANY 31 days: if the egress of every trailing 31-day
 * window stays under the ceiling, every billing month does. Members' own views of archived images
 * come out of the same allowance, so they are measured (Azure's figure less our own fetches) and a
 * share is reserved for them before the copy gets any.
 *
 * Two limits, the smaller wins:
 *
 * - **Headroom now.** Azure's 31-day figure plus what we fetched since Azure last reported, plus
 *   this run, stays under the ceiling.
 * - **Pace.** Spending evenly, a day's share is (ceiling less the members' reserve) / 31. In a
 *   steady state that keeps every window under the ceiling, not just today's.
 *
 * Anything it cannot read refuses: no figure is ever taken as zero. A bandwidth charge in the
 * billing period also asks for a latched stop, because it means the free allowance has already
 * gone and a person has to look.
 */
final class EgressBudget
{
    /** A billed GB is read as the larger of the two meanings, so the check errs high. */
    private const BILLED_GB_BYTES = 1073741824;

    public function __construct(
        private readonly int $ceilingBytes,
        private readonly float $siteGrowth,
        private readonly int $runMaxBytes,
        private readonly int $metricsMaxAgeMinutes,
        private readonly bool $costCheckRequired,
    ) {
    }

    public static function fromConfig(): self
    {
        $c = (array) config('freegle.azure_archive', []);

        return new self(
            (int) round(((float) ($c['ceiling_gb'] ?? 80)) * 1e9),
            max(1.0, (float) ($c['site_growth'] ?? 1.25)),
            max(0, (int) ($c['run_max_mb'] ?? 1024)) * 1000 * 1000,
            max(1, (int) ($c['metrics_max_age_minutes'] ?? 180)),
            (bool) ($c['require_cost_check'] ?? true),
        );
    }

    public function ceilingBytes(): int
    {
        return $this->ceilingBytes;
    }

    public function decide(BudgetFigures $f): BudgetDecision
    {
        $refusals = [];
        $latch = null;

        if ($f->latchedStop !== null) {
            $refusals[] = "Stopped by an earlier run: {$f->latchedStop}. A person clears it with azure-archive:status --clear-stop.";
        }

        if ($f->azureEgress31d === null) {
            $refusals[] = 'Azure egress figures could not be read' . ($f->metricsError ? ": {$f->metricsError}" : '') . '.';
        } elseif ($f->azureNewestPoint === null) {
            $refusals[] = 'Azure reported no egress figures at all for the last 31 days.';
        } elseif ($f->azureNewestPoint->diffInMinutes($f->now, true) > $this->metricsMaxAgeMinutes) {
            $refusals[] = "Azure's egress figures are stale: the newest is from {$f->azureNewestPoint->toIso8601String()}.";
        }

        if ($f->bandwidthCost === null) {
            if ($this->costCheckRequired) {
                $refusals[] = 'Azure Cost Management could not be read' . ($f->costError ? ": {$f->costError}" : '') . '.';
            }
        } elseif ($f->bandwidthCost > 0) {
            $latch = sprintf('Azure has charged %.2f for bandwidth this billing period, so the free allowance is used up', $f->bandwidthCost);
            $refusals[] = $latch . '.';
        }

        $billedBytes = $f->billedEgressGb === null ? null : $f->billedEgressGb * self::BILLED_GB_BYTES;

        if ($billedBytes !== null && $billedBytes >= $this->ceilingBytes) {
            $refusals[] = sprintf('Azure has billed %.1f GB of egress this billing period, at or over the ceiling.', $f->billedEgressGb);
        }

        // The working, computed even when refused so the status report can show it.
        $azure = $f->azureEgress31d ?? 0.0;
        $ourInAzure = max(0, $f->ourBytes31d - $f->ourBytesRecent);
        $site31d = max(0.0, $azure - $ourInAzure);
        $reserve = $site31d * $this->siteGrowth;
        $daily = max(0.0, ($this->ceilingBytes - $reserve) / 31);
        $todayLeft = $daily - $f->ourBytes24h;
        $headroom = $this->ceilingBytes - $azure - $f->ourBytesRecent;
        // Cost Management lags by up to a day, so our last day may not be in its figure yet.
        $billedLeft = $billedBytes === null ? INF : $this->ceilingBytes - $billedBytes - $f->ourBytes24h;

        $allowed = (int) floor(max(0.0, min($todayLeft, $headroom, $billedLeft, (float) $this->runMaxBytes)));

        if ($refusals !== []) {
            $allowed = 0;
        }

        return new BudgetDecision($allowed, $refusals, $latch, [
            'ceiling_bytes' => $this->ceilingBytes,
            'azure_egress_31d' => $f->azureEgress31d,
            'our_bytes_31d' => $f->ourBytes31d,
            'our_bytes_24h' => $f->ourBytes24h,
            'our_bytes_recent' => $f->ourBytesRecent,
            'site_bytes_31d' => $site31d,
            'site_reserve' => $reserve,
            'daily_share' => $daily,
            'today_left' => $todayLeft,
            'headroom' => $headroom,
            'billed_left' => is_infinite($billedLeft) ? null : $billedLeft,
            'bandwidth_cost' => $f->bandwidthCost,
            'billed_egress_gb' => $f->billedEgressGb,
            'run_max_bytes' => $this->runMaxBytes,
        ]);
    }
}
