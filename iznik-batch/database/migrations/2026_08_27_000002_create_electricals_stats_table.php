<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated payload for the public /electricals page.
 *
 * The page's figures are rolling twelve-month aggregates over messages joined to
 * messages_eee, messages_items and messages_outcomes. That is far too heavy to run per
 * request, so eee:stats computes it on a schedule and the API serves the newest row.
 *
 * Deliberately NOT stats_summaries: that table is keyed per group and carries a fixed ENUM
 * of stat types, so using it would mean an ENUM change - a table-rebuilding DDL on a table
 * the dashboards read - to add a national, page-shaped payload it was never meant to hold.
 *
 * One row per generation rather than a single updated row, so a bad run can be diagnosed
 * against its predecessor and the page can be rolled back by deleting a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('electricals_stats')) {
            return;
        }

        Schema::create('electricals_stats', function (Blueprint $table) {
            $table->comment('Generated payload for the public /electricals page');
            $table->bigIncrements('id');
            $table->timestamp('generated_at')->useCurrent()->index('electricals_stats_generated_at');
            $table->json('payload')->comment('The full page payload; shape owned by ElectricalsStatsService');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electricals_stats');
    }
};
