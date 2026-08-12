<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.density_band / density_radius_miles / max_minutes_cap - the sizing
 * decision that shaped this post's reach, recorded on the post.
 *
 * A post's reach budget is chosen from how thinly freeglers are spread around its
 * origin (see App\Services\Ripple\DensityService). Without these columns that choice is
 * invisible after the fact - you can see how far a post reached, but not which rule sent
 * it there, and so cannot tell whether shortening cities and lengthening the country
 * helps. Every question the sizing rule raises is a comparison BETWEEN bands, so the
 * band has to be on the row.
 *
 * density_radius_miles is the measurement itself, kept alongside the band so the
 * thresholds can be re-cut against real data without re-measuring. Where fewer than k
 * freeglers were found it is a lower bound (see DensityService::band).
 *
 * max_minutes_cap is what was ASKED for; max_drive_min is what the routing server
 * actually reached. They differ whenever the audience-budget governor or the road
 * network binds first, and that difference is itself worth being able to see.
 *
 * All nullable: a row written by a run where the measurement failed keeps NULL and means
 * "flat cap, unrecorded".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rippling_reach', 'density_band')) {
            return;
        }

        Schema::table('rippling_reach', function (Blueprint $t) {
            $t->string('density_band', 8)->nullable()->after('max_drive_min');
            $t->double('density_radius_miles')->nullable()->after('density_band');
            $t->double('max_minutes_cap')->nullable()->after('density_radius_miles');
        });

        // The whole point is per-band comparison over a window, so the band has to be
        // selectable without a full scan of a table that carries every live post.
        Schema::table('rippling_reach', function (Blueprint $t) {
            $t->index(['density_band', 'created_at'], 'rippling_reach_density_created');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'density_band')) {
            return;
        }

        Schema::table('rippling_reach', function (Blueprint $t) {
            $t->dropIndex('rippling_reach_density_created');
            $t->dropColumn(['density_band', 'density_radius_miles', 'max_minutes_cap']);
        });
    }
};
