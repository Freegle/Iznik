<?php

namespace App\Support;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Transaction policy helper for database operations.
 *
 * IMPORTANT: On a MySQL/Galera cluster, explicit transactions hold locks across
 * all nodes. Long-running or overlapping transactions cause deadlocks. The
 * iznik-server PHP code avoids this by using IMPLICIT transactions - each SQL
 * statement auto-commits immediately, minimizing lock time.
 *
 * POLICY:
 * - Most operations should NOT use explicit transactions
 * - Laravel auto-commits each statement by default (this is correct)
 * - Only use transactions when you NEED atomicity across multiple statements
 *
 * WHEN TO USE TRANSACTIONS (rare):
 * - Creating linked records that MUST exist together or not at all
 * - Financial operations where partial completion = incorrect balances
 * - Multi-table updates where partial completion = corrupt data
 *
 * WHEN NOT TO USE TRANSACTIONS (common):
 * - Bulk updates/deletes (each statement is atomic on its own)
 * - Sequential operations that don't depend on each other
 * - "For safety" - auto-commit IS safe per-statement
 * - Performance optimization (transactions make things SLOWER on clusters)
 *
 * @see https://galeracluster.com/library/documentation/isolation-levels.html
 */
class TransactionPolicy
{
    /**
     * Maximum retry attempts for atomic operations that encounter deadlocks.
     */
    protected static int $maxRetries = 3;

    /**
     * Base delay in milliseconds for exponential backoff.
     */
    protected static int $baseDelayMs = 100;

    /**
     * Execute an operation that REQUIRES atomicity across multiple statements.
     *
     * USE SPARINGLY. Most operations should NOT use this - just execute
     * statements normally and let them auto-commit individually.
     *
     * This wrapper:
     * 1. Wraps the operation in a transaction
     * 2. Retries on deadlock with exponential backoff
     * 3. Logs warnings about transaction usage for monitoring
     *
     * @param callable $operation The operation requiring atomicity
     * @param string $reason Why this operation requires atomicity (for logging)
     * @param int|null $maxRetries Override default max retries
     * @return mixed The operation result
     * @throws Throwable If deadlock persists after all retries or non-deadlock error occurs
     */
    public static function atomic(callable $operation, string $reason, ?int $maxRetries = null): mixed
    {
        $maxRetries ??= self::$maxRetries;
        $attempts = 0;
        $lastException = null;

        // Log transaction usage for monitoring - helps identify overuse
        Log::debug('TransactionPolicy: Starting atomic operation', [
            'reason' => $reason,
            'max_retries' => $maxRetries,
        ]);

        while ($attempts < $maxRetries) {
            try {
                return DB::transaction($operation);
            } catch (DeadlockException|QueryException $e) {
                $lastException = $e;

                if (self::isDeadlock($e)) {
                    $attempts++;

                    if ($attempts < $maxRetries) {
                        $delayMs = self::$baseDelayMs * pow(2, $attempts - 1);
                        Log::warning('TransactionPolicy: Deadlock detected, retrying', [
                            'reason' => $reason,
                            'attempt' => $attempts,
                            'max_retries' => $maxRetries,
                            'delay_ms' => $delayMs,
                        ]);
                        usleep($delayMs * 1000);
                        continue;
                    }

                    Log::error('TransactionPolicy: Deadlock persisted after all retries', [
                        'reason' => $reason,
                        'attempts' => $attempts,
                        'error' => $e->getMessage(),
                    ]);
                }

                throw $e;
            }
        }

        throw $lastException ?? new RuntimeException('Atomic operation failed');
    }

    /**
     * Assert that we are NOT currently in a transaction.
     *
     * Use this in code that should never run inside a transaction to catch
     * accidental nesting. Helps enforce the implicit transaction policy.
     *
     * @param string $context Description of the operation for error messages
     * @throws RuntimeException If called from within a transaction
     */
    public static function assertNoTransaction(string $context = 'this operation'): void
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                "TransactionPolicy violation: $context should not run inside a transaction. " .
                'Use implicit transactions (auto-commit) for better cluster performance.'
            );
        }
    }

    /**
     * Check if we are currently in a transaction.
     *
     * Useful for conditional logic or debugging.
     *
     * @return bool True if inside a transaction
     */
    public static function inTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * Get the current transaction nesting level.
     *
     * @return int The nesting level (0 = no transaction)
     */
    public static function transactionLevel(): int
    {
        return DB::transactionLevel();
    }

    /**
     * Check if an exception is a deadlock error.
     *
     * MySQL error codes:
     * - 1213: Deadlock found when trying to get lock
     * - 1205: Lock wait timeout exceeded
     *
     * PDO SQLSTATE:
     * - 40001: Serialization failure (deadlock)
     *
     * Laravel also wraps deadlocks in DeadlockException.
     *
     * @param Throwable $e The exception to check
     * @return bool True if this is a deadlock error
     */
    public static function isDeadlock(Throwable $e): bool
    {
        // Laravel's DeadlockException is always a deadlock
        if ($e instanceof DeadlockException) {
            return true;
        }

        $code = $e->getCode();
        $message = strtolower($e->getMessage());

        // PDO SQLSTATE for serialization failure
        if ($code === '40001' || $code === 40001) {
            return true;
        }

        // MySQL error codes embedded in message
        if (str_contains($message, '1213') || str_contains($message, 'deadlock')) {
            return true;
        }

        // Lock wait timeout (sometimes worth retrying)
        if (str_contains($message, '1205') || str_contains($message, 'lock wait timeout')) {
            return true;
        }

        return false;
    }

    /**
     * Execute a bulk operation WITHOUT a transaction.
     *
     * This is the PREFERRED way to do bulk operations on the cluster.
     * Each statement auto-commits, minimizing lock time and deadlock risk.
     *
     * Even with auto-commit, individual statements can still hit deadlocks
     * on the cluster, so this includes retry logic.
     *
     * @param callable $operation The bulk operation
     * @param string $description Description for logging
     * @param int|null $maxRetries Override default max retries
     * @return mixed The operation result
     */
    public static function bulk(callable $operation, string $description = 'bulk operation', ?int $maxRetries = null): mixed
    {
        self::assertNoTransaction($description);

        $maxRetries ??= self::$maxRetries;
        $attempts = 0;
        $lastException = null;

        Log::debug('TransactionPolicy: Executing bulk operation with auto-commit', [
            'description' => $description,
        ]);

        while ($attempts < $maxRetries) {
            try {
                return $operation();
            } catch (DeadlockException|QueryException $e) {
                $lastException = $e;

                if (self::isDeadlock($e)) {
                    $attempts++;

                    if ($attempts < $maxRetries) {
                        $delayMs = self::$baseDelayMs * pow(2, $attempts - 1);
                        Log::warning('TransactionPolicy: Deadlock in bulk operation, retrying', [
                            'description' => $description,
                            'attempt' => $attempts,
                            'max_retries' => $maxRetries,
                            'delay_ms' => $delayMs,
                        ]);
                        usleep($delayMs * 1000);
                        continue;
                    }

                    Log::error('TransactionPolicy: Deadlock persisted in bulk operation after all retries', [
                        'description' => $description,
                        'attempts' => $attempts,
                        'error' => $e->getMessage(),
                    ]);
                }

                throw $e;
            }
        }

        throw $lastException ?? new RuntimeException('Bulk operation failed');
    }

    /**
     * Configure the retry policy.
     *
     * @param int $maxRetries Maximum retry attempts
     * @param int $baseDelayMs Base delay in milliseconds for exponential backoff
     */
    public static function configure(int $maxRetries = 3, int $baseDelayMs = 100): void
    {
        self::$maxRetries = $maxRetries;
        self::$baseDelayMs = $baseDelayMs;
    }
}
