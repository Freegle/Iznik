<?php

namespace App\Console;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;

/**
 * A cache-backed scheduler mutex (redis) that FAILS OPEN when the store is
 * unavailable, instead of letting the exception escape and kill the whole
 * schedule:run tick.
 *
 * Why this matters: withoutOverlapping() registers a skip() filter that calls
 * $mutex->exists() from inside Event::filtersPass(), which ScheduleRunCommand
 * iterates OUTSIDE the try/catch that wraps a task's actual run. So if the store
 * throws (e.g. a redis blip), the exception propagates out of the loop and aborts
 * the entire tick — starving every job registered after the first guarded one.
 * With schedule:work spawning a fresh tick each minute, a transient redis outage
 * would become a near-total batch-scheduler outage.
 *
 * Failing open (create -> "acquired", exists -> "no lock") means that during a
 * store outage jobs simply run without overlap protection — no worse than the
 * flock default, and far better than not running at all. The overlap protection
 * that matters for the incident (a DB stall while redis is healthy) is unaffected,
 * because in that case the store is up and the real mutex is used.
 */
class ResilientCacheEventMutex extends CacheEventMutex
{
    public function create(Event $event)
    {
        try {
            return parent::create($event);
        } catch (\Throwable $e) {
            $this->failOpen('create', $e);

            return true; // treat as acquired -> let the job run
        }
    }

    public function exists(Event $event)
    {
        try {
            return parent::exists($event);
        } catch (\Throwable $e) {
            $this->failOpen('exists', $e);

            return false; // treat as "no overlap" -> don't skip the job
        }
    }

    public function forget(Event $event)
    {
        try {
            parent::forget($event);
        } catch (\Throwable $e) {
            $this->failOpen('forget', $e);
        }
    }

    private function failOpen(string $op, \Throwable $e): void
    {
        Log::warning(
            "Scheduler overlap mutex {$op}() failed on store '".($this->store ?? 'default')."' "
            ."({$e->getMessage()}); failing open so the job still runs."
        );
    }
}
