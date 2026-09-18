<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users_related.reason - a human-readable note telling the moderator WHY two accounts
 * were linked, shown on the Related Members card in ModTools.
 *
 * Until now every row was written by handleRelated() in iznik-server-go when the same
 * browser was seen signed in as more than one account, so "why" was always the same and
 * never needed saying. Once other detectors write here (the first is shared phone
 * numbers in chat, users:detect-related-phone) the mod has no way to judge a pair
 * without being told what the evidence was. NULL means the row predates this column, or
 * came from the browser detector, which the UI words as the session-based reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users_related') || Schema::hasColumn('users_related', 'reason')) {
            return;
        }

        Schema::table('users_related', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->after('detected');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users_related') || !Schema::hasColumn('users_related', 'reason')) {
            return;
        }

        Schema::table('users_related', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
