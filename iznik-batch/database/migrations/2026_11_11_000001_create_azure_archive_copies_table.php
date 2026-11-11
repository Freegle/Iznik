<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of the copy of V1's archived images from Azure to our own bucket
 * (docs/ops/runbooks/azure-archive-migration.md). One row per archived source row the copier has
 * reached. A row is written BEFORE its blob is fetched, so a crash mid-fetch still counts against
 * the egress budget and a restart never fetches a blob a second time without knowing it.
 *
 * - blobname is the blob in the Azure container and under archive/ in our bucket: img_123.jpg.
 * - azurebytes is every byte Azure sent for this blob, across attempts: it is what the egress
 *   budget sums. NULL while the first fetch is in flight; the budget then assumes the worst.
 * - azuremd5 is Azure's own Content-MD5 for the blob (base64), which the fetched bytes matched.
 * - storedbytes is what went into the bucket after the lossless optimisation.
 *
 * Production SQL: 2026_11_11_000001_create_azure_archive_copies_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('azure_archive_copies')) {
            return;
        }

        Schema::create('azure_archive_copies', function (Blueprint $table) {
            $table->bigIncrements('id');
            // The source table, such as messages_attachments, and the row's id there.
            $table->string('source', 32);
            $table->unsignedBigInteger('sourceid');
            $table->string('blobname', 40);
            $table->enum('status', ['Fetching', 'Copied', 'Missing', 'Failed', 'Superseded']);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('azurebytes')->nullable();
            $table->string('azuremd5', 24)->nullable();
            $table->unsignedInteger('storedbytes')->nullable();
            $table->boolean('optimised')->default(false);
            $table->string('error', 255)->nullable();
            // When the latest fetch started: the egress budget's clock.
            $table->timestamp('fetchedat')->nullable();
            $table->timestamp('copiedat')->nullable();
            $table->timestamp('created')->useCurrent();

            $table->unique('blobname', 'azure_archive_copies_blobname');
            $table->unique(['source', 'sourceid'], 'azure_archive_copies_source');
            $table->index('fetchedat', 'azure_archive_copies_fetchedat');
            $table->index('status', 'azure_archive_copies_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azure_archive_copies');
    }
};
