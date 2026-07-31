<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * messages_likes(userid, type, timestamp) index for DigestRelevanceService::interests(),
 * which reads a member's recently viewed posts to build their interest vectors.
 *
 * That query filters `userid = ? AND type = 'View' AND timestamp >= ?` and takes the
 * newest MAX_INTERESTS rows. The existing (userid) index can only seek the member, so
 * MySQL reads every like row they have ever had — thousands for an engaged member — to
 * keep a few dozen, and it runs once per digest recipient. The existing
 * messages_likes_source_ts_user index cannot serve it: that index leads on `source`,
 * which this query does not constrain. Leading on (userid, type) and range-scanning
 * timestamp lets the query stop as soon as it has enough rows.
 *
 * PRODUCTION NOTE: messages_likes is ~75M rows and HOT (every markseen writes it), and
 * prod is Percona/Galera with wsrep_OSU_method=TOI, so a plain ALTER serialises
 * cluster-wide for the whole index build. If the auto-migrate stall is unacceptable,
 * deploy the companion _migration.sql node-by-node under wsrep_OSU_method=RSU instead
 * of the auto-migrate path. Online (ALGORITHM=INPLACE, LOCK=NONE) and idempotent:
 * guarded on information_schema so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'messages_likes'
               AND index_name = 'messages_likes_user_type_ts'"
        );
        if ((int) ($exists->n ?? 0) === 0) {
            DB::statement(
                'ALTER TABLE messages_likes ADD INDEX messages_likes_user_type_ts (userid, type, timestamp), ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'messages_likes'
               AND index_name = 'messages_likes_user_type_ts'"
        );
        if ((int) ($exists->n ?? 0) > 0) {
            DB::statement('ALTER TABLE messages_likes DROP INDEX messages_likes_user_type_ts');
        }
    }
};
