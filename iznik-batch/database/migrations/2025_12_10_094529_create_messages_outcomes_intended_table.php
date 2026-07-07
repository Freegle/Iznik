<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('messages_outcomes_intended')) {
            return;
        }

        Schema::create('messages_outcomes_intended', function (Blueprint $table) {
            $table->comment('When someone starts telling us an outcome but doesn\'t finish');
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->enum('outcome', ['Taken', 'Received', 'Withdrawn', 'Repost']);

            $table->unique(['msgid', 'outcome'], 'msgid_2');
            $table->index(['msgid'], 'msgid_3');
            $table->index(['timestamp', 'outcome'], 'timestamp_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_outcomes_intended');
    }
};
