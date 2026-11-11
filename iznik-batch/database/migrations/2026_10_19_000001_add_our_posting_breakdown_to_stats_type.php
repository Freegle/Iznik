<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * StatsGenerationService writes a daily OurPostingBreakdown row per group (the
 * histogram of members' ourPostingStatus), but the stats.type ENUM never listed
 * that value. The connection is not strict, so MySQL stored '' instead of
 * failing, and 1.8M rows back to 2015 were written that way.
 *
 * Adding the value at the end of the ENUM is ALGORITHM=INSTANT. Every '' row is
 * one of these (the generator writes no other type outside the ENUM), and the
 * unique key is (date, type, groupid) with at most one '' row per date and
 * group, so relabelling them cannot collide.
 */
return new class extends Migration
{
    private const TYPES = "'ApprovedMessageCount','SpamMessageCount','MessageBreakdown','SpamMemberCount','PostMethodBreakdown','YahooDeliveryBreakdown','YahooPostingBreakdown','ApprovedMemberCount','SupportQueries','Happy','Fine','Unhappy','Searches','Activity','Weight','Outcomes','Replies','ActiveUsers'";

    public function up(): void
    {
        DB::statement('ALTER TABLE stats MODIFY `type` ENUM('.self::TYPES.",'OurPostingBreakdown') COLLATE utf8mb4_unicode_ci NOT NULL");

        // Chunked so a production-sized run stays within Galera's write-set limits.
        do {
            $updated = DB::update("UPDATE stats SET `type` = 'OurPostingBreakdown' WHERE `type` = '' LIMIT 10000");
        } while ($updated > 0);
    }

    public function down(): void
    {
        DB::table('stats')->where('type', 'OurPostingBreakdown')->delete();
        DB::statement('ALTER TABLE stats MODIFY `type` ENUM('.self::TYPES.') COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
