<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.overflow_bounds - the overflow lanes' rings for this post.
 *
 * Two lanes, mutually exclusive per post, decided by whether the audience cap actually bound
 * (see iznik-routing-go ripple.go, and section 7c of the rippling algorithm reference):
 *
 *   {"rural": {"dense": "<wkt>", "medium": "<wkt>", "sparse": "<wkt>"}}   cap-bound posts
 *   {"fairness": {"1": "<wkt>", ...}, "weight": 0.5}                     ceiling-bound posts
 *
 * A member outside the committed reach is admitted if they fall inside the ring for their own
 * density band (rural) or their own deprivation fifth (fairness). Neither lane changes which
 * groups the post is copied to.
 *
 * JSON rather than seven geometry columns because these are consulted only in the fallback
 * branch, never on the hot indexed path that polygon/outer_bound/inner_bound serve, so they
 * need no spatial index. Matches the existing convention on this table for
 * reachable_group_ids and rejected_groups, which are plain JSON for the same reason.
 *
 * Nullable, and NULL is the normal state: both lanes ship dark, so nothing writes this until
 * RIPPLE_RURAL_ACCESS_ENABLED or RIPPLE_FAIRNESS_ENABLED is turned on.
 *
 * NOTE FOR ANYONE ADDING A COLUMN HERE LATER: rippling_reach has THREE write paths in
 * ExpandService - the init INSERT, the advanceDue UPDATE and the recomputeReach shrink UPDATE.
 * density_band was added to only the first, which is why it is NULL on ~89% of rows and its
 * analytics are drawn from a biased minority. Write all three or the column is worthless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rippling_reach', 'overflow_bounds')) {
            return;
        }

        Schema::table('rippling_reach', function (Blueprint $table) {
            $table->json('overflow_bounds')->nullable()
                ->comment('Overflow lane rings (rural per band, or fairness per deprivation fifth). NULL unless a lane is enabled.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rippling_reach', 'overflow_bounds')) {
            return;
        }

        Schema::table('rippling_reach', function (Blueprint $table) {
            $table->dropColumn('overflow_bounds');
        });
    }
};
