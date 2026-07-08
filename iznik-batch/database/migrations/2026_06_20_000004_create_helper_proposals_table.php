<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `helper_proposals` — complex decisions the Helper queues for the human to confirm,
 * edit and send (allocations/promises, rejections, escalations, reminders, withdrawal
 * notices). Simple conversational messages are auto-sent and never become proposals.
 * `proposed_text` is the editable draft; `payload` carries structured action data
 * (e.g. allocation qty/state/userid); `rationale` is the scoring summary shown to the
 * offerer. When resolved with 'send' the Go API performs the side effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helper_proposals')) {
            return;
        }

        Schema::create('helper_proposals', function (Blueprint $table) {
            $table->comment('Complex Helper decisions awaiting human confirm/edit/send.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batchid');
            $table->enum('type', [
                'allocation', 'message', 'rejection', 'escalation', 'reminder', 'withdrawal_notice',
            ]);
            $table->unsignedBigInteger('replierid')->nullable();
            $table->unsignedBigInteger('bulkitemid')->nullable();
            $table->string('summary', 512)->nullable()->comment('One-line for the offerer');
            $table->text('proposed_text')->nullable()->comment('Editable draft chat message');
            $table->json('payload')->nullable()->comment('Structured action data');
            $table->string('rationale', 1024)->nullable()->comment('Why the Helper proposes this');
            $table->enum('status', ['pending', 'sent', 'dismissed', 'superseded'])->default('pending');
            $table->text('resolved_text')->nullable()->comment('Final text the human sent, if edited');
            $table->timestamp('resolvedat')->nullable();
            $table->unsignedBigInteger('resolvedby')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['batchid', 'status']);

            $table->foreign('batchid')->references('id')->on('helper_batches')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('replierid')->references('id')->on('helper_repliers')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('bulkitemid')->references('id')->on('messages_bulk_items')
                ->onUpdate('no action')->onDelete('set null');
            $table->foreign('resolvedby')->references('id')->on('users')
                ->onUpdate('no action')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helper_proposals');
    }
};
