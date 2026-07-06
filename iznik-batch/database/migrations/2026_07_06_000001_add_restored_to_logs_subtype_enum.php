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

        // Append-only ENUM widening: 'Restored' records an account being
        // reinstated after self-deletion (PATCH /session with deleted:null in
        // the Go API), pairing with the existing (User, Deleted) log so mods
        // can see a deletion was reversed. Without the enum member MySQL
        // silently truncates the value to '' in non-strict mode. Original
        // values kept in order; new value appended at the end so storage stays
        // at 1 byte. INPLACE + LOCK=NONE is valid for end-append on Percona
        // 8.0; INSTANT is not supported for ENUM modification.
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
                'Autoreposted','Outcome','OurPostingStatus','OurEmailFrequency',
                'VolunteersOff','Autoapproved','Unbounce','WorryWords',
                'NoteAdded','PostcodeChange','Repost',
                'Restored'
              ) DEFAULT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
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
                'Autoreposted','Outcome','OurPostingStatus','OurEmailFrequency',
                'VolunteersOff','Autoapproved','Unbounce','WorryWords',
                'NoteAdded','PostcodeChange','Repost'
              ) DEFAULT NULL,
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
