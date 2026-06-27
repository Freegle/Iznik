<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * memberships_history.rippled - marks the history row written for a ripple auto-join (Rippling
 * Out, PR #855). MembershipsProcessingService reads this to SUPPRESS the per-group welcome email
 * for rippled joins (a single bundled intro email is sent instead). The history row still has
 * processingrequired=1 so abuse detection still runs; only the welcome is suppressed. Flagging
 * the history row (not the membership) is robust: the membership could be deleted by the time the
 * async processing runs.
 *
 * NOT NULL DEFAULT 0 -> existing rows read as not-rippled. Trailing bool -> INSTANT add in MySQL 8.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('memberships_history', 'rippled')) {
            Schema::table('memberships_history', function (Blueprint $t) {
                $t->boolean('rippled')->default(false)->after('processingrequired');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('memberships_history', 'rippled')) {
            Schema::table('memberships_history', function (Blueprint $t) {
                $t->dropColumn('rippled');
            });
        }
    }
};
