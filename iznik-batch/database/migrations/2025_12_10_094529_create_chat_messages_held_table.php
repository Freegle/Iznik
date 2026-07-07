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
        if (Schema::hasTable('chat_messages_held')) {
            return;
        }

        Schema::create('chat_messages_held', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->unique('msgid');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('timestamp')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages_held');
    }
};
