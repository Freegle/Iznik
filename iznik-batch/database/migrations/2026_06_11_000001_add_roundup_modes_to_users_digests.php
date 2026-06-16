<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend users_digests.mode to cover the unified community-events and
 * volunteering-opportunity roundups.
 *
 * These roundups become user-centric (one combined email per user across all
 * their groups, deduplicated) like the message digest, so they need the same
 * per-user "last sent" tracking the table already provides for digests. We
 * reuse users_digests rather than the per-group groups.lasteventsroundup /
 * groups.lastvolunteeringroundup cursors, which no longer map to a per-user
 * send.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users_digests')) {
            return;
        }

        DB::statement(
            "ALTER TABLE users_digests
             MODIFY COLUMN mode ENUM('immediate','daily','events','volunteering')
             NOT NULL DEFAULT 'daily' COMMENT 'Digest mode'"
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('users_digests')) {
            return;
        }

        // Drop any rows using the new modes first so the narrower enum applies
        // cleanly.
        DB::table('users_digests')->whereIn('mode', ['events', 'volunteering'])->delete();

        DB::statement(
            "ALTER TABLE users_digests
             MODIFY COLUMN mode ENUM('immediate','daily')
             NOT NULL DEFAULT 'daily' COMMENT 'Digest mode'"
        );
    }
};
