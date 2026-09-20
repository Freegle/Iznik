<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A gazetteer of UK places, for saying what a Community News area actually
 * covers.
 *
 * This is deliberately NOT the `towns` table and does not replace it. `towns`
 * is a 234-row curated list that does two jobs it is fine at: it anchors and
 * names Community News areas, and it feeds the "Near: ..." hint under the
 * distance slider (where ascending id means descending population, a contract
 * `town.SelectNear` relies on). Loading thousands of rows into it would re-run
 * the nearest-town bucketing in CommunityNewsAreaService and split today's 239
 * areas into roughly 480 - and every newly created area has no `lastposted`,
 * so the hourly ChitChat job would post to all of them at once.
 *
 * What `towns` cannot do is say which places an area COVERS. Measured 2026-09-05
 * over the 496 live Freegle groups with a polygon, 298 of them (60%) contain no
 * curated town at all, so the research prompt was writing up one anchor town -
 * sometimes one that is not even inside the group (Oswestry Freegle's area is
 * named "Wrecsam", 12.7 miles away and in another country; Ribble Valley's is
 * "Blackburn", outside its polygon; every Fife member got Glenrothes).
 *
 * With this table the same query returns a median of 6 places inside a group's
 * polygon (p90 14), so one digest can cover Dunfermline, Kirkcaldy, St Andrews
 * and the rest of Fife rather than just its anchor.
 *
 * Rows come from GeoNames (CC-BY 4.0), populated places of 1,000+ people across
 * GB and the Crown Dependencies, loaded by `community-news:load-places` from
 * database/data/uk-places.csv. Population is stored rather than implied by id,
 * so nothing here inherits the `towns` id-order contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('places')) {
            return;
        }

        $srid = (int) config('freegle.srid', 3857);

        DB::statement("
            CREATE TABLE places (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                lat DECIMAL(10,5) NOT NULL,
                lng DECIMAL(10,5) NOT NULL,
                population INT UNSIGNED NOT NULL DEFAULT 0,
                position POINT NOT NULL SRID {$srid},
                PRIMARY KEY (id),
                UNIQUE KEY place_name_pos (name, lat, lng),
                KEY place_population (population),
                SPATIAL KEY place_position (position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
