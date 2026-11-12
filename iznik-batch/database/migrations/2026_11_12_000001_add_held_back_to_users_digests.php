<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users_digests.held_back - the posts the reach gate kept out of the member's daily digest
 * and that the digest cursor then moved past. The next run offers them again and sends each
 * one once the reach covers the member, so a post whose reach grows after the cursor has
 * passed it is not lost. Ids leave the list when sent, gone, or older than
 * UnifiedDigestService::HELD_BACK_MAX_AGE_DAYS.
 *
 * JSON array of msgids, or NULL when nothing is held back. Written by
 * UnifiedDigestService::updateDigestTracker(), read by loadPostsForUser().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users_digests') || Schema::hasColumn('users_digests', 'held_back')) {
            return;
        }

        Schema::table('users_digests', function (Blueprint $table) {
            $table->json('held_back')->nullable()
                ->comment('msgids the reach gate held back that the cursor passed; re-offered until sent or too old');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users_digests') || !Schema::hasColumn('users_digests', 'held_back')) {
            return;
        }

        Schema::table('users_digests', function (Blueprint $table) {
            $table->dropColumn('held_back');
        });
    }
};
