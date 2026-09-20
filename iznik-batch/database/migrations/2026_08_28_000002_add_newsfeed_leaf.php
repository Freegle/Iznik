<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Road-network region tag for ChitChat threads: which reach-engine partition
 * region the thread's location belongs to. The feed narrows a member's
 * distance filter to regions their travel-time budget can actually reach by
 * road, so the far bank of an estuary drops out of "chitchat near me" even
 * when it is inside the crow-flies radius. Nullable: untagged rows (and every
 * row until the backfill runs) keep the pure radius behaviour.
 *
 * Cheap on production: ALGORITHM=INSTANT column add; the index builds INPLACE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('newsfeed', 'leaf')) {
            DB::statement('ALTER TABLE newsfeed ADD COLUMN leaf INT NULL, ALGORITHM=INSTANT');
            DB::statement('ALTER TABLE newsfeed ADD KEY leaf (leaf), ALGORITHM=INPLACE');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('newsfeed', 'leaf')) {
            DB::statement('ALTER TABLE newsfeed DROP COLUMN leaf');
        }
    }
};
