<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('newsfeed')) {
            return;
        }

        // The ChitChat convert-to-post flow (apiv2 newsfeed action
        // 'ConvertedToPost') leaves a notice reply on the thread with this
        // type. It shipped without the enum value, so MySQL truncated the
        // type to '' and the notice rendered as an empty reply from the
        // moderator instead of the canned NewsConvertedNotice box.
        //
        // End-append keeps existing values' indexes stable and the enum at
        // 1 byte. ALGORITHM=INPLACE, LOCK=NONE is the Galera-safe form for
        // ENUM widening (INSTANT is not supported for ENUM modification on
        // Percona/Galera). Metadata-only; existing rows are untouched.
        DB::statement("
            ALTER TABLE newsfeed
              MODIFY COLUMN `type`
                ENUM('Message','CommunityEvent','VolunteerOpportunity','CentralPublicity','Alert','Story','ReferToWanted','ReferToOffer','ReferToTaken','ReferToReceived','AboutMe','Noticeboard','ConvertedToPost')
                NOT NULL DEFAULT 'Message',
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('newsfeed')) {
            return;
        }

        // Convert-to-post notices become plain refer notices before
        // narrowing, else the ALTER fails on rows whose value is no longer
        // in the enum.
        DB::statement("UPDATE newsfeed SET type = 'ReferToWanted' WHERE type = 'ConvertedToPost'");

        DB::statement("
            ALTER TABLE newsfeed
              MODIFY COLUMN `type`
                ENUM('Message','CommunityEvent','VolunteerOpportunity','CentralPublicity','Alert','Story','ReferToWanted','ReferToOffer','ReferToTaken','ReferToReceived','AboutMe','Noticeboard')
                NOT NULL DEFAULT 'Message',
              ALGORITHM=INPLACE, LOCK=NONE
        ");
    }
};
