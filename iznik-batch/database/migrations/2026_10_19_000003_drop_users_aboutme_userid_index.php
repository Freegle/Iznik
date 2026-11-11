<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users_aboutme.userid is the leading part of userid_2 (userid, timestamp), which
 * serves every lookup it could and also backs the foreign key to users. Dropping
 * it is metadata only.
 *
 * The space the repeated rows used is reclaimed on production by OPTIMIZE TABLE
 * after users:dedupe-aboutme --apply, which is in the PR's deploy steps rather
 * than here: run before the cleanup it would rebuild 3.3 GB under a cluster-wide
 * lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('userid')) {
            DB::statement('ALTER TABLE users_aboutme DROP INDEX userid, ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (! $this->hasIndex('userid')) {
            DB::statement('ALTER TABLE users_aboutme ADD INDEX userid (userid), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    private function hasIndex(string $name): bool
    {
        return DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users_aboutme' AND INDEX_NAME = ?",
            [$name]
        )->n > 0;
    }
};
