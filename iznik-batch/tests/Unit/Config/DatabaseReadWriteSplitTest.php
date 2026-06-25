<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Verifies the read/write database split in config/database.php:
 * writes always go to DB_HOST, reads go to DB_HOST_READ when set and fall back
 * to DB_HOST otherwise, and sticky is enabled for Galera read-after-write
 * safety. See the 'mysql' connection in config/database.php.
 */
class DatabaseReadWriteSplitTest extends TestCase
{
    /**
     * Re-evaluate config/database.php with a controlled environment and return
     * the resolved 'mysql' connection array. Reads the file directly so we test
     * the resolution logic rather than the booted app's already-resolved config.
     *
     * @param  array<string,string>  $env  Keys present are set; keys absent are unset.
     * @return array<string,mixed>
     */
    private function loadMysqlConfig(array $env): array
    {
        $keys = ['DB_HOST', 'DB_HOST_READ', 'DB_PORT'];

        $saved = [];
        foreach ($keys as $k) {
            $saved[$k] = [
                'env' => array_key_exists($k, $_ENV) ? $_ENV[$k] : null,
                'server' => array_key_exists($k, $_SERVER) ? $_SERVER[$k] : null,
                'getenv' => getenv($k),
            ];
        }

        try {
            foreach ($keys as $k) {
                if (array_key_exists($k, $env)) {
                    $val = $env[$k];
                    putenv("{$k}={$val}");
                    $_ENV[$k] = $val;
                    $_SERVER[$k] = $val;
                } else {
                    putenv($k);
                    unset($_ENV[$k], $_SERVER[$k]);
                }
            }

            $config = require base_path('config/database.php');

            return $config['connections']['mysql'];
        } finally {
            foreach ($keys as $k) {
                $s = $saved[$k];
                if ($s['getenv'] === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$s['getenv']}");
                }
                if ($s['env'] === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $s['env'];
                }
                if ($s['server'] === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $s['server'];
                }
            }
        }
    }

    public function test_read_falls_back_to_write_host_when_read_host_unset(): void
    {
        $mysql = $this->loadMysqlConfig(['DB_HOST' => '10.0.0.1']);

        $this->assertSame(['10.0.0.1'], $mysql['write']['host']);
        $this->assertSame(['10.0.0.1'], $mysql['read']['host'], 'read host must fall back to DB_HOST when DB_HOST_READ is unset');
    }

    public function test_read_host_uses_db_host_read_when_set(): void
    {
        $mysql = $this->loadMysqlConfig(['DB_HOST' => '10.0.0.1', 'DB_HOST_READ' => '10.0.0.2']);

        $this->assertSame(['10.0.0.1'], $mysql['write']['host'], 'writes always go to DB_HOST');
        $this->assertSame(['10.0.0.2'], $mysql['read']['host']);
    }

    public function test_empty_read_host_falls_back_to_write_host(): void
    {
        $mysql = $this->loadMysqlConfig(['DB_HOST' => '10.0.0.1', 'DB_HOST_READ' => '']);

        $this->assertSame(['10.0.0.1'], $mysql['read']['host'], 'an empty DB_HOST_READ must fall back to DB_HOST');
    }

    public function test_comma_separated_read_hosts_become_a_trimmed_list(): void
    {
        $mysql = $this->loadMysqlConfig(['DB_HOST' => '10.0.0.1', 'DB_HOST_READ' => '10.0.0.2, 10.0.0.3']);

        $this->assertSame(['10.0.0.2', '10.0.0.3'], $mysql['read']['host']);
    }

    public function test_sticky_is_disabled(): void
    {
        $mysql = $this->loadMysqlConfig(['DB_HOST' => '10.0.0.1']);

        // Galera is synchronous; only an open transaction must stay on one node,
        // which Laravel handles independently of sticky. Sticky would only defeat
        // read offloading.
        $this->assertFalse($mysql['sticky'], 'sticky must be false for a Galera read/write split');
    }

    public function test_booted_mysql_connection_is_configured_with_split(): void
    {
        // The resolved connection the app actually uses - not just the file - must
        // carry the split for it to take effect at runtime.
        $this->assertFalse(config('database.connections.mysql.sticky'));
        $this->assertIsArray(config('database.connections.mysql.read.host'));
        $this->assertIsArray(config('database.connections.mysql.write.host'));
        $this->assertNotEmpty(config('database.connections.mysql.write.host'));
    }

    public function test_runtime_splits_reads_and_writes_without_sticky_pinning(): void
    {
        // Build a throwaway connection from the real mysql config. Read and write
        // resolve to the same test host (no DB_HOST_READ in CI), but Laravel still
        // opens distinct read/write PDOs. This connection is NOT wrapped by
        // DatabaseTransactions (only the default connection is), so transactions
        // == 0 and read routing is actually observable.
        config(['database.connections.mysql_split_probe' => config('database.connections.mysql')]);

        try {
            $conn = \DB::connection('mysql_split_probe');

            // Split active: a read uses a separate read PDO from the write PDO.
            $this->assertNotSame(
                $conn->getPdo(),
                $conn->getReadPdo(),
                'the read/write split must open distinct read and write PDOs'
            );

            // No sticky: a prior write does NOT pin later reads to the write host.
            $conn->statement('DO 1');
            $this->assertNotSame(
                $conn->getPdo(),
                $conn->getReadPdo(),
                'without sticky, reads keep using the read host after a write'
            );

            // But an OPEN transaction routes reads to the write connection, so a
            // transaction always sees its own uncommitted writes (the one case
            // that genuinely needs one node).
            $conn->beginTransaction();
            try {
                $this->assertSame(
                    $conn->getPdo(),
                    $conn->getReadPdo(),
                    'inside a transaction, reads must use the write connection'
                );
            } finally {
                $conn->rollBack();
            }
        } finally {
            \DB::purge('mysql_split_probe');
        }
    }
}
