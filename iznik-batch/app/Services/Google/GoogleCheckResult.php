<?php

namespace App\Services\Google;

/**
 * The outcome of one Google housekeeping run: pass or fail, a one-line summary for
 * the ModTools housekeeping list and the failure email, and the detail behind it.
 */
class GoogleCheckResult
{
    /**
     * @param  list<string>  $log
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $summary,
        public readonly array $log,
    ) {
    }
}
