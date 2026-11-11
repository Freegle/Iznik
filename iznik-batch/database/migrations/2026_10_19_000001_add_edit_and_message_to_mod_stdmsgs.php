<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Edit and Message" standard message action changes the post and messages the
 * poster in one go. `body` stays the text added to the post; `replybody` is the
 * message to the poster.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mod_stdmsgs')) {
            return;
        }

        // End-append keeps the existing values' indexes stable, so this is metadata-only.
        DB::statement("
            ALTER TABLE mod_stdmsgs
              MODIFY COLUMN `action`
                ENUM('Approve','Reject','Leave','Approve Member','Reject Member','Leave Member','Leave Approved Message','Delete Approved Message','Leave Approved Member','Delete Approved Member','Edit','Hold Message','Edit and Message')
                NOT NULL DEFAULT 'Reject' COMMENT 'What action to take',
              ALGORITHM=INPLACE, LOCK=NONE
        ");

        if (!Schema::hasColumn('mod_stdmsgs', 'replybody')) {
            DB::statement("
                ALTER TABLE mod_stdmsgs
                  ADD COLUMN `replybody` MEDIUMTEXT NULL COMMENT 'For Edit and Message: the message to the poster',
                  ALGORITHM=INSTANT
            ");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('mod_stdmsgs')) {
            return;
        }

        if (Schema::hasColumn('mod_stdmsgs', 'replybody')) {
            DB::statement('ALTER TABLE mod_stdmsgs DROP COLUMN `replybody`');
        }

        DB::statement("UPDATE mod_stdmsgs SET action = 'Edit' WHERE action = 'Edit and Message'");

        DB::statement("
            ALTER TABLE mod_stdmsgs
              MODIFY COLUMN `action`
                ENUM('Approve','Reject','Leave','Approve Member','Reject Member','Leave Member','Leave Approved Message','Delete Approved Message','Leave Approved Member','Delete Approved Member','Edit','Hold Message')
                NOT NULL DEFAULT 'Reject' COMMENT 'What action to take'
        ");
    }
};
