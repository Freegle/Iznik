<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many of a multi-quantity post were promised to this person. NULL for promises
 * made before this column existed and for single items, which read as 1. The taken
 * count at outcome time stays in messages_by; this is the earlier allocation the
 * chooser records when a giver splits four chairs between three people.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messages_promises') || Schema::hasColumn('messages_promises', 'count')) {
            return;
        }
        DB::statement("ALTER TABLE messages_promises ADD COLUMN count INT UNSIGNED NULL DEFAULT NULL COMMENT 'How many were promised to this user; NULL reads as 1'");
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_promises', 'count')) {
            DB::statement("ALTER TABLE messages_promises DROP COLUMN count");
        }
    }
};
