<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ripple_algorithm_metrics — weekly Layer-3 monitoring of the ripple
 * notification algorithm.
 *
 * Each row is one (week, group, curve) tuple from a run of the simulator
 * against the previous week's historical post/reply data.  Trend dashboards
 * read this table; drift alerts compare week-over-week deltas.
 *
 * See plans/reference/ripple-curve-evaluation.md for what the metrics mean.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ripple_algorithm_metrics', function (Blueprint $t) {
            $t->id();

            // The Monday of the week the simulator was run for (extraction
            // covered the 7 days ending the previous Sunday).
            $t->date('week_start')->index();

            // Geographic stratification.  'all' = no stratification; otherwise
            // 'urban' / 'rural' / specific ONS RU category ('A1'..'F2').
            $t->string('group', 32);

            // Curve under simulation, e.g. 'linear', 'front-heavy', 'front-cubic+stop@3'.
            $t->string('curve', 64);

            // Sample sizes.
            $t->unsignedInteger('pairs_total');         // (post, replier) pairs seen
            $t->unsignedInteger('pairs_in_time');        // notified before they replied
            $t->unsignedInteger('pairs_late');           // notified after they replied
            $t->unsignedInteger('pairs_unreachable');    // beyond max isochrone

            // Notification volume across all simulated posts.
            $t->unsignedBigInteger('notify_vol');

            // Algorithm config used for the run.  Persisted alongside the
            // metric so a future config change doesn't make old rows ambiguous.
            $t->unsignedSmallInteger('ticks');
            $t->float('lifetime_days');
            $t->float('max_minutes');

            // Optional summary statistics on the underlying historical data —
            // useful for spotting user-behaviour drift independent of the
            // algorithm itself.
            $t->float('reply_p50_hours')->nullable();
            $t->float('reply_p75_hours')->nullable();
            $t->float('replier_rank_p50')->nullable();
            $t->float('replier_drive_min_p75')->nullable();

            $t->timestamp('created_at')->useCurrent();

            $t->unique(['week_start', 'group', 'curve', 'ticks', 'lifetime_days', 'max_minutes'], 'ripple_metrics_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ripple_algorithm_metrics');
    }
};
