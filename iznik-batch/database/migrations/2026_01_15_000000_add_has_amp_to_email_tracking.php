<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds missing columns to email_tracking table to match Go API model.
     * These columns enable:
     * - has_amp: Track which emails include AMP content (for ModTools stats)
     * - replied_at/replied_via: Track email replies
     * - bounce_type: Categorize bounce reasons
     * - opened_via: How the email was opened (pixel, click, etc)
     * - clicked_link: Which link was clicked
     * - scroll_depth_percent: Estimated scroll depth
     * - images_loaded/links_clicked: Engagement counters
     */
    public function up(): void
    {
        if (!Schema::hasTable('email_tracking')) {
            return;
        }

        Schema::table('email_tracking', function (Blueprint $table) {
            // Add columns only if they don't exist
            if (!Schema::hasColumn('email_tracking', 'bounce_type')) {
                $table->string('bounce_type', 50)->nullable()->after('bounced_at');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'opened_via')) {
                $table->string('opened_via', 50)->nullable()->after('opened_at');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'clicked_link')) {
                $table->string('clicked_link', 500)->nullable()->after('clicked_at');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'scroll_depth_percent')) {
                $table->unsignedTinyInteger('scroll_depth_percent')->nullable()->after('clicked_link');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'images_loaded')) {
                $table->unsignedSmallInteger('images_loaded')->default(0)->after('scroll_depth_percent');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'links_clicked')) {
                $table->unsignedSmallInteger('links_clicked')->default(0)->after('images_loaded');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'has_amp')) {
                $table->boolean('has_amp')->default(false)->after('unsubscribed_at');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'replied_at')) {
                $table->timestamp('replied_at')->nullable()->after('has_amp');
            }
        });

        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'replied_via')) {
                $table->string('replied_via', 50)->nullable()->after('replied_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = [
            'bounce_type',
            'opened_via',
            'clicked_link',
            'scroll_depth_percent',
            'images_loaded',
            'links_clicked',
            'has_amp',
            'replied_at',
            'replied_via',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('email_tracking', $column)) {
                Schema::table('email_tracking', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
