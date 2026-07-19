<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * messages_matched_notified — ledger of which matched post has been emailed to
 * which member by the matched-posts mail (matches:notify), so the same post is
 * never mailed to the same person twice.
 *
 * Composite PK (msgid, userid) makes the "already mailed?" check and the insert a
 * single keyed lookup, and makes re-notification impossible by construction
 * (same pattern as rippling_reach_notified). msgid is the MATCHED post shown to
 * the user (not the user's own post). Cascades away with the message or user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_matched_notified')) {
            return;
        }

        Schema::create('messages_matched_notified', function (Blueprint $t) {
            $t->unsignedBigInteger('msgid');
            $t->unsignedBigInteger('userid');
            $t->timestamp('mailed_at')->useCurrent();
            $t->primary(['msgid', 'userid']);
            $t->index('userid');
        });

        DB::statement(
            'ALTER TABLE messages_matched_notified
                ADD CONSTRAINT messages_matched_notified_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE messages_matched_notified
                ADD CONSTRAINT messages_matched_notified_userid_foreign
                FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_matched_notified');
    }
};
