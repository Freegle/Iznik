<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The stored functions give the same answers on MySQL and on PostgreSQL.
 *
 * tests/fixtures/stored-functions/cases.json lists inputs and the answers MySQL gave, recorded by
 * tools/pg-schema/record-function-cases.mjs. This test runs the table against whichever engine the
 * connection is on, calling the functions the way the application does (haversine in ORDER BY and
 * WHERE, GetMaxDimension on ST_GeomFromText, damlevlim in the GET /domains filter). On MySQL it checks
 * the recorded answers against the real stored functions from the migration, which is what keeps the
 * table honest. tools/pg-schema/functions.test.mjs runs the same table on PostgreSQL against
 * database/postgres/functions.sql.
 */
class StoredFunctionsTest extends TestCase
{
    private array $cases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cases = json_decode(
            file_get_contents(base_path('tests/fixtures/stored-functions/cases.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * The geometry label the stored columns carry on this engine: MySQL columns are labelled 3857 and
     * PostgreSQL ones 4326, and both hold degrees.
     */
    private function srid(): int
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 4326 : 3857;
    }

    private function one(string $sql, array $bindings = []): mixed
    {
        return DB::selectOne("SELECT {$sql} AS r", $bindings)->r;
    }

    private function relative(float $a, float $b): float
    {
        $scale = max(abs($a), abs($b));

        return $scale === 0.0 ? 0.0 : abs($a - $b) / $scale;
    }

    public function test_the_table_was_recorded_on_mysql_8(): void
    {
        $this->assertStringStartsWith('8.0', $this->cases['mysqlVersion']);
        $this->assertGreaterThan(100, count($this->cases['haversine']));
        $this->assertGreaterThan(30, count($this->cases['maxdimension']));
        $this->assertGreaterThan(80, count($this->cases['damlevlim']));
    }

    public function test_haversine_gives_the_recorded_answer(): void
    {
        $bad = [];
        foreach ($this->cases['haversine'] as $case) {
            $got = $this->one('haversine(?, ?, ?, ?)', $case['args']);

            if ($case['mysql'] === null || $got === null) {
                if ($case['mysql'] !== $got) {
                    $bad[] = "{$case['name']}: recorded ".var_export($case['mysql'], true).', got '.var_export($got, true);
                }
                continue;
            }

            // MySQL sends a FLOAT as six significant digits, and the table holds the exact single
            // precision value, so MySQL can be out by up to 5e-6 of the answer. PostgreSQL sends a REAL
            // with enough digits to be exact.
            $tolerance = DB::connection()->getDriverName() === 'pgsql' ? 1e-6 : 1e-5;

            if ($this->relative((float) $got, (float) $case['mysql']) > $tolerance) {
                $bad[] = "{$case['name']}: recorded {$case['mysql']}, got {$got}";
            }
        }

        $this->assertSame([], $bad);
    }

    public function test_haversine_is_null_for_some_identical_points_and_the_table_holds_both(): void
    {
        $same = array_filter($this->cases['haversine'], fn ($c) => str_starts_with($c['name'], 'same point'));
        $this->assertGreaterThanOrEqual(10, count(array_filter($same, fn ($c) => $c['mysql'] === null)));
        $this->assertGreaterThanOrEqual(10, count(array_filter($same, fn ($c) => $c['mysql'] !== null)));
    }

    public function test_getmaxdimension_gives_the_recorded_answer(): void
    {
        $bad = [];
        foreach ($this->cases['maxdimension'] as $case) {
            if (isset($case['mysqlError'])) {
                continue;
            }

            $got = (float) $this->one('GetMaxDimension(ST_GeomFromText(?, ?))', [$case['wkt'], $this->srid()]);

            if ($this->relative($got, (float) $case['mysql']) > 1e-12) {
                $bad[] = "{$case['name']}: recorded {$case['mysql']}, got {$got}";
            }
        }

        $this->assertSame([], $bad);
    }

    public function test_getmaxdimension_t_is_the_same_function(): void
    {
        foreach ($this->cases['maxdimension'] as $case) {
            if (isset($case['mysqlError'])) {
                continue;
            }

            $this->assertEquals(
                $this->one('GetMaxDimension(ST_GeomFromText(?, ?))', [$case['wkt'], $this->srid()]),
                $this->one('GetMaxDimensionT(ST_GeomFromText(?, ?))', [$case['wkt'], $this->srid()]),
                $case['name']
            );
        }
    }

    public function test_getmaxdimension_of_null_is_zero(): void
    {
        $this->assertEquals(0, $this->one('GetMaxDimension(NULL)'));
    }

    public function test_getmaxdimension_refuses_a_geometry_collection_with_a_polygon_on_mysql(): void
    {
        $refused = array_values(array_filter($this->cases['maxdimension'], fn ($c) => isset($c['mysqlError'])));
        $this->assertNotEmpty($refused);

        foreach ($refused as $case) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $got = (float) $this->one('GetMaxDimension(ST_GeomFromText(?, ?))', [$case['wkt'], $this->srid()]);
                $this->assertLessThan(1e-12, $this->relative($got, (float) $case['divergence']['pg']), $case['name']);

                continue;
            }

            try {
                $this->one('GetMaxDimension(ST_GeomFromText(?, ?))', [$case['wkt'], $this->srid()]);
                $this->fail("{$case['name']}: MySQL accepted a geometry collection with a polygon");
            } catch (QueryException $e) {
                $this->assertInstanceOf(QueryException::class, $e, $case['name']);
            }
        }
    }

    public function test_damlevlim_gives_the_recorded_answer(): void
    {
        $bad = [];
        foreach ($this->cases['damlevlim'] as $case) {
            $got = $this->one('damlevlim(?, ?, ?)', [$case['s1'], $case['s2'], $case['n']]);

            if ((int) $got !== $case['mysql']) {
                $bad[] = "{$case['name']}: recorded {$case['mysql']}, got ".var_export($got, true);
            }
        }

        $this->assertSame([], $bad);
    }

    /**
     * GET /domains keeps a common domain when damlevlim(domain, typed, LENGTH(typed)) < 3, most
     * common first, five at most (iznik-server-go/domain/domain.go).
     */
    public function test_the_domain_suggestions_are_the_recorded_ones(): void
    {
        $bad = [];
        foreach ($this->cases['domainSuggestions'] as $case) {
            $hits = [];
            foreach ($this->cases['domains'] as $domain) {
                $value = $this->one('damlevlim(?, ?, ?)', [$domain['domain'], $case['input'], strlen($case['input'])]);
                if ((int) $value < 3) {
                    $hits[] = $domain;
                }
            }

            usort($hits, fn ($a, $b) => $b['count'] <=> $a['count']);
            $got = array_map(fn ($d) => $d['domain'], array_slice($hits, 0, 5));

            if ($got !== $case['suggestions']) {
                $bad[] = "{$case['input']}: recorded ".implode(' ', $case['suggestions']).', got '.implode(' ', $got);
            }
        }

        $this->assertSame([], $bad);
    }
}
