<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * jobs (visible, cpc): the spatial server five-minute jobs delta check counts
 * `visible = 1 AND cpc >= 0.08 AND geometry IS NOT NULL`. geometry is NOT NULL, so MySQL drops that
 * predicate and the count is covered by this index alone, instead of scanning the whole compressed
 * table every time.
 *
 * WhatJobsService builds each replacement with CREATE TABLE jobs_new LIKE jobs, so the index
 * survives every table swap.
 *
 * PRODUCTION NOTE. Production is Galera with wsrep_OSU_method=TOI, so the ALTER runs cluster-wide.
 * The index is added by hand BEFORE deploy (see the companion _migration.sql); this migration then
 * finds it already there and does nothing. It only builds the index on databases that do not have
 * it (local, CI, yesterday).
 */
return new class extends Migration
{
    private const TABLE = 'jobs';

    private const INDEX = 'visible_cpc';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->indexExists()) {
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
