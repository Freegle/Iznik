<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen memberships `groupid` from (groupid, collection) to (groupid, collection, emailfrequency).
 *
 * UnifiedDigestService::processGroupImmediate asks, per community, who wants immediate
 * mail. UnifiedDigestService's rippling reach recipient query asks the same thing:
 *
 *   ... WHERE memberships.groupid = ?
 *         AND memberships.collection = 'Approved'
 *         AND memberships.emailfrequency = -1
 *
 * All three are equality tests. The index that serves this today stops at collection, so
 * the lookup seeks the community and then fetches every one of its members to read
 * emailfrequency off the row.
 *
 * Measured on production. Immediate members are 32,044 of 4,989,415 memberships, 0.64%.
 * The lookup runs about 15,455 times a day (once per community per minute in which that
 * community got a new post), reading 204,774,049 membership rows to find 1,165,044 wanted
 * ones - 176x more reading than needed. On the largest community it fetches 59,282 rows to
 * return 160.
 *
 * This got more expensive with #1339, which removed the coarse pre-filter that skipped
 * communities with no immediate members, so the loop now visits every community.
 *
 * WHY WIDEN RATHER THAN ADD. memberships already carries 12 indexes and 1.44GB of them
 * against 0.42GB of data, and it is a hot table, so a fourth groupid-leading index is
 * write amplification we do not have to pay. collection is functionally constant here
 * (4,989,400 Approved against 15 Pending and no Banned), so appending emailfrequency to
 * the existing (groupid, collection) index is a strict superset: every current user of
 * `groupid` keeps the plan it has, and the digest lookup gains a third equality column.
 * Index count stays where it is.
 *
 * Order is (groupid, collection, emailfrequency) rather than (groupid, emailfrequency)
 * precisely so it stays a superset. Anything doing WHERE groupid = ? AND collection = ?
 * keeps its two-column seek, which it would lose if emailfrequency went second.
 *
 * ONE ALTER, NOT TWO. Both changes go in a single statement so InnoDB makes one pass over
 * the table, per docs/ops/reference/database-index-hygiene.md, and so the swap is atomic:
 * there is no window in which the table has neither index, whatever interrupts it.
 * groupid_2 (groupid, role) would satisfy the groupid foreign key on its own anyway, so
 * the drop is safe regardless.
 *
 * INDEX NAMES COME FROM SHOW INDEX, NOT information_schema. statistics is served from a
 * cache governed by information_schema_stats_expiry, so a migration that checks there and
 * then alters in the same run can read stale metadata and fail with "Key ... doesn't
 * exist". Same page, "Finding candidates in the first place".
 *
 * PRODUCTION NOTE. prod is Percona/Galera with wsrep_OSU_method=TOI, so a plain ALTER
 * serialises cluster-wide writes for the whole index build. Deploy the companion
 * _migration.sql node-by-node under RSU instead of the auto-migrate path if that stall is
 * unacceptable.
 *
 * Idempotent: each half is guarded on the index names actually present, so a re-run is a no-op.
 */
return new class extends Migration
{
    private const TABLE = 'memberships';

    /** The index this replaces: (groupid, collection), named `groupid` by the table create. */
    private const OLD_INDEX = 'groupid';

    private const NEW_INDEX = 'memberships_groupid_collection_emailfrequency';

    public function up(): void
    {
        $this->swap(
            'ADD INDEX ' . self::NEW_INDEX . ' (groupid, collection, emailfrequency)',
            self::NEW_INDEX,
            'DROP INDEX `' . self::OLD_INDEX . '`',
            self::OLD_INDEX
        );
    }

    public function down(): void
    {
        $this->swap(
            'ADD INDEX `' . self::OLD_INDEX . '` (groupid, collection)',
            self::OLD_INDEX,
            'DROP INDEX ' . self::NEW_INDEX,
            self::NEW_INDEX
        );
    }

    /**
     * Add $wantName if missing and drop $dropName if present, in one ALTER.
     *
     * Each half is guarded independently, so this converges on the intended shape from any
     * of the four starting states and a re-run is a no-op.
     */
    private function swap(string $addClause, string $wantName, string $dropClause, string $dropName): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $present = $this->indexNames();

        $clauses = [];
        if (!in_array($wantName, $present, true)) {
            $clauses[] = $addClause;
        }
        if (in_array($dropName, $present, true)) {
            $clauses[] = $dropClause;
        }

        if (!$clauses) {
            return;
        }

        DB::statement(
            'ALTER TABLE ' . self::TABLE . ' ' . implode(', ', $clauses)
            . ', ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    /** @return string[] */
    private function indexNames(): array
    {
        return array_values(array_unique(array_map(
            static fn ($row) => $row->Key_name,
            DB::select('SHOW INDEX FROM ' . self::TABLE)
        )));
    }
};
