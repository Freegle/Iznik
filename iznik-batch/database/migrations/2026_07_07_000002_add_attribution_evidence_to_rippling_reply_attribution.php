<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Graded reply attribution: widen rippling_reply_attribution from the single negative
 * was_home_member bit to separate evidence bits + a derived attribution channel, all
 * snapshotted AT REPLY TIME by the Go reply handler (chat/chatmessage.go).
 *
 * Evidence bits (nullable: NULL = not captured, pre-migration row or evidence unavailable):
 *   was_notified             - (msgid, userid) hit in rippling_reach_notified before the reply:
 *                              we SENT this user the ripple mail for this post. Durable; backfillable.
 *   was_ripple_group_member  - established member (added < the copy's arrival) of a group the
 *                              post rippled INTO by reply time. Durable-ish (leavers decay);
 *                              backfillable, which is why the backfill freezes it.
 *   in_origin_catchment      - replier's location inside an origin group's catchment
 *                              (groups.polyindex). Location drifts, so NOT backfillable.
 *   in_reach                 - replier's location inside the post's rippling_reach polygon at
 *                              reply time. The polygon grows, so NOT backfillable.
 *   post_had_rippled         - the post had rippled at all (a rippled_in copy or a reach row)
 *                              by reply time. Durable; backfillable. The hard guard: when 0,
 *                              the reply can never be ripple-attributed.
 *
 * attribution is the derived channel (the ladder, in precedence order):
 *   home            - established origin-group member (conservative: wins over all ripple evidence)
 *   ripple_notified - we mailed them the post via the ripple
 *   ripple_group    - they saw it in their own group because it rippled in
 *   organic_local   - non-member inside the origin catchment: would have seen it in Browse anyway
 *   ripple_reach    - outside the origin catchment, inside the reach: exposure existed only
 *                     because the ripple extended there (Browse/nearby is reach-fed)
 *   unknown         - none of the above resolvable (search / deep link / share, or no location)
 * NULL = not yet derived (pre-migration row the backfill hasn't visited).
 *
 * client_source is the client-reported surface the reply came from (browse, search,
 * message_page, notification, ...). Advisory only - it is client-supplied and spoofable -
 * so it is NEVER folded into the attribution ladder; it cross-checks it.
 *
 * Bits are stored separately (not just the enum) so the ladder can be re-derived or
 * re-weighted later without losing information.
 *
 * Read by the sysadmin rippling dashboard (reply_source_split in rippling/metrics.go).
 * Backfilled for durable bits by ripple:backfill-reply-attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each column guarded separately: partial prior runs must not wedge the migration.
        if (!Schema::hasColumn('rippling_reply_attribution', 'was_notified')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->boolean('was_notified')->nullable()->after('was_home_member');
            });
        }
        if (!Schema::hasColumn('rippling_reply_attribution', 'was_ripple_group_member')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->boolean('was_ripple_group_member')->nullable()->after('was_notified');
            });
        }
        if (!Schema::hasColumn('rippling_reply_attribution', 'in_origin_catchment')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->boolean('in_origin_catchment')->nullable()->after('was_ripple_group_member');
            });
        }
        if (!Schema::hasColumn('rippling_reply_attribution', 'in_reach')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->boolean('in_reach')->nullable()->after('in_origin_catchment');
            });
        }
        if (!Schema::hasColumn('rippling_reply_attribution', 'post_had_rippled')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->boolean('post_had_rippled')->nullable()->after('in_reach');
            });
        }
        if (!Schema::hasColumn('rippling_reply_attribution', 'attribution')) {
            // New column, so the enum order is ours to choose (no prod divergence risk);
            // ladder precedence order for readability.
            DB::statement(
                "ALTER TABLE rippling_reply_attribution ADD COLUMN attribution " .
                "ENUM('home','ripple_notified','ripple_group','organic_local','ripple_reach','unknown') " .
                "NULL DEFAULT NULL AFTER post_had_rippled"
            );
        }
        if (!Schema::hasColumn('rippling_reply_attribution', 'client_source')) {
            Schema::table('rippling_reply_attribution', function (Blueprint $t) {
                $t->string('client_source', 32)->nullable()->after('attribution');
            });
        }
    }

    public function down(): void
    {
        foreach (['client_source', 'attribution', 'post_had_rippled', 'in_reach',
            'in_origin_catchment', 'was_ripple_group_member', 'was_notified'] as $col) {
            if (Schema::hasColumn('rippling_reply_attribution', $col)) {
                Schema::table('rippling_reply_attribution', function (Blueprint $t) use ($col) {
                    $t->dropColumn($col);
                });
            }
        }
    }
};
