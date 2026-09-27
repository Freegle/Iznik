<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * The stored outcome of running a Pending post through the automod flowchart
 * (plans/active/automod-flowchart.md) for one group. One row per (msgid, groupid); an edit
 * or a rerun replaces the row rather than adding another, so this is always the current
 * verdict for that post on that group, not a history.
 *
 * `mode` records whether the run only recorded (shadow) or could change the post's fate
 * (approve). `verdict`/`end_node`/`reason` are the chart's outcome; `path` is the full list
 * of nodes visited (question, answer, confidence, evidence) for the moderator-facing modal
 * and the agreement report. `chart_version` is the ai-flower WorkflowDefinition version that
 * produced the row, so a chart change can be told apart from a content change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_automod')) {
            return;
        }

        Schema::create('messages_automod', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->comment('The post reviewed.');
            $table->unsignedBigInteger('groupid')->comment('The group whose rules were applied.');
            $table->enum('mode', ['shadow', 'approve'])->comment('shadow: recorded only. approve: could auto-approve.');
            $table->string('chart_version')->comment('The chart WorkflowDefinition version that produced this row.');
            $table->enum('verdict', ['approve', 'hold'])->comment('The chart\'s decision. Never reject.');
            $table->string('end_node')->comment('The chart end node reached, e.g. APPROVE or HOLD_LOAN.');
            $table->string('reason', 255)->nullable()->comment('Moderator-facing explanation of the end node.');
            $table->json('path')->comment('Every node visited: question, answer, confidence, evidence.');
            $table->timestamp('created')->useCurrent();

            $table->unique(['msgid', 'groupid'], 'messages_automod_msgid_groupid_unique');
            $table->index('created', 'messages_automod_created_index');
            $table->index(['groupid', 'created'], 'messages_automod_groupid_created_index');

            $table->comment('One row per (msgid, groupid): the current automod flowchart outcome for that post on that group.');
        });

        DB::statement(
            'ALTER TABLE messages_automod
                ADD CONSTRAINT messages_automod_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE messages_automod
                ADD CONSTRAINT messages_automod_groupid_foreign
                FOREIGN KEY (groupid) REFERENCES `groups` (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_automod');
    }
};
