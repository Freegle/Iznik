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
        if (Schema::hasTable('stroll_close')) {
            return;
        }

        Schema::create('stroll_close', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->integer('dist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stroll_close');
    }
};
