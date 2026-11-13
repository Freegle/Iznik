<?php

namespace Tests\Unit\Database;

use App\Database\LockTimeoutPostgresConnector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** A PostgreSQL connection gives up waiting for a lock after 50 seconds, as InnoDB does. */
class LockTimeoutPostgresConnectorTest extends TestCase
{
    public function test_the_setting_is_checked_before_it_reaches_sql(): void
    {
        $this->assertSame('50s', LockTimeoutPostgresConnector::lockTimeout(['lock_timeout' => '50s']));
        $this->assertSame('200ms', LockTimeoutPostgresConnector::lockTimeout(['lock_timeout' => ' 200ms ']));
        $this->assertSame('0', LockTimeoutPostgresConnector::lockTimeout(['lock_timeout' => 0]));
        $this->assertNull(LockTimeoutPostgresConnector::lockTimeout([]));
        $this->assertNull(LockTimeoutPostgresConnector::lockTimeout(['lock_timeout' => '']));
        $this->assertNull(LockTimeoutPostgresConnector::lockTimeout(['lock_timeout' => null]));

        foreach (["50s'; DROP TABLE users; --", '50 seconds', '-1', 'soon'] as $bad) {
            try {
                LockTimeoutPostgresConnector::lockTimeout(['lock_timeout' => $bad]);
                $this->fail("'{$bad}' was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('PGSQL_LOCK_TIMEOUT', $e->getMessage());
            }
        }
    }

    public function test_the_default_is_fifty_seconds_and_the_connector_is_ours(): void
    {
        $this->assertSame('50s', config('database.connections.pgsql.lock_timeout'));
        $this->assertInstanceOf(LockTimeoutPostgresConnector::class, app('db.connector.pgsql'));
    }

    public function test_a_postgresql_session_has_the_lock_timeout(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        $this->assertSame('50s', DB::selectOne('SHOW lock_timeout')->lock_timeout);
    }
}
