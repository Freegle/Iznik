<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional "agreement" columns on a promise.
 *
 * A Freegle promise is one-sided: the owner promises an item to someone and
 * that is that. Another deployment of this codebase turns a promise into an
 * agreement between two members - the owner proposes terms, the other party
 * accepts, and both are followed up later. These nullable columns carry that:
 *
 *   terms                   what was proposed (JSON, shape is the client's)
 *   acceptedat / acceptedby when and by whom it was accepted
 *   checkins                follow-up check-ins recorded after acceptance (JSON)
 *   checkin_reminders_sent  which reminders have gone out (JSON)
 *
 * All NULL unless a client uses the terms field on the Promise action or the
 * AcceptAgreement action (iznik-server-go message.handleAcceptAgreement), so
 * every existing promise and every Freegle client is unaffected. The Go struct
 * omits them from JSON while NULL.
 *
 * Guarded so it is a no-op where an operator has already applied it, and where
 * a downstream deployment had added the same columns by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('messages_promises', 'terms')) {
            DB::statement('ALTER TABLE messages_promises ADD COLUMN terms JSON NULL AFTER promisedat, ALGORITHM=INSTANT');
        }
        if (! Schema::hasColumn('messages_promises', 'acceptedat')) {
            DB::statement('ALTER TABLE messages_promises ADD COLUMN acceptedat TIMESTAMP NULL AFTER terms, ADD COLUMN acceptedby BIGINT UNSIGNED NULL AFTER acceptedat, ALGORITHM=INSTANT');
        }
        if (! Schema::hasColumn('messages_promises', 'checkins')) {
            DB::statement('ALTER TABLE messages_promises ADD COLUMN checkins JSON NULL AFTER acceptedby, ADD COLUMN checkin_reminders_sent JSON NULL AFTER checkins, ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        foreach (['checkin_reminders_sent', 'checkins', 'acceptedby', 'acceptedat', 'terms'] as $column) {
            if (Schema::hasColumn('messages_promises', $column)) {
                DB::statement("ALTER TABLE messages_promises DROP COLUMN {$column}");
            }
        }
    }
};
