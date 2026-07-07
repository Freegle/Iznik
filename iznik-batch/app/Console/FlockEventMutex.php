<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Process-aware event mutex using Symfony's FlockStore.
 *
 * Unlike Laravel's default CacheEventMutex which uses TTL-based locks,
 * FlockStore uses OS-level file locks (flock) that are automatically
 * released when the process dies. This prevents stale mutex issues
 * where a crashed process leaves a lock that blocks execution for
 * the full TTL duration (default 24 hours).
 *
 * How it works:
 * - Uses flock() system call for locking
 * - Lock is held as long as the file handle is open
 * - OS automatically releases lock when process terminates (even on crash)
 * - No TTL needed - lock lifetime matches process lifetime exactly
 */
class FlockEventMutex implements EventMutex
{
    protected LockFactory $factory;

    /**
     * Active locks indexed by mutex name.
     *
     * @var array<string, LockInterface>
     */
    protected array $locks = [];

    public function __construct(?string $lockDirectory = null)
    {
        $lockDirectory ??= storage_path('framework/scheduler-locks');

        // Ensure lock directory exists.
        if (!is_dir($lockDirectory)) {
            mkdir($lockDirectory, 0755, true);
        }

        $store = new FlockStore($lockDirectory);
        $this->factory = new LockFactory($store);
    }

    /**
     * Attempt to obtain an event mutex for the given event.
     */
    public function create(Event $event): bool
    {
        $name = $event->mutexName();

        // Create a non-blocking lock (no TTL needed - flock handles this).
        $lock = $this->factory->createLock($name);

        // Try to acquire the lock without blocking.
        if ($lock->acquire(blocking: false)) {
            $this->locks[$name] = $lock;
            return true;
        }

        return false;
    }

    /**
     * Determine if an event mutex exists for the given event.
     */
    public function exists(Event $event): bool
    {
        $name = $event->mutexName();

        // If we hold the lock, it exists.
        if (isset($this->locks[$name])) {
            return true;
        }

        // Try to acquire to check if another process holds it.
        $lock = $this->factory->createLock($name);

        if ($lock->acquire(blocking: false)) {
            // We got it, so no other process had it - release immediately.
            $lock->release();
            return false;
        }

        // Another process holds the lock.
        return true;
    }

    /**
     * Clear the event mutex for the given event.
     */
    public function forget(Event $event): void
    {
        $name = $event->mutexName();

        if (isset($this->locks[$name])) {
            $this->locks[$name]->release();
            unset($this->locks[$name]);
        }
    }
}
