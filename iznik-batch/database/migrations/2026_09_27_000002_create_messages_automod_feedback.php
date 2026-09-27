<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * A moderator marking one node of a stored automod run (messages_automod) as wrong, from
 * the ModAutomodModal "This step is wrong" button (plans/active/automod-flowchart.md). Feeds
 * the agreement report alongside the moderator-outcome comparison; on its own it is a
 * direct human signal rather than an inference from what the moderator did next.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_automod_feedback')) {
            return;
        }

        Schema::create('messages_automod_feedback', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('automodid')->comment('The messages_automod row this feedback is about.');
            $table->string('node')->comment('The chart node flagged as wrong.');
            $table->unsignedBigInteger('userid')->comment('The moderator who gave the feedback.');
            $table->timestamp('created')->useCurrent();

            $table->foreign('automodid', 'messages_automod_feedback_automodid_foreign')
                ->references('id')->on('messages_automod')->onDelete('cascade');
            $table->foreign('userid', 'messages_automod_feedback_userid_foreign')
                ->references('id')->on('users')->onDelete('cascade');

            $table->index('automodid', 'messages_automod_feedback_automodid_index');

            $table->comment('A moderator flagging one node of a stored automod run as wrong.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_automod_feedback');
    }
};
