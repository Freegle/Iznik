<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Gmail outreach sender records the Gmail message + thread id of each sent
 * intro email so the inbox poller can match a reply thread back to its outreach
 * row. These are separate columns (not crammed into notes) so the poll query can
 * index on gmail_thread_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages_bulk_outreach')) {
            return;
        }

        Schema::table('messages_bulk_outreach', function (Blueprint $t) {
            if (! Schema::hasColumn('messages_bulk_outreach', 'gmail_message_id')) {
                $t->string('gmail_message_id', 255)->nullable()->after('sent_via');
                $t->index('gmail_message_id');
            }
            if (! Schema::hasColumn('messages_bulk_outreach', 'gmail_thread_id')) {
                $t->string('gmail_thread_id', 255)->nullable()->after('gmail_message_id');
                $t->index('gmail_thread_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages_bulk_outreach')) {
            return;
        }

        Schema::table('messages_bulk_outreach', function (Blueprint $t) {
            if (Schema::hasColumn('messages_bulk_outreach', 'gmail_thread_id')) {
                $t->dropColumn('gmail_thread_id');
            }
            if (Schema::hasColumn('messages_bulk_outreach', 'gmail_message_id')) {
                $t->dropColumn('gmail_message_id');
            }
        });
    }
};
