<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // PR #248 (2026-04-23) soft-deleted messages on member withdrawal but did not set
    // messages_groups.deleted = 1. This left orphaned Pending rows visible in Old ModTools
    // (V1 query 2 lacks messages.deleted IS NULL filter) but invisible in Live ModTools
    // (Go API filters m.deleted IS NULL). PR #284 added the messages_groups update for
    // future withdrawals; this migration cleans up any records created in between.
    public function up(): void
    {
        DB::statement(
            "UPDATE messages_groups mg
             INNER JOIN messages m ON m.id = mg.msgid
             SET mg.deleted = 1
             WHERE mg.collection = 'Pending'
               AND mg.deleted = 0
               AND m.deleted IS NOT NULL"
        );
    }

    public function down(): void
    {
        // No rollback: we cannot distinguish these rows from legitimately deleted ones.
    }
};
