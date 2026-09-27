<?php

namespace App\Services\CookieYes;

/**
 * The outcome of one watchdog run: pass or fail, a one-line summary for the
 * ModTools housekeeping list and the failure email, and the detail behind it.
 */
class CookieYesCheckResult
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
