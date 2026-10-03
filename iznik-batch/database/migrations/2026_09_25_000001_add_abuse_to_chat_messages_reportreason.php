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
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        DB::statement("
            ALTER TABLE chat_messages
              MODIFY COLUMN reportreason ENUM(
                'Spam','Other','Last','Force','Fully','TooMany','User',
                'UnknownMessage','SameImage','DodgyImage',
                'CountryBlocked',
                'IPUsedForDifferentUsers',
                'IPUsedForDifferentGroups',
                'SubjectUsedForDifferentGroups',
                'SpamAssassin',
                'Greetings spam',
                'Referenced known spammer',
                'Known spam keyword',
                'URL on DBL',
                'BulkVolunteerMail',
                'UsedOurDomain',
                'WorryWord',
                'Script',
                'Link',
                'Money',
                'Email',
                'Language',
                'Abuse'
              ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        DB::statement("
            UPDATE chat_messages SET reportreason = 'Spam' WHERE reportreason = 'Abuse'
        ");

        DB::statement("
            ALTER TABLE chat_messages
              MODIFY COLUMN reportreason ENUM(
                'Spam','Other','Last','Force','Fully','TooMany','User',
                'UnknownMessage','SameImage','DodgyImage',
                'CountryBlocked',
                'IPUsedForDifferentUsers',
                'IPUsedForDifferentGroups',
                'SubjectUsedForDifferentGroups',
                'SpamAssassin',
                'Greetings spam',
                'Referenced known spammer',
                'Known spam keyword',
                'URL on DBL',
                'BulkVolunteerMail',
                'UsedOurDomain',
                'WorryWord',
                'Script',
                'Link',
                'Money',
                'Email',
                'Language'
              ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
