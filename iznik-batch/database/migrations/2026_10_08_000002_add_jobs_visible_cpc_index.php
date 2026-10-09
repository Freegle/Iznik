<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * jobs (visible, cpc): the spatial server's five-minute jobs delta check counts
 * `visible = 1 AND cpc >= 0.08`, and this index answers that without reading the table.
 *
 * PRODUCTION NEVER RUNS THIS ALTER. The batch container runs `artisan migrate` on start, and jobs
 * on production is about 0.73M rows (0.54 GB compressed) under Galera TOI, where an index add runs
 * cluster-wide. WhatJobsService::prepareTempTable adds the index to the empty jobs_new table on
 * every sync, and the RENAME swap puts it on the live table, so production gets it at the next
 * sync with no ALTER on the live table.
 *
 * This migration exists so that a fresh database (local, CI, yesterday) has the schema without
 * waiting for a sync. It only acts on a small table: when jobs already holds more than
 * SMALL_TABLE_ROWS rows it leaves the index to the swap.
 */
return new class extends Migration
{
    private const TABLE = 'jobs';

    private const INDEX = 'visible_cpc';

    private const SMALL_TABLE_ROWS = 10000;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->indexExists() || ! $this->isSmall()) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ADD INDEX '.self::INDEX.' (visible, cpc), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && $this->indexExists()) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::INDEX);
        }
    }

    private function isSmall(): bool
    {
        // LIMIT inside a subquery so a big table is never counted in full.
        $row = DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT 1 FROM '.self::TABLE.' LIMIT '.(self::SMALL_TABLE_ROWS + 1).') t');

        return (int) ($row->n ?? 0) <= self::SMALL_TABLE_ROWS;
    }

    private function indexExists(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, self::INDEX]
        );

        return (int) ($row->n ?? 0) > 0;
    }
};
