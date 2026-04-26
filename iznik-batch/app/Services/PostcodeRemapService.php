<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remaps postcodes to their nearest enclosing area using PostGIS KNN queries.
 *
 * When a location area's geometry is created or modified, postcodes within
 * that geometry need to be reassigned to the correct (smallest, nearest) area.
 * This mirrors the V1 PHP Location::remapPostcodes() logic.
 *
 * Uses PostgreSQL/PostGIS for efficient K-nearest-neighbor spatial queries,
 * and MySQL for reading/writing the canonical locations data.
 */
class PostcodeRemapService
{
    /**
     * SRID used for spatial operations (Web Mercator).
     */
    private int $srid;

    public function __construct()
    {
        $this->srid = (int) config('freegle.srid', 3857);
    }

    /**
     * Remap postcodes within a given WKT polygon to their nearest area.
     *
     * Syncs the affected location to PostgreSQL first, then queries PostGIS
     * KNN to find the best area for each postcode.
     *
     * @param int|null $locationId The location that was modified (for incremental sync).
     * @param string|null $polygon WKT polygon to scope the remap. NULL = remap all.
     * @return int Number of postcodes remapped.
     */
    public function remapPostcodes(?int $locationId = NULL, ?string $polygon = NULL): int
    {
        if (! $this->postgresAvailable()) {
            Log::warning('PostcodeRemapService: PostgreSQL connection not available, skipping remap');

            return 0;
        }

        // Ensure PostgreSQL schema exists and sync location data.
        $this->ensurePostgresSchema();

        if ($polygon) {
            // Sync all locations within the polygon scope to PostgreSQL.
            // This is critical: the location_id in the task is the location that was edited,
            // but other locations within the polygon (e.g. newly created areas) may also need
            // syncing. V1 synced the edited location inline in setGeometry() before calling
            // remapPostcodes(), and relied on a nightly cron for everything else. We do better
            // by syncing all locations in the affected area.
            $this->syncLocationsInPolygon($polygon);
        } elseif ($locationId) {
            $this->syncSingleLocation($locationId);
        } else {
            $this->syncAllLocations();
        }

        $geomFilter = '';
        $params = [];

        if ($polygon) {
            // Include postcodes that either fall within the polygon OR currently point at
            // the affected location via areaid. The latter catches postcodes whose areaid
            // was assigned by KNN (buffered intersection / nearest-neighbour) rather than
            // strict containment — those sit outside the polygon but still need remapping
            // when the location they reference is excluded or its geometry changes.
            if ($locationId) {
                $geomFilter = "(ST_Contains(ST_GeomFromText(?, {$this->srid}), locations_spatial.geometry) OR locations.areaid = ?) AND";
                $params[] = $polygon;
                $params[] = $locationId;
            } else {
                $geomFilter = "ST_Contains(ST_GeomFromText(?, {$this->srid}), locations_spatial.geometry) AND";
                $params[] = $polygon;
            }
        }

        // Fetch all full postcodes (contain a space) within the scope.
        $postcodes = DB::select("
            SELECT DISTINCT locations_spatial.locationid, locations.name,
                   locations.lat, locations.lng, locations.areaid
            FROM locations_spatial
            INNER JOIN locations ON locations_spatial.locationid = locations.id
            WHERE {$geomFilter} locations.type = 'Postcode'
            AND LOCATE(' ', locations.name) > 0
        ", $params);

        $count = 0;
        $updated = 0;

        foreach ($postcodes as $pc) {
            $newAreaId = $this->findNearestArea($pc->lng, $pc->lat);

            if ($newAreaId && $newAreaId != $pc->areaid) {
                DB::update('UPDATE locations SET areaid = ? WHERE id = ?', [
                    $newAreaId,
                    $pc->locationid,
                ]);
                $updated++;
            }

            $count++;

            if ($count % 1000 === 0) {
                Log::info("PostcodeRemapService: processed {$count}/" . count($postcodes) . ", updated {$updated}");
            }
        }

        Log::info("PostcodeRemapService: remapped {$updated}/{$count} postcodes");

        return $updated;
    }

    /**
     * Find the nearest area location for a given point using PostGIS KNN.
     *
     * Uses expanding buffer intersection levels (matching V1 algorithm) to find
     * the smallest area that contains or is very close to the point.
     *
     * @param float $lng Longitude
     * @param float $lat Latitude
     * @return int|null Location ID of the best matching area, or null.
     */
    public function findNearestArea(float $lng, float $lat): ?int
    {
        $result = DB::connection('pgsql')->select("
            WITH ourpoint AS (
                SELECT ST_MakePoint(?, ?) AS p
            )
            SELECT locationid FROM (
                SELECT
                    locationid,
                    ST_Area(location) AS area,
                    CASE
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.00015625), ?)) THEN 1
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.0003125), ?)) THEN 2
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.000625), ?)) THEN 3
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.00125), ?)) THEN 4
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.0025), ?)) THEN 5
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.005), ?)) THEN 6
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.01), ?)) THEN 7
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.02), ?)) THEN 8
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.04), ?)) THEN 9
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.08), ?)) THEN 10
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.16), ?)) THEN 11
                        WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.32), ?)) THEN 12
                    END AS intersects
                FROM (
                    SELECT locationid,
                           location,
                           location <-> ST_SetSRID((SELECT p FROM ourpoint), ?) AS dist
                    FROM locations
                    WHERE ST_Area(location) BETWEEN 0.00001 AND 0.15
                    ORDER BY location <-> ST_SetSRID((SELECT p FROM ourpoint), ?)
                    LIMIT 10
                ) q
            ) candidates
            WHERE intersects IS NOT NULL
            ORDER BY intersects ASC, area ASC
            LIMIT 1
        ", [
            $lng, $lat,
            // 12 SRID params for the buffer intersections
            $this->srid, $this->srid, $this->srid, $this->srid,
            $this->srid, $this->srid, $this->srid, $this->srid,
            $this->srid, $this->srid, $this->srid, $this->srid,
            // 2 SRID params for the KNN subquery
            $this->srid, $this->srid,
        ]);

        if (count($result) > 0) {
            return (int) $result[0]->locationid;
        }

        return NULL;
    }

    /**
     * Ensure the PostgreSQL locations table and indexes exist.
     */
    public function ensurePostgresSchema(): void
    {
        $pgsql = DB::connection('pgsql');

        $pgsql->statement('CREATE EXTENSION IF NOT EXISTS postgis');
        $pgsql->statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Create the location_type enum if it doesn't exist.
        $typeExists = $pgsql->selectOne("SELECT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'location_type')");
        if (! $typeExists->exists) {
            $pgsql->statement("CREATE TYPE location_type AS ENUM('Road','Polygon','Line','Point','Postcode')");
        }

        // Create the locations table if it doesn't exist.
        $tableExists = $pgsql->selectOne("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'locations')");
        if (! $tableExists->exists) {
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

    /**
     * Sync a single location from MySQL to PostgreSQL (upsert).
     *
     * Matches V1's per-location sync in setGeometry() — fast for single updates.
     */
    private function syncSingleLocation(int $locationId): void
    {
        $loc = DB::selectOne("
            SELECT locations.id, name, type,
                   ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom
            FROM locations
            LEFT JOIN locations_excluded le ON locations.id = le.locationid
            WHERE locations.id = ?
            AND le.locationid IS NULL
            AND ST_Dimension(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) = 2
            AND type != 'Postcode'
        ", [$locationId]);

        if (! $loc || ! $loc->geom) {
            return;
        }

        DB::connection('pgsql')->statement(
            "INSERT INTO locations (locationid, name, type, area, location)
             VALUES (?, ?, ?, ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))
             ON CONFLICT (locationid) DO UPDATE SET
                 name = EXCLUDED.name,
                 type = EXCLUDED.type,
                 area = EXCLUDED.area,
                 location = EXCLUDED.location",
            [$loc->id, $loc->name, $loc->type, $loc->geom, $this->srid, $loc->geom, $this->srid]
        );
    }

    /**
     * Sync all non-postcode polygon locations within a WKT polygon to PostgreSQL.
     *
     * When a location is created or its geometry is changed, the remap task carries
     * the polygon scope. We need to sync all locations intersecting that scope —
     * not just the edited location — because newly created or recently modified
     * locations in the area may not yet be in PostgreSQL.
     */
    private function syncLocationsInPolygon(string $polygon): void
    {
        // First remove any now-excluded locations within the polygon scope from PostgreSQL.
        // Without this, a location excluded after being synced would remain in PG's KNN
        // index until the nightly syncAllLocations table-swap rebuilds from scratch,
        // meaning exclusions wouldn't take effect for postcode remapping until then.
        $excludedInScope = DB::select("
            SELECT DISTINCT le.locationid
            FROM locations_excluded le
            INNER JOIN locations_spatial ls ON ls.locationid = le.locationid
            WHERE ST_Intersects(ls.geometry, ST_GeomFromText(?, {$this->srid}))
        ", [$polygon]);

        if (!empty($excludedInScope)) {
            $ids = array_map(fn ($r) => (int) $r->locationid, $excludedInScope);
            DB::connection('pgsql')->delete(
                'DELETE FROM locations WHERE locationid IN (' . implode(',', $ids) . ')'
            );
            Log::info('PostcodeRemapService: removed ' . count($ids) . ' excluded locations from PostgreSQL within polygon scope');
        }

        $locations = DB::select("
            SELECT locations.id, locations.name, locations.type,
                   ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE ls.geometry END) AS geom
            FROM locations_spatial ls
            INNER JOIN locations ON ls.locationid = locations.id
            LEFT JOIN locations_excluded le ON locations.id = le.locationid
            WHERE le.locationid IS NULL
            AND ST_Intersects(ls.geometry, ST_GeomFromText(?, {$this->srid}))
            AND ST_Dimension(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE ls.geometry END) = 2
            AND locations.type != 'Postcode'
        ", [$polygon]);

        $synced = 0;

        foreach ($locations as $loc) {
            if (! $loc->geom) {
                continue;
            }

            DB::connection('pgsql')->statement(
                "INSERT INTO locations (locationid, name, type, area, location)
                 VALUES (?, ?, ?, ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))
                 ON CONFLICT (locationid) DO UPDATE SET
                     name = EXCLUDED.name,
                     type = EXCLUDED.type,
                     area = EXCLUDED.area,
                     location = EXCLUDED.location",
                [$loc->id, $loc->name, $loc->type, $loc->geom, $this->srid, $loc->geom, $this->srid]
            );

            $synced++;
        }

        Log::info("PostcodeRemapService: synced {$synced} locations in polygon scope to PostgreSQL");
    }

    /**
     * Full sync of all non-postcode polygon locations from MySQL to PostgreSQL.
     *
     * Uses a temp table + atomic swap matching V1's copyLocationsToPostgresql().
     * Used when no specific location ID is provided (full remap).
     */
    private function syncAllLocations(): void
    {
        $pgsql = DB::connection('pgsql');
        $uniq = '_' . uniqid();

        $pgsql->statement("DROP TABLE IF EXISTS locations_tmp{$uniq}");
        $pgsql->statement("CREATE TABLE locations_tmp{$uniq} (
            id serial PRIMARY KEY,
            locationid bigint UNIQUE NOT NULL,
            name text,
            type location_type,
            area numeric,
            location geometry
        )");
        $pgsql->statement("ALTER TABLE locations_tmp{$uniq} SET UNLOGGED");

        // Fetch non-excluded polygon locations from MySQL.
        $locations = DB::select("
            SELECT locations.id, name, type,
                   ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom
            FROM locations
            LEFT JOIN locations_excluded le ON locations.id = le.locationid
            WHERE le.locationid IS NULL
            AND ST_Dimension(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) = 2
            AND type != 'Postcode'
        ");

        foreach ($locations as $loc) {
            if (! $loc->geom) {
                continue;
            }

            $pgsql->insert(
                "INSERT INTO locations_tmp{$uniq} (locationid, name, type, area, location)
                 VALUES (?, ?, ?, ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))",
                [$loc->id, $loc->name, $loc->type, $loc->geom, $this->srid, $loc->geom, $this->srid]
            );
        }

        // Build index on temp table before swap.
        $pgsql->statement("CREATE INDEX idx_loc_tmp{$uniq} ON locations_tmp{$uniq} USING gist (location)");

        // Atomic swap: rename current to old, temp to current, drop old.
        $pgsql->statement("ALTER TABLE IF EXISTS locations RENAME TO locations_old{$uniq}");
        $pgsql->statement("ALTER TABLE locations_tmp{$uniq} RENAME TO locations");
        $pgsql->statement("DROP INDEX IF EXISTS idx_locations_location");
        $pgsql->statement("ALTER INDEX idx_loc_tmp{$uniq} RENAME TO idx_locations_location");
        $pgsql->statement("DROP TABLE IF EXISTS locations_old{$uniq}");

        Log::info('PostcodeRemapService: synced ' . count($locations) . ' locations to PostgreSQL');
    }

    /**
     * Check if the PostgreSQL connection is available.
     */
    public function postgresAvailable(): bool
    {
        try {
            DB::connection('pgsql')->getPdo();

            return TRUE;
        } catch (\Throwable $e) {
            return FALSE;
        }
    }
}
