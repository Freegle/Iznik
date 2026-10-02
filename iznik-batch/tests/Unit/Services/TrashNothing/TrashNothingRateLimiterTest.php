<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\LokiService;
use App\Services\TrashNothing\Sync\RatingsSyncer;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use App\Services\TrashNothing\Sync\UserChangesSyncer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The throttle in front of the TN API.
 *
 * TN rate-limits per API key, and one tn:sync run calls /ratings,
 * /user-changes and /posts/all with that one key, so the throttle is only
 * worth anything if all three syncers share it.
 */
class TrashNothingRateLimiterTest extends TestCase
{
    public function test_the_container_hands_every_caller_the_same_limiter(): void
    {
        $this->assertSame(
            app(TrashNothingRateLimiter::class),
            app(TrashNothingRateLimiter::class),
            'The limiter must be a singleton or each syncer throttles only itself.',
        );
    }

    public function test_it_waits_the_configured_interval_between_requests(): void
    {
        $limiter = new TrashNothingRateLimiter(minIntervalUs: 200_000);

        $limiter->await();
        $start = microtime(true);
        $limiter->await();
        $elapsedUs = (microtime(true) - $start) * 1_000_000;

        // Generous lower bound: usleep can return slightly early on some kernels.
        $this->assertGreaterThan(150_000, $elapsedUs);
    }

    public function test_a_zero_interval_disables_the_wait(): void
    {
        $limiter = new TrashNothingRateLimiter(minIntervalUs: 0);

        $start = microtime(true);
        $limiter->await();
        $limiter->await();

        $this->assertLessThan(0.1, microtime(true) - $start);
    }

    public function test_ratings_and_user_changes_share_one_budget_with_the_posts_sync(): void
    {
        // Both syncers must draw on the SAME limiter, so a ratings backlog
        // cannot burn the whole budget and leave the posts sync taking 429s.
        Http::fake([
            '*/ratings*'      => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $limiter = new class extends TrashNothingRateLimiter {
            public int $calls = 0;

            public function __construct()
            {
                parent::__construct(minIntervalUs: 0);
            }

            public function await(): void
            {
                $this->calls++;
                parent::await();
            }
        };

        $loki = app(LokiService::class);
        $from = '2026-03-20T10:00:00Z';
        $to   = '2026-03-20T11:00:00Z';

        (new RatingsSyncer(true, false, 'test-key', 'https://example.test/api', $loki, $limiter))->sync($from, $to);
        (new UserChangesSyncer(true, false, 'test-key', 'https://example.test/api', $loki, $limiter))->sync($from, $to);

        $this->assertSame(2, $limiter->calls, 'Each TN API request must pass through the shared limiter.');
    }
}
