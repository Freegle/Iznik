<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which post a collection time belongs to. Trysts were only ever keyed by the two
 * people, so a member promised two things by the same giver could be shown the wrong
 * time. NULL for trysts made before this column existed; readers fall back to the
 * by-user lookup they use today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('trysts') || Schema::hasColumn('trysts', 'msgid')) {
            return;
        }
        DB::statement("ALTER TABLE trysts ADD COLUMN msgid BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Post this collection time is for'");
        DB::statement("ALTER TABLE trysts ADD INDEX trysts_msgid (msgid)");
    }

    public function down(): void
    {
        if (Schema::hasColumn('trysts', 'msgid')) {
            DB::statement("ALTER TABLE trysts DROP INDEX trysts_msgid");
            DB::statement("ALTER TABLE trysts DROP COLUMN msgid");
        }
    }
};
