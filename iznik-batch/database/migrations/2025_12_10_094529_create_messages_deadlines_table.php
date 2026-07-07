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
        if (Schema::hasTable('messages_deadlines')) {
            return;
        }

        Schema::create('messages_deadlines', function (Blueprint $table) {
            $table->unsignedBigInteger('msgid')->unique('msgid');
            $table->tinyInteger('FOP')->default(1);
            $table->date('mustgoby')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_deadlines');
    }
};
