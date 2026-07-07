<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'held' to rippling_reach.status.
 *
 * 'held' marks a post whose ripple has been frozen because it was pulled back to Pending for
 * review - a report / microvolunteering quorum, or a moderator Back to Pending. Unlike 'done'
 * (a normally-completed ripple, which stays retractable), a 'held' post keeps its rippled-in
 * copies as Pending for per-group moderation, so ExpandService's retraction paths must SKIP
 * it. Freezing the reach row (rather than deleting it) also stops initialiseNew's anti-join
 * (WHERE mr.msgid IS NULL) from re-reaching and re-notifying the post if a moderator later
 * re-approves a copy.
 *
 * Additive + idempotent so it never conflicts with the create migration on branch merges.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }
        DB::statement("ALTER TABLE rippling_reach MODIFY status ENUM('expanding','stopped','done','held') NOT NULL DEFAULT 'expanding'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }
        DB::statement("ALTER TABLE rippling_reach MODIFY status ENUM('expanding','stopped','done') NOT NULL DEFAULT 'expanding'");
    }
};
