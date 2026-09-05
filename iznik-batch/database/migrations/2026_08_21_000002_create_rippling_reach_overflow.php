<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach_overflow - the ring bounding boxes, indexed, in their own table.
 *
 * WHY NOT AN INDEX ON rippling_reach. The rings live as JSON in
 * rippling_reach.overflow_bounds, and "does any ring admit this viewer" is a
 * JSON_EXTRACT bbox test no index can serve. ORing it into the spatial
 * containment predicate removed the SPATIAL index (EXPLAIN went from
 * key=rippling_reach_polygon rows=1 to key=NULL rows=62,534) and full-scanned a
 * ~17GB table, which took the site down on 2026-08-21.
 *
 * Indexing that column in place was not available. The ALTER sat 36 minutes at
 * `checking permissions` under TOI without ever starting, holding the cluster's
 * total order while 3,400+ write sets queued behind it on the write node, and it
 * blocks the node under RSU too.
 *
 * A separate table sidesteps all of it: CREATE TABLE has nothing to build, so it
 * neither scans nor locks the hot table. 4,257 of ~55,000 reach rows carry a ring
 * (7.6%), so this stays small, and a SPATIAL index over the bbox answers the ring
 * question directly. Measured after backfill: key=rippling_reach_overflow_bbox,
 * 8ms, returning the same 189 candidates the JSON scan took 49s to find.
 *
 * bbox is a POLYGON because MySQL will not spatially index loose DOUBLE columns.
 * The authoritative geometry stays in overflow_bounds; this is a PREFILTER that
 * says "this post's rings might admit you", and the exact per-lane test still
 * runs against the JSON, bounded by primary key to the few rows that survive it.
 *
 * SUPERSEDED THE SAME DAY, and nothing reads or writes it any more.
 *
 * The narrowing worked - 836 candidates in 8.7ms - but narrowing was never the
 * problem: 558 of those 836 genuinely admitted, and parsing their 37k-vertex
 * rings cost 4.8s regardless. Ring admission is now answered by
 * iznik-spatial-go's `reachoverflow` dataset, which rasterises each ring once,
 * and both the site and the mail ask it (rippling.AdmittedMsgids /
 * App\Services\Ripple\RingIndex). The writer and the backfill command are
 * deleted; this migration stays because the table exists on prod and migration
 * history should not be rewritten.
 *
 * The table can be DROPped whenever convenient - it holds ~4,400 rows of dead
 * weight. Prod schema changes are operator-only, so that is theirs to run:
 *
 *     DROP TABLE rippling_reach_overflow;
 *
 * Applied on prod by hand on 2026-08-21; prod schema changes are operator-only.
 */
return new class extends Migration
{
    private const TABLE = 'rippling_reach_overflow';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::statement('CREATE TABLE '.self::TABLE.' (
            msgid BIGINT UNSIGNED NOT NULL,
            bbox POLYGON NOT NULL SRID 3857,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (msgid),
            SPATIAL KEY rippling_reach_overflow_bbox (bbox),
            KEY rippling_reach_overflow_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            DB::statement('DROP TABLE '.self::TABLE);
        }
    }
};
