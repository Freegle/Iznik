<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress of the one-off copy of legacy uploads from the NFS share to object
 * storage (images:migrate-legacy).
 *
 * The legacy store is one flat directory of ~2M files that must never be
 * listed (see .claude/rules/dev-containers.md), so the copy is driven by the
 * database instead: each source is a table (and column) holding tusd upload
 * ids, walked by primary key. One row per source keeps the cursor and the
 * running counts, so a run can stop on its time budget and the next one
 * carries on. The verify pass has its own cursor so it can be re-run without
 * disturbing the copy.
 *
 * Nothing here is per file: 1.8M rows in a replicated table for a temporary
 * job would be the wrong trade. The object store itself is the record of
 * what has been copied.
 *
 * Production SQL: 2026_09_28_000001_create_image_store_migration_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('image_store_migration')) {
            return;
        }

        Schema::create('image_store_migration', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Table name, or table:column where a table has more than one
            // upload-id column (ai_images:pending_externaluid).
            $table->string('source', 96)->unique();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->unsignedBigInteger('verify_last_id')->default(0);
            $table->unsignedBigInteger('copied')->default(0);
            $table->unsignedBigInteger('present')->default(0);
            $table->unsignedBigInteger('missing_source')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('verify_missing')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verify_completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_store_migration');
    }
};
