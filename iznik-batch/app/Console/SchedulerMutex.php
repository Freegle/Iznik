<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/**
 * Chooses the store that backs the scheduler's withoutOverlapping() mutexes.
 *
 * Driven by the LOCK_STORE env var (config key cache.lock_store), default 'flock'.
 *
 *   LOCK_STORE=flock  (default) -> App\Console\FlockEventMutex is bound as the
 *                                   scheduler EventMutex (see AppServiceProvider).
 *   LOCK_STORE=redis            -> flock is NOT bound; a fail-open cache mutex
 *                                   (App\Console\ResilientCacheEventMutex) is bound
 *                                   and pointed at the 'redis' cache store here.
 *   LOCK_STORE=<other store>    -> as above, using that cache store.
 *   LOCK_STORE=<unknown/typo>   -> falls back to flock (with a logged warning)
 *                                   rather than crashing every overlap check.
 *
 * Why this exists (batch scheduler resilience, incident 2026-07-02):
 *
 * All scheduled jobs run with ->runInBackground(). With FlockEventMutex the OS
 * flock is acquired inside the short-lived `schedule:run` tick process and is
 * released the instant that process exits — which is seconds after the background
 * job is *spawned*, not when it *finishes*. So on the next tick the lock is free
 * and a fresh copy is spawned even though the previous one is still running. Under
 * a stalled database (jobs hang far longer than their cadence) this let copies
 * pile up until the host ran out of memory.
 *
 * A cache-based mutex fixes this: the lock lives in the store (redis) with a TTL,
 * so it persists across the per-tick `schedule:run` processes and genuinely blocks
 * a second spawn while the first is still running. Redis is also independent of the
 * primary DB that stalled. The reason flock was originally chosen — avoiding a
 * crashed job leaving a 24h stale lock — is handled instead by the bounded
 * withoutOverlapping() expiries in routes/console.php (minutes, not 24h), so a
 * killed job self-heals quickly.
 *
 * Kept as an env-gated switch (default flock) so this is a no-op until a human
 * flips LOCK_STORE=redis in the batch environment.
 */
class SchedulerMutex
{
    /** The raw configured lock store name (e.g. 'flock', 'redis', 'database'). */
    public static function lockStore(): string
    {
        return (string) config('cache.lock_store', 'flock');
    }

    /**
     * The cache store name to back the overlap mutex, or null when using flock
     * (either explicitly, empty, or because the configured value is not a defined
     * cache store — in which case we fall back to flock rather than crash). The
     * value is normalised (trim + lowercase) and validated against config('cache.stores').
     */
    public static function cacheStore(): ?string
    {
        $store = strtolower(trim(self::lockStore()));

        if ($store === '' || $store === 'flock') {
            return null;
        }

        return in_array($store, self::definedStores(), true) ? $store : null;
    }

    /** Whether the flock (process-death) mutex is in use. Derived from cacheStore() so they agree. */
    public static function usesFlock(): bool
    {
        return self::cacheStore() === null;
    }

    /**
     * Point the schedule's overlap mutex at the configured cache store.
     * No-op under flock (Schedule::useCache only affects the CacheEventMutex).
     * If a non-flock value was configured but is not a defined store, warn so the
     * misconfiguration is visible (we have already fallen back to flock).
     */
    public static function apply(Schedule $schedule): void
    {
        $store = self::cacheStore();

        if ($store !== null) {
            $schedule->useCache($store);

            return;
        }

        $raw = strtolower(trim(self::lockStore()));
        if ($raw !== '' && $raw !== 'flock') {
            Log::warning(
                "Scheduler LOCK_STORE='".self::lockStore()."' is not a defined cache store; "
                .'using flock instead. Valid stores: '.implode(', ', self::definedStores()).'.'
            );
        }
    }

    /** @return list<string> defined cache store names (lowercase). */
    private static function definedStores(): array
    {
        return array_map(fn ($k) => strtolower((string) $k), array_keys((array) config('cache.stores', [])));
    }
}
