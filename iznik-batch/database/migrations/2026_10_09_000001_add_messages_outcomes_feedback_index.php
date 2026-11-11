<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * messages_outcomes.feedback - is this a member's real feedback, as opposed to an automatic or
 * empty comment - and an index that leads with it.
 *
 * The Feedback badge counts unreviewed outcomes from the last 31 days whose comment is real. Real
 * comments are about 400 of 150,000 such rows, but the boilerplate test reads `comments` on every
 * row, so ModTools /session and /group/work each spent about 0.9s scanning ~148k rows to find
 * them. Nearly every row has reviewed = 0, so the existing (reviewed, timestamp, happiness) index
 * cannot narrow it. With feedback in the index the same count reads about 400 index entries.
 *
 * VIRTUAL, not STORED: ADD COLUMN ... VIRTUAL is ALGORITHM=INSTANT (no row rewrite, safe on a
 * 5.7M row table) where STORED rebuilds the table. The index holds the computed value, so reads
 * never evaluate the expression, and writes only compute it for the index entry.
 *
 * The excluded comments are the ones the outcome flow writes itself. Keep them in step with
 * happinessFilterComments in iznik-server-go/membership/membership.go and
 * HAPPINESS_FILTER_EXCLUDED_COMMENTS in iznik-batch/app/Models/Group.php.
 *
 * PRODUCTION NOTE. messages_outcomes is large and prod is Galera with wsrep_OSU_method=TOI, so the
 * index build must go node by node under RSU. The column add is INSTANT. Apply the companion
 * _migration.sql by hand BEFORE deploying the Go that reads the column; prod schema changes are
 * operator-only.
 */
return new class extends Migration
{
    private const TABLE = 'messages_outcomes';

    private const INDEX = 'feedback_reviewed_timestamp';

    private const COLUMN_SQL = "ALTER TABLE messages_outcomes ADD COLUMN feedback TINYINT(1) GENERATED ALWAYS AS ("
        ."comments IS NOT NULL AND comments <> '' AND comments NOT IN ("
        ."'Sorry, this is no longer available.', "
        ."'Thanks, this has now been taken.', "
        ."'Thanks, I''m no longer looking for this.', "
        ."'Sorry, this has now been taken.', "
        ."'Thanks for the interest, but this has now been taken.', "
        ."'Thanks, these have now been taken.', "
        ."'Thanks, this has now been received.', "
        ."'Withdrawn on user unsubscribe', "
        ."'Auto-Expired')) VIRTUAL, ALGORITHM=INSTANT";

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'feedback')) {
            DB::statement(self::COLUMN_SQL);
        }

        if (! $this->indexExists()) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD INDEX '.self::INDEX
                .' (feedback, reviewed, timestamp, happiness), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->indexExists()) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::INDEX);
        }

        if (Schema::hasColumn(self::TABLE, 'feedback')) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN feedback');
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
