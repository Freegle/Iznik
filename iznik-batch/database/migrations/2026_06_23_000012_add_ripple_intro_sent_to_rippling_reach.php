<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.ripple_intro_sent - one-shot guard so the bundled "your post is reaching nearby
 * communities" intro email (RippleIntroMail) is sent at most once per post, no matter how many
 * ticks/groups the post ripples into (Rippling Out, PR #855). rippling_reach already has exactly
 * one row per rippling post, so it is the natural place to track per-post send state.
 *
 * NOT NULL DEFAULT 0 -> existing reach rows read as not-yet-sent (the backfill command sends the
 * intro for already-rippled posters and stamps this). Trailing bool -> INSTANT add in MySQL 8.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'ripple_intro_sent')) {
            Schema::table('rippling_reach', function (Blueprint $t) {
                // No AFTER: appended at the end so this does not depend on a specific column's
                // position (the dev-DB-only reach_arm column is not created by any migration).
                $t->boolean('ripple_intro_sent')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rippling_reach', 'ripple_intro_sent')) {
            Schema::table('rippling_reach', function (Blueprint $t) {
                $t->dropColumn('ripple_intro_sent');
            });
        }
    }
};
