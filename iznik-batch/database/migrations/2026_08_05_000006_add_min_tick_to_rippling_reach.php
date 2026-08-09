<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.min_tick - a floor the expander must not sit below.
 *
 * Expansion is normally driven by elapsed time alone: the hazard schedule says
 * which tick a post should be on by now, and the expander advances to it. That
 * is right when nothing has been learned since the post went up.
 *
 * A scout reply IS something learned. Scouts are mailed to people OUTSIDE the
 * current reach precisely because the ripple has not got to them yet; if one of
 * them replies, that is evidence the item is wanted at that distance, and the
 * people around them deserve the same chance rather than waiting for the clock.
 * So the scout's own tick becomes a floor, and the next expansion jumps to it.
 *
 * A floor rather than a direct polygon write, deliberately: advancing reach
 * means resolving the tick's geometry, unioning the origin group's area,
 * deriving bounds, re-applying rejected-group clips and upgrading routing
 * bounds - all of which ExpandService already does carefully. Writing the
 * polygon from here would be that same geometry implemented twice, which is the
 * mistake this codebase has paid for before.
 *
 * Nullable: almost every row will never have one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rippling_reach', 'min_tick')) {
            return;
        }

        Schema::table('rippling_reach', function (Blueprint $t) {
            $t->unsignedSmallInteger('min_tick')->nullable()->after('tick');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'min_tick')) {
            return;
        }

        Schema::table('rippling_reach', function (Blueprint $t) {
            $t->dropColumn('min_tick');
        });
    }
};
