<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * memberships.added_origin and memberships_history.added_origin: how the membership row was
 * created. Set once, at insert, and never changed. memberships.rippled records that today for
 * ripple joins only, mixed up with whether the membership has since become ordinary; implicit
 * took over that second job, and this takes over the first, for every way of joining.
 *
 * 'unknown' is the default and what every existing row starts as, until the join logs are read
 * back into it. History gets the origin and no implicit, because it records the creation only.
 *
 * Both are added after an existing column with a default, so INSTANT on Percona 8.0. The values
 * are kept in step with Membership::ADDED_ORIGINS by MembershipAddedOriginColumnTest.
 */
return new class extends Migration
{
    private const ORIGINS = "'manual','posted','replied','subscribed','rippled','moderator','partner','tn_unaddressed','signup','unknown'";

    public function up(): void
    {
        if (! Schema::hasColumn('memberships', 'added_origin')) {
            DB::statement('ALTER TABLE memberships '
                .'ADD COLUMN added_origin ENUM('.self::ORIGINS.") NOT NULL DEFAULT 'unknown' AFTER implicit, "
                .'ALGORITHM=INSTANT');
        }

        if (! Schema::hasColumn('memberships_history', 'added_origin')) {
            DB::statement('ALTER TABLE memberships_history '
                .'ADD COLUMN added_origin ENUM('.self::ORIGINS.") NOT NULL DEFAULT 'unknown' AFTER rippled, "
                .'ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('memberships_history', 'added_origin')) {
            DB::statement('ALTER TABLE memberships_history DROP COLUMN added_origin');
        }

        if (Schema::hasColumn('memberships', 'added_origin')) {
            DB::statement('ALTER TABLE memberships DROP COLUMN added_origin');
        }
    }
};
