<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.max_polygon - the reach the post will EVENTUALLY have.
 *
 * `polygon` is the reach right now (hazard tick N). The routing server already
 * returns the whole tick schedule at t=0, so the final tick's geometry - the
 * widest the post will ever get - is knowable the moment the post starts
 * rippling. Nothing stored it, so every consumer could only ask "is this person
 * inside the reach yet?", never "will this person ever be inside the reach?".
 *
 * The first-reply work needs the second question. Holding back a post's very
 * first reply from someone who is inside the final reach buys nothing: they were
 * always going to be allowed to reply, so the hold converts a fast reply into a
 * slow one and nothing else. Same for picking who to tell early about a post
 * nobody has replied to.
 *
 * NULLABLE and NOT spatially indexed on purpose:
 *  - nullable because it is populated by a background pass (firstreply:maxreach),
 *    so existing rows and rows whose schedule carries no inline WKT (geometry has
 *    to be re-fetched from the routing server) simply stay NULL. Readers treat
 *    NULL as "no max reach known" and fall back to current-reach behaviour, so a
 *    deploy before the backfill drains is a no-op rather than a behaviour change.
 *  - no spatial index because every read is a point-in-polygon test on a row
 *    already located by msgid, which is the PRIMARY KEY. An R-tree on this column
 *    would never be consulted, and a SPATIAL INDEX would force NOT NULL.
 *
 * SRID 3857 matches rippling_reach.polygon and messages_spatial.point (lng/lat
 * degrees under an SRID-3857 label - see ExpandService), so ST_Contains works
 * against the same ST_SRID(POINT(lng, lat), 3857) points as the current reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach') || Schema::hasColumn('rippling_reach', 'max_polygon')) {
            return;
        }

        DB::statement(
            'ALTER TABLE rippling_reach
                ADD COLUMN max_polygon GEOMETRY NULL SRID 3857 AFTER polygon,
                ADD COLUMN max_cumulative_users INT UNSIGNED NULL AFTER max_polygon'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('rippling_reach') || !Schema::hasColumn('rippling_reach', 'max_polygon')) {
            return;
        }

        DB::statement('ALTER TABLE rippling_reach DROP COLUMN max_polygon, DROP COLUMN max_cumulative_users');
    }
};
