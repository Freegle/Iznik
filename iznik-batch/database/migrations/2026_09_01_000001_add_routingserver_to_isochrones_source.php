<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let isochrones.source record the value the code actually writes.
 *
 * iznik-server-go's getIsochrone sets source = 'RoutingServer' when our own
 * routing server answers, falling back to 'Mapbox' only when it does not. But
 * the column is enum('Mapbox','OSM','Valhalla','GraphHopper','ORS'), so
 * 'RoutingServer' is not a member: under strict mode that INSERT errors and no
 * isochrone is stored, and under a lax mode it stores an empty string.
 *
 * It has not bitten yet because the routing-server path has never succeeded in
 * production: all 193,831 rows read 'Mapbox'. It would bite the moment
 * ROUTING_EVAL_URL is set for apiv2, and the symptom would be an isochrone that
 * is recomputed on every single request rather than an obvious failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE isochrones MODIFY COLUMN source " .
            "enum('Mapbox','OSM','Valhalla','GraphHopper','ORS','RoutingServer') " .
            "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci " .
            "DEFAULT 'Mapbox' COMMENT 'Isochrone data source'"
        );
    }

    public function down(): void
    {
        // Rows written by the routing server would have no representation in the
        // narrower enum, so send them back to the fallback they would otherwise
        // have come from rather than losing the row.
        DB::statement("UPDATE isochrones SET source = 'Mapbox' WHERE source = 'RoutingServer'");
        DB::statement(
            "ALTER TABLE isochrones MODIFY COLUMN source " .
            "enum('Mapbox','OSM','Valhalla','GraphHopper','ORS') " .
            "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci " .
            "DEFAULT 'Mapbox' COMMENT 'Isochrone data source'"
        );
    }
};
