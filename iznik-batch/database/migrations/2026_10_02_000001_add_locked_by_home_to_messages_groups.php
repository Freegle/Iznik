<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds messages_groups.locked_by_home - set on each rippled-in copy that a moderator of the
 * post's HOME community pulls back with Back to pending. While it is set, and the home copy
 * is not Approved, the receiving community cannot approve its copy: the home community is
 * reviewing the post. Approving the home copy clears it, and the copies go back to normal
 * per-group moderation. A reach the pull-back froze is resumed by the same Approve
 * (ResumeReachIfHeld in iznik-server-go/microvolunteering) and carries on from where it had
 * got to, without telling anyone already told (rippling_reach_notified).
 *
 * Back to pending by a receiving community's own moderator, or by a members' report quorum,
 * does not set it: those copies stay independent. Nothing sets the flag today, because a home
 * Back to pending now withdraws the copies instead (rippling_blocked), which also bars the
 * reach from resuming; it is still honoured where it is set.
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
