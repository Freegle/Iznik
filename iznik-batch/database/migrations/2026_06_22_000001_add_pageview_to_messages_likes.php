<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds messages_likes.pageview to distinguish a genuine page-open from a
     * list-scroll impression:
     *   - pageview = 1  : genuine page-open  (Go handleView writes/upgrades to 1)
     *   - pageview = 0  : list-scroll impression (Go MarkSeen writes 0 on a fresh insert)
     *   - pageview = NULL: legacy / unknown (all pre-migration rows)
     *
     * The 'View' type still marks "seen" for list de-duplication; pageview isolates
     * real eyeballs for the rippling extent governor and the reach experiment.
     *
     * Nullable ON PURPOSE: pre-existing rows never distinguished views from
     * impressions, so they are genuinely unknown (NULL) - not impressions (0).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('messages_likes', 'pageview')) {
            Schema::table('messages_likes', function (Blueprint $table) {
                $table->boolean('pageview')->nullable()->default(null)->after('count');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('messages_likes', 'pageview')) {
            Schema::table('messages_likes', function (Blueprint $table) {
                $table->dropColumn('pageview');
            });
        }
    }
};
