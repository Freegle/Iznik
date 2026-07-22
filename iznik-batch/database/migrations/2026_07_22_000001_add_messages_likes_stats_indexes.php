<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * messages_likes(source, timestamp, userid) index for the ModTools sysadmin
 * "Recommendations" funnel (iznik-server-go/recommendations/stats.go). Its three
 * queries all filter on source + timestamp; the holdout cohort adds DISTINCT userid.
 *
 * Without this index, `SELECT DISTINCT userid ... WHERE source = ? AND timestamp >= ?`
 * picks a pathological plan: it walks the whole (userid) secondary index (~64.5M
 * entries) doing a row lookup per entry to test the predicate, which is WORSE than a
 * table scan and times the endpoint out (seen live at /modtools/recommendations/stats).
 * The covering (source, timestamp, userid) order lets all three queries seek on the
 * tagged, recent rows and keeps the DISTINCT userid index-only.
 *
 * PRODUCTION NOTE: messages_likes is ~75M rows and HOT (every markseen writes it), and
 * prod is Percona/Galera with wsrep_OSU_method=TOI, so a plain ALTER serialises
 * cluster-wide for the whole index build. If the auto-migrate stall is unacceptable,
 * deploy the companion _migration.sql node-by-node under wsrep_OSU_method=RSU instead
 * of the auto-migrate path. Online (ALGORITHM=INPLACE, LOCK=NONE) and idempotent:
 * guarded on information_schema so a re-run is a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'messages_likes'
               AND index_name = 'messages_likes_source_ts_user'"
        );
        if ((int) ($exists->n ?? 0) === 0) {
            DB::statement(
                'ALTER TABLE messages_likes ADD INDEX messages_likes_source_ts_user (source, timestamp, userid), ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'messages_likes'
               AND index_name = 'messages_likes_source_ts_user'"
        );
        if ((int) ($exists->n ?? 0) > 0) {
            DB::statement('ALTER TABLE messages_likes DROP INDEX messages_likes_source_ts_user');
        }
    }
};
