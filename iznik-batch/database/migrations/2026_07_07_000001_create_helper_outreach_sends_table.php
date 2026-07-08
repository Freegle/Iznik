<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dup guard + audit for email-outreach replies sent from an approved Helper
 * proposal. One row per proposal that has actually been emailed, so
 * bulkoffer:send-approved-outreach can never send the same approved reply twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helper_outreach_sends')) {
            // Already created out-of-band (dev DBs where the helper session made the table
            // before this migration was recorded) - guard so migrate doesn't abort.
            return;
        }

        Schema::create('helper_outreach_sends', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('proposalid');
            $table->string('gmail_thread_id', 255)->nullable();
            $table->string('gmail_message_id', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('proposalid');   // the dup guard
            $table->foreign('proposalid')->references('id')->on('helper_proposals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helper_outreach_sends');
    }
};
