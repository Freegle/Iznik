<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which chat message each TrashNothing chat-messages feed record became. tn:sync re-reads
 * overlapping windows, so the same record arrives more than once: the unique key on TN's id makes
 * the write idempotent and decides a race. A table of its own rather than a column on
 * chat_messages, which is too large to alter casually. See
 * plans/2026-11-12-tn-api-chat-ingestion.md, section 3.4.
 *
 * Production SQL: 2026_11_19_000001_create_chat_messages_tn_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_messages_tn')) {
            return;
        }

        Schema::create('chat_messages_tn', function (Blueprint $table) {
            $table->bigIncrements('id');
            // The message written for the record.
            $table->unsignedBigInteger('chatmsgid');
            // TN's id for the message: the X-Trash-Nothing-Log-Id of the email TN sends for it.
            $table->string('tnmessageid', 64);
            // TN's date for the message. chat_messages.date is the time of ingestion instead.
            $table->timestamp('tndate')->nullable();
            $table->timestamp('created')->useCurrent();

            $table->unique('tnmessageid', 'chat_messages_tn_tnmessageid');
            $table->index('chatmsgid', 'chat_messages_tn_chatmsgid');
            $table->foreign('chatmsgid', 'chat_messages_tn_chatmsgid_fk')
                ->references('id')->on('chat_messages')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages_tn');
    }
};
