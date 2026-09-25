<?php

namespace App\Services\TrashNothing\Sync;

/**
 * One process-wide throttle in front of every Trash Nothing API request.
 *
 * TN rate-limits per API KEY, not per endpoint, and a single `tn:sync` run
 * hits /ratings, /user-changes and /posts/all with the same key one after
 * another. A throttle owned by one syncer therefore protects nothing: it only
 * paces its own requests, so a large ratings or user-changes backlog can burn
 * through the budget unthrottled and leave the posts sync — the part that
 * matters most — taking 429s, or get the key suspended outright.
 *
 * Registered as a container singleton (see AppServiceProvider) so all three
 * syncers share the same `lastRequestTime`. It is deliberately in-process
 * only: `tn:sync` holds an overlap lock, so there is one run at a time and
 * nothing to coordinate across processes.
 */
class TrashNothingRateLimiter
{
    /** TN allows 2 requests/second; a 750ms floor keeps us comfortably inside it. */
    public const DEFAULT_MIN_REQUEST_INTERVAL_US = 750_000;

    private float $lastRequestTime = 0.0;

    private int $minIntervalUs;

    public function __construct(?int $minIntervalUs = null)
    {
        $this->minIntervalUs = $minIntervalUs ?? (int) config(
            'freegle.trashnothing.min_request_interval_us',
            self::DEFAULT_MIN_REQUEST_INTERVAL_US,
        );
    }

    /**
     * Block until enough time has passed since the previous request, then
     * record this one as the new reference point.
     */
    public function await(): void
    {
        if ($this->minIntervalUs <= 0) {
            $this->lastRequestTime = microtime(true);

            return;
        }

        $elapsed = microtime(true) - $this->lastRequestTime;
        $waitUs  = $this->minIntervalUs - (int) ($elapsed * 1_000_000);

        if ($waitUs > 0) {
            usleep($waitUs);
        }

        $this->lastRequestTime = microtime(true);
    }
}
