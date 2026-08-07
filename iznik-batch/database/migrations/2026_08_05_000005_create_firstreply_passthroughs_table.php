<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * firstreply_passthroughs - one row per first reply that was let through instead
 * of held, and how long that reply would otherwise have waited.
 *
 * A count of passthroughs says the lever fired. It does not say whether firing
 * was worth anything, and the obvious proxy - the average hold duration across
 * all held replies - is a different population answering a different question.
 * The number that matters is per reply: for THIS replier, at THIS location, when
 * would the post's reach have got to them?
 *
 * That is knowable, because the routing server hands over the whole tick
 * schedule at t=0. Find the first tick whose polygon contains the replier and
 * the hazard schedule says when that tick was due; the gap between then and when
 * they actually replied is the wait we removed.
 *
 * Deliberately split into "record the event" and "work out the saving":
 * both the batch app (email / TrashNothing) and the Go API (web / app) let
 * replies through, and making each of them parse tick schedules would be the
 * same non-trivial geometry in two languages, drifting apart. So both do the
 * cheap INSERT, and one sweep in MaxReachService fills in waited_hours
 * afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('firstreply_passthroughs')) {
            return;
        }

        Schema::create('firstreply_passthroughs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('msgid');

            // Nullable: the batch-side decision happens before the chat message
            // exists, and the replier is not always identified at that point.
            $t->unsignedBigInteger('chatmsgid')->nullable();
            $t->unsignedBigInteger('userid')->nullable();

            // Which door the reply came through. The volumes differ a lot, so a
            // change in one should not be read as a change in the other.
            $t->enum('source', ['web', 'email', 'tn'])->default('web');

            // Where the replier was. Needed to work out which tick would have
            // covered them, so it has to be stored, not just tested and thrown away.
            $t->double('lat')->nullable();
            $t->double('lng')->nullable();

            $t->timestamp('created_at')->useCurrent();

            // How long this reply would have waited had it been held. NULL until
            // the sweep runs, and stays NULL when the schedule cannot answer -
            // an unknown is left as unknown rather than counted as zero saving.
            $t->float('waited_hours')->nullable();
            $t->timestamp('computed_at')->nullable();

            // The sweep's work queue.
            $t->index(['computed_at', 'created_at'], 'firstreply_passthroughs_pending');
            $t->index('msgid', 'firstreply_passthroughs_msgid');
        });

        DB::statement(
            'ALTER TABLE firstreply_passthroughs
                ADD CONSTRAINT firstreply_passthroughs_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('firstreply_passthroughs');
    }
};
