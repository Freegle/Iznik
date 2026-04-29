<?php

namespace Tests\Unit\Database;

use App\Database\DeadlockRetryConnection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockRetryConnectionTest extends TestCase
{
    public function test_connection_is_deadlock_retry_instance(): void
    {
        // Verify the service provider registered our custom connection.
        $connection = DB::connection();
        $this->assertInstanceOf(DeadlockRetryConnection::class, $connection);
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

        DB::statement('DROP TEMPORARY TABLE test_deadlock_conn');
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

        DB::statement('DROP TEMPORARY TABLE test_deadlock_txn');
    }

    public function test_non_deadlock_errors_propagate_immediately(): void
    {
        // Syntax errors or missing tables should throw immediately, not retry.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::select('SELECT * FROM nonexistent_table_deadlock_test_xyz');
    }

    public function test_deadlock_retries_and_succeeds(): void
    {
        $conn = DB::connection();
        $this->assertInstanceOf(DeadlockRetryConnection::class, $conn);

        // Subclass that fails with a deadlock the first N times runQueryCallback
        // is invoked, then succeeds. Proves the retry loop actually reruns the
        // callback rather than just swallowing the exception.
        $testable = new class($conn->getPdo(), $conn->getDatabaseName(), $conn->getTablePrefix(), $conn->getConfig()) extends DeadlockRetryConnection {
            public int $callCount = 0;
            public int $failUntilAttempt = 2;
            public int $lastDelayMs = 0;

            protected function runQueryCallback($query, $bindings, \Closure $callback)
            {
                $this->callCount++;
                if ($this->callCount <= $this->failUntilAttempt) {
                    throw new \Illuminate\Database\QueryException(
                        'mysql', $query, $bindings,
                        new \PDOException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction', 40001)
                    );
                }
                return 1;
            }
        };

        // Fast path: shrink delay so the test runs quickly.
        $ref = new \ReflectionProperty(DeadlockRetryConnection::class, 'deadlockBaseDelayMs');
        $ref->setAccessible(true);
        $ref->setValue($testable, 1);

        $result = $testable->update('UPDATE fake SET x = 1 WHERE id = ?', [1]);

        // initial (fail) -> retry 1 (fail) -> retry 2 (succeeds) = 3 calls.
        $this->assertEquals(3, $testable->callCount);
        $this->assertEquals(1, $result);
    }

    public function test_deadlock_exhausts_retries_and_throws(): void
    {
        $conn = DB::connection();

        // Always fails with deadlock — after maxRetries attempts, should rethrow.
        $testable = new class($conn->getPdo(), $conn->getDatabaseName(), $conn->getTablePrefix(), $conn->getConfig()) extends DeadlockRetryConnection {
            public int $callCount = 0;

            protected function runQueryCallback($query, $bindings, \Closure $callback)
            {
                $this->callCount++;
                throw new \Illuminate\Database\QueryException(
                    'mysql', $query, $bindings,
                    new \PDOException('SQLSTATE[40001]: Deadlock found; try restarting transaction', 40001)
                );
            }
        };

        $ref = new \ReflectionProperty(DeadlockRetryConnection::class, 'deadlockBaseDelayMs');
        $ref->setAccessible(true);
        $ref->setValue($testable, 1);

        try {
            $testable->update('UPDATE fake SET x = 1 WHERE id = ?', [1]);
            $this->fail('Expected QueryException after retries exhausted');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('Deadlock', $e->getMessage());
        }

        // 1 initial + 3 retries = 4 attempts total
        $this->assertEquals(4, $testable->callCount);
    }
}
