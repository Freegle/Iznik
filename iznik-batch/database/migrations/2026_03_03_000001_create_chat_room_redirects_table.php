<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_room_redirects')) {
            return;
        }

        Schema::create('chat_room_redirects', function (Blueprint $table) {
            $table->unsignedBigInteger('old_id')->primary();
            $table->unsignedBigInteger('new_id')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_room_redirects');
    }
};
