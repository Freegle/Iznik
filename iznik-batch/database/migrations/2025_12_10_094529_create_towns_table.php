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
        if (Schema::hasTable('towns')) {
            return;
        }

        Schema::create('towns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->decimal('lat', 10, 4)->nullable();
            $table->decimal('lng', 10, 4)->nullable();
            $table->geometry('position', 'point')->nullable();
            $table->text('comment')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('towns');
    }
};
