<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * rippling_reach(updated_at) — for the spatial server's reach delta poll.
 *
 * iznik-spatial-go asks, every two minutes, on every node:
 *
 *   SELECT msgid, status, ST_AsWKB(polygon) FROM rippling_reach WHERE updated_at > ?
 *
 * There is no index on updated_at, so each poll is a full scan of a ~29GB table
 * (EXPLAIN reports type=ALL over ~48k rows, and the rows are large because each carries
 * a polygon). Measured executions of 51s on db3 and 52s on db2 — with a two-minute
 * poll interval that is close to a 43% duty cycle per instance, roughly 0.4–0.9 mysqld
 * cores per node, continuously, day and night. It is the largest steady-state database
 * cost in the rippling family, and being 24/7 it is the one thing no amount of moving
 * work to the night can help.
 *
 * PRODUCTION NOTE. rippling_reach is large and hot, and prod is Percona/Galera with
 * wsrep_OSU_method=TOI, so a plain ALTER serialises cluster-wide writes for the whole
 * index build. Deploy the companion _migration.sql node-by-node under RSU instead of the
 * auto-migrate path if that stall is unacceptable.
 *
 * The build itself is fully online: ALGORITHM=INPLACE, LOCK=NONE is accepted on this
 * table, verified against Percona 8.0.43 (prod runs 8.0.45). That is worth stating
 * because the table carries two SPATIAL indexes and spatial indexes cannot themselves be
 * built with LOCK=NONE — but that restriction is about adding a spatial index, not about
 * adding a plain one to a table that has some.
 *
 * Idempotent: guarded on information_schema so a re-run is a no-op.
 */
return new class extends Migration
{
    private const TABLE = 'rippling_reach';

    private const INDEX = 'rippling_reach_updated_at';

    public function up(): void
    {
        // The table is created by the reach engine's own migration; if this runs in an
        // environment that has not got there yet, there is nothing to index.
        if (!$this->tableExists()) {
            return;
        }

        if ($this->indexExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE ' . self::TABLE . ' ADD INDEX ' . self::INDEX . ' (updated_at), ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    public function down(): void
    {
        if (!$this->tableExists() || !$this->indexExists()) {
            return;
        }

        DB::statement('ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::INDEX);
    }

    private function tableExists(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [self::TABLE]
        );

        return (int) ($row->n ?? 0) > 0;
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
