<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach_notified — ledger of which members have already been sent the
 * immediate "new post near you" mail for a rippling post, so expansion steps
 * never re-notify someone already reached (PR B).
 *
 * Composite PK (msgid, userid) makes the "already notified?" check and the insert
 * a single keyed lookup, and makes re-notification impossible by construction.
 * Cascades away with the message (and the reach row).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rippling_reach_notified')) {
            return;
        }

        Schema::create('rippling_reach_notified', function (Blueprint $t) {
            $t->unsignedBigInteger('msgid');
            $t->unsignedBigInteger('userid');
            $t->timestamp('notified_at')->useCurrent();
            $t->primary(['msgid', 'userid']);
            $t->index('userid');
        });

        DB::statement(
            'ALTER TABLE rippling_reach_notified
                ADD CONSTRAINT rippling_reach_notified_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE rippling_reach_notified
                ADD CONSTRAINT rippling_reach_notified_userid_foreign
                FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_reach_notified');
    }
};
