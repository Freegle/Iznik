<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds A/B-experiment, journey-segment and effectiveness-tracking columns to the
 * `reengage` sequence table so the win-back flow can (a) run a controlled
 * experiment between copy variants with a real holdout arm, (b) be segmented by
 * the user's first action when they joined, and (c) have its effectiveness
 * (opens, clicks, actual re-engagement) graphed in the sysadmin dashboard.
 *
 * All columns are nullable so pre-existing rows and the default (experiment-off)
 * behaviour are unaffected — the feature stays dark until an operator opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reengage')) {
            return;
        }

        Schema::table('reengage', function (Blueprint $table) {
            // Experiment arm assignment (stable per user across all 3 stages).
            if (! Schema::hasColumn('reengage', 'experiment')) {
                $table->string('experiment', 32)->nullable()->after('template');
            }
            if (! Schema::hasColumn('reengage', 'arm')) {
                // 'control' (held out, no mail) | 'a' | 'b' | ...
                $table->string('arm', 16)->nullable()->after('experiment');
            }
            if (! Schema::hasColumn('reengage', 'bucket')) {
                // Raw deterministic bucket 0-99, kept for audit/debugging.
                $table->unsignedTinyInteger('bucket')->nullable()->after('arm');
            }
            // User-journey segment captured AT SEND time (users.* mutates later).
            if (! Schema::hasColumn('reengage', 'segment')) {
                // 'offer' | 'wanted' | 'replier' | 'other'
                $table->string('segment', 16)->nullable()->after('bucket');
            }
            // Join key into the shared email_tracking table for opens/clicks.
            if (! Schema::hasColumn('reengage', 'email_tracking_id')) {
                $table->unsignedBigInteger('email_tracking_id')->nullable()->after('segment');
            }
            // Actual re-engagement outcome — written by mail:reengage-outcomes from
            // the precise `logs`/`messages` event trail (not the mutable lastaccess).
            if (! Schema::hasColumn('reengage', 'reengaged_at')) {
                $table->timestamp('reengaged_at')->nullable()->after('outcome');
            }
            if (! Schema::hasColumn('reengage', 'reengaged_via')) {
                // 'login' | 'reply' | 'post'
                $table->string('reengaged_via', 16)->nullable()->after('reengaged_at');
            }
        });

        // Indexes (guarded via information_schema — MySQL has no IF NOT EXISTS
        // for ADD INDEX pre-8.0.29, and Laravel 11 dropped the Doctrine schema
        // manager we'd otherwise use to introspect them).
        $hasIndex = static function (string $index): bool {
            return DB::table('information_schema.statistics')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', 'reengage')
                ->where('index_name', $index)
                ->exists();
        };

        Schema::table('reengage', function (Blueprint $table) use ($hasIndex) {
            if (! $hasIndex('experiment_arm_segment')) {
                $table->index(['experiment', 'arm', 'segment'], 'experiment_arm_segment');
            }
            if (! $hasIndex('reengage_email_tracking_id')) {
                $table->index('email_tracking_id', 'reengage_email_tracking_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reengage')) {
            return;
        }

        Schema::table('reengage', function (Blueprint $table) {
            foreach (['experiment_arm_segment', 'reengage_email_tracking_id'] as $idx) {
                try {
                    $table->dropIndex($idx);
                } catch (\Throwable $e) {
                    // best effort
                }
            }
            foreach (['experiment', 'arm', 'bucket', 'segment', 'email_tracking_id', 'reengaged_at', 'reengaged_via'] as $col) {
                if (Schema::hasColumn('reengage', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
