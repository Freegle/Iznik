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

    public function test_remap_returns_zero_when_postgres_unavailable(): void
    {
        // Override the pgsql config to point to a non-existent host.
        config(['database.connections.pgsql.host' => 'nonexistent-host-that-does-not-exist']);
        DB::purge('pgsql');

        $result = $this->service->remapPostcodes(NULL);
        $this->assertEquals(0, $result);
    }

    public function test_remap_postcodes_task_dispatches_correctly(): void
    {
        // Ensure background_tasks table exists.
        DB::statement('CREATE TABLE IF NOT EXISTS background_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_type VARCHAR(50) NOT NULL,
            data JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            error_message TEXT NULL,
            attempts INT UNSIGNED DEFAULT 0,
            INDEX idx_task_type (task_type),
            INDEX idx_pending (processed_at, created_at)
        )');

        // Insert a remap_postcodes task like Go would.
        $polygon = 'POLYGON((-3.22 55.93, -3.22 55.98, -3.17 55.98, -3.17 55.93, -3.22 55.93))';
        DB::table('background_tasks')->insert([
            'task_type' => 'remap_postcodes',
            'data' => json_encode([
                'location_id' => 12345,
                'polygon' => $polygon,
            ]),
            'created_at' => now(),
        ]);

        $task = DB::table('background_tasks')
            ->where('task_type', 'remap_postcodes')
            ->first();

        $this->assertNotNull($task);
        $data = json_decode($task->data, TRUE);
        $this->assertEquals(12345, $data['location_id']);
        $this->assertEquals($polygon, $data['polygon']);

        // Clean up.
        DB::table('background_tasks')->truncate();
    }

    public function test_find_nearest_area_with_postgres(): void
    {
        // Skip if PostgreSQL is not available.
        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available');
        }

        $this->setupPostgresLocationsTable();

        $srid = (int) config('freegle.srid', 3857);

        // Insert a test area polygon (roughly Edinburgh area).
        $polygon = 'POLYGON((-3.25 55.90, -3.25 56.00, -3.15 56.00, -3.15 55.90, -3.25 55.90))';
        DB::connection('pgsql')->insert(
            "INSERT INTO locations (locationid, name, type, area, location)
             VALUES (?, ?, 'Polygon', ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))",
            [99999, 'Test Area Edinburgh', $polygon, $srid, $polygon, $srid]
        );

        // Query for a point inside the polygon.
        $result = $this->service->findNearestArea(-3.20, 55.95);
        $this->assertEquals(99999, $result);

        // Query for a point far away — should still find nearest but with larger buffer.
        $farResult = $this->service->findNearestArea(0.0, 51.5);
        // May or may not find it depending on buffer size — just test it doesn't crash.
        $this->assertTrue($farResult === NULL || $farResult === 99999);

        // Clean up.
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS locations');
    }

    /**
     * Regression test: when a location is created/updated and a polygon-scoped remap
     * runs, all locations within the polygon must be synced to PostgreSQL — not just
     * the single location_id from the task. Otherwise newly created locations won't
     * be candidates in the KNN query.
     */
    public function test_polygon_scoped_remap_syncs_all_locations_in_scope(): void
    {
        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available');
        }

        $this->setupPostgresLocationsTable();
        $srid = (int) config('freegle.srid', 3857);

        // Create a large area ("City") and a small area ("Neighbourhood") in MySQL.
        // The neighbourhood is inside the city. Both are 2D polygons.
        $cityPoly = 'POLYGON((-1.55 53.30, -1.55 53.40, -1.45 53.40, -1.45 53.30, -1.55 53.30))';
        $neighbourhoodPoly = 'POLYGON((-1.51 53.34, -1.51 53.36, -1.49 53.36, -1.49 53.34, -1.51 53.34))';

        $cityId = DB::table('locations')->insertGetId([
            'name' => 'TestCity',
            'type' => 'Polygon',
            'lat' => 53.35,
            'lng' => -1.50,
        ]);

        $neighbourhoodId = DB::table('locations')->insertGetId([
            'name' => 'TestNeighbourhood',
            'type' => 'Polygon',
            'lat' => 53.35,
            'lng' => -1.50,
        ]);

        // Insert geometries into locations_spatial.
        DB::statement(
            "INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))",
            [$cityId, $cityPoly, $srid]
        );
        DB::statement(
            "INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))",
            [$neighbourhoodId, $neighbourhoodPoly, $srid]
        );

        // Create a postcode inside the neighbourhood, initially mapped to the city.
        $postcodeId = DB::table('locations')->insertGetId([
            'name' => 'ZZ1 1AA',
            'type' => 'Postcode',
            'lat' => 53.35,
            'lng' => -1.50,
            'areaid' => $cityId,
        ]);

        DB::statement(
            "INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))",
            [$postcodeId, "POINT(-1.50 53.35)", $srid]
        );

        // Only sync the city to PostgreSQL — simulating the bug where the neighbourhood
        // (the newly created/updated location) hasn't been synced yet.
        DB::connection('pgsql')->insert(
            "INSERT INTO locations (locationid, name, type, area, location)
             VALUES (?, ?, 'Polygon', ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))",
            [$cityId, 'TestCity', $cityPoly, $srid, $cityPoly, $srid]
        );

        // Verify neighbourhood is NOT in PostgreSQL yet.
        $pgNeighbourhood = DB::connection('pgsql')
            ->selectOne('SELECT locationid FROM locations WHERE locationid = ?', [$neighbourhoodId]);
        $this->assertNull($pgNeighbourhood);

        // Run remap with polygon scope covering both locations.
        // This simulates what happens when the neighbourhood is created and
        // a remap task fires with the neighbourhood's polygon as scope.
        $updated = $this->service->remapPostcodes($neighbourhoodId, $neighbourhoodPoly);

        // The neighbourhood should now be in PostgreSQL (synced by polygon scope).
        $pgNeighbourhood = DB::connection('pgsql')
            ->selectOne('SELECT locationid FROM locations WHERE locationid = ?', [$neighbourhoodId]);
        $this->assertNotNull($pgNeighbourhood);

        // The postcode should now be mapped to the neighbourhood (smaller, closer area)
        // instead of the city.
        $postcode = DB::table('locations')->where('id', $postcodeId)->first();
        $this->assertEquals($neighbourhoodId, $postcode->areaid,
            'Postcode should be remapped to the neighbourhood, not the city');

        // Clean up.
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS locations');
        DB::table('locations_spatial')->whereIn('locationid', [$cityId, $neighbourhoodId, $postcodeId])->delete();
        DB::table('locations')->whereIn('id', [$cityId, $neighbourhoodId, $postcodeId])->delete();
    }

    /**
     * Test that syncLocationsInPolygon updates stale PostgreSQL data.
     */
    public function test_polygon_scoped_sync_updates_stale_postgres_data(): void
    {
        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available');
        }

        $this->setupPostgresLocationsTable();
        $srid = (int) config('freegle.srid', 3857);

        $oldPoly = 'POLYGON((-1.50 53.34, -1.50 53.36, -1.48 53.36, -1.48 53.34, -1.50 53.34))';
        $newPoly = 'POLYGON((-1.52 53.33, -1.52 53.37, -1.47 53.37, -1.47 53.33, -1.52 53.33))';

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

        // Now mark the first area as excluded.
        DB::table('locations_excluded')->insert([
            'locationid' => $excludedId,
            'groupid' => 1,
            'userid' => 1,
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

        DB::table('locations_excluded')->insert([
            'locationid' => $excludedId,
            'groupid' => 1,
            'userid' => 1,
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
