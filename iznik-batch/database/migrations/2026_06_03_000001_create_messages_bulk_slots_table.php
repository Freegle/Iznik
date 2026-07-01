<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `messages_bulk_slots` — the collection date/time windows a bulk-offer
 * ("clearance") offerer makes available (e.g. "Tue 7 Apr, 10am-4pm"). Repliers
 * pick one of these when expressing interest, rather than typing a free-form
 * time, so the offerer can concentrate collections into known windows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_bulk_slots')) {
            return;
        }

        Schema::create('messages_bulk_slots', function (Blueprint $table) {
            $table->comment('Collection date/time windows offered for a bulk offer; repliers pick one.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid');
            $table->unsignedInteger('position')->default(0);
            $table->string('slot', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index('msgid');
            $table->foreign('msgid')->references('id')->on('messages')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_bulk_slots');
    }
};
