<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_likes.source (rippling reach experiment - Blocker 2). Tags WHERE a genuine
 * page-open (pageview=1) arrived from, so notification-click opens can be distinguished
 * from organic browse within the experiment outcome window. Notification links append
 * ?src=ripple_notify; handleView reads it and stores it here.
 *
 * Nullable: pre-existing / untagged opens are genuinely unknown (NULL), not a known source.
 * Complements messages_likes.pageview (1=open, 0=impression, NULL=legacy).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('messages_likes', 'source')) {
            Schema::table('messages_likes', function (Blueprint $t) {
                $t->string('source', 32)->nullable()->default(null)->after('pageview');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_likes', 'source')) {
            Schema::table('messages_likes', function (Blueprint $t) {
                $t->dropColumn('source');
            });
        }
    }
};
