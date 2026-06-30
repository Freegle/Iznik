<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `helper_batches` — one Freegle Helper run per clearance message. The Helper is
 * the AI concierge that manages replies to a bulk offer (see
 * plans/active/freegle-helper-concierge.md). A "batch" is a single clearance
 * message (one msgid, many bulk items). `status` is the pause/resume/stop control
 * the offerer drives from the management page and the driver loop gates on.
 * `briefing` holds the criteria / collection constraints / preferences Claude
 * extracted from the post body once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helper_batches')) {
            return;
        }

        Schema::create('helper_batches', function (Blueprint $table) {
            $table->comment('One Freegle Helper concierge run per clearance message.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('offereruserid');
            $table->enum('status', ['active', 'paused', 'stopped'])->default('active')
                ->comment('Pause/resume/stop control for the AI loop');
            $table->json('briefing')->nullable()
                ->comment('Criteria, collection constraints and preferences extracted from the post');
            $table->timestamp('lastpolledat')->nullable()->comment('Last time the poller checked chats');
            $table->timestamp('lastrunat')->nullable()->comment('Last time the LLM processed this batch');
            $table->timestamp('pausedat')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('msgid');
            $table->index('offereruserid');

            $table->foreign('msgid')->references('id')->on('messages')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('offereruserid')->references('id')->on('users')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helper_batches');
    }
};
