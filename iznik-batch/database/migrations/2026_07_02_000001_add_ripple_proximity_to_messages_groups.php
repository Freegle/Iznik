<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds messages_groups.ripple_proximity_p / ripple_proximity_q — the human place names
 * for the "quicker to get to" moderator note (see
 * ExpandService::recordRippleProximity), computed once at ripple-in time from the
 * routing server's /v1/group-proximity. Both NULL unless the routing server said
 * quicker=true when the row was rippled in; the frontend shows the note only when
 * both are present.
 *
 * Trailing nullable columns → INSTANT add in MySQL 8 (no table rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages_groups', 'ripple_proximity_p')) {
            DB::statement('ALTER TABLE messages_groups ADD COLUMN ripple_proximity_p VARCHAR(160) NULL');
        }
        if (!Schema::hasColumn('messages_groups', 'ripple_proximity_q')) {
            DB::statement('ALTER TABLE messages_groups ADD COLUMN ripple_proximity_q VARCHAR(160) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_groups', 'ripple_proximity_q')) {
            DB::statement('ALTER TABLE messages_groups DROP COLUMN ripple_proximity_q');
        }
        if (Schema::hasColumn('messages_groups', 'ripple_proximity_p')) {
            DB::statement('ALTER TABLE messages_groups DROP COLUMN ripple_proximity_p');
        }
    }
};
