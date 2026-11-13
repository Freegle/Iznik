<?php

namespace Tests\Support\OrmHarness;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * Layer 2 of the parity harness: the same call gives the same rows on MySQL and on PostgreSQL.
 * The Go counterpart is iznik-server-go/parity/resultparity.go and the two behave alike.
 *
 * A test supplies the rows the site needs (a fixture) and a closure that makes the call. The
 * harness loads the fixture into a transaction on each engine, makes the default connection that
 * engine so the site's own DB::table(), DB::select() and Eloquent calls reach it, runs the closure,
 * rolls back, and compares what came back.
 *
 *   EngineParity::assert('a1b2c3d4e5f6', [
 *       EngineParity::table('users', [['id' => EngineParity::id(1)]]),
 *   ], fn () => app(PurgeService::class)->something(), expect: [...]);
 *
 * Engines. MySQL is the connection the suite runs on, and PostgreSQL is the `pgsql` connection
 * (PGSQL_HOST, PGSQL_PORT, PGSQL_DATABASE, PGSQL_USERNAME, PGSQL_PASSWORD).
 *   PG_ENGINE      unset, use PostgreSQL if it is reachable; 1, it must be; 0, skip it.
 *   PARITY_ENGINES unset, both engines; "pgsql" or "mysql", that one alone. A single-engine run
 *                  checks that the site works there and gives the fixture's answer (expect), and
 *                  compares nothing. CI leaves it unset.
 * With PostgreSQL absent the test is skipped, because there is nothing to compare. CI sets
 * PG_ENGINE=1 once a job provides the engine, and the skip becomes a failure.
 *
 * What it fails on, beyond a difference in the rows: an empty result (two engines agreeing on
 * nothing prove nothing), a result that is not the one the fixture should give (`expect`), and a
 * fixture an engine refuses.
 */
final class EngineParity
{
    /** @var array<string,true> the tables a MySQL fixture has written to since the counters were last reset */
    private static array $touchedTables = [];

    /** The first id a fixture row may use. Other tests create their own rows, so fixtures keep clear. */
    public const ID_BASE = 2000000000;

    public static function id(int $n): int
    {
        return self::ID_BASE + $n;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{table:string,rows:list<array<string,mixed>>}
     */
    public static function table(string $name, array $rows): array
    {
        return ['table' => $name, 'rows' => $rows];
    }

    /**
     * A spatial column value from well-known text, such as "POINT(-3.2 55.9)". The MySQL columns
     * carry the SRID label 3857 and the PostgreSQL columns 4326 (the baseline relabels, it does not
     * reproject), and both hold longitude and latitude in degrees.
     */
    public static function geometry(string $wkt): array
    {
        if (preg_match('/^[A-Z ]+\([-0-9. ,()]+\)$/', $wkt) !== 1) {
            throw new \InvalidArgumentException("not simple well-known text: {$wkt}");
        }

        return ['__dialect' => [
            'mysql' => DB::raw("ST_GeomFromText('{$wkt}', 3857)"),
            'pgsql' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
        ]];
    }

    /**
     * @param  list<array{table:string,rows:list<array<string,mixed>>}>  $fixture  tables in insert order,
     *         parents before the tables that refer to them: the foreign keys are real on both engines
     * @param  callable(): mixed  $run  the call under test. It returns what the site returned, or the
     *         rows a write site left behind, read back
     * @param  bool|null  $ordered  null: ordered when the site's golden SQL has an ORDER BY
     * @param  mixed  $expect  when given (not null), the result must equal this on every engine
     */
    public static function assert(
        string $siteId,
        array $fixture,
        callable $run,
        ?bool $ordered = null,
        bool $allowEmpty = false,
        mixed $expect = null,
    ): void {
        $site = Manifest::site($siteId);
        $engines = self::engines();
        self::compare($siteId, (string) ($site['goldenSql'] ?? ''), $engines, $fixture, $run, $ordered, $allowEmpty, $expect, $expect !== null);
    }

    /**
     * The engines this run uses, as connection names.
     *
     * @return list<string>
     */
    public static function engines(): array
    {
        $only = (string) getenv('PARITY_ENGINES');
        if ($only === 'mysql' || $only === 'pgsql') {
            self::reachable($only, true);

            return [$only];
        }
        if ($only !== '') {
            Assert::fail("parity: PARITY_ENGINES={$only}: use pgsql, mysql or leave it unset");
        }

        $mode = (string) getenv('PG_ENGINE');
        self::reachable('mysql', true);
        // With PostgreSQL switched off (PG_ENGINE=0), or not there and not required, the site still runs on
        // MySQL and gets every check one engine can have. CI sets PG_ENGINE=1, so there both engines run or
        // the test fails.
        if ($mode === '0' || self::reachable('pgsql', $mode === '1') !== null) {
            return ['mysql'];
        }

        return ['mysql', 'pgsql'];
    }

    /**
     * @return string|null why the connection is not usable, or null when it is. With $required a
     *                     failure is a test failure
     */
    private static function reachable(string $name, bool $required): ?string
    {
        try {
            DB::connection($name)->select('select 1');

            return null;
        } catch (\Throwable $e) {
            if ($required) {
                Assert::fail("parity: the {$name} connection is not reachable: ".$e->getMessage());
            }

            return strtok($e->getMessage(), "\n") ?: 'unreachable';
        }
    }

    /**
     * The comparison, with the engines passed in so the harness's own tests can supply their own.
     *
     * @param  list<string>  $engines  connection names
     */
    public static function compare(
        string $siteId,
        string $goldenSql,
        array $engines,
        array $fixture,
        callable $run,
        ?bool $ordered,
        bool $allowEmpty,
        mixed $expect,
        bool $hasExpect,
    ): void {
        if ($engines === []) {
            Assert::fail("parity: site {$siteId}: no engine to run on");
        }
        $ordered ??= preg_match('/\border\s+by\b/i', $goldenSql) === 1;

        $results = [];
        foreach ($engines as $i => $engine) {
            $got = self::runOn($engine, $siteId, $fixture, $run);
            $n = ParityValues::normalise($got);

            if (! $allowEmpty && ParityValues::isEmpty($n)) {
                Assert::fail(sprintf(
                    'parity: site %s on %s: the result is empty (%s). Two engines agreeing on nothing prove nothing: '.
                    'give the fixture rows the query returns, or pass allowEmpty if empty is the point',
                    $siteId, $engine, ParityValues::show($n)
                ));
            }
            if ($hasExpect) {
                $d = ParityValues::firstDifference(ParityValues::normalise($expect), $n);
                if ($d !== '') {
                    Assert::fail("parity: site {$siteId} on {$engine}: the result is not what the fixture should give\n    {$d}");
                }
            }
            $results[$i] = $ordered ? $n : ParityValues::sortUnordered($n);
        }

        // With one engine there is nothing to compare: the checks above are all it gets.
        for ($i = 1; $i < count($engines); $i++) {
            $d = ParityValues::firstDifference($results[0], $results[$i]);
            if ($d !== '') {
                Assert::fail(sprintf(
                    "parity: site %s gives different rows on %s and %s (%s)\n    first difference: %s\n    %s: %s\n    %s: %s",
                    $siteId, $engines[0], $engines[$i], $ordered ? 'compared in order' : 'compared as a set', $d,
                    $engines[0], ParityValues::show($results[0]), $engines[$i], ParityValues::show($results[$i])
                ));
            }
        }
        Assert::assertTrue(true); // a run with one engine and no failure has still made its checks
    }

    /**
     * Loads the fixture into a transaction on the engine, runs the site with that engine as the
     * default connection, and rolls back.
     */
    private static function runOn(string $engine, string $siteId, array $fixture, callable $run): mixed
    {
        $conn = DB::connection($engine);
        $previous = DB::getDefaultConnection();

        $conn->beginTransaction();
        DB::setDefaultConnection($engine);
        try {
            self::load($conn, $engine, $fixture);

            return $run();
        } catch (\PHPUnit\Framework\Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            Assert::fail(sprintf(
                "parity: site %s on %s: %s: %s\n    A statement the engine refuses, a fixture it refuses, or code that threw.",
                $siteId, $engine, get_class($e), $e->getMessage()
            ));
        } finally {
            DB::setDefaultConnection($previous);
            try {
                $conn->rollBack();
            } catch (\Throwable) {
                // The transaction is already gone if the engine aborted it.
            }
        }
    }

    /**
     * One statement per row, through the query builder, so quoting and placeholders follow the
     * engine.
     *
     * @param  list<array{table:string,rows:list<array<string,mixed>>}>  $fixture
     */
    private static function load(Connection $conn, string $engine, array $fixture): void
    {
        if ($engine === 'mysql') {
            foreach ($fixture as $t) {
                self::$touchedTables[$t['table']] = true;
            }
        }
        foreach ($fixture as $t) {
            foreach ($t['rows'] as $i => $row) {
                if ($row === []) {
                    throw new \InvalidArgumentException("fixture table {$t['table']} row {$i} has no columns");
                }
                foreach ($row as $col => $value) {
                    if (is_array($value) && isset($value['__dialect'])) {
                        $row[$col] = $value['__dialect'][$engine];
                    }
                }
                try {
                    $conn->table($t['table'])->insert($row);
                } catch (\Throwable $e) {
                    throw new \RuntimeException("fixture table {$t['table']} row {$i}: ".$e->getMessage(), 0, $e);
                }
            }
        }
    }

    /**
     * Puts MySQL's AUTO_INCREMENT counters back after the tests that loaded a fixture. An explicit id above
     * the counter moves the counter past it and a rollback does not move it back, so the ids the rest of the
     * suite is handed climb out of the fixture's range and can reach the ids a later fixture uses.
     * ALTER TABLE ... AUTO_INCREMENT = 1 sets the counter to one above the highest id left in the table.
     *
     * Called by Tests\TestCase::tearDown after the test's own transaction has ended, on a connection of its
     * own: an ALTER TABLE on the test's connection would end that transaction, and from any other connection
     * it would wait for the test's locks. It does nothing unless a MySQL fixture was loaded.
     */
    public static function resetAutoIncrement(): void
    {
        if (self::$touchedTables === []) {
            return;
        }
        $tables = array_keys(self::$touchedTables);
        self::$touchedTables = [];
        try {
            $pdo = new \PDO(
                'mysql:host='.(getenv('DB_HOST') ?: 'percona').';port='.(getenv('DB_PORT') ?: '3306').';dbname='.(getenv('DB_DATABASE') ?: 'iznik_batch_test'),
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: 'iznik'
            );
            // MySQL's default metadata lock wait is a year: a table still held by a transaction elsewhere must
            // cost this a few seconds, not the run.
            $pdo->exec('SET SESSION lock_wait_timeout = 3');
            foreach ($tables as $table) {
                $pdo->exec('ALTER TABLE `'.str_replace('`', '', $table).'` AUTO_INCREMENT = 1');
            }
        } catch (\Throwable) {
            // The counters stay where they are. Nothing else depends on this.
        }
    }
}
