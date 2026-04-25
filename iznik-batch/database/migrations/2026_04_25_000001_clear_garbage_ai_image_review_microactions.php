<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The AIImageReview micro-volunteering challenge had a broken URL from day one:
     * the Go API built `IMAGES_HOST/freegletusd-<id>` which always returned HTTP 404.
     * Users saw a default profile placeholder, not the AI image, so all votes cast
     * before the URL fix (PR #277) are garbage (100% Reject, 100% containsPeople=No).
     *
     * This migration removes all AIImageReview microactions so images can be cleanly
     * re-reviewed once the correct URL is in production.
     */
    public function up(): void
    {
        DB::table('microactions')->where('actiontype', 'AIImageReview')->delete();
    }

    public function down(): void
    {
        // Cannot restore deleted votes.
    }
};
