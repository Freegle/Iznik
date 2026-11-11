<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'Abuse' to chat_messages.reportreason. Needed for the Judge's 'decent' question
 * (ai-judgement.md): a Moderated member's chat message the judge flags as abusive,
 * threatening, discriminatory or harassing is held with this reason, mapped in
 * config('freegle.judgement.chat_reportreason') and consumed by ChatProcessService.
 * Append-only widening, same technique as 2026_05_27_000001_widen_chat_messages_reportreason_enum.
 *
 * The value is appended to whatever the column holds, so a database that already has other
 * values (master's 'Lockdown') keeps them, and a second run does nothing. An INPLACE change is
 * only allowed when the new list is the old list with values added at the end.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        $type = DB::selectOne("SHOW COLUMNS FROM chat_messages LIKE 'reportreason'")->Type ?? '';

        if (!str_starts_with($type, 'enum(') || str_contains($type, "'Abuse'")) {
            return;
        }

        $enum = substr($type, 0, -1) . ",'Abuse')";

        DB::statement("
            ALTER TABLE chat_messages
              MODIFY COLUMN reportreason {$enum}
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        $type = DB::selectOne("SHOW COLUMNS FROM chat_messages LIKE 'reportreason'")->Type ?? '';

        if (!str_starts_with($type, 'enum(') || !str_contains($type, "'Abuse'")) {
            return;
        }

        DB::statement("
            UPDATE chat_messages SET reportreason = 'Spam' WHERE reportreason = 'Abuse'
        ");

        // Taking a value off the end of the list is a rebuild, so the algorithm is not forced.
        $enum = str_replace(",'Abuse'", '', $type);

        DB::statement("
            ALTER TABLE chat_messages
              MODIFY COLUMN reportreason {$enum}
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        ");
    }
};
