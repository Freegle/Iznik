<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds messages_groups.needs_moderator - set on every copy a moderator's Back to pending
 * pulls back, and cleared when a moderator approves that copy. While it is set no automatic
 * path (content check, auto-approve) may approve the copy: only that group's moderators can
 * approve or reject it. Without it, the content check re-approved every copy but the acting
 * moderator's own a minute after the next edit, with no log entry (122011064, 121985402).
 *
 * Trailing NOT NULL column with a default → INSTANT add in MySQL 8 (no table rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages_groups', 'needs_moderator')) {
            DB::statement('ALTER TABLE messages_groups ADD COLUMN needs_moderator TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_groups', 'needs_moderator')) {
            DB::statement('ALTER TABLE messages_groups DROP COLUMN needs_moderator');
        }
    }
};
