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

        // 'Restored' records an account being reinstated after self-deletion
        // (PATCH /session with deleted:null in the Go API), pairing with the
        // existing (User, Deleted) log so mods can see a deletion was
        // reversed. Without the enum member MySQL silently truncates the
        // value to '' in non-strict mode.
        //
        // ENUM values are stored as their 1-based ordinal, so the list below
        // MUST match the live column's physical order with the new value
        // appended at the end. On the live DB 'OurEmailFrequency' is LAST
        // (position 47) - it was hot-fixed there as an append - NOT next to
        // 'OurPostingStatus' where create_logs_table used to place it. An
        // earlier version of this migration used that "logical" order, which
        // relative to the live DB reorders 8 ordinals: MySQL refuses
        // INSTANT/INPLACE for a reorder and the only remaining path is a
        // COPY rebuild of ~40M rows, which under Galera TOI locks the whole
        // cluster. A true end-append keeps the enum at 1 byte and is
        // metadata-only. Do not "tidy" this order.
        $appendRestored = "
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
                'Restored'
              ) DEFAULT NULL";

        try {
            DB::statement($appendRestored . ", ALGORITHM=INSTANT");
        } catch (\Illuminate\Database\QueryException $e) {
            try {
                // Some 8.0 builds accept end-append only as INPLACE.
                DB::statement($appendRestored . ", ALGORITHM=INPLACE, LOCK=NONE");
            } catch (\Illuminate\Database\QueryException $e) {
                // Only reachable on dev/test DBs created before the
                // create_logs_table fix that moved OurEmailFrequency to the
                // end: there this statement is a reorder, which needs a COPY
                // rebuild. Fine at dev size; the live DB always matches the
                // append path above and never gets here.
                DB::statement($appendRestored);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('logs')) {
            return;
        }

        // Remove rows using the new value before narrowing the column,
        // otherwise the ALTER fails on rows whose value is no longer in the
        // enum.
        DB::statement("DELETE FROM logs WHERE type = 'User' AND subtype = 'Restored'");

        // Removing an enum member is never INSTANT/INPLACE-eligible, so let
        // MySQL pick the algorithm (COPY). Rolling back on the live DB would
        // need a maintenance window regardless.
        DB::statement("
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
                'PostcodeChange','Repost','OurEmailFrequency'
              ) DEFAULT NULL
        ");
    }
};
