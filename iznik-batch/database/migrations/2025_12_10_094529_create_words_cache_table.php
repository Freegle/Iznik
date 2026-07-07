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
        if (Schema::hasTable('words_cache')) {
            return;
        }

        Schema::create('words_cache', function (Blueprint $table) {
            $table->bigIncrements('id')->unique('id');
            $table->string('search')->unique('search');
            $table->text('words');
            $table->timestamp('added')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('words_cache');
    }
};
