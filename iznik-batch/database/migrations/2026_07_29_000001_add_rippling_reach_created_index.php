<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * rippling_reach(created_at, total_freeglers) index for the ModTools sysadmin rippling
 * analytics tab (iznik-server-go/rippling/analytics.go). Every analytics query anchors
 * on "posts rippled in the [start,end) window, split by density stratum", i.e. a range
 * on created_at filtered by total_freeglers.
 *
 * Without this index each query full-scans rippling_reach. The table is only ~52k rows
 * but ~17GB, because each row carries the reach polygons, so the scan costs ~15s — and
 * the endpoint runs four such queries serially, tipping /rippling/analytics past the
 * gateway timeout (seen live as 504s on the sysadmin KPIs). The covering index (msgid
 * is the PK so it rides along) answers the window predicate without touching a single
 * polygon page: the base scan drops from ~15s to ~1s.
 *
 * PRODUCTION NOTE: prod is Percona/Galera with wsrep_OSU_method=TOI, so a plain ALTER
 * serialises cluster-wide for the index build. Applied to prod 2026-07-29 node-by-node
 * under RSU (see companion _migration.sql); this migration is guarded on
 * information_schema so it no-ops there. NOTE: rippling_reach has a SPATIAL index, so
 * MySQL refuses LOCK=NONE for INPLACE builds on it — LOCK=SHARED is the least locking
 * mode available (a few seconds on this table).
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
               AND index_name = 'rippling_reach_created_freeglers'"
        );
        if ((int) ($exists->n ?? 0) === 0) {
            DB::statement(
                'ALTER TABLE rippling_reach ADD INDEX rippling_reach_created_freeglers (created_at, total_freeglers), ALGORITHM=INPLACE, LOCK=SHARED'
            );
        }
    }

    public function down(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
               AND index_name = 'rippling_reach_created_freeglers'"
        );
        if ((int) ($exists->n ?? 0) > 0) {
            DB::statement('ALTER TABLE rippling_reach DROP INDEX rippling_reach_created_freeglers');
        }
    }
};
