<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.has_overflow - make the overflow-ring rows reachable by index.
 *
 * The rural-access rings are stored as JSON in overflow_bounds, and the read
 * surfaces ask "does any ring admit this viewer" with JSON_EXTRACT over that
 * column. No index can serve that, so the test is only affordable once
 * something else has cut the rows down.
 *
 * On 2026-08-21 it was instead ORed into the spatial containment predicate in
 * message/search.go. That removed the SPATIAL index from the query - EXPLAIN
 * went from `key=rippling_reach_polygon rows=1` to `key=NULL rows=62,534` - so
 * every cold cache fill full-scanned a ~17GB table. Measured standalone at 49s;
 * under real concurrency it put 250 running threads and load 158 on the read
 * node and 209 threads on the write node, with API calls in seconds. apiv2 was
 * rolled back to 8c5551f41 to recover.
 *
 * The code now asks the ring question as its own query. This index is what makes
 * that query cheap: 4,213 of 55,195 rows carry a ring (7.6%), and without it
 * finding them is still a scan.
 *
 * PRODUCTION NOTE. rippling_reach is ~17GB and prod is Galera with
 * wsrep_OSU_method=TOI, so the index build must go node-by-node under RSU or it
 * stalls cluster-wide writes for the whole build (~1 minute per node, based on
 * the 49s scan). The column add is separate and INSTANT. Apply the companion
 * _migration.sql by hand; prod schema changes are operator-only.
 *
 * The Go side works either side of this: rippling.OverflowRowSelector() uses
 * has_overflow when the column exists and falls back to `overflow_bounds IS NOT
 * NULL` when it does not, with the ring arm capped by MAX_EXECUTION_TIME so a
 * slow arm degrades to committed-reach-only rather than holding the database.
 */
return new class extends Migration
{
    private const TABLE = 'rippling_reach';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'has_overflow')) {
            DB::statement('ALTER TABLE '.self::TABLE.
                ' ADD COLUMN has_overflow TINYINT(1) GENERATED ALWAYS AS (overflow_bounds IS NOT NULL) VIRTUAL'.
                ', ALGORITHM=INSTANT');
        }

        if (! $this->indexExists('rippling_reach_has_overflow')) {
            DB::statement('ALTER TABLE '.self::TABLE.
                ' ADD INDEX rippling_reach_has_overflow (has_overflow, updated_at)'.
                ', ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->indexExists('rippling_reach_has_overflow')) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX rippling_reach_has_overflow');
        }

        if (Schema::hasColumn(self::TABLE, 'has_overflow')) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN has_overflow');
        }
    }

    private function indexExists(string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, $index]
        );

        return (int) ($row->n ?? 0) > 0;
    }
};
