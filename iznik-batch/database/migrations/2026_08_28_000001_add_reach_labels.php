<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reach-engine labels: the compact per-region reach record (routing server's
 * FRL2 blob) from which membership is answered exactly, plus the reached
 * region ids for a road-aware feed prefilter.
 *
 *  - rippling_reach.reach_labels: the FRL2 label bytes, computed ONCE at the
 *    post's maximum budget (0.6-3.8KB measured on real posts, vs 2-46KB cell
 *    grids). Nullable: a deploy ahead of the backfill is a no-op and every
 *    reader falls back to the stored cells.
 *  - rippling_reach_leaves: one row per (post, reached region). Reads run in
 *    the (leaf, msgid) direction — "posts whose reach includes my region" —
 *    which is what turns the browse feed's crow-radius prefilter road-aware.
 *
 * Cheap on production: the column add is ALGORITHM=INSTANT on the ~50GB
 * table (metadata only, same class as the 2026-08-24 cell columns), and the
 * new table starts empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'reach_labels')) {
            DB::statement('ALTER TABLE rippling_reach ADD COLUMN reach_labels MEDIUMBLOB NULL, ALGORITHM=INSTANT');
        }

        if (!Schema::hasTable('rippling_reach_leaves')) {
            DB::statement(
                'CREATE TABLE rippling_reach_leaves (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    msgid BIGINT UNSIGNED NOT NULL,
                    leaf INT NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY msgid_leaf (msgid, leaf),
                    KEY leaf_msgid (leaf, msgid),
                    CONSTRAINT rippling_reach_leaves_msgid_foreign
                        FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_reach_leaves');
        if (Schema::hasColumn('rippling_reach', 'reach_labels')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN reach_labels');
        }
    }
};
