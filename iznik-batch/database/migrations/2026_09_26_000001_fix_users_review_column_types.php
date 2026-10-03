<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a type bug in 2026_09_20_000001_remove_group_model: users.reviewrequestedat,
 * reviewreason and reviewedat were added as BIGINT UNSIGNED NULL (copy-pasted from the
 * adjacent bannedby/modconfigid lines), but the legacy per-membership columns they
 * replace are timestamp/string/timestamp (see 2025_12_10_094529_create_memberships_table,
 * lines 34-36), and application code already reads and writes them that way:
 * user/user.go's CheckLocationChangeVelocity assigns a free-text reason string to
 * reviewreason and NOW() to reviewrequestedat/reviewedat, and session.go's spammembers
 * dashboard count compares reviewrequestedat/reviewedat with > and >=.
 *
 * reviewrequestedat/reviewedat as BIGINT happened to not error (MySQL casts a DATETIME
 * assigned to an integer column to its YYYYMMDDHHMMSS numeric form, which still compares
 * and sorts correctly), but reviewreason as BIGINT silently discards the human-readable
 * reason a moderator needs to see on the Flagged members screen (a non-numeric string
 * assigned to an integer column becomes 0, or errors under strict SQL mode). This
 * migration corrects the three columns to match their proven legacy types.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        DB::statement("
            ALTER TABLE users
              MODIFY COLUMN reviewrequestedat TIMESTAMP NULL,
              MODIFY COLUMN reviewreason VARCHAR(255) NULL,
              MODIFY COLUMN reviewedat TIMESTAMP NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        DB::statement("
            ALTER TABLE users
              MODIFY COLUMN reviewrequestedat BIGINT UNSIGNED NULL,
              MODIFY COLUMN reviewreason BIGINT UNSIGNED NULL,
              MODIFY COLUMN reviewedat BIGINT UNSIGNED NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
