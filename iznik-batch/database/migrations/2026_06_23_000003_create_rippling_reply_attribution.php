<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reply_attribution: one row per (post, replier) first Interested reply, recording AT
 * REPLY TIME whether the replier was already an established home-group member.
 *
 * This is the only sound way to split "rippling vs existing-member" replies for the sysadmin KPI.
 * The Nuxt reply flow JOINS the replier to the group in order to reply
 * (useReplyStateMachine.handleJoinGroup -> authStore.joinGroup), so by the time the reply reaches
 * the server the rippling replier already looks like a member - a retrospective membership check
 * would mis-count every rippling reply as home-group. We therefore snapshot, in the Go reply
 * handler (CreateChatMessage), whether the replier was an approved member of an ORIGIN
 * (non-rippled-in) group of the post whose membership was ESTABLISHED before this reply action
 * (added more than the join grace ago). Frozen here so a later leave can't erase the signal.
 *
 * Read by the sysadmin rippling dashboard (reply_source_split in rippling/metrics.go).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reply_attribution')) {
            Schema::create('rippling_reply_attribution', function (Blueprint $t) {
                $t->unsignedBigInteger('msgid');
                $t->unsignedBigInteger('userid');
                $t->timestamp('replied_at')->useCurrent();
                $t->boolean('was_home_member')
                    ->comment('1 = replier was an established member of an origin (non-rippled-in) group of the post at reply time; 0 = reached via rippling');
                $t->primary(['msgid', 'userid']);
                $t->index('replied_at', 'rra_replied_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_reply_attribution');
    }
};
