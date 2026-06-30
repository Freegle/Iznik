<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `helper_sent_messages` — records every chat message the Helper sent (auto or via a
 * resolved proposal). Two purposes: the management page shows an "AI" badge on these,
 * and the driver never reprocesses its own outbound messages. Kept as a side table so
 * the high-traffic `chat_messages` schema is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helper_sent_messages')) {
            return;
        }

        Schema::create('helper_sent_messages', function (Blueprint $table) {
            $table->comment('Chat messages sent by the Helper (AI badge + outbound dedupe).');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batchid');
            $table->unsignedBigInteger('chatmsgid');
            $table->unsignedBigInteger('chatid');
            $table->unsignedBigInteger('replierid')->nullable();
            $table->enum('kind', [
                'gathering', 'answer', 'ack', 'nudge', 'allocation', 'rejection',
                'reminder', 'withdrawal_notice', 'other',
            ])->default('other');
            $table->boolean('auto')->default(true)->comment('Auto-sent vs human-confirmed send');
            $table->unsignedBigInteger('proposalid')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('chatmsgid');
            $table->index('chatid');

            $table->foreign('batchid')->references('id')->on('helper_batches')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('chatmsgid')->references('id')->on('chat_messages')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('chatid')->references('id')->on('chat_rooms')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('replierid')->references('id')->on('helper_repliers')
                ->onUpdate('no action')->onDelete('set null');
            $table->foreign('proposalid')->references('id')->on('helper_proposals')
                ->onUpdate('no action')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helper_sent_messages');
    }
};
