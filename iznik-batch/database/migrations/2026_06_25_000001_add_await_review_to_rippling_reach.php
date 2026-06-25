<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach: awaiting_review_since + awaiting_review_seconds - earned-reach gate
 * instrumentation (auto-approve x rippling).
 *
 * awaiting_review_since: set to NOW() the first time a post's earned-reach expansion is
 * paused (review weight < required); cleared to NULL when expansion resumes or terminates.
 * awaiting_review_seconds: accumulates the total wall-clock seconds a post spent paused across
 * all pause-then-resume cycles. Both are surfaced on the sysadmin rippling page.
 *
 * Additive only; existing rows read as "not currently paused, zero elapsed". INSTANT adds in
 * MySQL 8.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }
        if (!Schema::hasColumn('rippling_reach', 'awaiting_review_since')) {
            DB::statement(
                'ALTER TABLE rippling_reach'
                . ' ADD COLUMN awaiting_review_since TIMESTAMP NULL DEFAULT NULL,'
                . ' ADD COLUMN awaiting_review_seconds INT UNSIGNED NOT NULL DEFAULT 0'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rippling_reach') && Schema::hasColumn('rippling_reach', 'awaiting_review_since')) {
            DB::statement(
                'ALTER TABLE rippling_reach'
                . ' DROP COLUMN awaiting_review_since,'
                . ' DROP COLUMN awaiting_review_seconds'
            );
        }
    }
};
