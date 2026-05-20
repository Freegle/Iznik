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
        if (Schema::hasTable('messages_outcomes')) {
            return;
        }

        Schema::create('messages_outcomes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->enum('outcome', ['Taken', 'Received', 'Withdrawn', 'Repost', 'Expired', 'Partial']);
            $table->enum('happiness', ['Happy', 'Fine', 'Unhappy'])->nullable();
            $table->text('comments')->nullable();
            $table->boolean('reviewed')->default(false);

            $table->index(['timestamp', 'outcome'], 'timestamp_2');
            $table->index(['reviewed', 'timestamp', 'happiness'], 'timestamp_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_outcomes');
    }
};
