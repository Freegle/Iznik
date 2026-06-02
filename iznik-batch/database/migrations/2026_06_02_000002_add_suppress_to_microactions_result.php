<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('microactions')) {
            return;
        }

        // Append 'Suppress' so an AIImageReview micro-volunteering vote can say
        // "this item should never have an AI image" (distinct from 'Reject', which
        // means "this generated image is bad"). At quorum the Go API sets the
        // ai_images row to status='suppressed'. End-append is metadata-only;
        // INPLACE/LOCK=NONE is the Galera-safe form for ENUM widening.
        DB::statement("
            ALTER TABLE microactions
              MODIFY COLUMN result ENUM('Approve','Reject','Suppress') NOT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('microactions')) {
            return;
        }

        DB::statement("UPDATE microactions SET result = 'Reject' WHERE result = 'Suppress'");

        DB::statement("
            ALTER TABLE microactions
              MODIFY COLUMN result ENUM('Approve','Reject') NOT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
