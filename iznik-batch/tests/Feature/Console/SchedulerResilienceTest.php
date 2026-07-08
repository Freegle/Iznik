<?php

namespace Tests\Feature\Console;

use App\Console\FlockEventMutex;
use App\Console\ResilientCacheEventMutex;
use App\Console\SchedulerMutex;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

/**
 * Guards the batch scheduler resilience work (incident 2026-07-02):
 *
 *  - Every ->withoutOverlapping() guard is bounded well below Laravel's 24h
 *    default, so a killed job's mutex self-heals in minutes not a day.
 *  - The overlap mutex store is selectable: flock by default (unchanged), or a
 *    cache store (redis) that survives across the per-tick schedule:run processes
 *    and is independent of the primary DB. See App\Console\SchedulerMutex.
 */
class SchedulerResilienceTest extends TestCase
{
    /** Reflect the cache store name a CacheEventMutex is pointed at. */
    private function mutexCacheStore(Schedule $schedule): ?string
    {
        $mp = new \ReflectionProperty(Schedule::class, 'eventMutex');
        $mp->setAccessible(true);
        $mutex = $mp->getValue($schedule);

        if (! $mutex instanceof CacheEventMutex) {
            return '(not-a-cache-mutex)';
        }

        return $mutex->store; // public on CacheEventMutex
    }

    public function test_default_lock_store_is_flock_and_binds_flock_mutex(): void
    {
        // Default env (LOCK_STORE unset) keeps the current behaviour so this change
        // is a no-op until a human opts in.
        config(['cache.lock_store' => 'flock']);

        $this->assertTrue(SchedulerMutex::usesFlock());
        $this->assertNull(SchedulerMutex::cacheStore());
        $this->assertInstanceOf(FlockEventMutex::class, $this->app->make(EventMutex::class));
    }

    /**
     * @dataProvider lockStoreCases
     */
    public function test_helper_selects_cache_store_from_config(string $configured, bool $usesFlock, ?string $cacheStore): void
    {
        config(['cache.lock_store' => $configured]);

        $this->assertSame($usesFlock, SchedulerMutex::usesFlock());
        $this->assertSame($cacheStore, SchedulerMutex::cacheStore());
    }

    public static function lockStoreCases(): array
    {
        return [
            'flock (default)'          => ['flock', true, null],
            'empty is flock'           => ['', true, null],
            'redis'                    => ['redis', false, 'redis'],
            'database'                 => ['database', false, 'database'],
            'mixed case is normalized' => ['Redis', false, 'redis'],
            'unknown store -> flock'   => ['reddis-typo', true, null],
        ];
    }

    public function test_resilient_cache_mutex_fails_open_when_store_unavailable(): void
    {
        // A store outage must NOT propagate out of the withoutOverlapping filter and
        // abort the whole schedule:run tick — it must fail open so jobs still run.
        $mutex = new ResilientCacheEventMutex($this->app->make('cache'));
        $mutex->useStore('nonexistent-store-xyz'); // parent create()/exists() will throw

        $schedule = new Schedule();
        $event = $schedule->command('test:overlap-probe')->everyMinute()->withoutOverlapping(5);

        $this->assertTrue($mutex->create($event), 'create() must fail open (true) when the store is unavailable');
        $this->assertFalse($mutex->exists($event), 'exists() must fail open (false) when the store is unavailable');
        $mutex->forget($event); // must not throw
        $this->assertTrue(true, 'forget() swallowed the store error');
    }

    public function test_apply_points_cache_event_mutex_at_configured_store(): void
    {
        // Force the cache-backed mutex (as when LOCK_STORE != flock leaves it unbound).
        $this->app->instance(EventMutex::class, new CacheEventMutex($this->app->make('cache')));

        config(['cache.lock_store' => 'array']);
        $schedule = new Schedule();
        SchedulerMutex::apply($schedule);

        $this->assertSame('array', $this->mutexCacheStore($schedule));
    }

    public function test_apply_is_a_noop_under_flock(): void
    {
        $this->app->instance(EventMutex::class, new CacheEventMutex($this->app->make('cache')));

        config(['cache.lock_store' => 'flock']);
        $schedule = new Schedule();
        SchedulerMutex::apply($schedule);

        // useCache was never called, so the mutex keeps the default (null) store.
        $this->assertNull($this->mutexCacheStore($schedule));
    }

    public function test_all_scheduled_overlap_guards_are_bounded(): void
    {
        // Load the real schedule (routes/console.php) onto a fresh instance.
        $schedule = new Schedule();
        ScheduleFacade::swap($schedule);
        require base_path('routes/console.php');

        $events = $schedule->events();
        $this->assertNotEmpty($events, 'routes/console.php failed to register any scheduled events');

        $guarded = 0;
        foreach ($events as $event) {
            if (! $event->withoutOverlapping) {
                continue;
            }
            $guarded++;
            $label = $event->command ?? $event->description ?? 'unknown';
            $this->assertGreaterThan(
                0,
                $event->expiresAt,
                "Overlap expiry for [{$label}] must be positive"
            );
            $this->assertLessThan(
                1440,
                $event->expiresAt,
                "Overlap expiry for [{$label}] must be bounded below Laravel's 24h (1440min) default so a killed job self-heals quickly"
            );
        }

        // The scheduler has many overlap-guarded jobs; guard against the test
        // silently passing because nothing loaded.
        $this->assertGreaterThan(50, $guarded, 'expected many overlap-guarded scheduled jobs');
    }
}
