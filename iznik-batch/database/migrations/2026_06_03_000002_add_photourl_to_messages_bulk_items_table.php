<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `photourl` to messages_bulk_items so a catalogue item can carry an
 * external photo link supplied via the spreadsheet import (an http URL),
 * avoiding a manual upload. Uploaded photos (messages_attachments.bulkitemid)
 * still take precedence; photourl is the fallback image.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages_bulk_items', 'photourl')) {
            return;
        }

        Schema::table('messages_bulk_items', function (Blueprint $table) {
            $table->string('photourl', 2048)->nullable()->after('dimensions');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages_bulk_items', 'photourl')) {
            return;
        }

        Schema::table('messages_bulk_items', function (Blueprint $table) {
            $table->dropColumn('photourl');
        });
    }
};
