<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHY a chat message was dropped during processing.
 *
 * A message that fails processing is set processingsuccessful = 0, which permanently
 * excludes it from chat notifications - the recipient is never told it exists, and the
 * sender believes they were ignored. Until now nothing recorded the reason, so a
 * suppressed reply was indistinguishable from one that was never written, and support
 * had no way to answer "why didn't I see this reply?" except by guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('processingfailreason', 32)->nullable()->after('processingsuccessful');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('processingfailreason');
        });
    }
};
