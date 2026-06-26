<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a per-browse-session id to browse_scroll_depth and make it unique, so the
 * client can report scroll depth repeatedly (debounced as it scrolls) and the Go
 * apiv2 handler upserts one row per session keeping GREATEST(max_position) -
 * instead of one INSERT per leave-beacon. Idempotent across repeat sends and
 * re-entry, so a browse session is counted exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('browse_scroll_depth') &&
            !Schema::hasColumn('browse_scroll_depth', 'session')
        ) {
            Schema::table('browse_scroll_depth', function (Blueprint $t) {
                $t->string('session', 64)->nullable()->after('id');
                $t->unique('session', 'bsd_session');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('browse_scroll_depth', 'session')) {
            Schema::table('browse_scroll_depth', function (Blueprint $t) {
                $t->dropUnique('bsd_session');
                $t->dropColumn('session');
            });
        }
    }
};
