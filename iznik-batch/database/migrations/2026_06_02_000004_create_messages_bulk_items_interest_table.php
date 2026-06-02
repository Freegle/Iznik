<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `messages_bulk_items_interest` — interest expressed against the bulk-offer
 * catalogue. Captures, for every item, WHO asked for it, HOW MANY they want,
 * WHEN they can collect, and the current STATE — exactly the data the Mind in
 * Brighton clearance tracked in a spreadsheet, and that the Freegle Helper
 * concierge needs to manage allocation.
 *
 * One row per (item, user); re-expressing interest updates the row. `msgid` is
 * denormalised from the parent item for easy per-post queries. `chatid` links to
 * the User2User conversation the interest belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_bulk_items_interest')) {
            return;
        }

        Schema::create('messages_bulk_items_interest', function (Blueprint $table) {
            $table->comment('Per-item interest in a bulk offer: who wants what, how many, when they can collect, and state.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bulkitemid');
            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('userid');
            $table->unsignedInteger('quantity')->default(1)->comment('How many of this item the user wants');
            $table->string('cancollect', 255)->nullable()->comment('When they can collect (free text)');
            $table->enum('state', ['Interested', 'Reserved', 'Collected', 'Withdrawn', 'Rejected'])->default('Interested');
            $table->unsignedBigInteger('chatid')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['bulkitemid', 'userid']);
            $table->index('msgid');
            $table->index('userid');
            $table->index('bulkitemid');

            $table->foreign('bulkitemid')->references('id')->on('messages_bulk_items')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('msgid')->references('id')->on('messages')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('userid')->references('id')->on('users')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('chatid')->references('id')->on('chat_rooms')
                ->onUpdate('no action')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_bulk_items_interest');
    }
};
