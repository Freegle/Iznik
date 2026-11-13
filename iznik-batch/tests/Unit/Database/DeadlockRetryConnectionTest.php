<?php

namespace Tests\Unit\Database;

use App\Database\DeadlockRetryConnection;
use App\Database\JsonTextPostgresConnection;
use App\Database\PostgresBinaryConnection;
use App\Database\Sql;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockRetryConnectionTest extends TestCase
{
    /** The connection class that retries a deadlocked statement on the engine the suite is running on. */
    private function retryingClass(): string
    {
        return $this->onPostgres() ? PostgresBinaryConnection::class : DeadlockRetryConnection::class;
    }

    /** The error each engine raises for a statement that lost a deadlock. */
    private static function deadlock(string $query, array $bindings): QueryException
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return new QueryException('mysql', $query, $bindings, new \PDOException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction', 40001));
        }

        // PDO's pgsql driver reports the SQLSTATE in errorInfo and always 7 as its own code.
        $lost = new \PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        $lost->errorInfo = ['40P01', 7, 'ERROR:  deadlock detected'];

        return new QueryException('pgsql', $query, $bindings, $lost);
    }

    /**
     * A connection of the engine's retrying class whose statements lose a deadlock the first $failures times
     * and then succeed. It proves the retry loop runs the statement again, and does not just swallow the error.
     */
    private function failingConnection(int $failures): object
    {
        $conn = DB::connection();
        $args = [$conn->getPdo(), $conn->getDatabaseName(), $conn->getTablePrefix(), $conn->getConfig()];
        $raise = fn (string $query, array $bindings) => self::deadlock($query, $bindings);

        $testable = $this->onPostgres()
            // The class registered for pgsql, which is a PostgresBinaryConnection.
            ? new class(...$args) extends JsonTextPostgresConnection {
                public int $callCount = 0;
                public int $failUntilAttempt = 0;
                public ?\Closure $raise = null;

                protected function runQueryCallback($query, $bindings, \Closure $callback)
                {
                    $this->callCount++;
                    if ($this->callCount <= $this->failUntilAttempt) {
                        throw ($this->raise)($query, $bindings);
                    }

                    return 1;
                }
            }
            : new class(...$args) extends DeadlockRetryConnection {
                public int $callCount = 0;
                public int $failUntilAttempt = 0;
                public ?\Closure $raise = null;

                protected function runQueryCallback($query, $bindings, \Closure $callback)
                {
                    $this->callCount++;
                    if ($this->callCount <= $this->failUntilAttempt) {
                        throw ($this->raise)($query, $bindings);
                    }

                    return 1;
                }
            };
        $testable->failUntilAttempt = $failures;
        $testable->raise = $raise;

        // Shrink the delay so the test runs quickly.
        $ref = new \ReflectionProperty($this->retryingClass(), 'deadlockBaseDelayMs');
        $ref->setAccessible(true);
        $ref->setValue($testable, 1);

        return $testable;
    }

    public function test_connection_is_deadlock_retry_instance(): void
    {
        // Verify the service provider registered our custom connection.
        $this->assertInstanceOf($this->retryingClass(), DB::connection());
    }

    public function test_normal_queries_work(): void
    {
        // Basic sanity: the custom connection doesn't break normal queries.
        $result = DB::selectOne('SELECT 1 AS val');
        $this->assertEquals(1, $result->val);
    }

    public function test_normal_insert_update_delete_work(): void
    {
        DB::statement('CREATE TEMPORARY TABLE test_deadlock_conn (id INT PRIMARY KEY, val VARCHAR(50))');

        DB::insert('INSERT INTO test_deadlock_conn VALUES (1, ?)', ['hello']);
        $row = DB::selectOne('SELECT val FROM test_deadlock_conn WHERE id = 1');
        $this->assertEquals('hello', $row->val);

        DB::update('UPDATE test_deadlock_conn SET val = ? WHERE id = 1', ['world']);
        $row = DB::selectOne('SELECT val FROM test_deadlock_conn WHERE id = 1');
        $this->assertEquals('world', $row->val);

        DB::delete('DELETE FROM test_deadlock_conn WHERE id = 1');
        $count = DB::selectOne('SELECT COUNT(*) AS cnt FROM test_deadlock_conn');
        $this->assertEquals(0, $count->cnt);

        DB::statement(Sql::dropTemporaryTable('test_deadlock_conn'));
    }

    public function test_transactions_still_work(): void
    {
        DB::statement('CREATE TEMPORARY TABLE test_deadlock_txn (id INT PRIMARY KEY)');

        // Test transaction nesting (test framework already wraps in one).
        $baseTxnLevel = DB::transactionLevel();

        DB::beginTransaction();
        $this->assertEquals($baseTxnLevel + 1, DB::transactionLevel());
        DB::insert('INSERT INTO test_deadlock_txn VALUES (1)');
        DB::rollBack();

        $count = DB::selectOne('SELECT COUNT(*) AS cnt FROM test_deadlock_txn');
        $this->assertEquals(0, $count->cnt);

        DB::statement(Sql::dropTemporaryTable('test_deadlock_txn'));
    }

    public function test_non_deadlock_errors_propagate_immediately(): void
    {
        // Syntax errors or missing tables should throw immediately, not retry.
        try {
            // In a transaction of its own: PostgreSQL ends the transaction a refused statement is in.
            DB::transaction(fn () => DB::select('SELECT * FROM nonexistent_table_deadlock_test_xyz'));
            $this->fail('a missing table raised nothing');
        } catch (QueryException $e) {
            $this->assertStringContainsString('nonexistent_table_deadlock_test_xyz', $e->getMessage());
        }
    }

    public function test_deadlock_retries_and_succeeds(): void
    {
        $testable = $this->failingConnection(2);

        $result = $testable->update('UPDATE fake SET x = 1 WHERE id = ?', [1]);

        // initial (fail) -> retry 1 (fail) -> retry 2 (succeeds) = 3 calls.
        $this->assertEquals(3, $testable->callCount);
        $this->assertEquals(1, $result);
    }

    public function test_deadlock_exhausts_retries_and_throws(): void
    {
        // Always fails with a deadlock: after the retries it is raised to the caller.
        $testable = $this->failingConnection(PHP_INT_MAX);

        try {
            $testable->update('UPDATE fake SET x = 1 WHERE id = ?', [1]);
            $this->fail('Expected QueryException after retries exhausted');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('deadlock', $e->getMessage());
        }

        // 1 initial + 3 retries = 4 attempts total
        $this->assertEquals(4, $testable->callCount);
    }
}
