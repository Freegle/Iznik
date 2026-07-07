<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `edittoken` to messages_bulk_access: an unguessable secret that lets an
 * external item-owner open a logged-out page to update this bulk offer's item
 * availability/counts (see the Go /bulkoffer/update endpoints). One token per
 * offer (messages_bulk_access has one row per msgid); nulling it revokes the link.
 * No login is granted - the token only authorises availability/count edits on
 * this one offer, and exposes no replier data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages_bulk_access', 'edittoken')) {
            return;
        }

        Schema::table('messages_bulk_access', function (Blueprint $table) {
            $table->string('edittoken', 64)->nullable()->after('accessinstructions')
                ->comment('Secret token for the logged-out availability-update page');
            $table->unique('edittoken');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages_bulk_access', 'edittoken')) {
            return;
        }

        Schema::table('messages_bulk_access', function (Blueprint $table) {
            $table->dropUnique(['edittoken']);
            $table->dropColumn('edittoken');
        });
    }
};
