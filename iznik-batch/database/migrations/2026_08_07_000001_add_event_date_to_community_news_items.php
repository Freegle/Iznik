<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a community-news item's event actually happens, so the weekly
 * newsletter can leave out the ones already over.
 *
 * Research runs hourly; the newsletter goes out on Fridays, and an item stays
 * eligible for `item_freshness_days` (10 on live) after it was researched. So a
 * jumble sale found on a Monday and held on the Wednesday was still perfectly
 * "fresh" on Friday, and went out inviting people to something that had already
 * happened. Nothing in the table said when the event was, so nothing could
 * filter it: `researched_at` records when WE looked, which says nothing about
 * when the event IS.
 *
 * Nullable because most items are not dated events at all — a new cycle path, a
 * library refurbishment — and those must keep flowing through untouched. Only
 * an item with a date in the past is held back.
 *
 * A DATE, not a datetime: what matters is whether the day has passed, and the
 * research only ever recovers day-level precision from a listing anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('community_news_items')) {
            return;
        }
        if (Schema::hasColumn('community_news_items', 'event_date')) {
            return;
        }

        Schema::table('community_news_items', function (Blueprint $t) {
            $t->date('event_date')->nullable()->after('source');
            // The newsletter filters on (area, not yet emailed, event not past),
            // so the date rides along with the existing lookup index.
            $t->index(['areaid', 'event_date']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('community_news_items')) {
            return;
        }
        if (!Schema::hasColumn('community_news_items', 'event_date')) {
            return;
        }

        Schema::table('community_news_items', function (Blueprint $t) {
            $t->dropIndex(['areaid', 'event_date']);
            $t->dropColumn('event_date');
        });
    }
};
