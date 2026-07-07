<?php

namespace App\Services\WorkerPool;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * A bounded worker pool using Redis BLPOP for back pressure.
 *
 * This implements a semaphore pattern where:
 * - Pool is initialized with N permits (tokens in a Redis list)
 * - acquire() uses BLPOP - blocks until a permit is available
 * - release() uses RPUSH - returns the permit to the pool
 *
 * When all permits are taken, new requests block at acquire(),
 * providing natural back pressure to upstream producers.
 */
class BoundedPool
{
    private string $permitsKey;

    private string $statsKey;

    public function __construct(
        private string $name,
        private int $maxConcurrency,
        private int $timeoutSeconds = 0, // 0 = block forever
        private int $sentryThrottleSeconds = 300
    ) {
        $this->permitsKey = "pool:{$name}:permits";
        $this->statsKey = "pool:{$name}:stats";
    }

    /**
     * Initialize the pool with permits.
     * Safe to call multiple times - only adds missing permits.
     */
    public function initialize(): void
    {
        $this->withRedisOrSkip('initialize', function () {
            $current = Redis::llen($this->permitsKey);
            $needed = $this->maxConcurrency - $current;

            if ($needed > 0) {
                for ($i = 0; $i < $needed; $i++) {
                    Redis::rpush($this->permitsKey, '1');
                }
                Log::info("BoundedPool[{$this->name}]: Initialized {$needed} permits (total: {$this->maxConcurrency})");
            }
        });
    }

    /**
     * Acquire a permit. Blocks until one is available.
     *
     * Returns true on success. If Redis is unreachable, returns true so the
     * caller proceeds without back pressure rather than failing the request —
     * losing back pressure is preferable to losing emails.
     *
     * @return bool True if permit acquired (or Redis unavailable), false if timeout exceeded
     */
    public function acquire(): bool
    {
        $result = $this->withRedisOrSkip('acquire', function () {
            // BLPOP returns [key, value] on success, null on timeout
            return Redis::blpop($this->permitsKey, $this->timeoutSeconds);
        }, sentinel: 'redis-unavailable');

        if ($result === 'redis-unavailable') {
            return true;
        }

        if ($result === null || $result === []) {
            $this->recordTimeout();

            return false;
        }

        $this->recordAcquire();

        return true;
    }

    /**
     * Release a permit back to the pool.
     */
    public function release(): void
    {
        $this->withRedisOrSkip('release', function () {
            Redis::rpush($this->permitsKey, '1');
        });
    }

    /**
     * Execute callback with a permit, ensuring release on completion.
     *
     * @throws PoolTimeoutException If permit cannot be acquired within timeout
     */
    public function withPermit(callable $callback): mixed
    {
        if (! $this->acquire()) {
            throw new PoolTimeoutException(
                "Could not acquire permit for pool: {$this->name}"
            );
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Get current pool statistics.
     *
     * Returns sentinel "unknown" values for fields that depend on Redis when
     * Redis is unreachable, so monitoring callers can distinguish real zero
     * from "couldn't reach Redis".
     */
    public function getStats(): array
    {
        return $this->withRedisOrSkip('getStats', function () {
            $available = Redis::llen($this->permitsKey);

            return [
                'name' => $this->name,
                'max_concurrency' => $this->maxConcurrency,
                'available' => $available,
                'in_use' => $this->maxConcurrency - $available,
                'timeouts' => (int) (Redis::hget($this->statsKey, 'timeouts') ?? 0),
                'total_acquired' => (int) (Redis::hget($this->statsKey, 'acquired') ?? 0),
            ];
        }, sentinel: [
            'name' => $this->name,
            'max_concurrency' => $this->maxConcurrency,
            'available' => null,
            'in_use' => null,
            'timeouts' => null,
            'total_acquired' => null,
            'redis_unavailable' => true,
        ]);
    }

    /**
     * Check if pool is at capacity (no permits available).
     *
     * Returns false if Redis is unreachable, so callers don't reject work
     * solely because we can't see the permit count.
     */
    public function isAtCapacity(): bool
    {
        return $this->withRedisOrSkip('isAtCapacity', function () {
            return Redis::llen($this->permitsKey) === 0;
        }, sentinel: false);
    }

    /**
     * Get the pool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the maximum concurrency.
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * Reset the pool - removes all permits and reinitializes.
     * Use with caution - may leave work in progress without permits.
     */
    public function reset(): void
    {
        $this->withRedisOrSkip('reset', function () {
            Redis::del($this->permitsKey);
            Redis::del($this->statsKey);
        });
        $this->initialize();
    }

    private function recordAcquire(): void
    {
        $this->withRedisOrSkip('recordAcquire', function () {
            Redis::hincrby($this->statsKey, 'acquired', 1);
        });
    }

    private function recordTimeout(): void
    {
        $count = $this->withRedisOrSkip('recordTimeout', function () {
            return Redis::hincrby($this->statsKey, 'timeouts', 1);
        }, sentinel: null);

        // Throttled Sentry alert - only alert every N seconds
        $lastAlertKey = "pool:{$this->name}:last_alert";
        $lastAlert = $this->withRedisOrSkip('recordTimeout:get', function () use ($lastAlertKey) {
            return Redis::get($lastAlertKey);
        }, sentinel: null);

        if (! $lastAlert || (time() - (int) $lastAlert) > $this->sentryThrottleSeconds) {
            $this->withRedisOrSkip('recordTimeout:set', function () use ($lastAlertKey) {
                Redis::setex($lastAlertKey, $this->sentryThrottleSeconds, time());
            });

            Log::error("BoundedPool[{$this->name}] at max capacity", [
                'pool' => $this->name,
                'max_concurrency' => $this->maxConcurrency,
                'total_timeouts' => $count,
            ]);

            // Report to Sentry via Laravel's error handler
            report(new PoolCapacityException(
                "Pool '{$this->name}' at maximum capacity ({$this->maxConcurrency})"
            ));
        }
    }

    /**
     * Run a Redis operation with retry, falling back to a sentinel on failure.
     *
     * Why: Redis connection blips (DNS, restart, network reconfig) must not
     * propagate as exceptions through the pool. The pool is back-pressure,
     * not a correctness invariant — losing it for a few seconds is far
     * better than failing the work.
     *
     * Three attempts with short backoff (50ms, 200ms) catches the common
     * transient case. Exhausted retries return the sentinel and emit one
     * throttled Sentry report so we still see the failure.
     */
    private function withRedisOrSkip(string $op, callable $fn, mixed $sentinel = null): mixed
    {
        $delaysMs = [50, 200];
        $lastException = null;

        for ($attempt = 0; $attempt <= count($delaysMs); $attempt++) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < count($delaysMs)) {
                    usleep($delaysMs[$attempt] * 1000);
                }
            }
        }

        $this->reportRedisOutage($op, $lastException);

        return $sentinel;
    }

    /**
     * Throttle Redis-outage Sentry reports per pool, per minute.
     *
     * Uses a process-local static rather than the Cache facade because the
     * Cache store is itself usually Redis — using it here would loop back into
     * the same outage we're trying to report.
     */
    private static array $lastOutageReportAt = [];

    private function reportRedisOutage(string $op, ?\Throwable $e): void
    {
        $now = time();
        $last = self::$lastOutageReportAt[$this->name] ?? 0;
        if ($now - $last < 60) {
            return;
        }
        self::$lastOutageReportAt[$this->name] = $now;

        Log::warning("BoundedPool[{$this->name}]: Redis unavailable during {$op} — degrading to no-back-pressure mode", [
            'pool' => $this->name,
            'op' => $op,
            'error' => $e?->getMessage(),
        ]);

        if ($e !== null) {
            report($e);
        }
    }
}
