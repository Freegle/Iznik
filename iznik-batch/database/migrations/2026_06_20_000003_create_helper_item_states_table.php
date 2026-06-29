<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `helper_item_states` — per replier, per bulk item: the per-item FSM state, the
 * quantity wanted/allocated, and the Helper's score. A replier may be QUALIFIED for
 * one item but GATHERING for another, so item state is tracked separately from the
 * replier's overall conversation state. `score` + `score_breakdown` drive the
 * prioritisation the management page shows; the human always makes the final call.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helper_item_states')) {
            return;
        }

        Schema::create('helper_item_states', function (Blueprint $table) {
            $table->comment('Per-replier per-item Helper state, quantities and score.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('replierid');
            $table->unsignedBigInteger('bulkitemid');
            $table->enum('state', [
                'NEW', 'GATHERING', 'QUALIFIED', 'ALLOCATED', 'CONFIRMED', 'COLLECTED',
                'PARKED_REPLIED', 'PARKED_QUIET', 'ESCALATED', 'TIMED_OUT', 'WITHDRAWN', 'REJECTED',
            ])->default('NEW');
            $table->unsignedInteger('qty_wanted')->default(1);
            $table->unsignedInteger('qty_allocated')->default(0);
            $table->decimal('score', 6, 2)->nullable()->comment('Helper priority score for this candidate/item');
            $table->json('score_breakdown')->nullable()->comment('Factor -> contribution, for display');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['replierid', 'bulkitemid']);
            $table->index(['bulkitemid', 'state']);

            $table->foreign('replierid')->references('id')->on('helper_repliers')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('bulkitemid')->references('id')->on('messages_bulk_items')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helper_item_states');
    }
};
