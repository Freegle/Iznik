<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `bulkitemid` to `messages_attachments` so a photo in a bulk-offer listing
 * can belong to a specific catalogue item (messages_bulk_items). NULL preserves
 * the existing post-level behaviour for ordinary single-item posts. ON DELETE SET
 * NULL: if an item is removed the photo stays attached to the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages_attachments', 'bulkitemid')) {
            return;
        }

        Schema::table('messages_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('bulkitemid')->nullable()->after('msgid')->index();
            $table->foreign('bulkitemid')->references('id')->on('messages_bulk_items')
                ->onUpdate('no action')->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages_attachments', 'bulkitemid')) {
            return;
        }

        Schema::table('messages_attachments', function (Blueprint $table) {
            $table->dropForeign(['bulkitemid']);
            $table->dropColumn('bulkitemid');
        });
    }
};
