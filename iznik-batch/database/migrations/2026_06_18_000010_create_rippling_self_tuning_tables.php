<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §16 self-tuning loop (PR G). Three tables:
 *
 *  - rippling_live_metrics: periodic rollup of the rippling outcome/friction metrics, stored
 *    long-form (period x stratum x metric -> value) so new metrics need no schema change. The
 *    "overall" stratum is the aggregate picture; ons/imd/group strata give the equity + local view.
 *
 *  - rippling_hotspots: geographically-UNUSUAL areas - groups whose metric deviates far from the
 *    population (robust median+MAD modified z-score). This is what catches a local problem the
 *    aggregate average hides (e.g. one area rejecting almost every rippled-in post).
 *
 *  - rippling_params: per-ONS-category tuned parameters the engine can read (closed loop). The
 *    tuner writes PROPOSED rows (advisory mode); a human promotes them to active.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_live_metrics')) {
            Schema::create('rippling_live_metrics', function ($t) {
                $t->id();
                $t->date('period_start');
                $t->enum('period_type', ['daily', 'weekly'])->default('weekly');
                $t->string('stratum_type', 16)->default('overall'); // overall|ons|imd|group
                $t->string('stratum_key', 64)->default('all');
                $t->string('metric', 48);     // volume_posts, reach_drive_min, secondary_reject_rate, ...
                $t->double('value');
                $t->integer('sample_size')->default(0);
                $t->timestamp('created_at')->useCurrent();
                $t->unique(
                    ['period_start', 'period_type', 'stratum_type', 'stratum_key', 'metric'],
                    'rippling_live_metrics_uniq'
                );
            });
        }

        if (!Schema::hasTable('rippling_hotspots')) {
            Schema::create('rippling_hotspots', function ($t) {
                $t->id();
                $t->timestamp('detected_at')->useCurrent();
                $t->date('period_start');
                $t->string('area_type', 16)->default('group'); // group|lsoa|cell
                $t->unsignedBigInteger('area_id')->nullable();
                $t->string('area_name', 128)->nullable();
                $t->string('metric', 48);
                $t->double('value');
                $t->double('baseline');             // population median
                $t->double('deviation');            // modified z-score (MAD units)
                $t->enum('direction', ['high', 'low']);
                $t->enum('severity', ['watch', 'alert'])->default('watch');
                $t->index(['period_start']);
                $t->index(['area_type', 'area_id']);
                $t->index(['metric']);
            });
        }

        if (!Schema::hasTable('rippling_params')) {
            Schema::create('rippling_params', function ($t) {
                $t->id();
                $t->string('ons_category', 32);     // e.g. urban_major, rural, or 'default'
                $t->string('curve', 24)->nullable();
                $t->integer('max_minutes')->nullable();
                $t->integer('target_density')->nullable();
                $t->json('hazard_schedule')->nullable();
                $t->enum('status', ['active', 'proposed', 'rejected'])->default('proposed');
                $t->string('rationale', 255)->nullable();
                $t->timestamp('proposed_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->timestamp('updated_at')->useCurrent();
                $t->unique(['ons_category', 'status'], 'rippling_params_uniq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_hotspots');
        Schema::dropIfExists('rippling_live_metrics');
        Schema::dropIfExists('rippling_params');
    }
};
