<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chat_prompts - the answerable part of a chat message of type 'Prompt'.
 *
 * One row per prompt, keyed on the chat message it belongs to. The chat message
 * carries the human-readable question (so email, push, mod review and search all
 * work untouched); this row carries the machine-readable options and, once the
 * member taps one, their answer.
 *
 * `kind` says what answering it DOES - 'delivery' patches messages.deliverypossible,
 * 'deadline' patches messages.deadline, 'views' and 'photo' are informational and
 * have no options. The side effect lives in PromptService, keyed off this column,
 * so adding a new kind never means touching the chat plumbing.
 *
 * ON DELETE CASCADE from chat_messages: a deleted chat message must not leave an
 * orphan prompt that the API would try to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_prompts')) {
            return;
        }

        Schema::create('chat_prompts', function (Blueprint $t) {
            // One prompt per chat message.
            $t->unsignedBigInteger('chatmsgid')->primary();

            // The post the prompt is about when it is about exactly one - which is
            // the exception. Drives the chat message's refmsgid and the item card
            // in the notification email.
            $t->unsignedBigInteger('msgid')->nullable()->index('chat_prompts_msgid');

            // The posts the answer applies to. Freegle talks about a member's
            // outstanding posts as a SET, the way a clearance treats its items:
            // "your 5 posts have been looked at 47 times between them" beats five
            // separate conversations, and "could you deliver?" is one question
            // about all of them rather than one per item. JSON rather than a join
            // table because it is only ever read whole, with the prompt.
            $t->json('msgids')->nullable();

            // What answering this prompt does. See PromptService::KIND_*.
            $t->string('kind', 32)->index('chat_prompts_kind');

            // [{"value": "maybe", "label": "Maybe", "variant": "primary"}, ...]
            // Empty array for informational prompts with nothing to tap.
            $t->json('options')->nullable();

            // The `value` of the option the member chose, once they choose one.
            $t->string('answer', 64)->nullable();
            $t->timestamp('answered_at')->nullable();

            // After this, the buttons stop being offered - a week-old "could you
            // deliver?" on a long-gone item should not still be answerable.
            $t->timestamp('expires_at')->nullable();

            $t->timestamp('created_at')->useCurrent();
        });

        DB::statement(
            'ALTER TABLE chat_prompts
                ADD CONSTRAINT chat_prompts_chatmsgid_foreign
                FOREIGN KEY (chatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE'
        );

        DB::statement(
            'ALTER TABLE chat_prompts
                ADD CONSTRAINT chat_prompts_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_prompts');
    }
};
