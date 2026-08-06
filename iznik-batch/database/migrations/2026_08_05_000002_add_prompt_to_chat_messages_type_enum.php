<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append 'Prompt' to chat_messages.type.
 *
 * A Prompt is a question Freegle asks a member inside an ordinary chat, with a
 * small set of tappable answers ("Could you deliver?" -> Maybe / Collection
 * only). The question text lives in chat_messages.message as usual so every
 * existing consumer (email notification, push, search, mod review) renders
 * something sensible without knowing about prompts; the ANSWERABLE part lives in
 * the chat_prompts side table keyed on chatmsgid.
 *
 * Deliberately a side table rather than new columns here: chat_messages is one of
 * the largest tables in the database and only a vanishingly small fraction of its
 * rows will ever be prompts, so a nullable JSON column on it would be pure waste
 * and a much more expensive ALTER than this one.
 *
 * End-append keeps every existing value's ordinal stable, which is what makes
 * this metadata-only. ALGORITHM=INPLACE, LOCK=NONE (INSTANT is not supported for
 * ENUM modification on Percona/Galera - see FreegleDocker memory
 * reference_galera_enum_alter). Galera TOI still pauses cluster writes for the
 * duration, which is ms-scale for a metadata-only change.
 */
return new class extends Migration
{
    private const VALUES_WITH_PROMPT = "'Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated','Reminder','Prompt'";

    private const VALUES_WITHOUT_PROMPT = "'Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated','Reminder'";

    public function up(): void
    {
        if (!Schema::hasTable('chat_messages') || $this->hasPromptValue()) {
            return;
        }

        DB::statement(
            'ALTER TABLE chat_messages
                MODIFY COLUMN `type` ENUM(' . self::VALUES_WITH_PROMPT . ") NOT NULL DEFAULT 'Default',
                ALGORITHM=INPLACE, LOCK=NONE"
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_messages') || !$this->hasPromptValue()) {
            return;
        }

        // Narrowing fails on any row still holding the removed value, so retype
        // prompts as System notices first - they read as "an automated message"
        // either way, and the chat_prompts rows go with the chat messages.
        DB::table('chat_messages')->where('type', 'Prompt')->update(['type' => 'System']);

        DB::statement(
            'ALTER TABLE chat_messages
                MODIFY COLUMN `type` ENUM(' . self::VALUES_WITHOUT_PROMPT . ") NOT NULL DEFAULT 'Default',
                ALGORITHM=INPLACE, LOCK=NONE"
        );
    }

    private function hasPromptValue(): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'chat_messages')
            ->where('column_name', 'type')
            ->where('column_type', 'like', '%Prompt%')
            ->exists();
    }
};
