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
        if (!Schema::hasTable('ai_images')) {
            Schema::create('ai_images', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->unique('name');
                $table->string('externaluid')->nullable();
                $table->timestamp('created')->useCurrent()->index('created');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_images');
    }
};
