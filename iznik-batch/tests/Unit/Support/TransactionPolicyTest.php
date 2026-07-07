<?php

namespace Tests\Unit\Support;

use App\Support\TransactionPolicy;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests for TransactionPolicy helper.
 *
 * Note: Laravel's test framework wraps tests in transactions for rollback.
 * Some tests account for this by checking relative transaction levels.
 */
class TransactionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset configuration to defaults
        TransactionPolicy::configure(3, 100);
    }

    public function test_transaction_level_increases_with_nested_transactions(): void
    {
        $baseLevel = TransactionPolicy::transactionLevel();

        DB::beginTransaction();
        $this->assertEquals($baseLevel + 1, TransactionPolicy::transactionLevel());

        DB::beginTransaction();
        $this->assertEquals($baseLevel + 2, TransactionPolicy::transactionLevel());

        DB::rollBack();
        $this->assertEquals($baseLevel + 1, TransactionPolicy::transactionLevel());

        DB::rollBack();
        $this->assertEquals($baseLevel, TransactionPolicy::transactionLevel());
    }

    public function test_in_transaction_detects_active_transaction(): void
    {
        // We may already be in a test transaction, so just verify behavior changes
        $initialState = TransactionPolicy::inTransaction();

        DB::beginTransaction();
        $this->assertTrue(TransactionPolicy::inTransaction());
        DB::rollBack();

        $this->assertEquals($initialState, TransactionPolicy::inTransaction());
    }

    public function test_bulk_rejects_call_inside_transaction(): void
    {
        // DatabaseTransactions wraps us in a transaction, so bulk() should throw.
        $this->assertTrue(TransactionPolicy::inTransaction());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('should not run inside a transaction');

        TransactionPolicy::bulk(function () {
            return 'should not reach here';
        }, 'test bulk inside transaction');
    }

    public function test_atomic_executes_operation_and_returns_result(): void
    {
        $result = TransactionPolicy::atomic(function () {
            return 'atomic result';
        }, 'test atomic');

        $this->assertEquals('atomic result', $result);
    }

    public function test_atomic_increases_transaction_level(): void
    {
        $baseLevel = TransactionPolicy::transactionLevel();
        $levelDuringOperation = null;

        TransactionPolicy::atomic(function () use (&$levelDuringOperation, $baseLevel) {
            $levelDuringOperation = TransactionPolicy::transactionLevel();
        }, 'test level');

        $this->assertEquals($baseLevel + 1, $levelDuringOperation);
    }

    public function test_is_deadlock_detects_deadlock_exception(): void
    {
        $e = new DeadlockException('Deadlock found');
        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_detects_message_with_deadlock_keyword(): void
    {
        $pdo = new PDOException('Deadlock found when trying to get lock', 0);
        $e = new QueryException('mysql', 'UPDATE x SET y=1', [], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_detects_message_with_1213(): void
    {
        $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213', 0);
        $e = new QueryException('mysql', 'UPDATE x SET y=1', [], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_detects_lock_wait_timeout(): void
    {
        $pdo = new PDOException('Lock wait timeout exceeded', 0);
        $e = new QueryException('mysql', 'UPDATE x SET y=1', [], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_returns_false_for_other_errors(): void
    {
        $pdo = new PDOException('Table does not exist', 0);
        $e = new QueryException('mysql', 'SELECT * FROM nonexistent', [], $pdo);

        $this->assertFalse(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_returns_false_for_syntax_error(): void
    {
        $pdo = new PDOException('You have an error in your SQL syntax', 0);
        $e = new QueryException('mysql', 'SELEC * FROM x', [], $pdo);

        $this->assertFalse(TransactionPolicy::isDeadlock($e));
    }

    public function test_atomic_retries_on_deadlock_exception(): void
    {
        $attempts = 0;

        TransactionPolicy::configure(3, 10); // Fast retries for testing

        $result = TransactionPolicy::atomic(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw new DeadlockException('Simulated deadlock');
            }
            return 'success after retry';
        }, 'test retry');

        $this->assertEquals(2, $attempts);
        $this->assertEquals('success after retry', $result);
    }

    public function test_atomic_gives_up_after_max_retries(): void
    {
        $attempts = 0;

        TransactionPolicy::configure(3, 10);

        $this->expectException(DeadlockException::class);

        TransactionPolicy::atomic(function () use (&$attempts) {
            $attempts++;
            throw new DeadlockException('Persistent deadlock');
        }, 'test max retries');
    }

    public function test_atomic_does_not_retry_non_deadlock_errors(): void
    {
        $attempts = 0;

        TransactionPolicy::configure(3, 10);

        try {
            TransactionPolicy::atomic(function () use (&$attempts) {
                $attempts++;
                throw new RuntimeException('Not a deadlock');
            }, 'test no retry');
        } catch (RuntimeException $e) {
            // Expected
        }

        $this->assertEquals(1, $attempts);
    }

    public function test_configure_changes_max_retries(): void
    {
        $attempts = 0;

        TransactionPolicy::configure(5, 10);

        try {
            TransactionPolicy::atomic(function () use (&$attempts) {
                $attempts++;
                throw new DeadlockException('Deadlock');
            }, 'test config');
        } catch (DeadlockException $e) {
            // Expected
        }

        $this->assertEquals(5, $attempts);
    }

    public function test_assert_no_transaction_throws_when_in_transaction(): void
    {
        DB::beginTransaction();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('should not run inside a transaction');
            TransactionPolicy::assertNoTransaction('bulk email sending');
        } finally {
            DB::rollBack();
        }
    }

    public function test_assert_no_transaction_message_includes_context(): void
    {
        DB::beginTransaction();

        try {
            TransactionPolicy::assertNoTransaction('my custom operation');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('my custom operation', $e->getMessage());
            $this->assertStringContainsString('auto-commit', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_atomic_rolls_back_on_non_deadlock_exception(): void
    {
        // Create a test table
        DB::statement('CREATE TEMPORARY TABLE test_rollback (id INT)');
        DB::insert('INSERT INTO test_rollback VALUES (1)');

        try {
            TransactionPolicy::atomic(function () {
                DB::insert('INSERT INTO test_rollback VALUES (2)');
                throw new RuntimeException('Simulated failure');
            }, 'test rollback');
        } catch (RuntimeException $e) {
            // Expected
        }

        // Only original row should exist (insert was rolled back)
        $count = DB::table('test_rollback')->count();
        $this->assertEquals(1, $count);

        DB::statement('DROP TEMPORARY TABLE test_rollback');
    }

    public function test_bulk_deadlock_retry_rejects_inside_transaction(): void
    {
        // Verify bulk() consistently rejects calls inside transactions,
        // even when testing retry scenarios.
        $this->assertTrue(TransactionPolicy::inTransaction());

        TransactionPolicy::configure(3, 10);

        $this->expectException(RuntimeException::class);
        TransactionPolicy::bulk(fn () => 'test', 'test bulk retry');
    }

    public function test_bulk_max_retries_rejects_inside_transaction(): void
    {
        $this->assertTrue(TransactionPolicy::inTransaction());

        TransactionPolicy::configure(3, 10);

        $this->expectException(RuntimeException::class);
        TransactionPolicy::bulk(fn () => 'test', 'test bulk max retries');
    }

    public function test_bulk_non_deadlock_rejects_inside_transaction(): void
    {
        $this->assertTrue(TransactionPolicy::inTransaction());

        TransactionPolicy::configure(3, 10);

        $this->expectException(RuntimeException::class);
        TransactionPolicy::bulk(fn () => 'test', 'test bulk no retry');
    }
}
