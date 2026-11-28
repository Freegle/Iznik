<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * chat_late_mails: the claim for a chat message that is mailed after a later message in the same chat.
 *
 * chat_roster.lastmsgemailed is one high-water mark per recipient. A message held for review, a rippling
 * hold, a lockdown hold or a reply question gets an id below the messages written while it was held, so
 * once one of those is mailed the held message is below the mark however it is released. The notifier
 * (ChatNotificationService) mails it anyway, to a recipient whose mark is past it and who has no mail for
 * it, and claims it here first. The unique key makes the claim atomic: a second run, or a second process,
 * inserts nothing and sends nothing. A row is written before the mail is built, so a crash loses at most
 * that one mail and never sends it twice.
 *
 * One row per late mail, a handful a day. Nothing reads a row after the release window has passed (24 hours).
 *
 * Production SQL: 2026_11_12_000001_create_chat_late_mails_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_late_mails')) {
            return;
        }

        Schema::create('chat_late_mails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chatmsgid');
            $table->unsignedBigInteger('userid');
            $table->timestamp('created')->useCurrent();

            $table->unique(['chatmsgid', 'userid'], 'chat_late_mails_msg_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_late_mails');
    }
};
