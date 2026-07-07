<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen messages_bulk_outreach.status to add AutoAck and Bounced.
 *
 * PollGmailOutreachCommand previously marked any non-unsubscribe inbound reply
 * as Replied and emitted it to the FSM brain, including auto-replies/OOO
 * auto-acknowledgements and delivery-failure bounces. Those two new statuses
 * let the poller record what actually happened without miscounting them as a
 * genuine human reply (see App\Console\Commands\BulkOffer\PollGmailOutreachCommand).
 *
 * Append-only ENUM widening: existing values kept in their original order, new
 * values appended at the end (see FreegleDocker memory
 * reference_galera_enum_alter - INPLACE + LOCK=NONE is valid for end-append on
 * Percona 8.0; Galera TOI still pauses cluster writes for the metadata-only DDL,
 * ms-scale on this table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages_bulk_outreach')) {
            return;
        }

        DB::statement("
            ALTER TABLE messages_bulk_outreach
              MODIFY COLUMN status ENUM(
                'Imported','Queued','Sent','Replied','Took','Declined','NoResponse','Skipped',
                'AutoAck','Bounced'
              ) NOT NULL DEFAULT 'Imported',
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages_bulk_outreach')) {
            return;
        }

        // Coerce any new values back to NoResponse before narrowing the column,
        // otherwise the ALTER fails on rows whose value is no longer in the enum.
        DB::statement("
            UPDATE messages_bulk_outreach
            SET status = 'NoResponse'
            WHERE status IN ('AutoAck','Bounced')
        ");

        DB::statement("
            ALTER TABLE messages_bulk_outreach
              MODIFY COLUMN status ENUM(
                'Imported','Queued','Sent','Replied','Took','Declined','NoResponse','Skipped'
              ) NOT NULL DEFAULT 'Imported',
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
