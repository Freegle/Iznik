<?php

namespace App\Services\AzureArchive;

use Carbon\CarbonImmutable;

/**
 * Everything the egress budget decides from, gathered in one place so the decision itself is a
 * pure function that can be tested case by case. A null means "could not be read", never zero.
 */
final class BudgetFigures
{
    public function __construct(
        public readonly CarbonImmutable $now,
        // Azure Monitor: the storage account's egress over the trailing 31 days, and the time of
        // the newest hour it reported.
        public readonly ?float $azureEgress31d,
        public readonly ?CarbonImmutable $azureNewestPoint,
        // The ledger: what the copier itself fetched in the trailing 31 days, in the last day, and
        // recently enough that Azure's figure may not include it yet.
        public readonly int $ourBytes31d,
        public readonly int $ourBytes24h,
        public readonly int $ourBytesRecent,
        // Cost Management, this billing period: bandwidth charged, and bandwidth billed in GB.
        public readonly ?float $bandwidthCost,
        public readonly ?float $billedEgressGb,
        public readonly ?string $costError,
        public readonly ?string $metricsError,
        // A stop latched by an earlier run, which only a person clears.
        public readonly ?string $latchedStop,
        // Bandwidth charged in the billing period before this one. Cost Management lags by a day
        // or more, so a charge from the last days of a period first shows after it has closed.
        public readonly ?float $previousBandwidthCost,
        // What a person acknowledged of each, when they last cleared a stop.
        public readonly float $acknowledgedCost = 0.0,
        public readonly float $acknowledgedPreviousCost = 0.0,
    ) {
    }
}
