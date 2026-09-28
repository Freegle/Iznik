<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the progress table of the one-off copy of legacy uploads from the NFS
 * share to object storage. The copy is verified complete and the share is
 * retired, so nothing reads or writes it any more.
 *
 * Production SQL: 2026_09_29_000001_drop_image_store_migration_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('image_store_migration');
    }

    public function down(): void
    {
        // Not recreated: the command that used it is gone.
    }
};
