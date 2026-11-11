<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily UX telemetry rollups, written by ux:rollup-daily and read by the ModTools SysAdmin UX tab.
 *
 * The browser's flow, interaction and impression events go to Loki as source="client", which keeps
 * them for seven days. These tables keep the daily counts and percentiles for years, so a step can
 * be compared with last month. Nothing here is per member: every row is a count over a day and a
 * handful of dimensions. ux_sample_sessions is the exception that proves it, holding session ids
 * that point back into Loki and are deleted once Loki no longer has the lines.
 *
 * Dimension columns are NOT NULL with '' defaults so that the unique keys hold and the rollup can
 * upsert a day idempotently. The event contract these are computed from is
 * docs/developers/reference/ux-telemetry.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ux_flow_daily')) {
            Schema::create('ux_flow_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('flow', 32);
                $t->string('step', 64);
                $t->string('device_class', 16)->default('');
                $t->boolean('logged_in')->default(false);
                $t->boolean('is_app')->default(false);
                $t->string('variant', 64)->default('');
                $t->unsignedInteger('entered')->default(0);
                $t->unsignedInteger('reentered')->default(0);
                $t->unsignedInteger('completed')->default(0);
                $t->unsignedInteger('backed')->default(0);
                $t->unsignedInteger('abandoned')->default(0);
                $t->unsignedInteger('revisits')->default(0);
                $t->unsignedInteger('validation_failures')->default(0);
                $t->unsignedInteger('help_sought')->default(0);
                $t->unsignedInteger('dwell_p50_ms')->nullable();
                $t->unsignedInteger('dwell_p90_ms')->nullable();
                $t->unique(['date', 'flow', 'step', 'device_class', 'logged_in', 'is_app', 'variant'], 'ux_flow_daily_key');
            });
        }

        if (!Schema::hasTable('ux_flow_outcome_daily')) {
            Schema::create('ux_flow_outcome_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('flow', 32);
                $t->string('device_class', 16)->default('');
                $t->boolean('logged_in')->default(false);
                $t->boolean('is_app')->default(false);
                $t->string('variant', 64)->default('');
                $t->unsignedInteger('started')->default(0);
                $t->unsignedInteger('submitted')->default(0);
                $t->unsignedInteger('submit_ok')->default(0);
                $t->unsignedInteger('submit_failed')->default(0);
                $t->unsignedInteger('completed')->default(0);
                $t->unsignedInteger('abandoned')->default(0);
                $t->unsignedInteger('resumed')->default(0);
                $t->unsignedInteger('total_dwell_p50_ms')->nullable();
                $t->unsignedInteger('total_dwell_p90_ms')->nullable();
                $t->unique(['date', 'flow', 'device_class', 'logged_in', 'is_app', 'variant'], 'ux_flow_outcome_daily_key');
            });
        }

        if (!Schema::hasTable('ux_field_daily')) {
            Schema::create('ux_field_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('flow', 32);
                $t->string('step', 64);
                $t->string('field', 64);
                $t->string('device_class', 16)->default('');
                $t->unsignedInteger('revisits')->default(0);
                $t->unsignedInteger('validation_failures')->default(0);
                $t->unsignedInteger('choice_switches')->default(0);
                $t->unique(['date', 'flow', 'step', 'field', 'device_class'], 'ux_field_daily_key');
            });
        }

        if (!Schema::hasTable('ux_component_daily')) {
            Schema::create('ux_component_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('page', 64);
                $t->string('component', 96);
                $t->string('device_class', 16)->default('');
                $t->unsignedInteger('impressions')->default(0);
                $t->unsignedInteger('clicks')->default(0);
                $t->unsignedInteger('dead_clicks')->default(0);
                $t->unsignedInteger('rage_clicks')->default(0);
                $t->unsignedInteger('repeated_intent')->default(0);
                $t->unique(['date', 'page', 'component', 'device_class'], 'ux_component_daily_key');
            });
        }

        if (!Schema::hasTable('ux_feature_adoption_daily')) {
            Schema::create('ux_feature_adoption_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('feature', 64);
                $t->unsignedInteger('active_users')->default(0);
                $t->unsignedInteger('using_users')->default(0);
                $t->unsignedInteger('sessions_using')->default(0);
                $t->unique(['date', 'feature'], 'ux_feature_adoption_daily_key');
            });
        }

        if (!Schema::hasTable('ux_search_daily')) {
            Schema::create('ux_search_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->unsignedInteger('searches')->default(0);
                $t->unsignedInteger('zero_result')->default(0);
                $t->unsignedInteger('re_searched_60s')->default(0);
                $t->unsignedInteger('browse_sessions')->default(0);
                $t->unsignedInteger('browse_scrolled_out')->default(0);
                $t->unique(['date'], 'ux_search_daily_key');
            });
        }

        if (!Schema::hasTable('ux_regret_daily')) {
            Schema::create('ux_regret_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('signal', 32);
                $t->unsignedInteger('count')->default(0);
                $t->unsignedInteger('base')->default(0);
                $t->unique(['date', 'signal'], 'ux_regret_daily_key');
            });
        }

        if (!Schema::hasTable('ux_telemetry_health_daily')) {
            Schema::create('ux_telemetry_health_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('metric', 96);
                $t->decimal('expected', 14, 2)->nullable();
                $t->decimal('observed', 14, 2)->default(0);
                $t->decimal('ratio', 8, 3)->nullable();
                $t->unique(['date', 'metric'], 'ux_telemetry_health_daily_key');
            });
        }

        if (!Schema::hasTable('ux_sample_sessions')) {
            Schema::create('ux_sample_sessions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('flow', 32);
                $t->string('step', 64);
                $t->string('signal', 32);
                $t->string('session_id', 64);
                $t->index(['date', 'flow', 'step'], 'ux_sample_sessions_lookup');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'ux_sample_sessions', 'ux_telemetry_health_daily', 'ux_regret_daily', 'ux_search_daily',
            'ux_feature_adoption_daily', 'ux_component_daily', 'ux_field_daily', 'ux_flow_outcome_daily',
            'ux_flow_daily',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
