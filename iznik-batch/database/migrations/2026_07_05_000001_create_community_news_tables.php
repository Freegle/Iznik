<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Community News — area-based local-news digest (see docs/COMMUNITY-NEWS.md).
 *
 * community_news_areas: one row per "area" — a cluster of neighbouring Freegle
 * communities that have the `communitynews` group setting enabled, unioned by
 * centre-distance so a small town (Edinburgh, Oxford) stands alone while dense
 * boroughs merge into one sensible research/dedup unit. Keyed by `anchorgroupid`
 * (the lowest enabled groupid in the cluster) so re-clustering upserts the same
 * row and keeps its cadence timers stable.
 *
 * community_news_items: the researched nuggets for an area. Each item is
 * drip-posted to ChitChat (the newsfeed) as the Freegle account during the
 * engagement trial (`newsfeedid` / `posted_at`) and/or bundled into the weekly
 * branded email (`emailed_at`).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('community_news_areas')) {
            Schema::create('community_news_areas', function (Blueprint $t) {
                $t->bigIncrements('id');

                // Lowest enabled groupid in the cluster — stable natural key so a
                // re-cluster updates the same area row (and its cadence timers).
                $t->unsignedBigInteger('anchorgroupid');

                // Human-readable label for the area (best-effort from group names).
                $t->string('name');

                // The latest warm/quirky intro blurb from the researcher, reused
                // as the email's opening (per-run, refreshed on each research).
                $t->text('intro')->nullable();

                // Area centre (degrees) — used to place ChitChat posts and to give
                // the researcher a geographic anchor.
                $t->double('lat');
                $t->double('lng');

                // JSON array of the groupids that make up this area.
                $t->longText('groupids');
                $t->unsignedInteger('groupcount')->default(0);

                // Cadence timers.
                $t->timestamp('lastresearched')->nullable();
                $t->timestamp('lastposted')->nullable();   // last ChitChat drip
                $t->timestamp('lastemailed')->nullable();   // last weekly email

                $t->timestamps();

                $t->unique('anchorgroupid');
                $t->index('lastresearched');
                $t->index('lastposted');
                $t->index('lastemailed');
            });
        }

        if (!Schema::hasTable('community_news_items')) {
            Schema::create('community_news_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('areaid');

                $t->string('title');
                $t->text('snippet');          // friendly blurb
                $t->string('url', 1024)->nullable();
                $t->string('source')->nullable();

                $t->timestamp('researched_at')->nullable();

                // ChitChat (newsfeed) trial linkage.
                $t->unsignedBigInteger('newsfeedid')->nullable();
                $t->timestamp('posted_at')->nullable();

                // Weekly email linkage.
                $t->timestamp('emailed_at')->nullable();

                $t->timestamps();

                $t->foreign('areaid')->references('id')->on('community_news_areas')->onDelete('cascade');
                $t->index(['areaid', 'posted_at']);
                $t->index(['areaid', 'emailed_at']);
                $t->index('newsfeedid');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('community_news_items');
        Schema::dropIfExists('community_news_areas');
    }
};
