<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('logs')) {
            return;
        }

        // 'Banned' and 'Unbanned' record a site-wide ban being applied or lifted
        // (the national ModTools member endpoints in the Go API, and the batch's
        // spam cleanup). Without the enum members MySQL silently truncates the
        // value to '' in non-strict mode: no error, an empty log.
        //
        // ENUM values are stored as their 1-based ordinal, so the list below
        // MUST match the live column's physical order with the new values
        // appended at the end. See 2026_07_06_000001_add_restored_to_logs_subtype_enum
        // for why the order is what it is and must not be tidied: a reorder
        // needs a COPY rebuild of ~40M rows under Galera TOI.
        $appendBanned = "
            ALTER TABLE logs
              MODIFY COLUMN subtype ENUM(
                'Created','Deleted','Received','Sent','Failure','ClassifiedSpam',
                'Joined','Left','Approved','Rejected','YahooDeliveryType',
                'YahooPostingStatus','NotSpam','Login','Hold','Release','Edit',
                'RoleChange','Merged','Split','Replied','Mailed','Applied',
                'Suspect','Licensed','LicensePurchase','YahooApplied',
                'YahooConfirmed','YahooJoined','MailOff','EventsOff',
                'NewslettersOff','RelevantOff','Logout','Bounce','SuspendMail',
                'Autoreposted','Outcome','OurPostingStatus','VolunteersOff',
                'Autoapproved','Unbounce','WorryWords','NoteAdded',
                'PostcodeChange','Repost','OurEmailFrequency',
                'Restored','Banned','Unbanned'
              ) DEFAULT NULL";

        try {
            DB::statement($appendBanned . ", ALGORITHM=INSTANT");
        } catch (\Illuminate\Database\QueryException $e) {
            try {
                // Some 8.0 builds accept end-append only as INPLACE.
                DB::statement($appendBanned . ", ALGORITHM=INPLACE, LOCK=NONE");
            } catch (\Illuminate\Database\QueryException $e) {
                // Only reachable on a dev or test DB whose order differs; fine at
                // that size. The live DB always takes the append path above.
                DB::statement($appendBanned);
            }
        }
    }

    public function down(): void
    {
        // Leaving the values in place is harmless; removing them would truncate
        // any row already written with them.
    }
};
