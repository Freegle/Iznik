<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages_outcomes', 'userid')) {
            return;
        }

        Schema::table('messages_outcomes', function (Blueprint $table) {
            $table->unsignedBigInteger('userid')->nullable()->index('userid')->after('msgid');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages_outcomes', 'userid')) {
            return;
        }

        Schema::table('messages_outcomes', function (Blueprint $table) {
            $table->dropIndex('userid');
            $table->dropColumn('userid');
        });
    }
};
