<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps an existing message attachment (photo) to a bulk-offer catalogue item.
 *
 * Replaces the former `messages_attachments.bulkitemid` column so the bulk-offer
 * feature adds NO column to the (multi-million-row) core `messages_attachments`
 * table - keeping the whole feature additive and avoiding any Galera-locking ALTER.
 * One attachment belongs to at most one item (unique attachmentid). The FK is to
 * the feature's own `messages_bulk_items`; there is deliberately no FK to
 * `messages_attachments` (the core table stays untouched), so a deleted attachment
 * may leave a harmless orphan mapping row that simply never matches on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_bulk_item_attachments', function (Blueprint $table) {
            $table->comment('Maps messages_attachments photos to bulk-offer catalogue items.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bulkitemid');
            $table->unsignedBigInteger('attachmentid');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('attachmentid');
            $table->index('bulkitemid');

            $table->foreign('bulkitemid')->references('id')->on('messages_bulk_items')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_bulk_item_attachments');
    }
};
