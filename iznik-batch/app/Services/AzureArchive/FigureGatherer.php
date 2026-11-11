<?php

namespace App\Services\AzureArchive;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the figures the egress budget decides from: Azure Monitor's egress, Cost Management's
 * charges, and the ledger's own count. A figure that cannot be read is left null with the reason,
 * and the budget refuses on it.
 */
class FigureGatherer
{
    /** Cost Management refreshes a few times a day; asking more often than this only risks throttling. */
    private const COST_CACHE_MINUTES = 60;

    private const COST_CACHE_KEY = 'azure_archive:costs:BillingMonthToDate';

    public function __construct(
        private readonly AzureClient $azure,
        private readonly ArchiveLedger $ledger,
        private readonly int $metricsLagMinutes,
    ) {
    }

    public static function fromConfig(AzureClient $azure, ArchiveLedger $ledger): self
    {
        return new self($azure, $ledger, max(0, (int) config('freegle.azure_archive.metrics_lag_minutes', 120)));
    }

    public function gather(?CarbonImmutable $now = null): BudgetFigures
    {
        $now ??= CarbonImmutable::now('UTC');

        $egress = null;
        $newest = null;
        $metricsError = null;

        try {
            $hours = $this->azure->egressByHour($now->subDays(31), $now);
            $egress = array_sum($hours);
            $newest = $hours === [] ? null : CarbonImmutable::parse(array_key_last($hours));
        } catch (AzureUnavailable $e) {
            $metricsError = $e->getMessage();
        }

        $bandwidthCost = null;
        $billedGb = null;
        $costError = null;

        try {
            $costs = $this->costs();
            $bandwidthCost = $costs->bandwidthCost();
            $billedGb = $costs->bandwidthGb();
        } catch (AzureUnavailable $e) {
            $costError = $e->getMessage();
        }

        return new BudgetFigures(
            now: $now,
            azureEgress31d: $egress,
            azureNewestPoint: $newest,
            ourBytes31d: $this->ledger->bytesSince($now->subDays(31)),
            ourBytes24h: $this->ledger->bytesSince($now->subDay()),
            ourBytesRecent: $this->ledger->bytesSince($now->subMinutes($this->metricsLagMinutes)),
            bandwidthCost: $bandwidthCost,
            billedEgressGb: $billedGb,
            costError: $costError,
            metricsError: $metricsError,
            latchedStop: $this->ledger->latchedStop(),
        );
    }

    public function costs(): CostSummary
    {
        $cached = Cache::get(self::COST_CACHE_KEY);

        if ($cached instanceof CostSummary) {
            return $cached;
        }

        $costs = $this->azure->costs('BillingMonthToDate');
        Cache::put(self::COST_CACHE_KEY, $costs, now()->addMinutes(self::COST_CACHE_MINUTES));

        return $costs;
    }
}
