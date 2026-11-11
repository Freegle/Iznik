<?php

namespace App\Services\AzureArchive;

/**
 * How many bytes the copier may fetch from Azure now, and why not more.
 */
final class BudgetDecision
{
    /**
     * @param  list<string>  $refusals  reasons nothing may be fetched; empty when allowed
     * @param  array<string, float|int|null>  $figures  the working, for the status report
     */
    public function __construct(
        public readonly int $allowedBytes,
        public readonly array $refusals,
        public readonly ?string $latch,
        public readonly array $figures,
    ) {
    }

    public function allowed(): bool
    {
        return $this->refusals === [] && $this->allowedBytes > 0;
    }
}
