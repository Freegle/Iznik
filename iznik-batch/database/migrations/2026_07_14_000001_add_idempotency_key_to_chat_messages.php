<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chat_messages.idempotencykey (+ unique index) - lets CreateChatMessage
 * (iznik-server-go/chat/chatmessage.go) collapse a retried/duplicated POST (a client
 * retry on an ambiguous fetch outcome, a double-click, a second tab) onto the SAME row
 * via INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), instead of creating a
 * second chat_messages row that briefly shows twice to the sender (Discourse #9913).
 *
 * Nullable: MySQL never treats two NULLs as equal in a unique index, so older clients
 * that send no key are completely unaffected - no backfill needed.
 *
 * ALGORITHM=INPLACE, LOCK=NONE - same Galera-safe pattern as the existing chat_messages
 * enum-widening migration (2026_05_27_000001_widen_chat_messages_reportreason_enum).
 * chat_messages is a large, high-traffic production table; an online ADD COLUMN + ADD
 * INDEX avoids a full table rebuild/lock. Guarded on BOTH the column and the index (not
 * just the column) so a partial prior run - e.g. the column landed but the index DDL
 * failed/was interrupted - is completed rather than skipped on re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        if (!Schema::hasColumn('chat_messages', 'idempotencykey')) {
            DB::statement("
                ALTER TABLE chat_messages
                  ADD COLUMN idempotencykey VARCHAR(64) NULL AFTER message,
                  ALGORITHM=INPLACE, LOCK=NONE
            ");
        }

        $indexExists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'chat_messages_idempotency_unique'"
        );
        if ((int) ($indexExists->n ?? 0) === 0) {
            DB::statement("
                ALTER TABLE chat_messages
                  ADD UNIQUE INDEX chat_messages_idempotency_unique (chatid, userid, idempotencykey),
                  ALGORITHM=INPLACE, LOCK=NONE
            ");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        $indexExists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'chat_messages_idempotency_unique'"
        );
        if ((int) ($indexExists->n ?? 0) > 0) {
            DB::statement('ALTER TABLE chat_messages DROP INDEX chat_messages_idempotency_unique, ALGORITHM=INPLACE, LOCK=NONE');
        }

        if (Schema::hasColumn('chat_messages', 'idempotencykey')) {
            DB::statement('ALTER TABLE chat_messages DROP COLUMN idempotencykey, ALGORITHM=INPLACE, LOCK=NONE');
        }
    }
};
