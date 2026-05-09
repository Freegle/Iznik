<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->timestamp('contentcheck_checked_at')->nullable()->after('spamreason');
            $table->json('contentcheck_reasons')->nullable()->after('contentcheck_checked_at');
        });

        // Backfill all existing Pending rows so they remain visible to mods.
        // New rows submitted after this migration will have NULL (unprocessed).
        DB::statement("UPDATE messages_groups SET contentcheck_checked_at = NOW() WHERE collection = 'Pending' AND contentcheck_checked_at IS NULL");
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropColumn(['contentcheck_checked_at', 'contentcheck_reasons']);
        });
    }
};
