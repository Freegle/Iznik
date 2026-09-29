<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds messages_groups.mod_messaging_allowed — whether mods on this group may message
 * the poster of this message directly. Defaults to allowed, matching existing behaviour
 * for ordinary Freegle posts. It is set to disallowed only in specific cases, e.g. TN
 * posters who have not consented to mod contact per TN's freegle_group_ids
 * (see PostSyncer::processPost).
 *
 * Trailing NOT NULL column with a default → INSTANT add in MySQL 8 (no table rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages_groups', 'mod_messaging_allowed')) {
            DB::statement('ALTER TABLE messages_groups ADD COLUMN mod_messaging_allowed TINYINT(1) NOT NULL DEFAULT 1');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_groups', 'mod_messaging_allowed')) {
            DB::statement('ALTER TABLE messages_groups DROP COLUMN mod_messaging_allowed');
        }
    }
};
