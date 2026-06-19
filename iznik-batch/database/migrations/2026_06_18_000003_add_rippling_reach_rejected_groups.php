<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds rippling_reach.rejected_groups — a JSON array of group ids whose area has been
 * subtracted from this post's reach by a secondary-group rejection (#9).
 *
 * Without this, the clip applied by ClipReachForRejectedGroup (Go) is silently undone on
 * the next ExpandService::advanceDue tick, which overwrites `polygon` from the cached
 * schedule. Persisting the rejected group ids lets advanceDue re-subtract them after every
 * tick write, so the rejection survives expansion.
 *
 * Additive + idempotent (separate file, not an edit to the create migration) so it never
 * conflicts with the rippling_reach create migration when branches merge in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        // rippling_reach is created by PR A (the reach engine), which merges first. If it
        // is not present yet this is a no-op — the column ships with whichever branch lands.
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }
        if (!Schema::hasColumn('rippling_reach', 'rejected_groups')) {
            DB::statement('ALTER TABLE rippling_reach ADD COLUMN rejected_groups JSON NULL AFTER status');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rippling_reach') && Schema::hasColumn('rippling_reach', 'rejected_groups')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN rejected_groups');
        }
    }
};
