<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which "WAITING FOR REPLY" chase-ups chats:chaseup-expected has sent. One row per chat message and
 * recipient. The command records a send here before it mails, so a second run, a rerun after a
 * deploy or two runs at once never chase the same message twice. Rows older than the eligibility
 * window are deleted by the command itself.
 *
 * Production SQL: 2026_11_12_000001_create_chat_chaseup_expected_sent_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_chaseup_expected_sent')) {
            return;
        }

        Schema::create('chat_chaseup_expected_sent', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chatmsgid');
            $table->unsignedBigInteger('userid');
            $table->timestamp('sent')->useCurrent();

            $table->unique(['chatmsgid', 'userid'], 'chat_chaseup_expected_sent_msg_user');
            $table->index('sent', 'chat_chaseup_expected_sent_sent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_chaseup_expected_sent');
    }
};
