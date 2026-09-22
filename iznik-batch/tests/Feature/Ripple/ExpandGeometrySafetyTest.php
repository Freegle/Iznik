<?php

namespace Tests\Feature\Ripple;

use Illuminate\Support\Facades\DB;
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
 *    their old geometries were undo-logged in full and could jointly overflow
 *    the undo page - retired with the polygons; a ~23KB grid cannot).
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
}
