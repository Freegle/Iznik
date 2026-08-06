<?php

namespace Tests\Feature\Ripple;

use App\Services\Ripple\ExpandService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pins the geometry-safety fixes in ExpandService (2026-08-06):
 *
 * 1. unionWithOriginGroupArea's coverage-fraction query must survive
 *    ST_Intersection returning a GEOMETRYCOLLECTION (polygons touching along
 *    an edge or at a point). ST_Area on a collection throws MySQL error 3516;
 *    the CASE guard on ST_GeometryType evaluates lazily so ST_Area only ever
 *    sees polygonal input, and a NULL frac falls back to "keep the original
 *    WKT" - the same outcome the exception produced, minus the exception
 *    (which was then misretried as a deadlock; see TransactionPolicyTest).
 *
 * 2. storeWithUndoLogShrink must react to MySQL error 1713 ("Undo log record
 *    is too big" - reach polygons grown past what a row update can carry) by
 *    progressively simplifying the polygon and retrying, instead of leaving
 *    the post permanently stuck re-failing the same oversized statement.
 */
class ExpandGeometrySafetyTest extends TestCase
{
    /**
     * Two unit squares sharing only an edge: their intersection is a
     * LINESTRING, and ST_Area on the old unguarded query threw error 3516.
     */
    public function test_frac_query_survives_geometrycollection_intersection(): void
    {
        $iso = 'POLYGON((0 0,1 0,1 1,0 1,0 0))';
        $grp = 'POLYGON((1 0,2 0,2 1,1 1,1 0))';

        $row = DB::selectOne(
            'SELECT CASE WHEN ST_GeometryType(inter) IN (\'POLYGON\', \'MULTIPOLYGON\')
                         THEN ST_Area(inter) / NULLIF(ST_Area(grp), 0)
                    END AS frac,
                    ST_AsText(ST_Union(iso, grp)) AS u
             FROM (SELECT ST_Intersection(iso, grp) AS inter, iso, grp
                   FROM (SELECT ST_GeomFromText(?, 3857) AS iso,
                                ST_GeomFromText(?, 3857) AS grp) s) t',
            [$iso, $grp]
        );

        $this->assertNotNull($row);
        $this->assertNull($row->frac, 'Non-polygonal intersection must yield NULL frac, not error 3516');
        $this->assertNotEmpty($row->u, 'The union must still be computed');
    }

    public function test_frac_query_returns_fraction_for_polygonal_intersection(): void
    {
        // The isochrone covers the left half of the group square.
        $iso = 'POLYGON((0 0,1 0,1 2,0 2,0 0))';
        $grp = 'POLYGON((0 0,2 0,2 2,0 2,0 0))';

        $row = DB::selectOne(
            'SELECT CASE WHEN ST_GeometryType(inter) IN (\'POLYGON\', \'MULTIPOLYGON\')
                         THEN ST_Area(inter) / NULLIF(ST_Area(grp), 0)
                    END AS frac
             FROM (SELECT ST_Intersection(iso, grp) AS inter, grp
                   FROM (SELECT ST_GeomFromText(?, 3857) AS iso,
                                ST_GeomFromText(?, 3857) AS grp) s) t',
            [$iso, $grp]
        );

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.5, (float) $row->frac, 0.001);
    }

    public function test_simplify_polygon_wkt_shrinks_dense_polygon(): void
    {
        $service = app(ExpandService::class);
        $method = new ReflectionMethod($service, 'simplifyPolygonWkt');

        $dense = $this->denseSquareWkt();
        $out = $method->invoke($service, $dense, 40.0);

        $this->assertNotNull($out);
        $this->assertTrue(
            str_starts_with($out, 'POLYGON') || str_starts_with($out, 'MULTIPOLYGON'),
            "Simplified output must stay polygonal, got: " . substr($out, 0, 40)
        );
        $this->assertLessThan(strlen($dense), strlen($out));
    }

    public function test_is_undo_log_too_big_matches_error_info_not_message_text(): void
    {
        $service = app(ExpandService::class);
        $method = new ReflectionMethod($service, 'isUndoLogTooBig');

        $undo = new PDOException('SQLSTATE[HY000]: General error: 1713 Undo log record is too big.');
        $undo->errorInfo = ['HY000', 1713, 'Undo log record is too big.'];
        $this->assertTrue($method->invoke($service, new QueryException('mysql', 'UPDATE rippling_reach SET polygon = ?', ['POLYGON((...))'], $undo)));

        $deadlock = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
        $this->assertFalse($method->invoke($service, new QueryException('mysql', 'UPDATE rippling_reach SET polygon = ?', ['POLYGON((...))'], $deadlock)));
    }

    public function test_store_with_undo_log_shrink_retries_with_simplified_polygon(): void
    {
        $service = app(ExpandService::class);
        $method = new ReflectionMethod($service, 'storeWithUndoLogShrink');

        $dense = $this->denseSquareWkt();
        $attempts = [];

        // A store that rejects anything as large as the original polygon with
        // a fake 1713, mimicking the undo-log limit.
        $store = function (string $wkt) use ($dense, &$attempts): void {
            $attempts[] = strlen($wkt);
            if (strlen($wkt) >= strlen($dense)) {
                $pdo = new PDOException('SQLSTATE[HY000]: General error: 1713 Undo log record is too big.');
                $pdo->errorInfo = ['HY000', 1713, 'Undo log record is too big.'];
                throw new QueryException('mysql', 'UPDATE rippling_reach SET polygon = ?', [$wkt], $pdo);
            }
        };

        $stored = $method->invoke($service, $store, $dense, 123);

        $this->assertGreaterThanOrEqual(2, count($attempts), 'Original attempt plus at least one shrink retry');
        $this->assertLessThan(strlen($dense), strlen($stored), 'The stored WKT must be the simplified one');
    }

    public function test_store_with_undo_log_shrink_rethrows_other_errors_unchanged(): void
    {
        $service = app(ExpandService::class);
        $method = new ReflectionMethod($service, 'storeWithUndoLogShrink');

        $pdo = new PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $pdo->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];
        $store = function (string $wkt) use ($pdo): void {
            throw new QueryException('mysql', 'UPDATE rippling_reach SET polygon = ?', [$wkt], $pdo);
        };

        $this->expectException(QueryException::class);
        $method->invoke($service, $store, 'POLYGON((0 0,1 0,1 1,0 1,0 0))', 123);
    }

    /**
     * A square whose edges carry hundreds of redundant collinear vertices, so
     * Douglas-Peucker at any tolerance collapses it dramatically.
     */
    private function denseSquareWkt(): string
    {
        $pts = [];
        $n = 300;
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('%.6f 0', 10000 * $i / $n);
        }
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('10000 %.6f', 10000 * $i / $n);
        }
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('%.6f 10000', 10000 - (10000 * $i / $n));
        }
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('0 %.6f', 10000 - (10000 * $i / $n));
        }
        $pts[] = '0 0';

        return 'POLYGON((' . implode(',', $pts) . '))';
    }
}
