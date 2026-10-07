<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds messages_groups.locked_by_home - set on each rippled-in copy that a moderator of the
 * post's HOME community pulls back with Back to pending. While it is set, and the home copy
 * is not Approved, the receiving community cannot approve its copy: the home community is
 * reviewing the post. Approving the home copy clears it, and the copies go back to normal
 * per-group moderation (the reach stays frozen, so nothing is re-sent).
 *
 * Back to pending by a receiving community's own moderator, or by a members' report quorum,
 * does not set it: those copies stay independent.
 *
 * Trailing NOT NULL column with a default → INSTANT add in MySQL 8 (no table rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages_groups', 'locked_by_home')) {
            DB::statement('ALTER TABLE messages_groups ADD COLUMN locked_by_home TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_groups', 'locked_by_home')) {
            DB::statement('ALTER TABLE messages_groups DROP COLUMN locked_by_home');
        }
    }
};
