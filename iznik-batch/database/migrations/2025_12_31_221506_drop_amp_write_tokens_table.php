<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drop the amp_write_tokens table - no longer needed as we switched
     * from database-stored one-time tokens to HMAC-based reusable tokens.
     */
    public function up(): void
    {
        Schema::dropIfExists('amp_write_tokens');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('amp_write_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 255)->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('email_tracking_id')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->useCurrent();

            $table->index('nonce', 'idx_nonce');
            $table->index(['user_id', 'chat_id'], 'idx_user_chat');
            $table->index('expires_at', 'idx_expires');
        });
    }
};
