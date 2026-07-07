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
        if (Schema::hasTable('weights')) {
            return;
        }

        Schema::create('weights', function (Blueprint $table) {
            $table->comment('Standard weights, from FRN 2009');
            $table->string('name', 80)->primary();
            $table->string('simplename', 80)->nullable()->comment('The name in simpler terms');
            $table->decimal('weight', 5);
            $table->enum('source', ['FRN 2009', 'Freegle'])->default('FRN 2009');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weights');
    }
};
