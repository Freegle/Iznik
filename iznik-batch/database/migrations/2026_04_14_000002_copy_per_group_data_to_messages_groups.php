<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copy heldby from messages to messages_groups for currently-held messages.
        DB::statement('
            UPDATE messages_groups mg
            INNER JOIN messages m ON m.id = mg.msgid
            SET mg.heldby = m.heldby
            WHERE m.heldby IS NOT NULL
        ');

        // Copy spamtype/spamreason from messages to messages_groups.
        DB::statement('
            UPDATE messages_groups mg
            INNER JOIN messages m ON m.id = mg.msgid
            SET mg.spamtype = m.spamtype, mg.spamreason = m.spamreason
            WHERE m.spamtype IS NOT NULL
        ');
    }

    public function down(): void
    {
        // No rollback needed — the messages table still has the original data.
    }
};
