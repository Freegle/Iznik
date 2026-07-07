<?php

namespace Tests\Unit\Services\WorkerPool;

use App\Services\WorkerPool\BoundedPool;
use App\Services\WorkerPool\PoolCapacityException;
use App\Services\WorkerPool\PoolTimeoutException;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class BoundedPoolTest extends TestCase
{
    private string $testPoolName;

    protected function setUp(): void
    {
        parent::setUp();

        // Use unique pool name for test isolation
        $this->testPoolName = 'test_' . uniqid('', true);

        // Ensure test pool is clean before each test
        $this->cleanupTestPool();

    }

    protected function tearDown(): void
    {
        $this->cleanupTestPool();

        parent::tearDown();
    }

    private function cleanupTestPool(): void
    {
        Redis::del("pool:{$this->testPoolName}:permits");
        Redis::del("pool:{$this->testPoolName}:stats");
        Redis::del("pool:{$this->testPoolName}:last_alert");
    }

    public function test_initialize_creates_permits(): void
    {
        $pool = new BoundedPool($this->testPoolName, 5);

        $this->assertEquals(0, Redis::llen("pool:{$this->testPoolName}:permits"));

        $pool->initialize();

        $this->assertEquals(5, Redis::llen("pool:{$this->testPoolName}:permits"));
    }

    public function test_initialize_is_idempotent(): void
    {
        $pool = new BoundedPool($this->testPoolName, 5);

        $pool->initialize();
        $this->assertEquals(5, Redis::llen("pool:{$this->testPoolName}:permits"));

        // Calling initialize again should not add more permits
        $pool->initialize();
        $this->assertEquals(5, Redis::llen("pool:{$this->testPoolName}:permits"));
    }

    public function test_acquire_takes_permit(): void
    {
        $pool = new BoundedPool($this->testPoolName, 3);
        $pool->initialize();

        $this->assertEquals(3, Redis::llen("pool:{$this->testPoolName}:permits"));

        $result = $pool->acquire();

        $this->assertTrue($result);
        $this->assertEquals(2, Redis::llen("pool:{$this->testPoolName}:permits"));
    }

    public function test_release_returns_permit(): void
    {
        $pool = new BoundedPool($this->testPoolName, 3);
        $pool->initialize();

        $pool->acquire();
        $this->assertEquals(2, Redis::llen("pool:{$this->testPoolName}:permits"));

        $pool->release();
        $this->assertEquals(3, Redis::llen("pool:{$this->testPoolName}:permits"));
    }

    public function test_acquire_with_timeout_returns_false_when_no_permits(): void
    {
        // Use very long sentry throttle to prevent alerts during test
        $pool = new BoundedPool($this->testPoolName, 1, timeoutSeconds: 1, sentryThrottleSeconds: 999999);
        $pool->initialize();

        // Take the only permit
        $pool->acquire();

        // Try to acquire another - should timeout
        $result = $pool->acquire();

        $this->assertFalse($result);
    }

    public function test_with_permit_executes_callback(): void
    {
        $pool = new BoundedPool($this->testPoolName, 3);
        $pool->initialize();

        $result = $pool->withPermit(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
    }

    public function test_with_permit_releases_on_exception(): void
    {
        $pool = new BoundedPool($this->testPoolName, 3);
        $pool->initialize();

        try {
            $pool->withPermit(function () {
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Permit should have been released
        $this->assertEquals(3, Redis::llen("pool:{$this->testPoolName}:permits"));
    }

    public function test_with_permit_throws_on_timeout(): void
    {
        // Use very long sentry throttle to prevent alerts during test
        $pool = new BoundedPool($this->testPoolName, 1, timeoutSeconds: 1, sentryThrottleSeconds: 999999);
        $pool->initialize();

        // Take the only permit
        $pool->acquire();

        $this->expectException(PoolTimeoutException::class);

        $pool->withPermit(function () {
            return 'should not execute';
        });
    }

    public function test_get_stats_returns_pool_info(): void
    {
        $pool = new BoundedPool($this->testPoolName, 5);
        $pool->initialize();

        $stats = $pool->getStats();

        $this->assertEquals($this->testPoolName, $stats['name']);
        $this->assertEquals(5, $stats['max_concurrency']);
        $this->assertEquals(5, $stats['available']);
        $this->assertEquals(0, $stats['in_use']);
    }

    public function test_stats_track_acquired_permits(): void
    {
        $pool = new BoundedPool($this->testPoolName, 3);
        $pool->initialize();

        $pool->acquire();
        $pool->acquire();

        $stats = $pool->getStats();

        $this->assertEquals(1, $stats['available']);
        $this->assertEquals(2, $stats['in_use']);
        $this->assertEquals(2, $stats['total_acquired']);
    }

    public function test_stats_track_timeouts(): void
    {
        // Use very long sentry throttle to prevent alerts during test
        $pool = new BoundedPool($this->testPoolName, 1, timeoutSeconds: 1, sentryThrottleSeconds: 999999);
        $pool->initialize();

        // Take the only permit
        $pool->acquire();

        // Try to acquire again (will timeout)
        $pool->acquire();

        $stats = $pool->getStats();

        $this->assertEquals(1, $stats['timeouts']);
    }

    public function test_is_at_capacity(): void
    {
        $pool = new BoundedPool($this->testPoolName, 2);
        $pool->initialize();

        $this->assertFalse($pool->isAtCapacity());

        $pool->acquire();
        $this->assertFalse($pool->isAtCapacity());

        $pool->acquire();
        $this->assertTrue($pool->isAtCapacity());
    }

    public function test_reset_clears_and_reinitializes(): void
    {
        $pool = new BoundedPool($this->testPoolName, 5);
        $pool->initialize();

        // Take some permits
        $pool->acquire();
        $pool->acquire();

        $this->assertEquals(3, Redis::llen("pool:{$this->testPoolName}:permits"));

        // Reset should restore to full capacity
        $pool->reset();

        $this->assertEquals(5, Redis::llen("pool:{$this->testPoolName}:permits"));
    }

    public function test_getters_return_correct_values(): void
    {
        $pool = new BoundedPool($this->testPoolName, 10);

        $this->assertEquals($this->testPoolName, $pool->getName());
        $this->assertEquals(10, $pool->getMaxConcurrency());
    }
}
