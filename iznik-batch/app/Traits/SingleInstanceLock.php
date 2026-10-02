<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hard single-instance guard for scheduled commands.
 *
 * The scheduler's withoutOverlapping() is unreliable for runInBackground()
 * jobs: the overlap mutex is released as soon as the foreground tick forks the
 * background process, so a fresh run launches every tick even while the
 * previous one is still going. ripple:expand hit this on 2026-06-26 (~90 piled
 * runs starving the serial worker); on 2026-08-27 the same hole let
 * embeddings:generate stack 11 deep and firstreply:maxreach 7 deep during a
 * slow spell, each pile compounding the slowness that caused it until the host
 * was thrashing swap.
 *
 * This DB-backed lock (CACHE_STORE=database -> cache_locks) is owned by the
 * running process and auto-expires, so at most one run executes at a time and
 * a crashed run cannot wedge the schedule forever. Same mechanism as
 * ExpandCommand's inline guard, shared so every pile-prone command states only
 * its name and a TTL.
 */
trait SingleInstanceLock
{
    /**
     * Run $body only if no other process holds $name; otherwise report and
     * exit successfully, exactly as a withoutOverlapping() skip would.
     *
     * @param  string  $name  Lock name, conventionally '<command>:run'.
     * @param  int  $ttl  Auto-expiry in seconds: longer than any healthy run,
     *                    short enough that a crashed run's stale lock clears
     *                    within a useful time.
     * @param  callable(): int  $body  The command body; its exit code is passed through.
     */
    protected function runSingleInstance(string $name, int $ttl, callable $body): int
    {
        $lock = Cache::lock($name, $ttl);

        if (!$lock->get()) {
            Log::info("{$this->getName()} skipped: another run already holds {$name}");
            $this->info('Another run is in progress; exiting.');

            return self::SUCCESS;
        }

        try {
            return $body();
        } finally {
            $lock->release();
        }
    }
}
