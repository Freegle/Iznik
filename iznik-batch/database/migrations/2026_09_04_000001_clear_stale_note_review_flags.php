<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clears Member Review entries left behind by flagged notes that no longer exist.
 *
 * Notes are removed outright rather than marked as deleted, and until now nothing
 * took away the review a flagged note had asked for on the member's other groups.
 * Moderators are left with review entries reading "Note flagged to other groups"
 * where there is no note to read (Discourse 9618 post 37).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            UPDATE memberships m
            SET m.reviewrequestedat = NULL, m.reviewreason = NULL, m.heldby = NULL
            WHERE m.reviewreason = 'Note flagged to other groups'
              AND m.reviewrequestedat IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM users_comments uc
                  WHERE uc.userid = m.userid AND uc.flag = 1
              )
        ");
    }

    public function down(): void
    {
        // The review entries cleared here asked moderators to read a note that no
        // longer exists, so there is nothing to put back.
    }
};
