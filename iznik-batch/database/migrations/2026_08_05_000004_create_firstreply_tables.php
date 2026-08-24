<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledgers for the first-reply work.
 *
 * firstreply_scouts   - who we told early about a post that had no replies yet,
 *                       and why we picked them. Doubles as the per-user fatigue
 *                       ledger (nobody should become Freegle's unpaid alerting
 *                       service) and as the attribution source for "did scouting
 *                       actually produce the reply?".
 *
 * firstreply_prompts_sent - which Freegle-chat prompts a MEMBER has already had.
 *                       Keyed on the member rather than the post, because one
 *                       message covers everything they have outstanding, so
 *                       "have they been asked this lately" is a question about
 *                       them. The cadence engine is idempotent off this: it
 *                       re-runs every few minutes and must never ask the same
 *                       question twice.
 *
 * firstreply_event_metrics - daily counters, same shape as rippling_event_metrics
 *                       so the sysadmin dashboards can read both the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('firstreply_scouts')) {
            Schema::create('firstreply_scouts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('msgid');
                $t->unsignedBigInteger('userid');

                // Which signal picked this person: 'wanted', 'search', 'similar',
                // 'frequent'. Kept so we can tell which signal is actually earning
                // its keep rather than guessing.
                $t->string('reason', 16);
                $t->float('score')->default(0);
                $t->timestamp('sent_at')->useCurrent();

                // Set when this scout went on to reply to the post. Written by the
                // attribution sweep, not at send time.
                $t->timestamp('replied_at')->nullable();

                $t->unique(['msgid', 'userid'], 'firstreply_scouts_msgid_userid');
                // Fatigue lookups are "has this user been scouted recently".
                $t->index(['userid', 'sent_at'], 'firstreply_scouts_userid_sent');
            });

            DB::statement(
                'ALTER TABLE firstreply_scouts
                    ADD CONSTRAINT firstreply_scouts_msgid_foreign
                    FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
            );
            DB::statement(
                'ALTER TABLE firstreply_scouts
                    ADD CONSTRAINT firstreply_scouts_userid_foreign
                    FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE'
            );
        }

        if (!Schema::hasTable('firstreply_prompts_sent')) {
            Schema::create('firstreply_prompts_sent', function (Blueprint $t) {
                $t->bigIncrements('id');

                // Keyed on the MEMBER, not a post: Freegle asks about their
                // outstanding posts as a set, so "have they been asked this
                // lately" is a question about them, not about any one item.
                $t->unsignedBigInteger('userid');
                $t->string('kind', 32);

                // How many posts that message covered, for the effectiveness
                // numbers - a question about six posts is not the same event as
                // one about a single post.
                $t->unsignedInteger('postcount')->default(1);
                $t->timestamp('sent_at')->useCurrent();

                // "has this member had this question recently", and "when did
                // they last hear from Freegle at all".
                $t->index(['userid', 'kind', 'sent_at'], 'firstreply_prompts_user_kind_sent');
                $t->index(['userid', 'sent_at'], 'firstreply_prompts_userid_sent');
            });
        }

        if (!Schema::hasTable('firstreply_event_metrics')) {
            Schema::create('firstreply_event_metrics', function (Blueprint $t) {
                $t->date('day');
                $t->string('event', 32);
                $t->unsignedBigInteger('count')->default(0);

                $t->primary(['day', 'event']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('firstreply_prompts_sent');
        Schema::dropIfExists('firstreply_scouts');
        Schema::dropIfExists('firstreply_event_metrics');
    }
};
