<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `helper_repliers` — the Helper's knowledge record for one person in a batch (one
 * chat per person). Mirrors the knowledge record in the concierge design doc:
 * conversation `state` in the FSM, what we know about collection/criteria/transport,
 * connector/household flags, cooldown after the offerer steps in, and the id of the
 * last inbound chat message we processed (so the driver never reprocesses).
 *
 * `distance_miles` is computed by the driver via haversine from API lat/lng — NEVER
 * from LLM geography (place names are ambiguous).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helper_repliers')) {
            return;
        }

        Schema::create('helper_repliers', function (Blueprint $table) {
            $table->comment('Helper knowledge record per replier per batch (FSM conversation state).');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batchid');
            $table->unsignedBigInteger('userid');
            $table->unsignedBigInteger('chatid')->nullable();
            $table->enum('state', [
                'NEW', 'GATHERING', 'QUALIFIED', 'ALLOCATED', 'CONFIRMED', 'COLLECTED',
                'PARKED_REPLIED', 'PARKED_QUIET', 'ESCALATED', 'TIMED_OUT', 'WITHDRAWN', 'REJECTED',
            ])->default('NEW');
            $table->enum('collection_ok', ['yes', 'no', 'unknown'])->default('unknown');
            $table->enum('criteria_met', ['yes', 'no', 'unknown', 'na'])->default('unknown');
            $table->enum('transport_ok', ['yes', 'no', 'unknown'])->default('unknown');
            $table->decimal('distance_miles', 7, 2)->nullable()->comment('Haversine from API lat/lng, never LLM');
            $table->boolean('is_connector')->default(false)->comment('Brokering for an org, not the end recipient');
            $table->unsignedBigInteger('related_to')->nullable()->comment('Household member who replied separately');
            $table->string('escalation_reason', 512)->nullable();
            $table->string('parked_reason', 512)->nullable();
            $table->string('next_action', 512)->nullable()->comment('Locked-in next action until a trigger fires');
            $table->boolean('other_items_mentioned')->default(false);
            $table->timestamp('cooldown_until')->nullable()->comment('No Helper messages until this passes (offerer stepped in)');
            $table->timestamp('offerer_last_message_at')->nullable();
            $table->unsignedBigInteger('last_processed_chatmsgid')->nullable()->comment('Dedupe inbound chat messages');
            $table->json('knowledge')->nullable()->comment('Flexible additional notes');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['batchid', 'userid']);
            $table->index('state');
            $table->index('userid');

            $table->foreign('batchid')->references('id')->on('helper_batches')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('userid')->references('id')->on('users')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('chatid')->references('id')->on('chat_rooms')
                ->onUpdate('no action')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helper_repliers');
    }
};
