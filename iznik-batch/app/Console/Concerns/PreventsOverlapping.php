<?php

namespace App\Console\Concerns;

/**
 * Trait for preventing overlapping command execution using file locks.
 *
 * Uses flock() system calls which are automatically released when:
 * - The process exits normally
 * - The process crashes
 * - The process is killed
 *
 * This is more reliable than TTL-based locks which can leave stale locks
 * after crashes.
 * 
 * Note: This trait expects commands to also use GracefulShutdown trait
 * which provides the isTestingEnvironment() method, or define their own.
 */
trait PreventsOverlapping
{
    protected ?string $lockFile = null;

    /** @var resource|null */
    protected $lockHandle = null;

    /**
     * Try to acquire an exclusive lock. Returns false if already running.
     * In testing environment, always returns true to avoid lock contention
     * between parallel test workers.
     */
    protected function acquireLock(): bool
    {
        // Skip locking in testing environment to avoid intermittent failures
        // when paratest runs multiple tests that call the same command.
        if ($this->shouldSkipLocking()) {
            return true;
        }

        $lockPath = storage_path('framework/command-locks');
        if (!is_dir($lockPath)) {
            if (!mkdir($lockPath, 0755, true)) {
                throw new \RuntimeException("Failed to create lock directory: {$lockPath}");
            }
        }

        $lockName = str_replace([':', '\\'], '-', static::class);

        // Commands that run in parallel partitions (e.g. immediate digest
        // sharded by group) can override lockKeySuffix() to get a
        // shard-specific lockfile so different shards don't block each
        // other. Default returns null → single lock per class.
        $suffix = method_exists($this, 'lockKeySuffix') ? $this->lockKeySuffix() : null;
        if ($suffix !== null && $suffix !== '') {
            $lockName .= '-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $suffix);
        }

        $this->lockFile = "{$lockPath}/{$lockName}.lock";

        $this->lockHandle = fopen($this->lockFile, 'c');
        if (!$this->lockHandle) {
            throw new \RuntimeException("Failed to open lock file: {$this->lockFile}");
        }

        // Non-blocking exclusive lock.
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }

        // Write PID for debugging.
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, (string) getmypid());
        fflush($this->lockHandle);

        return true;
    }

    /**
     * Release the lock (also released automatically on process death).
     */
    protected function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Check if we should skip locking (for testing environments).
     * Uses isTestingEnvironment() if available (from GracefulShutdown trait),
     * otherwise falls back to direct environment checks.
     */
    protected function shouldSkipLocking(): bool
    {
        // If the class has isTestingEnvironment (from GracefulShutdown), use it.
        if (method_exists($this, 'isTestingEnvironment')) {
            return $this->isTestingEnvironment();
        }

        // Fallback: direct environment checks.
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            return true;
        }

        if (getenv('APP_ENV') === 'testing') {
            return true;
        }

        if (($_ENV['APP_ENV'] ?? null) === 'testing') {
            return true;
        }

        if (($_SERVER['APP_ENV'] ?? null) === 'testing') {
            return true;
        }

        return false;
    }
}
