<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * email_tracking.replied_via gains 'tn_api': a reply to a chat notification that came from
 * TrashNothing's chat-messages feed rather than by email. Appending a value to the end of an enum
 * changes only the table's metadata, so INSTANT is stated and anything heavier refuses.
 *
 * Production SQL: 2026_11_19_000002_add_tn_api_to_email_tracking_replied_via_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('email_tracking', 'replied_via')) {
            return;
        }

        $type = DB::selectOne(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_tracking' AND COLUMN_NAME = 'replied_via'",
            [],
            false,
        )?->t;
        if ($type === null || str_contains((string) $type, "'tn_api'")) {
            return;
        }

        DB::statement("ALTER TABLE email_tracking MODIFY replied_via ENUM('amp','website','email','tn_api') NULL, ALGORITHM=INSTANT");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE email_tracking MODIFY replied_via ENUM('amp','website','email') NULL");
    }
};
