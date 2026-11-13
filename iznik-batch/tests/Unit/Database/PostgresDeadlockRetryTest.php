<?php

namespace Tests\Unit\Database;

use App\Database\JsonTextPostgresConnection;
use Illuminate\Database\QueryException;
use PDOException;
use Tests\TestCase;

/**
 * The PostgreSQL connection retries a statement that loses a deadlock or a lock wait, but only outside a
 * transaction. No database is needed: the statement is replaced by a stub that fails a set number of times,
 * so this runs whichever engine the suite is on.
 */
class PostgresDeadlockRetryTest extends TestCase
{
    /** A connection whose statements fail with the given PostgreSQL error until $failures have happened. */
    private function connection(string $sqlState, string $message, int $failures): JsonTextPostgresConnection
    {
        return new class(fn () => null, 'iznik', '', [], $sqlState, $message, $failures) extends JsonTextPostgresConnection {
            public int $callCount = 0;

            public function __construct($pdo, $database, $prefix, $config, private string $sqlState, private string $failMessage, private int $failures)
            {
                parent::__construct($pdo, $database, $prefix, $config);
                $this->deadlockBaseDelayMs = 1;
            }

            public function pretendInTransaction(): void
            {
                $this->transactions = 1;
            }

            protected function runQueryCallback($query, $bindings, \Closure $callback)
            {
                $this->callCount++;

                if ($this->callCount <= $this->failures) {
                    $pdo = new PDOException("SQLSTATE[{$this->sqlState}]: 7 {$this->failMessage}", 0);
                    $pdo->errorInfo = [$this->sqlState, 7, $this->failMessage];

                    throw new QueryException('pgsql', $query, $bindings, $pdo);
                }

                return 1;
            }
        };
    }

    public function test_a_deadlocked_statement_outside_a_transaction_is_run_again(): void
    {
        $conn = $this->connection('40P01', 'ERROR:  deadlock detected', 2);

        $this->assertSame(1, $conn->update('update "x" set "y" = 1 where "id" = ?', [1]));
        $this->assertSame(3, $conn->callCount);
    }

    public function test_a_lock_timeout_outside_a_transaction_is_run_again(): void
    {
        $conn = $this->connection('55P03', 'ERROR:  canceling statement due to lock timeout', 1);

        $this->assertSame(1, $conn->update('update "x" set "y" = 1 where "id" = ?', [1]));
        $this->assertSame(2, $conn->callCount);
    }

    public function test_it_gives_up_after_three_retries(): void
    {
        $conn = $this->connection('40P01', 'ERROR:  deadlock detected', 99);

        try {
            $conn->update('update "x" set "y" = 1 where "id" = ?', [1]);
            $this->fail('Expected the deadlock to be thrown once the retries ran out.');
        } catch (QueryException $e) {
            $this->assertSame('40P01', $e->getPrevious()->errorInfo[0]);
        }

        // One first attempt and three retries.
        $this->assertSame(4, $conn->callCount);
    }

    /**
     * PostgreSQL has already aborted the transaction, so running the statement again could only fail with
     * 25P02. The error goes to the caller, which retries the whole transaction.
     */
    public function test_a_deadlock_inside_a_transaction_is_not_retried(): void
    {
        $conn = $this->connection('40P01', 'ERROR:  deadlock detected', 99);
        $conn->pretendInTransaction();

        try {
            $conn->update('update "x" set "y" = 1 where "id" = ?', [1]);
            $this->fail('Expected the deadlock to be thrown.');
        } catch (QueryException $e) {
            $this->assertSame(1, $conn->callCount);
        }
    }

    public function test_another_error_is_not_retried(): void
    {
        $conn = $this->connection('23505', 'ERROR:  duplicate key value violates unique constraint "x_pkey"', 99);

        try {
            $conn->update('update "x" set "y" = 1 where "id" = ?', [1]);
            $this->fail('Expected the unique violation to be thrown.');
        } catch (QueryException $e) {
            $this->assertSame(1, $conn->callCount);
        }
    }
}
