<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add AI-concierge helper columns:
 *
 *   messages.helper_enabled   – offerer has opted in to the AI helper concierge
 *   chat_messages.fromhelper  – message was authored by the AI helper (for disclosure
 *                               and mod-review; never shown as a human message)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages', 'helper_enabled')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->tinyInteger('helper_enabled')->unsigned()->default(0)
                    ->after('id')
                    ->comment('1 = offerer has consented to the AI concierge helper');
            });
        }

        if (!Schema::hasColumn('chat_messages', 'fromhelper')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->tinyInteger('fromhelper')->unsigned()->default(0)
                    ->after('id')
                    ->comment('1 = message was authored by the AI helper');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'fromhelper')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('fromhelper');
            });
        }

        if (Schema::hasColumn('messages', 'helper_enabled')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('helper_enabled');
            });
        }
    }
};
