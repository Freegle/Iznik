<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds admins.modguidance - guidance written by Support/Admin for local moderators on how
 * they might adapt a system-wide ADMIN for their community. It lives in its own column and is
 * deliberately never read by the mail sender, so it cannot reach members.
 *
 * Nullable TEXT added with ALGORITHM=INSTANT: a metadata-only change, no table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('admins', 'modguidance')) {
            DB::statement('ALTER TABLE admins ADD COLUMN modguidance TEXT '
                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admins', 'modguidance')) {
            DB::statement('ALTER TABLE admins DROP COLUMN modguidance');
        }
    }
};
