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
        if (Schema::hasTable('messages_attachments_recognise')) {
            return;
        }

        Schema::create('messages_attachments_recognise', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attid')->unique('attid');
            $table->json('info');
            $table->enum('rating', ['Good', 'Bad'])->nullable();
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_attachments_recognise');
    }
};
