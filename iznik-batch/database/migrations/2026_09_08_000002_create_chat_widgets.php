<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tappable part of a message from the Freegle chat assistant. The chat message
 * itself carries the words, so ModTools and older apps render plain text; this row
 * carries the chips, progress line and cards the chat shell renders alongside it.
 * Same shape of split as chat_prompts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_widgets') || !Schema::hasTable('chat_messages')) {
            return;
        }
        DB::statement("
            CREATE TABLE chat_widgets (
                chatmsgid BIGINT UNSIGNED NOT NULL,
                kind VARCHAR(32) NOT NULL,
                payload JSON NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (chatmsgid),
                KEY chat_widgets_kind (kind),
                CONSTRAINT chat_widgets_chatmsgid_foreign FOREIGN KEY (chatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_widgets');
    }
};
