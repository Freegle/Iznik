<?php

namespace Tests\Feature\Ripple;

use App\Database\Expressions\StGeomFromText;
use App\Database\Expressions\Value;
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
 *
 * 3. advanceSplitForUndoLog: 1713 is really about the OLD values of the
 *    updated columns (both polygon and outer_bound are SPATIAL-indexed, so
 *    their old geometries are undo-logged in full and can jointly overflow
 *    the undo page). The split stores the polygon and the bounds in separate
 *    statements so no single undo record carries both.
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
        $out = $method->invoke($service, $dense, 0.0003);

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

    public function test_advance_split_for_undo_log_stores_polygon_then_bounds(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);

        $small = 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))';
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 9, 0, 30, NULL, NOW(), 'expanding', NOW(), NOW())",
            [$message->id, $small, $small]
        );

        $service = app(ExpandService::class);
        $method = new ReflectionMethod($service, 'advanceSplitForUndoLog');

        // The same update-values shape process() builds, minus the bounds columns.
        // advanceSplitForUndoLog() now takes a callable returning the column =>
        // value map rather than a SQL string plus a bindings array; this mirrors
        // the production construction at ExpandService::process().
        $advanceValues = fn (string $wkt): array => [
            'polygon' => new StGeomFromText(Value::of($wkt), 3857),
            'tick' => 9,
            'next_expansion_at' => null,
            'status' => 'done',
            'updated_at' => now(),
        ];
        $bigger = 'POLYGON((-0.3 51.3,0.1 51.3,0.1 51.7,-0.3 51.7,-0.3 51.3))';

        $method->invoke($service, $bigger, $advanceValues, $message->id);

        $row = DB::selectOne(
            'SELECT tick, status, ST_AsText(polygon) AS poly FROM rippling_reach WHERE msgid = ?',
            [$message->id]
        );
        $this->assertSame(9, (int) $row->tick, 'The split path must still advance the tick');
        $this->assertSame('done', $row->status, 'The split path must still apply the status');
        $this->assertStringContainsString('51.7', $row->poly, 'The split path must store the new polygon');
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
     * Douglas-Peucker at any tolerance collapses it dramatically. Sized in
     * lon/lat degrees (0.1 across, roughly a UK-town reach polygon) because
     * production geometry is degree-coordinates despite its 3857 SRID tag -
     * a metre-scale test square here is exactly what let the metre-scale
     * tolerance ladder ship: ST_Simplify returned NULL on every real polygon
     * while the tests kept passing.
     */
    private function denseSquareWkt(): string
    {
        $pts = [];
        $n = 300;
        $side = 0.1;
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('%.8f 0', $side * $i / $n);
        }
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('%.8f %.8f', $side, $side * $i / $n);
        }
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('%.8f %.8f', $side - ($side * $i / $n), $side);
        }
        for ($i = 0; $i < $n; $i++) {
            $pts[] = sprintf('0 %.8f', $side - ($side * $i / $n));
        }
        $pts[] = '0 0';

        return 'POLYGON((' . implode(',', $pts) . '))';
    }
}
