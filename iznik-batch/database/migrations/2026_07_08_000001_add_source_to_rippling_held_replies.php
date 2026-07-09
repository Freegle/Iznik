<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_held_replies.source — where a held reply originated.
 *
 * Until now only email / TrashNothing replies were ever held (the in-app web path
 * REJECTED out-of-reach replies with 403 not_in_reach). The web path now HOLDS them
 * too, so record the origin to let the sysadmin rippling dashboard break holds down
 * by channel (and to measure how many web replies the old gate was turning away).
 *
 * Existing rows are all email/TN, so default 'email'. Idempotent: guarded by
 * hasColumn so a partial/replayed deploy is a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('rippling_held_replies')) {
            return;
        }
        if (Schema::hasColumn('rippling_held_replies', 'source')) {
            return;
        }

        DB::statement(
            "ALTER TABLE rippling_held_replies
                ADD COLUMN source ENUM('email', 'tn', 'web') NOT NULL DEFAULT 'email' AFTER replieruserid"
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn('rippling_held_replies', 'source')) {
            DB::statement('ALTER TABLE rippling_held_replies DROP COLUMN source');
        }
    }
};
