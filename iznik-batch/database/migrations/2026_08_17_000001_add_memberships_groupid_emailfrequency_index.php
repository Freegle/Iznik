<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * memberships(groupid, emailfrequency) — for the immediate digest's recipient lookup.
 *
 * UnifiedDigestService::processGroupImmediate asks, per community, who wants immediate
 * mail:
 *
 *   ... WHERE memberships.groupid = ? AND memberships.emailfrequency = ?
 *
 * Both are equality tests, but the only index that starts with groupid carries collection
 * as its second column, so the lookup seeks on the community and then walks every one of
 * its members checking emailfrequency. Measured against production across all live Freegle
 * communities: 4,987,773 membership rows examined to find 344,961 immediate members, so
 * 93% of the work is discarded. On the largest community EXPLAIN reports 39,078 rows
 * examined for one lookup.
 *
 * This matters more since #1339. That removed the coarse pre-filter which skipped
 * communities with no immediate members, so the loop now visits all of them, and this
 * lookup runs for every community that has new posts.
 *
 * Column order is groupid first, matching how the code asks and the existing
 * groupid-leading indexes (groupid+collection, groupid+role). Both predicates are
 * equality, so either order would serve this query; this one also keeps the index useful
 * to anything else scoped to a community.
 *
 * PRODUCTION NOTE. memberships is large and hot, and prod is Percona/Galera with
 * wsrep_OSU_method=TOI, so a plain ALTER serialises cluster-wide writes for the whole
 * index build. Deploy the companion _migration.sql node-by-node under RSU instead of the
 * auto-migrate path if that stall is unacceptable.
 *
 * Idempotent: guarded on information_schema so a re-run is a no-op.
 */
return new class extends Migration
{
    private const TABLE = 'memberships';

    private const INDEX = 'memberships_groupid_emailfrequency';

    public function up(): void
    {
        if (!$this->tableExists() || $this->indexExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE ' . self::TABLE . ' ADD INDEX ' . self::INDEX
            . ' (groupid, emailfrequency), ALGORITHM=INPLACE, LOCK=NONE'
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
