<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_images')) {
            return;
        }

        // Append 'suppressed' so a reviewer can permanently mark an item NAME as
        // "should never have an AI image" (e.g. abstract items like cash/lift/voucher
        // where a generated photo is misleading).
        //
        // Unlike 'rejected' — which is part of the reversible rejected -> regenerating
        // -> active flow ("this image is bad, make a better one") — 'suppressed' is
        // TERMINAL: the Pollinations generator skips the name and Regenerate refuses.
        //
        // End-append keeps the enum at 1 byte of storage. ALGORITHM=INPLACE, LOCK=NONE
        // is the Galera-safe form for ENUM widening (INSTANT is not supported for ENUM
        // modification on Percona/Galera — see reference_galera_enum_alter). The DDL is
        // metadata-only; existing rows are untouched.
        DB::statement("
            ALTER TABLE ai_images
              MODIFY COLUMN status
                ENUM('active','rejected','regenerating','suppressed')
                NOT NULL DEFAULT 'active',
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_images')) {
            return;
        }

        // Coerce suppressed rows back to 'rejected' before narrowing, else the ALTER
        // fails on rows whose value is no longer in the enum.
        DB::statement("UPDATE ai_images SET status = 'rejected' WHERE status = 'suppressed'");

        DB::statement("
            ALTER TABLE ai_images
              MODIFY COLUMN status
                ENUM('active','rejected','regenerating')
                NOT NULL DEFAULT 'active',
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
