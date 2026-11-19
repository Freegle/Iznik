<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds counts of distinct people to the two UX rollup tables the weekly report reads. A person is a
 * member id when the event has one, else the client address. The event counts beside them are moved
 * by anything that sends events (one stuck page, one person clicking in anger, a scraper); a person
 * is counted once a day however many events they send.
 *
 * NULL means the day was rolled up before these columns existed, which is not the same as nobody.
 * INT UNSIGNED NULL added with ALGORITHM=INSTANT: a metadata-only change, no table rebuild.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'ux_flow_daily' => ['people_entered', 'people_completed'],
        'ux_component_daily' => ['people', 'people_trouble'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
                    DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} INT UNSIGNED NULL, ALGORITHM=INSTANT");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
                }
            }
        }
    }
};
