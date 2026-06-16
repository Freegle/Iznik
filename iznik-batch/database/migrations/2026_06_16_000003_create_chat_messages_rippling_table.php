<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chat_messages_rippling — external (email / TrashNothing) replies held because
 * the post hasn't yet rippled out to the replier's location (PR C / #3).
 *
 * Named `_rippling`, NOT `_held`: a separate `chat_messages_held` table already
 * exists for a moderator's MANUAL hold; this is the distinct rippling-reach hold.
 * Kept in its own small table (not a flag on the large chat_messages) and bounded
 * by the in-flight set.
 *
 * A row is released by the ripple engine when the post's reach grows to cover the
 * replier's (lat,lng): ST_Contains(messages_reach.polygon, ST_SRID(POINT(lng,lat),3857)).
 * Terminal: post taken/withdrawn before coverage -> 'taken-gone' (tell replier);
 * reach maxes out without covering -> released anyway so interest isn't stranded.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('chat_messages_rippling')) {
            return;
        }

        Schema::create('chat_messages_rippling', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('chatid');        // chat_rooms.id
            $t->unsignedBigInteger('chatmsgid');     // chat_messages.id — the held reply
            $t->unsignedBigInteger('msgid');         // the post P being replied to
            $t->unsignedBigInteger('replieruserid'); // who replied
            $t->double('lat')->nullable();           // replier location, for the release test
            $t->double('lng')->nullable();
            $t->enum('status', ['held', 'released', 'dropped', 'taken-gone'])->default('held');
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('releasedat')->nullable();

            $t->index('msgid');     // release-on-tick lookup
            $t->index('chatid');    // mod "why is this held?" display
            $t->index('status');
        });

        DB::statement(
            'ALTER TABLE chat_messages_rippling
                ADD CONSTRAINT chat_messages_rippling_chatmsgid_foreign
                FOREIGN KEY (chatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE chat_messages_rippling
                ADD CONSTRAINT chat_messages_rippling_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages_rippling');
    }
};
