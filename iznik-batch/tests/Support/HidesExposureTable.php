<?php

namespace Tests\Support;

/**
 * Make ux_experiment_exposures look missing, as it is on a database the SQL has not been run on yet.
 *
 * The table is renamed on a SECOND connection. MySQL commits on any DDL, so renaming it on the
 * test's own connection would end the transaction every test runs in and leave its rows behind for
 * the classes after it (.claude/rules/laravel-batch-traps.md). The test's own connection has not
 * touched the table when this is called, so it holds no lock that would make the rename wait.
 *
 * Two ways to use it:
 *  - withoutExposureTable($work) hides the table for the length of a callback. Statements against
 *    the table then fail with "table not found", which is enough for code that catches the failure.
 *  - hideExposureTable() / restoreExposureTable() around the test's own transaction, for code that
 *    asks Schema::hasTable. A transaction that has already read answers schema questions from the
 *    snapshot it took, so a table renamed after that still looks present. Hide the table BEFORE
 *    parent::setUp() begins the transaction and put it back AFTER parent::tearDown() ends it.
 */
trait HidesExposureTable
{
    use RecoversFromDatabaseErrors;

    private const EXPOSURES = 'ux_experiment_exposures';

    private const EXPOSURES_HIDDEN = 'ux_experiment_exposures_hidden';

    /**
     * The second connection, opened when the table is hidden and kept until it is put back, because
     * the put-back may run after the application (and so config()) has been torn down.
     */
    private ?\PDO $exposureSide = null;

    /** Whether the suite runs on PostgreSQL, fixed when the table is hidden because config() is gone by the put-back. */
    private ?bool $exposureOnPostgres = null;

    private ?string $exposureSchema = null;

    private ?string $exposureSearchPath = null;

    protected function hideExposureTable(): void
    {
        $this->exposureOnPostgres = config('database.default') === 'pgsql';
        // The schema the test's own connection works in: another connection must rename that one's table, not another's.
        $this->exposureSchema = $this->exposureOnPostgres ? \Illuminate\Support\Facades\DB::selectOne('select current_schema() as s')->s : null;
        $this->exposureSide = $this->exposureSideConnection();
        if ($this->exposureOnPostgres) {
            // The shared schema also holds the table: without this the lookup would fall through to it.
            $this->exposureSearchPath = \Illuminate\Support\Facades\DB::selectOne('show search_path')->search_path;
            \Illuminate\Support\Facades\DB::unprepared('SET search_path TO "'.$this->exposureSchema.'"');
        }
        $this->exposureSide->exec($this->renameSql(self::EXPOSURES, self::EXPOSURES_HIDDEN));
    }

    protected function restoreExposureTable(): void
    {
        $side = $this->exposureSide ?? $this->exposureSideConnection();
        $hidden = $this->onPostgres()
            ? $side->query("SELECT to_regclass('".$this->qualified(self::EXPOSURES_HIDDEN)."')")->fetchColumn()
            : $side->query("SHOW TABLES LIKE '".self::EXPOSURES_HIDDEN."'")->fetchColumn();
        if ($hidden !== false && $hidden !== null) {
            $side->exec($this->renameSql(self::EXPOSURES_HIDDEN, self::EXPOSURES));
        }
    }

    /**
     * Run $work with the table gone, and put it back whatever happens.
     */
    protected function withoutExposureTable(callable $work): mixed
    {
        $this->hideExposureTable();

        try {
            return $this->recoveringFromDatabaseErrors($work);
        } finally {
            if ($this->exposureSearchPath !== null) {
                \Illuminate\Support\Facades\DB::unprepared('SET search_path TO '.$this->exposureSearchPath);
                $this->exposureSearchPath = null;
            }
            $this->restoreExposureTable();
        }
    }

    private function onPostgres(): bool
    {
        return $this->exposureOnPostgres ?? config('database.default') === 'pgsql';
    }

    private function qualified(string $table): string
    {
        return $this->exposureSchema === null ? $table : '"'.$this->exposureSchema.'".'.$table;
    }

    private function renameSql(string $from, string $to): string
    {
        return $this->onPostgres() ? 'ALTER TABLE '.$this->qualified($from)." RENAME TO {$to}" : "RENAME TABLE {$from} TO {$to}";
    }

    private function exposureSideConnection(): \PDO
    {
        if ($this->onPostgres()) {
            $c = config('database.connections.pgsql');
            $pdo = new \PDO(
                "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
                $c['username'],
                $c['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            // A rename that cannot get its lock fails in seconds instead of waiting for a year.
            $pdo->exec("SET lock_timeout = '5s'");

            return $pdo;
        }

        $c = config('database.connections.mysql');
        $host = $c['write']['host'] ?? [$c['host'] ?? '127.0.0.1'];
        $host = is_array($host) ? reset($host) : $host;

        $pdo = new \PDO(
            "mysql:host={$host};port={$c['port']};dbname={$c['database']};charset=utf8mb4",
            $c['username'],
            $c['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        // A rename that cannot get its lock fails in seconds instead of waiting for a year.
        $pdo->exec('SET SESSION lock_wait_timeout = 5');

        return $pdo;
    }
}
