<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grid-removal endgame columns.
 *
 * rippling_reach.origin_union_secs: the road-native origin-group union - the
 * smallest drive-time budget at which the post's stored label covers 90% of
 * its origin group's road nodes. NULL = not computed yet (the transitional
 * origin_area behaviour applies, cells decide); -1 = computed, the union
 * never activates; >= 0 = the budget at which the group's whole area becomes
 * admitted. Computed once when the label is stored; exact at every tick.
 *
 * rippling_reach_leaves.fp: the partition-build fingerprint the row's leaf id
 * belongs to. Leaf ids are build-local, so a routing server holding two
 * builds (rolling label migration across a map refresh) filters candidate
 * rows to the builds it has loaded. NULL rows (from before this column, or
 * not yet stamped by the union backfill) match loosely - a false candidate
 * only costs a lookup, the blob itself still decides.
 *
 * Cheap on production: both ALGORITHM=INSTANT column adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'origin_union_secs')) {
            DB::statement('ALTER TABLE rippling_reach ADD COLUMN origin_union_secs FLOAT NULL, ALGORITHM=INSTANT');
        }
        if (!Schema::hasColumn('rippling_reach_leaves', 'fp')) {
            DB::statement('ALTER TABLE rippling_reach_leaves ADD COLUMN fp BIGINT UNSIGNED NULL, ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rippling_reach', 'origin_union_secs')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN origin_union_secs');
        }
        if (Schema::hasColumn('rippling_reach_leaves', 'fp')) {
            DB::statement('ALTER TABLE rippling_reach_leaves DROP COLUMN fp');
        }
    }
};
