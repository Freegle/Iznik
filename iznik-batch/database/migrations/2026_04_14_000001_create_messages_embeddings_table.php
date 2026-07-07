<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_embeddings')) {
            return;
        }

        Schema::create('messages_embeddings', function (Blueprint $table) {
            $table->unsignedBigInteger('msgid')->primary();
            $table->binary('embedding'); // 256 float32 = 1024 bytes
            $table->string('model_version', 50);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('msgid')
                ->references('id')
                ->on('messages')
                ->cascadeOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_embeddings');
    }
};
