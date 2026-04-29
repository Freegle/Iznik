<?php

namespace App\Database;

use App\Support\TransactionPolicy;
use Closure;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * MySQL connection that automatically retries deadlocked statements at autocommit level.
 *
 * On a Galera cluster, concurrent single-statement operations (INSERTs, UPDATEs)
 * can deadlock even without explicit transactions. At autocommit level, each
 * statement is atomic — a deadlocked statement was never applied, so retrying
 * is always safe.
 *
 * Inside explicit transactions, Laravel's parent class already throws immediately
 * (the caller must retry the entire transaction, not individual statements).
 */
class DeadlockRetryConnection extends MySqlConnection
{
    protected int $deadlockMaxRetries = 3;

    protected int $deadlockBaseDelayMs = 100;

    /**
     * Handle a query exception.
     *
     * Extends parent to retry deadlocks at autocommit level with exponential
     * backoff, in addition to the existing lost-connection retry.
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        // Parent checks $this->transactions >= 1 and throws — we mirror that.
        if ($this->transactions >= 1) {
            throw $e;
        }

        if (TransactionPolicy::isDeadlock($e)) {
            return $this->retryDeadlockedStatement($e, $query, $bindings, $callback);
        }

        // Fall through to parent's lost-connection retry.
        return $this->tryAgainIfCausedByLostConnection(
            $e, $query, $bindings, $callback
        );
    }

    /**
     * Retry a deadlocked statement with exponential backoff.
     */
    protected function retryDeadlockedStatement(QueryException $e, $query, $bindings, Closure $callback)
    {
        for ($attempt = 1; $attempt <= $this->deadlockMaxRetries; $attempt++) {
            $delayMs = $this->deadlockBaseDelayMs * pow(2, $attempt - 1);

            Log::warning('DeadlockRetryConnection: retrying deadlocked statement', [
                'query' => $query,
                'attempt' => $attempt,
                'max_retries' => $this->deadlockMaxRetries,
                'delay_ms' => $delayMs,
            ]);

            usleep($delayMs * 1000);

            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $retryException) {
                if (!TransactionPolicy::isDeadlock($retryException)) {
                    throw $retryException;
                }
                $e = $retryException;
            }
        }

        Log::error('DeadlockRetryConnection: deadlock persisted after all retries', [
            'query' => $query,
            'attempts' => $this->deadlockMaxRetries,
        ]);

        throw $e;
    }
}
