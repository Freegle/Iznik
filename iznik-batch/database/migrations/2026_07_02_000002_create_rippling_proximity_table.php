<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_proximity — the moderator "quicker to get to" P/Q note for a post that has rippled
 * into a group, computed once at ripple-in time (ExpandService::recordRippleProximity). Kept in
 * its own rippling_* table rather than as columns on the hot messages_groups table (same approach
 * as rippling_reach / rippling_reach_notified / rippling_held_replies).
 *
 * Composite PK (msgid, groupid): the note is per copy-in-a-group, written once. p/q are the
 * human place names ("AB10 1XG (Gilcomston)"). Cascades away with the message or the group.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rippling_proximity')) {
            return;
        }

        Schema::create('rippling_proximity', function (Blueprint $t) {
            $t->unsignedBigInteger('msgid');
            $t->unsignedBigInteger('groupid');
            $t->string('p', 160);
            $t->string('q', 160);
            $t->timestamp('computed_at')->useCurrent();
            $t->primary(['msgid', 'groupid']);
            $t->index('groupid');
        });

        DB::statement(
            'ALTER TABLE rippling_proximity
                ADD CONSTRAINT rippling_proximity_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE rippling_proximity
                ADD CONSTRAINT rippling_proximity_groupid_foreign
                FOREIGN KEY (groupid) REFERENCES `groups` (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_proximity');
    }
};
