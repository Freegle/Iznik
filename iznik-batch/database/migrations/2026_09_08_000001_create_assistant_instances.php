<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per open conversation with the Freegle chat assistant: which state of the
 * ai-flower workflow it is in, what has been collected so far (context) and how it got
 * there (history). The conversation's words live in chat_messages like any other chat;
 * this is only the machine's place in the flowchart. Owner is "u:<userid>" for a
 * member or "a:<anonid>" for a signed-out visitor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assistant_instances')) {
            return;
        }
        DB::statement("
            CREATE TABLE assistant_instances (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                uuid CHAR(36) NOT NULL,
                owner VARCHAR(64) NOT NULL,
                workflow VARCHAR(64) NOT NULL,
                state VARCHAR(64) NOT NULL,
                status ENUM('active','completed','error','paused') NOT NULL DEFAULT 'active',
                context JSON NULL,
                history JSON NULL,
                stay_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY assistant_instances_uuid (uuid),
                KEY assistant_instances_owner (owner),
                KEY assistant_instances_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_instances');
    }
};
