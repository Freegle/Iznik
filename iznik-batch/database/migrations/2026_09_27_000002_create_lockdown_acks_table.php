<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * lockdown_acks: one row per batch loop recording the newest lockdowns row it has acted on,
 * and when. The Support page reads it to show the presser that each loop has picked up a
 * change, and how long it took. Written once per state change, not per item.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lockdown_acks')) {
            DB::statement("
                CREATE TABLE lockdown_acks (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `loop` VARCHAR(64) NOT NULL,
                    lockdownrowid BIGINT UNSIGNED NOT NULL,
                    seenat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `loop` (`loop`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lockdown_acks');
    }
};
