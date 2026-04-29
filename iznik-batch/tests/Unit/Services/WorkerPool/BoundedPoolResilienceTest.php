<?php

namespace Tests\Unit\Services\WorkerPool;

use App\Services\WorkerPool\BoundedPool;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Verifies BoundedPool degrades gracefully when Redis is unreachable.
 *
 * Background: 2026-04-27 incident — a Compose network recreation stripped
 * service-name aliases from running containers. batch-prod could no longer
 * resolve "redis", so every email build threw at pool initialize() and was
 * mis-reported as "MJML compilation failed". 124k errors, 7 dead-lettered
 * background_tasks. The pool is back-pressure, not a correctness invariant —
 * it must never propagate failure into the email path.
 */
class BoundedPoolResilienceTest extends TestCase
{
    private string $testPoolName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testPoolName = 'test_resilience_'.uniqid('', true);

        // Reset the per-pool throttle so each test sees a fresh report window.
        $reflection = new \ReflectionClass(BoundedPool::class);
        $prop = $reflection->getProperty('lastOutageReportAt');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * Make all Redis facade calls throw, simulating DNS/connect failure.
     */
    private function makeRedisUnreachable(): void
    {
        Redis::shouldReceive('llen')->andThrow(new \RedisException('connection refused'));
        Redis::shouldReceive('rpush')->andThrow(new \RedisException('connection refused'));
        Redis::shouldReceive('blpop')->andThrow(new \RedisException('connection refused'));
        Redis::shouldReceive('hincrby')->andThrow(new \RedisException('connection refused'));
        Redis::shouldReceive('hget')->andThrow(new \RedisException('connection refused'));
        Redis::shouldReceive('get')->andThrow(new \RedisException('connection refused'));
        Redis::shouldReceive('setex')->andThrow(new \RedisException('connection refused'));
        Redis::shouldReceive('del')->andThrow(new \RedisException('connection refused'));
    }

    public function test_initialize_does_not_throw_when_redis_unreachable(): void
    {
        $this->makeRedisUnreachable();

        $pool = new BoundedPool($this->testPoolName, 5);

        // Must not throw — emails would otherwise fail at construction time.
        $pool->initialize();

        $this->assertTrue(true);
    }

    public function test_acquire_returns_true_when_redis_unreachable(): void
    {
        $this->makeRedisUnreachable();

        $pool = new BoundedPool($this->testPoolName, 5);

        // No back pressure when Redis is down — caller proceeds.
        $this->assertTrue($pool->acquire());
    }

    public function test_release_does_not_throw_when_redis_unreachable(): void
    {
        $this->makeRedisUnreachable();

        $pool = new BoundedPool($this->testPoolName, 5);

        $pool->release();

        $this->assertTrue(true);
    }

    public function test_with_permit_runs_callback_when_redis_unreachable(): void
    {
        $this->makeRedisUnreachable();

        $pool = new BoundedPool($this->testPoolName, 5);

        $ran = false;
        $result = $pool->withPermit(function () use (&$ran) {
            $ran = true;

            return 'compiled-html';
        });

        $this->assertTrue($ran, 'callback must still run when Redis is unreachable');
        $this->assertSame('compiled-html', $result);
    }

    public function test_get_stats_returns_sentinel_when_redis_unreachable(): void
    {
        $this->makeRedisUnreachable();

        $pool = new BoundedPool($this->testPoolName, 5);

        $stats = $pool->getStats();

        $this->assertSame($this->testPoolName, $stats['name']);
        $this->assertSame(5, $stats['max_concurrency']);
        $this->assertNull($stats['available']);
        $this->assertNull($stats['in_use']);
        $this->assertTrue($stats['redis_unavailable']);
    }

    public function test_is_at_capacity_returns_false_when_redis_unreachable(): void
    {
        $this->makeRedisUnreachable();

        $pool = new BoundedPool($this->testPoolName, 5);

        // Returning true here would back-pressure the entire app on a Redis blip.
        $this->assertFalse($pool->isAtCapacity());
    }

    public function test_redis_outage_is_logged_only_once_per_minute(): void
    {
        $this->makeRedisUnreachable();
        \Log::spy();

        $pool = new BoundedPool($this->testPoolName, 5);

        // Multiple operations during a single outage — should produce ONE warning.
        $pool->initialize();
        $pool->acquire();
        $pool->release();
        $pool->isAtCapacity();
        $pool->getStats();

        \Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'Redis unavailable'));
    }

    public function test_throttle_is_per_pool_not_global(): void
    {
        $this->makeRedisUnreachable();
        \Log::spy();

        $poolA = new BoundedPool('pool_a_'.uniqid(), 5);
        $poolB = new BoundedPool('pool_b_'.uniqid(), 5);

        $poolA->acquire();
        $poolB->acquire();

        // Each pool reports its own outage independently.
        \Log::shouldHaveReceived('warning')->twice();
    }

    public function test_recovers_after_transient_failure(): void
    {
        // First call fails, subsequent calls succeed — verifies the retry loop.
        Redis::shouldReceive('llen')
            ->once()
            ->andThrow(new \RedisException('temporary'));
        Redis::shouldReceive('llen')
            ->andReturn(0);
        Redis::shouldReceive('rpush')->andReturn(1);

        $pool = new BoundedPool($this->testPoolName, 3);
        $pool->initialize();

        $this->assertTrue(true);
    }
}
