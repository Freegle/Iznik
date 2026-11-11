<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chat_prompts.userid and chat_prompts.heldchatmsgid - a prompt asked of one member, about a
 * reply that waits for the answer.
 *
 * The 'confirm_reply' prompt is asked in a chat between two members, but only of the
 * replier: "this looks damaged / needs a car - still want it?". `userid` says who was asked,
 * so only they see it and only they can answer it. NULL keeps the existing meaning: a prompt
 * in a member's own chat with Freegle, answerable by that member.
 *
 * `heldchatmsgid` is the reply the answer decides. chats:process-incoming leaves it
 * unprocessed - so the poster neither sees it nor hears about it - until the prompt is
 * answered.
 *
 * `answered_via` is how it was answered: 'button', or 'message' when writing again counted as
 * a yes.
 *
 * Both columns have a key of their own, named here, so that each is indexed whether or not the
 * foreign keys are kept: the chat code filters on userid for every chat message it creates and
 * on heldchatmsgid for every pass of chats:process-incoming. Production gets the same change by
 * hand, in two statements (add_reply_confirm_to_chat_prompts_migration.sql).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_prompts', 'userid')) {
            Schema::table('chat_prompts', function (Blueprint $t) {
                $t->unsignedBigInteger('userid')->nullable()->after('msgids')
                    ->index('chat_prompts_userid');
                $t->unsignedBigInteger('heldchatmsgid')->nullable()->after('userid')
                    ->index('chat_prompts_heldchatmsgid');
                $t->string('answered_via', 16)->nullable()->after('answered_at');
            });

            DB::statement(
                'ALTER TABLE chat_prompts
                    ADD CONSTRAINT chat_prompts_userid_foreign
                    FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE'
            );

            DB::statement(
                'ALTER TABLE chat_prompts
                    ADD CONSTRAINT chat_prompts_heldchatmsgid_foreign
                    FOREIGN KEY (heldchatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_prompts', 'userid')) {
            Schema::table('chat_prompts', function (Blueprint $t) {
                $t->dropForeign('chat_prompts_userid_foreign');
                $t->dropForeign('chat_prompts_heldchatmsgid_foreign');
                $t->dropIndex('chat_prompts_userid');
                $t->dropIndex('chat_prompts_heldchatmsgid');
                $t->dropColumn(['userid', 'heldchatmsgid', 'answered_via']);
            });
        }
    }
};
