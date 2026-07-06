<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'reachable_group_ids')) {
            Schema::table('rippling_reach', function (Blueprint $t) {
                // JSON array of group IDs that contain at least one road node reachable
                // from the post origin - the water/toll-correct ripple-targeting signal
                // from the routing server (GET /v1/ripple-schedule reachable_group_ids).
                // Nullable: rows written before the reachable-gate rollout have no value,
                // and the gate/retraction treat NULL as "not available", falling back to
                // the polygon only.
                $t->json('reachable_group_ids')->nullable()->after('schedule');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rippling_reach', 'reachable_group_ids')) {
            Schema::table('rippling_reach', function (Blueprint $t) {
                $t->dropColumn('reachable_group_ids');
            });
        }
    }
};
