<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds messages_groups.rippled_in — marks a messages_groups row that was created by the
 * rippling-out engine (ExpandService::rippleIntoNewGroups) rather than by the poster or a
 * mod. Such a post was already vetted on its origin group, so AutoApproveService can
 * fast-track it past the membership gate (the poster is not a member of every nearby group
 * its reach touches) on a short mod-veto window instead of leaving it Pending forever.
 *
 * Trailing NOT NULL column with a default → INSTANT add in MySQL 8 (no table rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages_groups', 'rippled_in')) {
            DB::statement('ALTER TABLE messages_groups ADD COLUMN rippled_in TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_groups', 'rippled_in')) {
            DB::statement('ALTER TABLE messages_groups DROP COLUMN rippled_in');
        }
    }
};
