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
        if (Schema::hasTable('logos')) {
            return;
        }

        Schema::create('logos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('path');
            $table->string('date', 5)->index('date');
            $table->string('reason', 80)->nullable();
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logos');
    }
};
