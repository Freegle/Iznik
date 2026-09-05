<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mail_suppressed_counts.lastkey - stop counting the same withheld mail twice.
 *
 * `count` was meant to be "mails we declined to generate", and the support
 * screen shows it as "Held". It is not that. ChatNotificationService skips a
 * suppressed recipient WITHOUT advancing chat_roster.lastmsgemailed - correctly,
 * so the catch-up can still see there are unread messages - which means every
 * run re-processes the same unread messages and increments the counter again.
 *
 * Measured on prod 2026-08-20: user 3546689 at 10,777 for `chat` over a 106
 * minute window (101.7 per minute), user 44607900 at 11,691 over 133 minutes.
 * Nobody is sent a hundred emails a minute. The figure was attempts, not mails,
 * and it was the retry-heavy denominator making a member look catastrophically
 * backed up when they had a handful of unread messages.
 *
 * lastkey holds the highest per-mail identity counted so far - for chat, the
 * chat message id. Ids increase, so re-processing the same backlog cannot
 * advance it and cannot count again, while a genuinely new message does. A
 * caller with no natural identity passes nothing and keeps the old behaviour of
 * one increment per call, which is right for the once-per-run mailers.
 *
 * Nullable and additive, so it is safe to deploy before or after the code.
 */
return new class extends Migration
{
    private const TABLE = 'mail_suppressed_counts';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'lastkey')) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN lastkey BIGINT UNSIGNED NULL COMMENT '.
            "'Highest per-mail identity counted, so retries of the same mail do not re-count'".
            ', ALGORITHM=INSTANT');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'lastkey')) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN lastkey');
        }
    }
};
