<?php

namespace App\Services\AzureArchive;

/**
 * What Azure Cost Management says has been charged in one billing period, by meter.
 *
 * Bandwidth is the category the free 100 GB a month belongs to: its cost stays 0 until that is
 * used up, so any bandwidth cost above 0 means the free allowance was exceeded.
 */
final class CostSummary
{
    /**
     * @param  list<array{category: string, subcategory: string, meter: string, cost: float, quantity: float}>  $rows
     */
    public function __construct(
        public readonly string $timeframe,
        public readonly string $currency,
        public readonly array $rows,
    ) {
    }

    public function total(): float
    {
        return array_sum(array_column($this->rows, 'cost'));
    }

    public function bandwidthCost(): float
    {
        return array_sum(array_column($this->bandwidthRows(), 'cost'));
    }

    /**
     * Billed egress in GB, the quantity the free allowance is measured in. Every bandwidth meter
     * is counted, which over-counts transfers that are not to the internet, never under-counts.
     */
    public function bandwidthGb(): float
    {
        return array_sum(array_column($this->bandwidthRows(), 'quantity'));
    }

    /** @return list<array{category: string, subcategory: string, meter: string, cost: float, quantity: float}> */
    private function bandwidthRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (array $r) => strcasecmp($r['category'], 'Bandwidth') === 0
        ));
    }
}
