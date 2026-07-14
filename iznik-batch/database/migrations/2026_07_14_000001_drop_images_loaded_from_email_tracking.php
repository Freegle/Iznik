<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the retired email_tracking.images_loaded column.
 *
 * images_loaded was a denormalised per-hit counter incremented on every tracking-image
 * load. A single digest open loads many images near-simultaneously, and each per-hit
 * UPDATE needed an exclusive lock on the one parent email_tracking row that every
 * concurrent load - plus each load's own FK insert into email_tracking_images - serialised
 * on, causing lock-wait timeouts. The increment was removed in the same change set, and
 * nothing reads the column (front-end, ModTools, and the stats API all ignore it): the
 * per-load rows in email_tracking_images are the source of truth, so the count is
 * derivable there with COUNT(*)/COUNT(DISTINCT image_position). Dropping it removes a
 * now-permanently-zero, misleading column.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('email_tracking', 'images_loaded')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->dropColumn('images_loaded');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('email_tracking', 'images_loaded')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->unsignedSmallInteger('images_loaded')
                    ->default(0)
                    ->after('scroll_depth_percent');
            });
        }
    }
};
