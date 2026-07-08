<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Verifies that FailoverConnectionFactory falls back to the write host when all
 * read-replica hosts are unreachable.
 *
 * Each test creates a fresh connection via the factory directly (using
 * $factory->make()) so the main DB connection used by DatabaseTransactions is
 * never touched. Overriding config and purging the registered connection in
 * tearDown ensures subsequent tests get a clean slate.
 */
class FailoverConnectionFactoryTest extends TestCase
{
    /** @var array<string>|null */
    private ?array $originalReadHosts = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalReadHosts = config('database.connections.mysql.read.host');
    }

    protected function tearDown(): void
    {
        // Restore the original read-host config before parent tearDown reconnects,
        // and purge so the next test gets a clean connection with the restored config.
        if ($this->originalReadHosts !== null) {
            config(['database.connections.mysql.read.host' => $this->originalReadHosts]);
        }
        DB::purge('mysql');

        parent::tearDown();
    }

    /**
     * When all read-replica hosts are unreachable (DNS failure), the factory
     * must transparently fall back to the write host so reads still work, and
     * it must log a warning.
     */
    public function test_falls_back_to_write_host_when_read_replica_unreachable(): void
    {
        Log::spy();

        // Override read config to a hostname that will never resolve.
        config(['database.connections.mysql.read.host' => ['no-such-replica-host.invalid']]);

        // Create a fresh connection via the registered factory so we exercise
        // FailoverConnectionFactory with the bad read config, without touching
        // the main DB connection (which DatabaseTransactions wrapped in a transaction).
        /** @var \App\Database\FailoverConnectionFactory $factory */
        $factory = $this->app->make('db.factory');
        $config  = config('database.connections.mysql');
        $connection = $factory->make($config, 'mysql');

        // Reads should succeed via the write-host fallback.
        $result = $connection->select('select 1 as val');
        $this->assertNotEmpty($result);
        $this->assertEquals(1, (int) ($result[0]->val ?? $result[0]['val'] ?? null));

        $connection->disconnect();

        // The warning must have been emitted.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'read-replica'));
    }

    /**
     * When read and write hosts are the same (single-host / dev/CI deployments),
     * no failover wrapper is applied — the factory behaves as it always did.
     */
    public function test_single_host_deployment_unaffected(): void
    {
        $writeHosts = config('database.connections.mysql.write.host');

        // Set read hosts == write hosts to simulate a single-host setup.
        config(['database.connections.mysql.read.host' => $writeHosts]);

        /** @var \App\Database\FailoverConnectionFactory $factory */
        $factory = $this->app->make('db.factory');
        $config  = config('database.connections.mysql');
        $connection = $factory->make($config, 'mysql');

        $result = $connection->select('select 1 as val');
        $this->assertNotEmpty($result);
        $this->assertEquals(1, (int) ($result[0]->val ?? $result[0]['val'] ?? null));

        $connection->disconnect();
    }
}
