<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        // Append-only ENUM widening: every new value matches a SpamCheckService::REASON_*
        // constant that previously got collapsed to 'Spam' by
        // IncomingMailService::mapReportReason. ModChatReview.vue already has friendly
        // switch cases for each of these strings, so widening the column lets the real
        // reason flow through to the moderator UI instead of the generic "failed spam
        // checks, no more info" text. Original 10 values kept in order; new values
        // appended at the end so storage stays at 1 byte (still ≤ 255 members).
        //
        // INPLACE + LOCK=NONE is valid for end-append on Percona 8.0; INSTANT is not
        // supported for ENUM modification (see FreegleDocker memory
        // reference_galera_enum_alter). Galera TOI still pauses cluster writes for the
        // duration of the metadata-only DDL — ms-scale on this table.
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

    public function down(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        // Coerce any new values back to 'Spam' before narrowing the column, otherwise
        // the ALTER will fail on rows whose value is no longer in the enum. Done in
        // one statement here for migration reversibility, but on prod this would be
        // per-row to stay Galera-safe (feedback_prod_deletes_one_at_a_time).
        DB::statement("
            UPDATE chat_messages
            SET reportreason = 'Spam'
            WHERE reportreason IN (
              'CountryBlocked','IPUsedForDifferentUsers','IPUsedForDifferentGroups',
              'SubjectUsedForDifferentGroups','SpamAssassin','Greetings spam',
              'Referenced known spammer','Known spam keyword','URL on DBL',
              'BulkVolunteerMail','UsedOurDomain','WorryWord','Script','Link',
              'Money','Email','Language'
            )
        ");

        DB::statement("
            ALTER TABLE chat_messages
              MODIFY COLUMN reportreason ENUM(
                'Spam','Other','Last','Force','Fully','TooMany','User',
                'UnknownMessage','SameImage','DodgyImage'
              ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
