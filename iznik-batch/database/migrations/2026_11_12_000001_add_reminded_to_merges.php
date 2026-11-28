<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds merges.reminded - when the merge offer email was re-sent (merges:remind), so it is sent
 * at most once. NULL means no reminder. The logs table cannot hold this: it is purged and its
 * subtype is a fixed list.
 *
 * Nullable TIMESTAMP added with ALGORITHM=INSTANT: a metadata-only change, no table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('merges', 'reminded')) {
            DB::statement('ALTER TABLE merges ADD COLUMN reminded TIMESTAMP NULL DEFAULT NULL, ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('merges', 'reminded')) {
            DB::statement('ALTER TABLE merges DROP COLUMN reminded');
        }
    }
};
