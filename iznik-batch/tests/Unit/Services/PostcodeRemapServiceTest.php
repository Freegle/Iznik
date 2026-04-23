<?php

namespace Tests\Unit\Services;

use App\Services\PostcodeRemapService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostcodeRemapServiceTest extends TestCase
{
    private PostcodeRemapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PostcodeRemapService::class);
    }

    /**
     * Test postgresAvailable returns true when PostgreSQL is reachable.
     */
    public function test_postgres_available_returns_true_when_connected(): void
    {
        // PostgreSQL should be available in the test environment.
        $this->assertTrue($this->service->postgresAvailable());
    }

    /**
     * Test ensurePostgresSchema creates required extensions.
     */
    public function test_ensure_postgres_schema_creates_postgis_extension(): void
    {
        // Call should succeed without exception.
        $this->service->ensurePostgresSchema();

        // Verify postgis is installed by checking a function exists.
        $result = DB::connection('pgsql')->selectOne(
            "SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'st_makepoint')"
        );

        $this->assertTrue((bool) $result->exists);
    }

    /**
     * Test remapPostcodes returns 0 when PostgreSQL is unavailable.
     */
    public function test_remap_postcodes_returns_zero_when_postgres_unavailable(): void
    {
        // Mock postgresAvailable to return false.
        $mock = $this->createPartialMock(PostcodeRemapService::class, ['postgresAvailable']);
        $mock->method('postgresAvailable')->willReturn(false);

        $result = $mock->remapPostcodes();

        $this->assertEquals(0, $result);
    }

    /**
     * Test findNearestArea returns null when no area found.
     */
    public function test_find_nearest_area_returns_null_when_no_match(): void
    {
        // Ensure schema exists.
        $this->service->ensurePostgresSchema();

        // Call with a point in the middle of the ocean (no areas nearby).
        $result = $this->service->findNearestArea(0.0, 0.0);

        $this->assertNull($result);
    }

    /**
     * Test remapPostcodes processes no postcodes when table is empty.
     */
    public function test_remap_postcodes_processes_zero_when_no_postcodes_exist(): void
    {
        // Ensure schema exists.
        $this->service->ensurePostgresSchema();

        // Call with no postcodes in database.
        $result = $this->service->remapPostcodes();

        // Should return 0 since no postcodes were found.
        $this->assertEquals(0, $result);
    }

    /**
     * Test remapPostcodes with location scope returns 0 when no postcodes in scope.
     */
    public function test_remap_postcodes_with_location_scope_returns_zero_when_no_postcodes_match(): void
    {
        // Ensure schema exists.
        $this->service->ensurePostgresSchema();

        // Create a test polygon WKT that definitely has no postcodes.
        $polygon = 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))';

        // Call with a polygon scope.
        $result = $this->service->remapPostcodes(null, $polygon);

        // Create location in MySQL with the NEW polygon.
        $locId = DB::table('locations')->insertGetId([
            'name' => 'TestArea',
            'type' => 'Polygon',
            'lat' => 53.35,
            'lng' => -1.50,
        ]);

        DB::statement(
            "INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))",
            [$locId, $newPoly, $srid]
        );

        // Put the OLD polygon in PostgreSQL (simulating stale nightly sync).
        DB::connection('pgsql')->insert(
            "INSERT INTO locations (locationid, name, type, area, location)
             VALUES (?, ?, 'Polygon', ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))",
            [$locId, 'TestArea', $oldPoly, $srid, $oldPoly, $srid]
        );

        // Create a postcode that is inside the new polygon but outside the old one.
        $postcodeId = DB::table('locations')->insertGetId([
            'name' => 'ZZ2 2BB',
            'type' => 'Postcode',
            'lat' => 53.335,
            'lng' => -1.51,
            'areaid' => NULL,
        ]);

        DB::statement(
            "INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))",
            [$postcodeId, "POINT(-1.51 53.335)", $srid]
        );

        // Remap with the new polygon as scope — this should sync the updated geometry.
        $updated = $this->service->remapPostcodes($locId, $newPoly);

        // The postcode should be mapped to the area now.
        $postcode = DB::table('locations')->where('id', $postcodeId)->first();
        $this->assertEquals($locId, $postcode->areaid,
            'Postcode should be mapped to area after polygon sync updated stale data');

        // Clean up.
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS locations');
        DB::table('locations_spatial')->whereIn('locationid', [$locId, $postcodeId])->delete();
        DB::table('locations')->whereIn('id', [$locId, $postcodeId])->delete();
    }

    /**
     * Regression: when a location is excluded after being synced to PostgreSQL,
     * the next polygon-scoped remap that covers it must DELETE it from the PG
     * KNN index — otherwise findNearestArea keeps returning the excluded area
     * and postcodes never move off it until the nightly full sync rebuilds PG.
     */
    public function test_excluded_location_is_removed_from_postgres_during_polygon_remap(): void
    {
        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available');
        }

        $this->setupPostgresLocationsTable();
        $srid = (int) config('freegle.srid', 3857);

        $excludedPoly = 'POLYGON((-1.50 53.34, -1.50 53.36, -1.48 53.36, -1.48 53.34, -1.50 53.34))';
        $replacementPoly = 'POLYGON((-1.51 53.33, -1.51 53.37, -1.47 53.37, -1.47 53.33, -1.51 53.33))';

        $excludedId = DB::table('locations')->insertGetId([
            'name' => 'ExcludedArea',
            'type' => 'Polygon',
            'lat' => 53.35,
            'lng' => -1.49,
        ]);

        $replacementId = DB::table('locations')->insertGetId([
            'name' => 'ReplacementArea',
            'type' => 'Polygon',
            'lat' => 53.35,
            'lng' => -1.49,
        ]);

        DB::statement(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$excludedId, $excludedPoly, $srid]
        );
        DB::statement(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$replacementId, $replacementPoly, $srid]
        );

        // Postcode sits inside both polygons, currently pointing at the excluded area.
        $postcodeId = DB::table('locations')->insertGetId([
            'name' => 'ZZ3 3CC',
            'type' => 'Postcode',
            'lat' => 53.35,
            'lng' => -1.49,
            'areaid' => $excludedId,
        ]);
        DB::statement(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$postcodeId, 'POINT(-1.49 53.35)', $srid]
        );

        // Simulate prior sync: both areas are in PG already.
        foreach ([[$excludedId, 'ExcludedArea', $excludedPoly], [$replacementId, 'ReplacementArea', $replacementPoly]] as [$id, $name, $poly]) {
            DB::connection('pgsql')->insert(
                "INSERT INTO locations (locationid, name, type, area, location)
                 VALUES (?, ?, 'Polygon', ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))",
                [$id, $name, $poly, $srid, $poly, $srid]
            );
        }

        // Create a real group and user to satisfy FK constraints on locations_excluded.
        $group = $this->createTestGroup();
        $user = $this->createTestUser();

        // Now mark the first area as excluded.
        DB::table('locations_excluded')->insert([
            'locationid' => $excludedId,
            'groupid' => null,
            'userid' => null,
        ]);

        try {
            $this->service->remapPostcodes($excludedId, $excludedPoly);

            $stillThere = DB::connection('pgsql')
                ->selectOne('SELECT locationid FROM locations WHERE locationid = ?', [$excludedId]);
            $this->assertNull($stillThere, 'Excluded location must be deleted from PG during polygon-scoped remap');

            $postcode = DB::table('locations')->where('id', $postcodeId)->first();
            $this->assertNotEquals($excludedId, $postcode->areaid, 'Postcode must move off the excluded area');
        } finally {
            DB::connection('pgsql')->statement('DROP TABLE IF EXISTS locations');
            DB::table('locations_excluded')->where('locationid', $excludedId)->delete();
            DB::table('locations_spatial')->whereIn('locationid', [$excludedId, $replacementId, $postcodeId])->delete();
            DB::table('locations')->whereIn('id', [$excludedId, $replacementId, $postcodeId])->delete();
        }
    }

    /**
     * Regression: a postcode can carry an areaid assigned via KNN (buffered intersection)
     * that places it *outside* the area's polygon. When that area is excluded, the
     * polygon-scoped remap must still reach the postcode via its areaid — otherwise
     * it stays pointing at the excluded area forever.
     */
    public function test_postcode_pointing_at_excluded_area_is_remapped_even_if_outside_polygon(): void
    {
        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available');
        }

        $this->setupPostgresLocationsTable();
        $srid = (int) config('freegle.srid', 3857);

        $excludedPoly = 'POLYGON((-1.50 53.34, -1.50 53.36, -1.48 53.36, -1.48 53.34, -1.50 53.34))';
        $replacementPoly = 'POLYGON((-1.46 53.30, -1.46 53.40, -1.42 53.40, -1.42 53.30, -1.46 53.30))';

        $excludedId = DB::table('locations')->insertGetId([
            'name' => 'ExcludedArea',
            'type' => 'Polygon',
            'lat' => 53.35,
            'lng' => -1.49,
        ]);

        $replacementId = DB::table('locations')->insertGetId([
            'name' => 'ReplacementArea',
            'type' => 'Polygon',
            'lat' => 53.35,
            'lng' => -1.44,
        ]);

        DB::statement(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$excludedId, $excludedPoly, $srid]
        );
        DB::statement(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$replacementId, $replacementPoly, $srid]
        );

        // Postcode sits *outside* the excluded area's polygon (closer to the replacement),
        // but has areaid pointing at the excluded area — as KNN can do.
        $postcodeId = DB::table('locations')->insertGetId([
            'name' => 'ZZ4 4DD',
            'type' => 'Postcode',
            'lat' => 53.35,
            'lng' => -1.44,
            'areaid' => $excludedId,
        ]);
        DB::statement(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$postcodeId, 'POINT(-1.44 53.35)', $srid]
        );

        // Create a real group and user to satisfy FK constraints on locations_excluded.
        $group = $this->createTestGroup();
        $user = $this->createTestUser();


        DB::table('locations_excluded')->insert([
            'locationid' => $excludedId,
            'groupid' => null,
            'userid' => null,
        ]);

        // Seed PG with only the replacement area (excluded area is filtered out by sync).
        DB::connection('pgsql')->insert(
            "INSERT INTO locations (locationid, name, type, area, location)
             VALUES (?, ?, 'Polygon', ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))",
            [$replacementId, 'ReplacementArea', $replacementPoly, $srid, $replacementPoly, $srid]
        );

        try {
            $this->service->remapPostcodes($excludedId, $excludedPoly);

            $postcode = DB::table('locations')->where('id', $postcodeId)->first();
            $this->assertNotEquals($excludedId, $postcode->areaid,
                'Postcode outside the excluded polygon but pointing at it via areaid must still be remapped');
        } finally {
            DB::connection('pgsql')->statement('DROP TABLE IF EXISTS locations');
            DB::table('locations_excluded')->where('locationid', $excludedId)->delete();
            DB::table('locations_spatial')->whereIn('locationid', [$excludedId, $replacementId, $postcodeId])->delete();
            DB::table('locations')->whereIn('id', [$excludedId, $replacementId, $postcodeId])->delete();
        }
    }

    private function setupPostgresLocationsTable(): void
    {
        $pgsql = DB::connection('pgsql');
        $pgsql->statement('CREATE EXTENSION IF NOT EXISTS postgis');
        $pgsql->statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        $typeExists = $pgsql->selectOne("SELECT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'location_type')");
        if (! $typeExists->exists) {
            $pgsql->statement("CREATE TYPE location_type AS ENUM('Road','Polygon','Line','Point','Postcode')");
        }

        $pgsql->statement('DROP TABLE IF EXISTS locations');
        $pgsql->statement('CREATE TABLE locations (
            id serial PRIMARY KEY,
            locationid bigint UNIQUE NOT NULL,
            name text,
            type location_type,
            area numeric,
            location geometry
        )');
        $pgsql->statement('CREATE INDEX idx_locations_location ON locations USING gist (location)');
    }
}
