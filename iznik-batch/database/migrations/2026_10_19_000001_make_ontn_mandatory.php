<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every Freegle community is on TrashNothing. groups.ontn is no longer a
 * per-community choice: it defaults on for new communities, is switched on for
 * every existing one, and ModTools no longer shows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `groups` ALTER COLUMN `ontn` SET DEFAULT 1');
        DB::statement("UPDATE `groups` SET `ontn` = 1 WHERE `type` = 'Freegle' AND `ontn` = 0");
    }

    public function down(): void
    {
        // The communities switched on are not recorded, so only the default is restored.
        DB::statement('ALTER TABLE `groups` ALTER COLUMN `ontn` SET DEFAULT 0');
    }
};
