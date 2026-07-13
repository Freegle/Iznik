<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job/result cache for the sysadmin rippling drive-time analytics.
 *
 * The ~250-call routing pass takes tens of seconds — longer than the production gateway timeout —
 * so it can't run inside the request (it 504s, which surfaces as a misleading CORS error). apiv2
 * (rippling/analytics.go) instead runs it as a background job whose progress + final JSON payload
 * live here, keyed by (stratum|start|end). The row is the single source of truth so that across
 * the multi-node prod apiv2 exactly one caller computes and the rest just poll for progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rippling_drivetime_cache')) {
            return;
        }

        Schema::create('rippling_drivetime_cache', function (Blueprint $table) {
            $table->string('cache_key', 120)->primary(); // "<stratum>|<start>|<end>"
            $table->string('status', 16)->default('computing'); // 'computing' | 'done'
            $table->integer('progress')->default(0);            // posts scored so far
            $table->integer('total')->default(0);               // posts in the sample
            $table->mediumText('result')->nullable();           // full JSON response payload when done
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_drivetime_cache');
    }
};
