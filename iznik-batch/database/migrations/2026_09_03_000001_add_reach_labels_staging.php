<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staging for a reach-engine partition change.
 *
 * A partition rebuild renumbers the regions every stored reach_labels blob
 * refers to, so the artifacts and the stored labels are one versioned pair:
 * loading new artifacts against old labels silently empties every member's
 * nearby feed (2026-09-03). Regenerating labels in place is no better - mid-
 * way the engine serves one partition while the table holds a mix.
 *
 * rippling_reach.reach_labels_next / reach_labels_next_fp: the incoming
 * partition's label, staged beside the live one and stamped with the
 * partition it was built against. Readers use it ONLY when the stamp equals
 * the live engine's partition (routing: its own partFP; batch: the
 * config.reach_partition_fp pairing record), so the cutover is atomic by
 * fingerprint and nothing is mutated to get there. Copied into reach_labels
 * afterwards at leisure.
 *
 * rippling_reach_leaves unique key widened to (msgid, leaf, fp): leaf ids are
 * partition-local, and under the old (msgid, leaf) key an INSERT IGNORE of a
 * new-partition row that collided with an old one was silently dropped - the
 * post went undiscoverable after the switch. With fp in the key both
 * partitions' rows coexist and the loader's existing fp filter picks.
 *
 * Applied on production BY THE OPERATOR on 2026-09-03: the column adds with
 * ALGORITHM=INSTANT (metadata only), the index change INPLACE (index build,
 * no table rebuild, 0.29 GB). Guarded so it is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'reach_labels_next')) {
            DB::statement('ALTER TABLE rippling_reach ADD COLUMN reach_labels_next MEDIUMBLOB NULL, ADD COLUMN reach_labels_next_fp BIGINT UNSIGNED NULL, ALGORITHM=INSTANT');
        }
        if (!$this->hasIndex('rippling_reach_leaves', 'msgid_leaf_fp')) {
            DB::statement('ALTER TABLE rippling_reach_leaves ADD UNIQUE INDEX msgid_leaf_fp (msgid, leaf, fp), ALGORITHM=INPLACE, LOCK=NONE');
        }
        if ($this->hasIndex('rippling_reach_leaves', 'msgid_leaf')) {
            DB::statement('ALTER TABLE rippling_reach_leaves DROP INDEX msgid_leaf, ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (!$this->hasIndex('rippling_reach_leaves', 'msgid_leaf')) {
            // Only restorable while each (msgid, leaf) is unique again, i.e.
            // after the old partition's rows have been purged.
            DB::statement('ALTER TABLE rippling_reach_leaves ADD UNIQUE INDEX msgid_leaf (msgid, leaf), ALGORITHM=INPLACE, LOCK=NONE');
        }
        if ($this->hasIndex('rippling_reach_leaves', 'msgid_leaf_fp')) {
            DB::statement('ALTER TABLE rippling_reach_leaves DROP INDEX msgid_leaf_fp, ALGORITHM=INPLACE, LOCK=NONE');
        }
        if (Schema::hasColumn('rippling_reach', 'reach_labels_next')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN reach_labels_next, DROP COLUMN reach_labels_next_fp, ALGORITHM=INSTANT');
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
