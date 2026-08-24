<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a 'Clearance' permission to the existing users.permissions SET so the
 * bulk-offer / clearance feature - the composer page, its create/edit API and the
 * /helper concierge endpoints - can be limited to specific users during the trial.
 *
 * The email-outreach sub-feature is gated further (in the UI / outreach API) to
 * users whose primary email is @ilovefreegle.org, since the service account can
 * only send as those mailboxes.
 *
 * Appending a value to the END of a SET is an INSTANT metadata change in MySQL 8
 * (no table rebuild) and preserves every existing row's bit positions, so it is
 * safe on the Galera cluster. The existing values are kept in their original order.
 */
return new class extends Migration
{
    private string $base = "'BusinessCardsAdmin','Newsletter','NationalVolunteers','Teams','GiftAid','SpamAdmin'";

    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY permissions SET({$this->base},'Clearance') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY permissions SET({$this->base}) NULL");
    }
};
