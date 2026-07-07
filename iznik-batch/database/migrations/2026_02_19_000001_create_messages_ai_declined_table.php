<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the messages_ai_declined table.
 *
 * Tracks messages where the user declined the AI-generated illustration
 * during compose. Referenced by iznik-server's Message class.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messages_ai_declined')) {
            Schema::create('messages_ai_declined', function (Blueprint $table) {
                $table->unsignedBigInteger('msgid');
                $table->timestamp('created')->useCurrent();

                $table->primary('msgid');
                $table->foreign('msgid')->references('id')->on('messages')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_ai_declined');
    }
};
