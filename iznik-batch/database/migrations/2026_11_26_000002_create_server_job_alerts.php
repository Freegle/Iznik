<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * server_job_alerts - which late-job alerts were showing each time servers:sample ran.
 *
 * monitor:scheduled-outcomes keeps only the alerts showing now (config key status.platform).
 * A server that does background work is meant to run flat out, so the question for its size is
 * whether the jobs keep up. servers:sample copies the current alerts here every fifteen minutes,
 * one row per alert, and servers:rightsize-report adds up how long each showed in the week.
 *
 * Rows older than the retention setting are removed by servers:sample.
 *
 * Production SQL: 2026_11_26_000002_create_server_job_alerts_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('server_job_alerts')) {
            return;
        }

        Schema::create('server_job_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('sampled_at')->useCurrent();
            $table->string('slug', 191);
            $table->index('sampled_at', 'server_job_alerts_sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_job_alerts');
    }
};
