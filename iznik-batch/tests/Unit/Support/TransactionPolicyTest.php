<?php

namespace Tests\Unit\Support;

use App\Database\Sql;
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

    /**
     * Regression (2026-08-06): rippling queries bind polygon WKT whose
     * coordinate digits routinely contain "1213". Substring-matching the whole
     * QueryException message - which embeds the SQL with bindings - classified
     * deterministic geometry errors as deadlocks, burning 3 futile retries per
     * statement and logging "deadlock persisted" hundreds of times a day. The
     * driver's structured errorInfo is authoritative and unpollutable.
     */
    public function test_is_deadlock_ignores_1213_inside_query_bindings(): void
    {
        $pdo = new PDOException('SQLSTATE[22S01]: <<Unknown error>>: 3516 POLYGON/MULTIPOLYGON value is a geometry of unexpected type GEOMCOLLECTION in st_area.');
        $pdo->errorInfo = ['22S01', 3516, 'POLYGON/MULTIPOLYGON value is a geometry of unexpected type GEOMCOLLECTION in st_area.'];
        $e = new QueryException(
            'mysql',
            'SELECT ST_Area(ST_Intersection(iso, grp)) FROM (SELECT ST_GeomFromText(?, ' . config('freegle.srid') . ') AS iso, ST_GeomFromText(?, ' . config('freegle.srid') . ') AS grp) t',
            ['POLYGON((-121300.5 6712133.4,-121200.1 6712050.9,-121300.5 6712133.4))', 'POLYGON((0 0,1 0,1 1,0 1,0 0))'],
            $pdo
        );

        $this->assertFalse(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_error_info_1713_undo_log_is_not_deadlock(): void
    {
        $pdo = new PDOException('SQLSTATE[HY000]: General error: 1713 Undo log record is too big.');
        $pdo->errorInfo = ['HY000', 1713, 'Undo log record is too big.'];
        $e = new QueryException(
            'mysql',
            'UPDATE rippling_reach SET polygon = ST_GeomFromText(?, ' . config('freegle.srid') . ') WHERE msgid = ?',
            ['POLYGON((-121356.1 6712050.9,-121300.5 6712133.4,-121356.1 6712050.9))', 121159007],
            $pdo
        );

        $this->assertFalse(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_error_info_1213_is_deadlock(): void
    {
        $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        $e = new QueryException('mysql', 'INSERT INTO rippling_reach (msgid) VALUES (?)', [1], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_error_info_1205_lock_wait_is_deadlock(): void
    {
        $pdo = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
        $pdo->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];
        $e = new QueryException('mysql', 'UPDATE x SET y = ?', [1], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    /**
     * PostgreSQL through PDO always reports driver code 7, so the SQLSTATE is the only signal. These are the
     * errorInfo arrays the pgsql driver produced for a real deadlock and a real lock timeout.
     */
    public function test_is_deadlock_postgres_40p01_is_deadlock(): void
    {
        $pdo = new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected', 0);
        $pdo->errorInfo = ['40P01', 7, "ERROR:  deadlock detected\nDETAIL:  Process 105618 waits for ShareLock on transaction 481478; blocked by process 105601.\nHINT:  See server log for query details.\nCONTEXT:  while updating tuple (0,1) in relation \"memberships\""];
        $e = new QueryException('pgsql', 'update "memberships" set "role" = ? where "id" = ?', ['Member', 1], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_postgres_55p03_lock_timeout_is_deadlock(): void
    {
        $pdo = new PDOException('SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout', 0);
        $pdo->errorInfo = ['55P03', 7, "ERROR:  canceling statement due to lock timeout\nCONTEXT:  while updating tuple (0,1) in relation \"memberships\""];
        $e = new QueryException('pgsql', 'update "memberships" set "role" = ? where "id" = ?', ['Member', 1], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    public function test_is_deadlock_postgres_40001_serialization_failure_is_deadlock(): void
    {
        $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 7 ERROR:  could not serialize access due to concurrent update', 0);
        $pdo->errorInfo = ['40001', 7, 'ERROR:  could not serialize access due to concurrent update'];
        $e = new QueryException('pgsql', 'update "memberships" set "role" = ? where "id" = ?', ['Member', 1], $pdo);

        $this->assertTrue(TransactionPolicy::isDeadlock($e));
    }

    /**
     * Driver code 7 on every PostgreSQL error must not make every error a deadlock, and the word "deadlock"
     * or the digits 1213 in a bound value must not either.
     */
    public function test_is_deadlock_postgres_other_errors_are_not_deadlocks(): void
    {
        $cases = [
            ['22012', 'ERROR:  division by zero'],
            ['23505', 'ERROR:  duplicate key value violates unique constraint "users_emails_email"'],
            ['25P02', 'ERROR:  current transaction is aborted, commands ignored until end of transaction block'],
            ['57014', 'ERROR:  canceling statement due to statement timeout'],
        ];

        foreach ($cases as [$state, $message]) {
            $pdo = new PDOException("SQLSTATE[{$state}]: 7 {$message}", 0);
            $pdo->errorInfo = [$state, 7, $message];
            $e = new QueryException('pgsql', 'update "x" set "note" = ? where "id" = ?', ['deadlock 1213 1205', 1], $pdo);

            $this->assertFalse(TransactionPolicy::isDeadlock($e), $state);
        }
    }

    /** MySQL never reports the PostgreSQL states, and its own codes classify as they always have. */
    public function test_is_deadlock_mysql_classification_is_unchanged(): void
    {
        $cases = [
            [['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'], true],
            [['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'], true],
            [['22S01', 3516, 'POLYGON/MULTIPOLYGON value is a geometry of unexpected type GEOMCOLLECTION in st_area.'], false],
            [['23000', 1062, "Duplicate entry 'a@b.c' for key 'email'"], false],
            [['42S02', 1146, "Table 'iznik.nope' doesn't exist"], false],
        ];

        foreach ($cases as [$info, $expected]) {
            $pdo = new PDOException("SQLSTATE[{$info[0]}]: {$info[1]} {$info[2]}", 0);
            $pdo->errorInfo = $info;
            $e = new QueryException('mysql', 'UPDATE x SET y = ?', [1], $pdo);

            $this->assertSame($expected, TransactionPolicy::isDeadlock($e), (string) $info[1]);
        }
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

        DB::statement(Sql::dropTemporaryTable('test_rollback'));
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
