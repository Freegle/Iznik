<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add 'MatchedPost' to users_notifications.type for the matched-posts feature's
 * in-app (bell) + push notification. ENUM values are stored as a 1-based
 * ordinal, so the new value MUST be appended at the END of the existing physical
 * order — an end-append is metadata-only (ALGORITHM=INSTANT); a reorder forces a
 * full table rebuild. This enum was created 2025-12-10 with no reorder history,
 * so the create-migration order is the live order. Do not tidy the order.
 */
return new class extends Migration
{
    private string $existing = "'CommentOnYourPost','CommentOnCommented','LovedPost','LovedComment','TryFeed','MembershipPending','MembershipApproved','MembershipRejected','AboutMe','Exhort','GiftAid','OpenPosts'";

    public function up(): void
    {
        if (! Schema::hasTable('users_notifications')) {
            return;
        }

        $enum = "ENUM({$this->existing},'MatchedPost') NOT NULL";
        try {
            DB::statement("ALTER TABLE users_notifications MODIFY COLUMN type {$enum}, ALGORITHM=INSTANT");
        } catch (\Throwable $e) {
            DB::statement("ALTER TABLE users_notifications MODIFY COLUMN type {$enum}");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users_notifications')) {
            return;
        }

        DB::table('users_notifications')->where('type', 'MatchedPost')->delete();
        DB::statement("ALTER TABLE users_notifications MODIFY COLUMN type ENUM({$this->existing}) NOT NULL");
    }
};
