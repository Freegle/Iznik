<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users_digests.carryover - the posts the member's last daily digest had to leave out
 * because the email was already at DIGEST_POST_CAP. The next run adds them back into the
 * member's window, so a post that was eligible but did not fit gets a second chance
 * rather than being lost when the cursor moves past it (Discourse 10029/17).
 *
 * JSON array of msgids, or NULL when nothing was left out. Written by
 * UnifiedDigestService::updateDigestTracker(), read by getPostsForUser().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users_digests') || Schema::hasColumn('users_digests', 'carryover')) {
            return;
        }

        Schema::table('users_digests', function (Blueprint $table) {
            $table->json('carryover')->nullable()->after('lastsent')
                ->comment('msgids the last digest left out at the post cap; re-offered next run');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users_digests') || !Schema::hasColumn('users_digests', 'carryover')) {
            return;
        }

        Schema::table('users_digests', function (Blueprint $table) {
            $table->dropColumn('carryover');
        });
    }
};
