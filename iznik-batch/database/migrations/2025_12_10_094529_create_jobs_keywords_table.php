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
        if (Schema::hasTable('jobs_keywords')) {
            return;
        }

        Schema::create('jobs_keywords', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('keyword')->unique('keyword');
            $table->unsignedBigInteger('count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs_keywords');
    }
};
