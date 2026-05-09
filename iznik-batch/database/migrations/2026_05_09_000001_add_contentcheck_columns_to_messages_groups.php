<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->timestamp('contentcheck_checked_at')->nullable()->after('spamreason');
            $table->json('contentcheck_reasons')->nullable()->after('contentcheck_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropColumn(['contentcheck_checked_at', 'contentcheck_reasons']);
        });
    }
};
