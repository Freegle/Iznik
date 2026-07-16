<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalise the per-(member, post) "we notified this member about this post"
 * ledger from `rippling_reach_notified` to `messages_notified`, keyed by a
 * `channel` so the same (msgid, userid) can be recorded once per delivery channel
 * (currently 'reach'; the daily/immediate digest will add 'digest').
 *
 * Existing rows become channel='reach'. Consumers that mean "was the REACH mail
 * sent?" now filter channel='reach' (reply attribution depends on it) — see the
 * same-PR changes to UnifiedDigestService / ReplyAttributionBackfillService and
 * the Go metrics/chatmessage reach checks.
 *
 * Behaviour-neutral: nothing writes a non-'reach' channel yet.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('messages_notified')) {
            return; // already generalised
        }

        if (! Schema::hasTable('rippling_reach_notified')) {
            // Fresh environment where the old table was never created: build the
            // generalised table directly.
            Schema::create('messages_notified', function ($t) {
                $t->unsignedBigInteger('msgid');
                $t->unsignedBigInteger('userid');
                $t->string('channel', 16)->default('reach');
                $t->timestamp('notified_at')->useCurrent();
                $t->primary(['msgid', 'userid', 'channel']);
                $t->index('userid');
            });
            DB::statement('ALTER TABLE messages_notified ADD CONSTRAINT messages_notified_msgid_foreign FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE messages_notified ADD CONSTRAINT messages_notified_userid_foreign FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE');

            return;
        }

        // Generalise the existing reach ledger in place, then rename. Existing rows
        // default to channel='reach'.
        if (! Schema::hasColumn('rippling_reach_notified', 'channel')) {
            DB::statement("ALTER TABLE rippling_reach_notified ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT 'reach'");
        }

        // Widen the PK to include channel. Single ALTER so msgid stays the leftmost
        // key column throughout and the msgid FK index is never dropped.
        DB::statement('ALTER TABLE rippling_reach_notified DROP PRIMARY KEY, ADD PRIMARY KEY (msgid, userid, channel)');

        Schema::rename('rippling_reach_notified', 'messages_notified');
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages_notified')) {
            return;
        }

        // Drop any non-reach rows so the narrower PK can be restored.
        DB::table('messages_notified')->where('channel', '!=', 'reach')->delete();

        Schema::rename('messages_notified', 'rippling_reach_notified');
        DB::statement('ALTER TABLE rippling_reach_notified DROP PRIMARY KEY, ADD PRIMARY KEY (msgid, userid)');

        if (Schema::hasColumn('rippling_reach_notified', 'channel')) {
            DB::statement('ALTER TABLE rippling_reach_notified DROP COLUMN channel');
        }
    }
};
