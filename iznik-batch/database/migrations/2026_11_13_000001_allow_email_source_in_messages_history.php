<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * messages_history.source records where a post came from, for spam checking. Posts that arrive by
 * email, or through the TrashNothing posts API, are written with source 'Email', the same value
 * messages.source takes. The column's list did not include it, so MySQL stored an empty value and
 * PostgreSQL refused the row. This adds 'Email' to the list. Rows already stored are not changed.
 *
 * Production SQL: 2026_11_13_000001_allow_email_source_in_messages_history_migration.sql
 */
return new class extends Migration
{
    private const WITH_EMAIL = "'Yahoo Approved','Yahoo Pending','Yahoo System','Platform','Email'";

    private const WITHOUT_EMAIL = "'Yahoo Approved','Yahoo Pending','Yahoo System','Platform'";

    public function up(): void
    {
        if (!Schema::hasTable('messages_history') || DB::getDriverName() !== 'mysql') {
            return;
        }

        // mysql-only: Blueprint cannot append a value to an ENUM; the PostgreSQL baseline is converted from this schema.
        DB::statement('ALTER TABLE messages_history MODIFY COLUMN source ENUM(' . self::WITH_EMAIL . ") NULL DEFAULT NULL COMMENT 'Source of incoming message'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('messages_history') || DB::getDriverName() !== 'mysql') {
            return;
        }

        // mysql-only: the reverse of up(); rows holding 'Email' go back to the empty value first.
        DB::statement("UPDATE messages_history SET source = NULL WHERE source = 'Email'");
        // mysql-only: Blueprint cannot change an ENUM's list.
        DB::statement('ALTER TABLE messages_history MODIFY COLUMN source ENUM(' . self::WITHOUT_EMAIL . ") NULL DEFAULT NULL COMMENT 'Source of incoming message'");
    }
};
