<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend users_digests.mode to cover the daily new-posts push notification.
 *
 * The push cursor is kept in users_digests (mode='push') so that each user's
 * push send advances independently of their email digest cursor, allowing
 * the two delivery paths to run in parallel without cursor interference.
 *
 * 'push' is appended after the existing 'events' and 'volunteering' values,
 * following the same style as 2026_06_11_000001_add_roundup_modes_to_users_digests.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users_digests')) {
            return;
        }

        DB::statement(
            "ALTER TABLE users_digests
             MODIFY COLUMN mode ENUM('immediate','daily','events','volunteering','push')
             NOT NULL DEFAULT 'daily' COMMENT 'Digest mode'"
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('users_digests')) {
            return;
        }

        // Drop any rows using the new mode first so the narrower enum applies cleanly.
        DB::table('users_digests')->where('mode', 'push')->delete();

        DB::statement(
            "ALTER TABLE users_digests
             MODIFY COLUMN mode ENUM('immediate','daily','events','volunteering')
             NOT NULL DEFAULT 'daily' COMMENT 'Digest mode'"
        );
    }
};
