<?php

namespace App\Services\AzureArchive;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the figures the egress budget decides from: Azure Monitor's egress, Cost Management's
 * charges for this billing period and the last, and the ledger's own count. A figure that cannot
 * be read, or that cannot be trusted, is left null with the reason, and the budget refuses on it.
 */
class FigureGatherer
{
    /** Cost Management refreshes a few times a day; asking more often than this only risks throttling. */
    private const COST_CACHE_MINUTES = 60;

    /**
     * Azure's figure for these hours before its newest is compared with what the copy alone
     * fetched in them. Azure reporting less than the copy means its figures are behind.
     */
    private const CHECK_HOURS = 6;

    /** Our count runs high (worst cases, framing), so Azure may show this much less before it is doubted. */
    private const CHECK_SLACK_SHARE = 1.25;

    private const CHECK_SLACK_BYTES = 50 * 1000 * 1000;

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
        $since = $now->subMinutes($this->metricsLagMinutes);

        $egress = null;
        $newest = null;
        $metricsError = null;

        try {
            $hours = $this->azure->egressByHour($now->subDays(31), $now);
            $egress = array_sum($hours);
            $newest = self::newestReported($hours);

            if ($newest !== null) {
                // Our bytes from the start of Azure's newest hour on may not be in its figure yet,
                // so they are added on top, whichever reaches further back.
                $since = min($since, $newest);
                $metricsError = $this->behind($hours, $newest);
            }
        } catch (AzureUnavailable $e) {
            $metricsError = $e->getMessage();
        }

        if ($metricsError !== null) {
            $egress = null;
        }

        [$bandwidthCost, $billedGb, $costError] = $this->bandwidth('BillingMonthToDate');
        [$previousCost, , $previousError] = $this->bandwidth('TheLastBillingMonth');

        if ($bandwidthCost !== null) {
            $this->ledger->noteCosts($bandwidthCost, $previousCost);
        }

        $ack = $this->ledger->costAcknowledgement();

        return new BudgetFigures(
            now: $now,
            azureEgress31d: $egress,
            azureNewestPoint: $newest,
            ourBytes31d: $this->ledger->bytesSince($now->subDays(31)),
            ourBytes24h: $this->ledger->bytesSince($now->subDay()),
            ourBytesRecent: $this->ledger->bytesSince($since),
            bandwidthCost: $bandwidthCost,
            billedEgressGb: $billedGb,
            costError: $costError ?? $previousError,
            metricsError: $metricsError,
            latchedStop: $this->ledger->latchedStop(),
            previousBandwidthCost: $previousCost,
            acknowledgedCost: $ack['current'],
            acknowledgedPreviousCost: $ack['previous'],
        );
    }

    /**
     * The newest hour Azure has reported a value above zero for. Trailing zeros are taken as hours
     * Azure has not recorded yet, not as hours with no traffic: members' views never stop.
     *
     * @param  array<string, float>  $hours
     */
    public static function newestReported(array $hours): ?CarbonImmutable
    {
        foreach (array_reverse($hours, true) as $hour => $bytes) {
            if ($bytes > 0) {
                return CarbonImmutable::parse($hour);
            }
        }

        return null;
    }

    /**
     * Why Azure's figure cannot be trusted, or null: it reports less egress for the hours before
     * its newest than the copy alone fetched in them.
     *
     * @param  array<string, float>  $hours
     */
    private function behind(array $hours, CarbonImmutable $newest): ?string
    {
        $from = $newest->subHours(self::CHECK_HOURS);
        $azure = 0.0;

        foreach ($hours as $hour => $bytes) {
            $t = CarbonImmutable::parse($hour);

            if ($t >= $from && $t < $newest) {
                $azure += $bytes;
            }
        }

        $ours = $this->ledger->bytesBetween($from, $newest);

        if ($ours > $azure * self::CHECK_SLACK_SHARE + self::CHECK_SLACK_BYTES) {
            return sprintf(
                'Azure reports %s of egress for the %d hours before %s, but the copy alone fetched %s: its figures are behind',
                Bytes::gb($azure),
                self::CHECK_HOURS,
                $newest->toIso8601String(),
                Bytes::gb($ours)
            );
        }

        return null;
    }

    /** @return array{0: ?float, 1: ?float, 2: ?string} cost, billed GB, error */
    private function bandwidth(string $timeframe): array
    {
        try {
            $costs = $this->costs($timeframe);

            return [$costs->bandwidthCost(), $costs->bandwidthGb(), null];
        } catch (AzureUnavailable $e) {
            return [null, null, $e->getMessage()];
        }
    }

    /** Read the charges afresh next time, for a person who is about to acknowledge them. */
    public function forgetCosts(): void
    {
        Cache::forget('azure_archive:costs:BillingMonthToDate');
        Cache::forget('azure_archive:costs:TheLastBillingMonth');
    }

    public function costs(string $timeframe = 'BillingMonthToDate'): CostSummary
    {
        $key = 'azure_archive:costs:' . $timeframe;
        $cached = Cache::get($key);

        if ($cached instanceof CostSummary) {
            return $cached;
        }

        $costs = $this->azure->costs($timeframe);
        Cache::put($key, $costs, now()->addMinutes(self::COST_CACHE_MINUTES));

        return $costs;
    }
}
