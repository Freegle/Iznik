<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the ripple_join attribution channel, and the evidence bit behind it.
 *
 * Rippling auto-joins a poster to every group their post rippled into (memberships.rippled = 1,
 * see ExpandService::addPosterMembershipToRippledGroups). When one of those groups later hosts a
 * post of its own and that member replies, every "was already a member" test counted them as an
 * established local member and the reply landed in `home` - the bucket that means "would have seen
 * it anyway, rippling gets no credit". But that membership exists ONLY because of an earlier
 * ripple, so without rippling they would never have seen the post at all. The effect was to hand
 * rippling's own downstream reach to the home column and understate its effectiveness.
 *
 *   was_ripple_join - established member of an ORIGIN group of this post via a RIPPLE-CREATED
 *                     membership only (no ordinary membership of any of its origin groups).
 *                     Durable-ish (leavers/retractions decay it), so the capture freezes it and
 *                     the backfill can reconstruct it for legacy rows.
 *
 * ripple_join sits in the ladder immediately below ripple_group: both are membership-level
 * exposure that exists because of a ripple, and both outrank organic_local for the same reason
 * ripple_group already does - a member sees the post in their own feed and digest, which is
 * stronger evidence than "might have come across it in Browse".
 *
 * ENUM values can only be appended in place on a live table without a rebuild, so ripple_join
 * goes on the END rather than in ladder position - the order of the enum has never carried
 * meaning here (readers match on the string). See the reply capture in chat/chatmessage.go and
 * the ladder in rippling/attribution.go.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rippling_reply_attribution', 'was_ripple_join')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->boolean('was_ripple_join')->nullable()->after('was_ripple_group_member');
            });
        }

        // Appended, not reordered: adding a value at the end of an ENUM is metadata-only in
        // MySQL 8, whereas inserting one mid-list rewrites every row.
        $type = DB::selectOne(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rippling_reply_attribution'
               AND COLUMN_NAME = 'attribution'"
        );
        if ($type && !str_contains($type->t, "'ripple_join'")) {
            DB::statement(
                "ALTER TABLE rippling_reply_attribution MODIFY COLUMN attribution " .
                "ENUM('home','ripple_notified','ripple_group','organic_local','ripple_reach'," .
                "'unknown','ripple_join') NULL DEFAULT NULL"
            );
        }
    }

    public function down(): void
    {
        // Rows already carrying the new value would become '' on a narrowing MODIFY, so fold
        // them back to the bucket they used to sit in before dropping the value.
        DB::statement("UPDATE rippling_reply_attribution SET attribution = 'home' WHERE attribution = 'ripple_join'");
        DB::statement(
            "ALTER TABLE rippling_reply_attribution MODIFY COLUMN attribution " .
            "ENUM('home','ripple_notified','ripple_group','organic_local','ripple_reach','unknown') " .
            "NULL DEFAULT NULL"
        );

        if (Schema::hasColumn('rippling_reply_attribution', 'was_ripple_join')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->dropColumn('was_ripple_join');
            });
        }
    }
};
