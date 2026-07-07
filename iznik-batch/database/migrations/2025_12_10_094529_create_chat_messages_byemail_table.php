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
        if (Schema::hasTable('chat_messages_byemail')) {
            return;
        }

        Schema::create('chat_messages_byemail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chatmsgid')->index('chatmsgid');
            $table->unsignedBigInteger('msgid')->index('msgid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages_byemail');
    }
};
