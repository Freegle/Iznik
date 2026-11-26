<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * whatjobs_clicks_daily - one row per day: the clicks WhatJobs counted for us, beside our own
 * figures for the same day, written by integrations:whatjobs-clicks.
 *
 * The *_clicks columns are copied from the WhatJobs partner dashboard. WhatJobs revises a day
 * for about 48 hours, so the copy is refreshed on every run and first_billable keeps the figure
 * as first seen. our_clicks counts logs_jobs rows (kept 31 days), active_users counts distinct
 * users_active members; both are snapshots, because the source tables are pruned. Rows are kept
 * for good: WhatJobs only shows about a year, and this table is the long history.
 *
 * judged_at is when the day was checked for a sudden change; alert is what the check reported.
 *
 * Production SQL: 2026_11_26_000001_create_whatjobs_clicks_daily_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatjobs_clicks_daily')) {
            return;
        }

        Schema::create('whatjobs_clicks_daily', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date')->unique('whatjobs_clicks_daily_date');
            $table->unsignedInteger('billable_clicks')->nullable();
            $table->unsignedInteger('first_billable_clicks')->nullable();
            $table->unsignedInteger('duplicate_clicks')->nullable();
            $table->unsignedInteger('invalid_clicks')->nullable();
            $table->unsignedInteger('foreign_clicks')->nullable();
            $table->unsignedInteger('latent_clicks')->nullable();
            $table->unsignedInteger('applications')->nullable();
            $table->unsignedInteger('our_clicks')->nullable();
            $table->unsignedInteger('active_users')->nullable();
            $table->timestamp('partner_seen_at')->nullable();
            $table->timestamp('judged_at')->nullable();
            $table->string('alert', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatjobs_clicks_daily');
    }
};
