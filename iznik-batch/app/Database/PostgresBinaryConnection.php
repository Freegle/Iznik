<?php

namespace App\Database;

use App\Support\TransactionPolicy;
use Closure;
use Generator;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * PostgreSQL connection that handles binary data the way the MySQL driver does, so code written against a
 * blob column works unchanged on a bytea column.
 *
 * Writing: a string that is not text (it holds a NUL byte or is not valid UTF-8) is sent as a binary
 * parameter. PostgreSQL refuses it as text. MySQL takes any bytes in a string parameter.
 *
 * Reading: PDO's PostgreSQL driver returns a bytea value as a stream resource, where MySQL's returns a string.
 * The value is read into a string before the row reaches the caller.
 *
 * Deadlocks: a statement run outside a transaction that loses a deadlock (40P01) or runs out of lock_timeout
 * (55P03) is retried, as DeadlockRetryConnection does for MySQL. PostgreSQL has rolled the whole statement
 * back, so running it again is safe. Inside a transaction nothing is retried here: PostgreSQL has aborted the
 * transaction and refuses every later statement (25P02), so only the caller can retry, and it has to retry
 * the transaction as a whole (TransactionPolicy::atomic does).
 */
class PostgresBinaryConnection extends PostgresConnection
{
    protected int $deadlockMaxRetries = 3;

    protected int $deadlockBaseDelayMs = 100;

    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        // The parent throws here too: a statement inside a transaction is never retried on its own.
        if ($this->transactions >= 1) {
            throw $e;
        }

        if (TransactionPolicy::isDeadlock($e)) {
            return $this->retryDeadlockedStatement($e, $query, $bindings, $callback);
        }

        return $this->tryAgainIfCausedByLostConnection($e, $query, $bindings, $callback);
    }

    /** Runs the statement again, waiting twice as long before each attempt, until it stops losing the lock. */
    protected function retryDeadlockedStatement(QueryException $e, $query, $bindings, Closure $callback)
    {
        for ($attempt = 1; $attempt <= $this->deadlockMaxRetries; $attempt++) {
            $delayMs = $this->deadlockBaseDelayMs * pow(2, $attempt - 1);

            Log::warning('PostgresBinaryConnection: retrying deadlocked statement', [
                'query' => $query,
                'attempt' => $attempt,
                'max_retries' => $this->deadlockMaxRetries,
                'delay_ms' => $delayMs,
            ]);

            usleep($delayMs * 1000);

            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $retryException) {
                if (! TransactionPolicy::isDeadlock($retryException)) {
                    throw $retryException;
                }
                $e = $retryException;
            }
        }

        Log::error('PostgresBinaryConnection: deadlock persisted after all retries', [
            'query' => $query,
            'attempts' => $this->deadlockMaxRetries,
        ]);

        throw $e;
    }

    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_resource($value) => PDO::PARAM_LOB,
                default => PDO::PARAM_STR,
            };

            if (is_string($value) && self::isBinary($value)) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $value);
                rewind($stream);
                $value = $stream;
                $type = PDO::PARAM_LOB;
            }

            $statement->bindValue(is_string($key) ? $key : $key + 1, $value, $type);
        }
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return array_map(fn ($row) => $this->readStreams($row), parent::select($query, $bindings, $useReadPdo));
    }

    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        return (function () use ($query, $bindings, $useReadPdo): Generator {
            foreach (parent::cursor($query, $bindings, $useReadPdo) as $row) {
                yield $this->readStreams($row);
            }
        })();
    }

    /** True when the string cannot go to PostgreSQL as text. */
    private static function isBinary(string $value): bool
    {
        return str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8');
    }

    /** Replaces any stream in the row with its contents. */
    private function readStreams(mixed $row): mixed
    {
        if (is_object($row)) {
            foreach (get_object_vars($row) as $name => $value) {
                if (is_resource($value)) {
                    $row->$name = stream_get_contents($value);
                }
            }

            return $row;
        }

        if (is_array($row)) {
            foreach ($row as $name => $value) {
                if (is_resource($value)) {
                    $row[$name] = stream_get_contents($value);
                }
            }
        }

        return $row;
    }
}
